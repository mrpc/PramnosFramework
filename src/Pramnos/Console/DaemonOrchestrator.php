<?php

declare(strict_types=1);

namespace Pramnos\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generic daemon orchestrator — supervises a set of background processes and
 * keeps them alive, respawning crashes and gracefully stopping removed entries.
 *
 * Applications extend this class and implement buildDesiredProcesses() to
 * declare which daemons should run. Everything else (reconcile loop, state
 * persistence, stop-file mechanism, flock singleton guard, dedup scan, interactive
 * dashboard, git-hash restart detection) is provided by the framework.
 *
 * Each "desired process" is an associative array:
 *   id            string   Unique slot identifier (used for state + log file)
 *   daemon        string   Daemon type label (e.g. 'queue', 'kafka', 'custom')
 *   workerId      string   Value for --worker-id argument
 *   lockFile      string   Absolute path to the worker's lock file
 *   tokens        string[] CLI arguments passed to the entry-point script
 *   requireLockFile bool   Whether a healthy lock file is required for "running" status (default true)
 *   shellCommand  string   (optional) Raw shell command — overrides tokens + getEntryPoint()
 *   profile       string   (optional) Human-readable profile name shown in dashboard
 *
 */
abstract class DaemonOrchestrator extends CommandBase
{
    /**
     * Seconds to wait for a graceful exit before sending SIGTERM.
     */
    protected const STOP_GRACE_SECONDS = 30;

    /**
     * Seconds without a heartbeat update before a managed daemon is considered
     * unhealthy and scheduled for graceful restart.
     */
    protected const HEARTBEAT_STALE_SECONDS = 300;

    /**
     * How many reconcile cycles between deduplication scans.
     */
    protected const DEDUP_SCAN_INTERVAL = 3;

    /**
     * How often (seconds) to re-check isOrchestratorEnabled() while disabled.
     */
    protected const DISABLED_POLL_SECONDS = 15;

    /**
     * How often (seconds) to check for a new git commit while running.
     */
    protected const GIT_CHECK_SECONDS = 60;

    /** @var bool  Keep main loop running. */
    protected bool $shouldContinue = true;

    /** @var int   Dashboard start timestamp. */
    protected int $startTime = 0;

    /** @var float Last measured CPU load. */
    protected float $cpuUsage = 0.0;

    /** @var int   Last measured memory usage in bytes. */
    protected int $memoryUsage = 0;

    /** @var int   Interactive terminal width. */
    protected int $terminalWidth = 120;

    /** @var int   Interactive terminal height. */
    protected int $terminalHeight = 30;

    /** @var int   Active reconcile interval for dashboard display. */
    protected int $reconcileInterval = 10;

    /** @var bool  Emit [ok] every cycle (service-mode verbosity). */
    protected bool $verboseHealthLogs = false;

    /** @var array<string, int>  Last announced healthy PID per daemon id. */
    protected array $announcedHealthyPids = [];

    /** @var resource|null  Open file handle for flock-based singleton guard. */
    private $orchestratorLock = null;

    // ── Abstract methods — application must implement ─────────────────────────

    /**
     * Return the list of processes that should be running.
     *
     * Each element is an associative array with at minimum:
     *   id, daemon, workerId, lockFile, tokens
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function buildDesiredProcesses(): array;

    /**
     * Return the title string shown in the interactive dashboard header.
     *
     * Example: ' MY APP DAEMON ORCHESTRATOR '
     */
    abstract protected function getDashboardTitle(): string;

    /**
     * Return the absolute path to the CLI entry-point script that child
     * daemons are spawned with.
     *
     * Example: ROOT . '/bin/myapp'
     */
    abstract protected function getEntryPoint(): string;

    // ── Overrideable hooks ────────────────────────────────────────────────────

    /**
     * Whether the orchestrator should actively supervise processes.
     *
     * Override to read an application setting. When this returns false the
     * orchestrator requests a graceful stop of all managed processes and
     * waits until re-enabled.
     *
     * Default: always enabled.
     */
    protected function isOrchestratorEnabled(): bool
    {
        return true;
    }

    /**
     * Absolute path to the orchestrator's exclusive singleton lock file.
     */
    protected function getOrchestratorLockFile(): string
    {
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        return $base . '/var/DAEMON_ORCHESTRATOR.lock';
    }

    /**
     * Absolute path to the JSON state file that tracks running PIDs.
     */
    protected function getStateFile(): string
    {
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        return $base . '/var/daemon_orchestrator_state.json';
    }

    /**
     * Glob pattern (relative to var/) for managed lock files that should be
     * cleaned up on startup. Return '' to skip the cleanup scan.
     *
     * Example: '{QUEUE_PROCESSOR_*,KAFKA_CONSUMER_*}'
     */
    protected function getManagedLockFileGlobPattern(): string
    {
        return '*';
    }

    // ── Terminal size hook ────────────────────────────────────────────────────

    protected function updateTerminalSize(): void
    {
        [$height, $width]   = $this->detectTerminalSize();
        $this->terminalHeight = $height;
        $this->terminalWidth  = $width;
    }

    // ── Command configuration ─────────────────────────────────────────────────

    protected function configure(): void
    {
        $name = $this->getOrchestratorCommandName();
        $this->setName($name)
            ->setDescription('Orchestrates daemon processes, keeping them alive and respawning crashes.')
            ->addOption('once',           null, InputOption::VALUE_NONE,     'Run one reconciliation cycle and exit')
            ->addOption('interval',       'i',  InputOption::VALUE_REQUIRED, 'Seconds between reconciliation cycles', 10)
            ->addOption('php-binary',     null, InputOption::VALUE_REQUIRED, 'PHP executable used to spawn child daemons', PHP_BINARY)
            ->addOption('dry-run',        null, InputOption::VALUE_NONE,     'Show planned actions without making changes')
            ->addOption('interactive',    null, InputOption::VALUE_NONE,     'Render a live dashboard')
            ->addOption('verbose-health', null, InputOption::VALUE_NONE,     'Log [ok] status every reconcile cycle');
    }

    // ── Command execution ─────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $once        = (bool)$input->getOption('once');
        $dryRun      = (bool)$input->getOption('dry-run');
        $interactive = (bool)$input->getOption('interactive');
        $this->verboseHealthLogs = (bool)$input->getOption('verbose-health');
        $interval    = max(1, (int)$input->getOption('interval'));
        $phpBinary   = (string)$input->getOption('php-binary');

        $this->startTime          = time();
        $this->reconcileInterval  = $interval;

        if ($interactive && $once) {
            $output->writeln('<comment>Interactive mode is ignored when --once is used.</comment>');
            $interactive = false;
        }

        if ($interactive) {
            $this->initializeInteractiveTerminal($output, false);
        }

        $varDir = (defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var';
        if (!is_dir($varDir)) {
            $output->writeln('<error>ERROR: var/ directory not found: ' . $varDir . '</error>');
            $output->writeln('<comment>Run the orchestrator from inside the application container.</comment>');
            return 1;
        }

        if (!$this->tryAcquireOrchestratorLock($output)) {
            return 1;
        }

        register_shutdown_function(fn() => $this->releaseOrchestratorLock());
        $this->registerSignalHandlers($output);

        $output->writeln('<info>Starting daemon orchestrator</info>');
        $modeLabel = $once ? 'single run' : 'daemon';
        if ($interactive) {
            $modeLabel .= ' (interactive)';
        }
        $output->writeln('Mode: ' . $modeLabel);
        if ($dryRun) {
            $output->writeln('<comment>Dry-run mode enabled; no process changes will be applied.</comment>');
        }

        $this->cleanupStaleLockFiles($output);

        $wasEnabled          = null;
        $disableStopRequested = false;
        $cycleCount          = 1;
        $lastGitHash         = $this->getCurrentGitHash();
        $lastGitCheck        = time();

        do {
            $enabled = $this->isOrchestratorEnabled();

            if (!$enabled) {
                if (!$disableStopRequested) {
                    $this->requestStopAll($output);
                    $disableStopRequested = true;
                    $output->writeln('<comment>Orchestrator disabled — stop requested for managed daemons.</comment>');
                }
                if ($wasEnabled !== false) {
                    $output->writeln('<comment>Orchestrator disabled — waiting for re-enable…</comment>');
                    $wasEnabled = false;
                }
                if ($once) {
                    break;
                }
                for ($i = 0; $i < static::DISABLED_POLL_SECONDS; $i++) {
                    if (!$this->shouldContinue) {
                        break;
                    }
                    sleep(1);
                }
                continue;
            }

            if ($wasEnabled === false) {
                $output->writeln('<info>Orchestrator re-enabled. Resuming supervision.</info>');
            }
            $wasEnabled           = true;
            $disableStopRequested = false;

            // Periodic git-hash change detection.
            if (!$once && (time() - $lastGitCheck) >= static::GIT_CHECK_SECONDS) {
                $currentHash = $this->getCurrentGitHash();
                if ($currentHash !== '' && $lastGitHash !== '' && $currentHash !== $lastGitHash) {
                    $output->writeln(
                        '<info>[git]</info> New deployment detected ('
                        . substr($lastGitHash, 0, 8) . ' → ' . substr($currentHash, 0, 8)
                        . '). Requesting graceful restart of all daemons…'
                    );
                    $this->requestStopAll($output);
                }
                if ($currentHash !== '') {
                    $lastGitHash = $currentHash;
                }
                $lastGitCheck = time();
            }

            $reconcileOutput = $interactive ? new NullOutput() : $output;
            $this->reconcile($phpBinary, $dryRun, $reconcileOutput);

            $dedupMessages = [];
            if (!$dryRun) {
                $runDedup = $interactive || ($cycleCount % static::DEDUP_SCAN_INTERVAL === 0);
                if ($runDedup) {
                    $dedupOut = new \Symfony\Component\Console\Output\BufferedOutput();
                    $this->deduplicateRunningProcesses($this->buildDesiredProcesses(), $this->loadState(), $dedupOut);
                    $raw = trim($dedupOut->fetch());
                    if ($raw !== '') {
                        $dedupMessages = explode("\n", $raw);
                        if (!$interactive) {
                            foreach ($dedupMessages as $msg) {
                                $output->writeln($msg);
                            }
                        }
                    }
                }
            }
            $cycleCount++;

            if ($interactive) {
                $this->renderInteractiveDashboard($output, $dryRun, $dedupMessages);
            }

            if ($once) {
                break;
            }

            for ($i = 0; $i < $interval; $i++) {
                if (!$this->shouldContinue) {
                    break;
                }
                sleep(1);
            }
        } while ($this->shouldContinue);

        $output->writeln('<info>Daemon orchestrator exited.</info>');
        if ($interactive) {
            $this->showCursor($output);
        }
        return 0;
    }

    // ── System metrics ────────────────────────────────────────────────────────

    protected function updateSystemMetrics(): void
    {
        $this->memoryUsage = memory_get_usage(true);
        if ($this->supportsSysGetLoadAvg()) {
            $load            = $this->getLoadAvg();
            $this->cpuUsage  = isset($load[0]) ? (float)$load[0] : 0.0;
        }
        $this->updateTerminalSize();
    }

    // ── Reconcile loop ────────────────────────────────────────────────────────

    /**
     * Compare desired vs actual state and spawn/stop processes as needed.
     */
    protected function reconcile(string $phpBinary, bool $dryRun, OutputInterface $output): void
    {
        $desired    = $this->buildDesiredProcesses();
        $desiredById = [];
        foreach ($desired as $item) {
            $desiredById[$item['id']] = $item;
        }

        $state    = $this->loadState();
        $stateById = [];
        foreach ($state as $item) {
            $stateById[$item['id']] = $item;
        }

        // Bring up missing / restart crashed processes.
        foreach ($desiredById as $id => $desiredProcess) {
            $existing        = $stateById[$id] ?? null;
            $pid             = (int)($existing['pid'] ?? 0);
            $requiresLock    = (bool)($desiredProcess['requireLockFile'] ?? true);
            $lockFile        = (string)($desiredProcess['lockFile'] ?? '');
            $hasHealthyLock  = !$requiresLock
                || ($lockFile !== '' && file_exists($lockFile) && !file_exists($lockFile . '.stop'));

            $pidAlive   = $this->isProcessRunning($pid);
            $lockPid    = 0;
            $lockPidAlive = false;

            if ($requiresLock && $hasHealthyLock && $lockFile !== '') {
                $lockPid      = $this->readWorkerPidFromLockFile($lockFile);
                $lockPidAlive = $lockPid > 0 && $this->isProcessRunning($lockPid);
            }

            // Stale heartbeat: lock exists but not touched recently.
            if (
                $requiresLock
                && file_exists($lockFile)
                && !file_exists($lockFile . '.stop')
                && (time() - filemtime($lockFile)) > static::HEARTBEAT_STALE_SECONDS
            ) {
                if (!$dryRun) {
                    $this->requestStop($lockFile);
                }
                $stale = time() - filemtime($lockFile);
                $output->writeln('<error>[stale]</error> ' . $id . ' — no heartbeat for ' . $stale . 's, requesting graceful restart');
                continue;
            }

            if ($hasHealthyLock) {
                if ($lockPid > 0 && $lockPidAlive) {
                    // Sync state PID if it drifted.
                    if ($pid !== $lockPid) {
                        $stateById[$id] = array_merge((array)$existing, [
                            'id'        => $id,
                            'daemon'    => $desiredProcess['daemon'] ?? 'daemon',
                            'workerId'  => $desiredProcess['workerId'] ?? $id,
                            'lockFile'  => $lockFile,
                            'pid'       => $lockPid,
                            'updatedAt' => gmdate('c'),
                        ]);
                    }
                    if ($this->shouldAnnounceHealthyProcess($id, $lockPid)) {
                        $output->writeln('<info>[ok]</info> ' . $id . ' pid=' . $lockPid . ' (lock active)');
                    }
                    continue;
                }

                if ($pid <= 0 || !$pidAlive) {
                    $reason = $pid <= 0 ? 'state corrupted' : 'process dead';
                    unset($this->announcedHealthyPids[$id]);
                    $output->writeln('<error>[crashed]</error> ' . $id . ' pid=' . $pid . ' — ' . $reason . ', cleaning up and restarting');
                    if (!$dryRun) {
                        @unlink($lockFile);
                        @unlink($lockFile . '.stop');
                    }
                    $hasHealthyLock = false;
                } else {
                    if ($this->shouldAnnounceHealthyProcess($id, $pid)) {
                        $output->writeln('<info>[ok]</info> ' . $id . ' pid=' . $pid . ' (lock active)');
                    }
                    continue;
                }
            }

            // Old instance still alive but a stop file was written.
            if ($pidAlive && !$hasHealthyLock) {
                unset($this->announcedHealthyPids[$id]);
                $output->writeln('<comment>[waiting]</comment> ' . $id . ' pid=' . $pid . ' — gracefully stopping, will restart when done');
                continue;
            }

            // PID was known but is now dead — exited cleanly.
            if ($pid > 0 && !$pidAlive && !$hasHealthyLock) {
                unset($this->announcedHealthyPids[$id]);
                unset($stateById[$id]);
                $output->writeln('<info>[exited]</info> ' . $id . ' — daemon shutdown cleanly');
                continue;
            }

            if ($dryRun) {
                $output->writeln('<comment>[start]</comment> ' . $id);
                continue;
            }

            // Pre-spawn guard: scan for a live process with this worker-id.
            $tokens       = (array)($desiredProcess['tokens'] ?? []);
            $workerIdInTokens = '';
            for ($ti = 0; $ti < count($tokens) - 1; $ti++) {
                if ($tokens[$ti] === '--worker-id') {
                    $workerIdInTokens = (string)($tokens[$ti + 1] ?? '');
                    break;
                }
            }
            if ($workerIdInTokens !== '') {
                $alreadyRunning = $this->findRunningPidsByWorkerSignature($workerIdInTokens);
                if (count($alreadyRunning) > 0) {
                    $adoptPid = max($alreadyRunning);
                    $output->writeln('<comment>[adopt]</comment> ' . $id . ' pid=' . $adoptPid . ' — already running, skipping spawn');
                    $stateById[$id] = [
                        'id'        => $id,
                        'daemon'    => $desiredProcess['daemon'],
                        'profile'   => (string)($desiredProcess['profile'] ?? ''),
                        'workerId'  => $desiredProcess['workerId'],
                        'pid'       => $adoptPid,
                        'lockFile'  => $desiredProcess['lockFile'],
                        'updatedAt' => date('c'),
                    ];
                    continue;
                }
            }

            $this->clearStopFile($desiredProcess['lockFile']);
            $spawnedPid = $this->startDesiredProcess($phpBinary, $desiredProcess);

            if (!$this->confirmProcessStartup($desiredProcess, $spawnedPid)) {
                if ($spawnedPid > 0 && $this->isProcessRunning($spawnedPid)) {
                    $output->writeln(
                        '<comment>[started-unverified]</comment> ' . $id . ' pid=' . $spawnedPid
                        . ' (started but lock not yet healthy, will verify next cycle)'
                    );
                    $stateById[$id] = [
                        'id'        => $id,
                        'daemon'    => $desiredProcess['daemon'],
                        'profile'   => (string)($desiredProcess['profile'] ?? ''),
                        'workerId'  => $desiredProcess['workerId'],
                        'pid'       => $spawnedPid,
                        'lockFile'  => $desiredProcess['lockFile'],
                        'updatedAt' => date('c'),
                    ];
                } else {
                    $output->writeln(
                        '<error>[failed-start]</error> ' . $id . ' pid=' . $spawnedPid
                        . ' ' . $this->readStartupFailureDetails($desiredProcess)
                    );
                }
                continue;
            }

            $stateById[$id] = [
                'id'        => $id,
                'daemon'    => $desiredProcess['daemon'],
                'profile'   => (string)($desiredProcess['profile'] ?? ''),
                'workerId'  => $desiredProcess['workerId'],
                'pid'       => $spawnedPid,
                'lockFile'  => $desiredProcess['lockFile'],
                'updatedAt' => date('c'),
            ];
            $output->writeln('<info>[started]</info> ' . $id . ' pid=' . $spawnedPid);
            $this->announcedHealthyPids[$id] = $spawnedPid;
        }

        // Tear down processes that are no longer desired.
        foreach ($stateById as $id => $currentProcess) {
            if (isset($desiredById[$id])) {
                continue;
            }

            if ($dryRun) {
                $output->writeln('<comment>[stop]</comment> ' . $id);
                continue;
            }

            $pid      = (int)($currentProcess['pid'] ?? 0);
            $lockFile = (string)($currentProcess['lockFile'] ?? '');

            if (!$this->isProcessRunning($pid)) {
                unset($this->announcedHealthyPids[$id]);
                unset($stateById[$id]);
                $output->writeln('<info>[stopped]</info> ' . $id);
                continue;
            }

            $stoppingAt = $currentProcess['stoppingAt'] ?? null;

            if ($stoppingAt === null) {
                $this->requestStop($lockFile);
                $stateById[$id]['stoppingAt'] = date('c');
                $output->writeln('<comment>[stopping]</comment> soft stop requested for ' . $id . ' pid=' . $pid);
            } elseif ((time() - (int)strtotime((string)$stoppingAt)) >= static::STOP_GRACE_SECONDS) {
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, defined('SIGTERM') ? \SIGTERM : 15);
                }
                unset($this->announcedHealthyPids[$id]);
                unset($stateById[$id]);
                $output->writeln('<comment>[killed]</comment> ' . $id . ' pid=' . $pid . ' (grace period expired)');
            } else {
                $output->writeln('<comment>[stopping]</comment> waiting for graceful exit: ' . $id . ' pid=' . $pid);
            }
        }

        if (!$dryRun) {
            $this->saveState(array_values($stateById));
        }
    }

    // ── Process management ────────────────────────────────────────────────────

    /**
     * Spawn a daemon process in the background, redirecting output to a log file.
     *
     * @param array<string, mixed> $desiredProcess
     */
    protected function startDesiredProcess(string $phpBinary, array $desiredProcess): int
    {
        $this->ensureLogsDir();
        $logFile = $this->getProcessLogFile($desiredProcess);

        if (isset($desiredProcess['shellCommand'])) {
            $command = trim((string)$desiredProcess['shellCommand']);
        } else {
            $tokens  = (array)($desiredProcess['tokens'] ?? []);
            $command = escapeshellarg($phpBinary)
                . ' ' . escapeshellarg($this->getEntryPoint())
                . ' ' . $this->buildShellTokens($tokens);
        }

        $shell = 'nohup setsid ' . $command . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        $pid   = (int)trim((string)shell_exec($shell));
        return max(0, $pid);
    }

    /**
     * Return true once pid is both alive and (for lock-based daemons) has a
     * healthy lock file. Polls for up to 3 seconds.
     *
     * @param array<string, mixed> $desiredProcess
     */
    protected function confirmProcessStartup(array $desiredProcess, int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $requiresLock = (bool)($desiredProcess['requireLockFile'] ?? true);
        $lockFile     = (string)($desiredProcess['lockFile'] ?? '');
        $deadline     = microtime(true) + 3.0;

        while (microtime(true) <= $deadline) {
            $pidAlive = $this->isProcessRunning($pid);

            if (!$requiresLock) {
                if ($pidAlive) {
                    return true;
                }
            } elseif ($lockFile !== '' && file_exists($lockFile) && !file_exists($lockFile . '.stop')) {
                $lockPid = $this->readWorkerPidFromLockFile($lockFile);
                if ($lockPid === $pid && $pidAlive) {
                    return true;
                }
            }

            usleep(200000);
        }

        return false;
    }

    /**
     * Log file path for a given desired process.
     *
     * @param array<string, mixed> $desiredProcess
     */
    protected function getProcessLogFile(array $desiredProcess): string
    {
        $base     = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $daemon   = (string)($desiredProcess['daemon']   ?? 'daemon');
        $workerId = (string)($desiredProcess['workerId'] ?? 'worker');
        return $base . '/var/logs/' . $daemon . '-' . $workerId . '.log';
    }

    /**
     * Brief diagnostic extracted from the daemon's log file on startup failure.
     *
     * @param array<string, mixed> $desiredProcess
     */
    protected function readStartupFailureDetails(array $desiredProcess): string
    {
        $logFile = $this->getProcessLogFile($desiredProcess);
        if (!file_exists($logFile)) {
            return '(log: not created yet)';
        }

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || count($lines) === 0) {
            return '(log: empty)';
        }

        $tail = array_values(array_filter(
            array_map(static fn($l) => trim((string)$l), array_slice($lines, -5)),
            static fn($l) => $l !== ''
        ));

        if (count($tail) === 0) {
            return '(log: empty)';
        }

        $excerpt = preg_replace('/\s+/', ' ', implode(' | ', $tail)) ?? '';
        if ($excerpt === '') {
            return '(log: unreadable)';
        }
        if (strlen($excerpt) > 600) {
            $excerpt = substr($excerpt, -600);
        }

        return '(log tail: ' . $excerpt . ')';
    }

    /**
     * Escape an array of CLI tokens for shell use.
     *
     * @param string[] $tokens
     */
    protected function buildShellTokens(array $tokens): string
    {
        return implode(' ', array_map('escapeshellarg', $tokens));
    }

    /**
     * Returns true when process $pid is alive.
     */
    protected function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return file_exists('/proc/' . $pid);
    }

    /**
     * Scan /proc (or ps) for PIDs of PHP processes with the given --worker-id.
     *
     * @return int[]
     */
    protected function findRunningPidsByWorkerSignature(string $workerId): array
    {
        $pids   = [];
        $needle = '--worker-id ' . $workerId;

        if (is_dir('/proc')) {
            $entries = @scandir('/proc');
            if (!is_array($entries)) {
                return $pids;
            }
            foreach ($entries as $entry) {
                if (!ctype_digit($entry)) {
                    continue;
                }
                $raw = @file_get_contents('/proc/' . $entry . '/cmdline');
                if ($raw === false || $raw === '') {
                    continue;
                }
                if (strpos(str_replace("\0", ' ', $raw), $needle) !== false) {
                    $pids[] = (int)$entry;
                }
            }
            return $pids;
        }

        // @codeCoverageIgnoreStart
        $lines = [];
        exec('ps aux 2>/dev/null', $lines);
        foreach ($lines as $line) {
            if (strpos($line, $needle) === false) {
                continue;
            }
            if (preg_match('/^\S+\s+(\d+)/', $line, $m)) {
                $pids[] = (int)$m[1];
            }
        }
        return $pids;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Kill duplicate instances of the same daemon slot, keeping the preferred PID.
     *
     * @param array<int, array<string, mixed>> $desired
     * @param array<int, array<string, mixed>> $state
     */
    protected function deduplicateRunningProcesses(array $desired, array $state, OutputInterface $output): void
    {
        $stateById = [];
        foreach ($state as $item) {
            $stateById[(string)($item['id'] ?? '')] = $item;
        }

        foreach ($desired as $desiredProcess) {
            $id     = (string)($desiredProcess['id'] ?? '');
            $tokens = (array)($desiredProcess['tokens'] ?? []);

            $workerId = '';
            for ($i = 0; $i < count($tokens) - 1; $i++) {
                if ($tokens[$i] === '--worker-id') {
                    $workerId = (string)($tokens[$i + 1] ?? '');
                    break;
                }
            }

            if ($workerId === '') {
                continue;
            }

            $running = $this->findRunningPidsByWorkerSignature($workerId);
            if (count($running) <= 1) {
                continue;
            }

            $statePid = (int)(($stateById[$id] ?? [])['pid'] ?? 0);
            $keepPid  = ($statePid > 0 && in_array($statePid, $running, true))
                ? $statePid
                : max($running);

            foreach ($running as $runningPid) {
                if ($runningPid === $keepPid) {
                    continue;
                }
                if (function_exists('posix_kill')) {
                    @posix_kill($runningPid, defined('SIGTERM') ? \SIGTERM : 15);
                }
                $output->writeln(
                    '<comment>[dedup]</comment> killed duplicate '
                    . $id . ' pid=' . $runningPid
                    . ' (keeping pid=' . $keepPid . ')'
                );
            }
        }
    }

    // ── Healthy-process announcement dedup ───────────────────────────────────

    protected function shouldAnnounceHealthyProcess(string $id, int $pid): bool
    {
        if ($this->verboseHealthLogs) {
            return true;
        }
        if ($pid <= 0) {
            return false;
        }
        if (($this->announcedHealthyPids[$id] ?? 0) === $pid) {
            return false;
        }
        $this->announcedHealthyPids[$id] = $pid;
        return true;
    }

    // ── Stop-file mechanism ───────────────────────────────────────────────────

    /**
     * Write a .stop sentinel file to request a graceful worker shutdown.
     */
    protected function requestStop(string $lockFile): void
    {
        file_put_contents($lockFile . '.stop', '1');
    }

    /**
     * Request graceful stop for every currently tracked process.
     */
    protected function requestStopAll(OutputInterface $output): void
    {
        foreach ($this->loadState() as $item) {
            $lockFile = (string)($item['lockFile'] ?? '');
            if ($lockFile !== '') {
                $this->requestStop($lockFile);
                $output->writeln('<comment>[stop-all]</comment> stop requested for ' . ($item['id'] ?? '?'));
            }
        }
    }

    /**
     * Remove the .stop sentinel when respawning a process.
     */
    protected function clearStopFile(string $lockFile): void
    {
        $stopFile = $lockFile . '.stop';
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }
    }

    // ── State persistence ─────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadState(): array
    {
        $file = $this->getStateFile();
        if (!file_exists($file)) {
            return [];
        }
        $json = @file_get_contents($file);
        if ($json === false || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    protected function saveState(array $state): void
    {
        file_put_contents($this->getStateFile(), json_encode($state, JSON_PRETTY_PRINT));
    }

    // ── Singleton orchestrator lock ───────────────────────────────────────────

    protected function tryAcquireOrchestratorLock(OutputInterface $output): bool
    {
        $lockFile = $this->getOrchestratorLockFile();

        if (!file_exists($lockFile)) {
            @touch($lockFile);
        }

        $existingPid = $this->readOrchestratorPidFromLock($lockFile);
        if ($existingPid > 0 && !$this->isProcessRunning($existingPid)) {
            @unlink($lockFile);
            @touch($lockFile);
        }

        $handle = @fopen($lockFile, 'r+') ?: @fopen($lockFile, 'w+');
        if (!$handle) {
            $output->writeln('<error>ERROR: Could not open orchestrator lock file: ' . $lockFile . '</error>');
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $oldPid     = $this->readOrchestratorPidFromLock($lockFile);
            $pidDisplay = $oldPid > 0 ? (string)$oldPid : '(unknown)';
            $killCmd    = $oldPid > 0 ? 'kill ' . $oldPid : 'pkill -f "php.*' . $this->getOrchestratorCommandName() . '"';
            $output->writeln(
                '<error>ERROR: Another orchestrator instance is already running (PID ' . $pidDisplay . ').</error>'
                . PHP_EOL . '<info>' . $killCmd . '</info>'
            );
            return false;
        }

        ftruncate($handle, 0);
        fseek($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);

        $this->orchestratorLock = $handle;
        return true;
    }

    protected function readOrchestratorPidFromLock(string $lockFile): int
    {
        try {
            if (file_exists($lockFile)) {
                $content = @file_get_contents($lockFile);
                if ($content !== false && $content !== '') {
                    return max(0, (int)trim((string)$content));
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        return 0;
    }

    protected function readWorkerPidFromLockFile(string $lockFile): int
    {
        try {
            if (!file_exists($lockFile)) {
                return 0;
            }
            $content = @file_get_contents($lockFile);
            if ($content === false || $content === '') {
                return 0;
            }
            foreach (explode("\n", $content) as $line) {
                $line = trim((string)$line);
                if ($line !== '' && ctype_digit($line)) {
                    return (int)$line;
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        return 0;
    }

    protected function releaseOrchestratorLock(): void
    {
        if ($this->orchestratorLock) {
            @flock($this->orchestratorLock, LOCK_UN);
            @fclose($this->orchestratorLock);
            $this->orchestratorLock = null;
        }
    }

    // ── Signal handlers ───────────────────────────────────────────────────────

    protected function registerSignalHandlers(OutputInterface $output): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_signal(SIGINT, function () use ($output) {
            $output->writeln('<comment>Received SIGINT, stopping orchestrator loop.</comment>');
            $this->shouldContinue = false;
        });
        pcntl_signal(SIGTERM, function () use ($output) {
            $output->writeln('<comment>Received SIGTERM, stopping orchestrator loop.</comment>');
            $this->shouldContinue = false;
        });
        declare(ticks = 1);
    }

    // ── Filesystem helpers ────────────────────────────────────────────────────

    protected function ensureLogsDir(): void
    {
        $dir = (defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    protected function cleanupStaleLockFiles(OutputInterface $output): void
    {
        $varDir = (defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var';
        if (!is_dir($varDir)) {
            return;
        }

        $pattern = $this->getManagedLockFileGlobPattern();
        if ($pattern === '') {
            return;
        }

        try {
            $files = @glob($varDir . '/' . $pattern, GLOB_BRACE);
            if (!is_array($files)) {
                return;
            }

            $now            = time();
            $staleThreshold = static::HEARTBEAT_STALE_SECONDS + 60;
            $cleaned        = 0;

            foreach ($files as $file) {
                if (!is_file($file) || substr(basename($file), -5) === '.stop') {
                    continue;
                }
                if (($now - filemtime($file)) > $staleThreshold) {
                    @unlink($file);
                    $cleaned++;
                }
            }

            if ($cleaned > 0) {
                $output->writeln('<comment>Cleaned up ' . $cleaned . ' stale daemon lock file(s)</comment>');
            }
        } catch (\Exception $e) {
            // ignore
        }
    }

    // ── Git hash detection ────────────────────────────────────────────────────

    /**
     * Read the current git commit hash by parsing .git/HEAD without spawning
     * an external process. Returns '' when not inside a git repository.
     */
    protected function getCurrentGitHash(): string
    {
        $base     = defined('ROOT') ? ROOT : getcwd();
        $headFile = $base . '/.git/HEAD';
        if (!file_exists($headFile)) {
            return '';
        }

        $head = trim((string)file_get_contents($headFile));

        if (strlen($head) === 40 && ctype_xdigit($head)) {
            return $head;
        }

        if (str_starts_with($head, 'ref: ')) {
            $ref     = substr($head, 5);
            $refFile = $base . '/.git/' . $ref;
            if (file_exists($refFile)) {
                $sha = trim((string)file_get_contents($refFile));
                if (strlen($sha) === 40 && ctype_xdigit($sha)) {
                    return $sha;
                }
            }
        }

        return '';
    }

    // ── Interactive dashboard ─────────────────────────────────────────────────

    /**
     * @param string[] $dedupMessages
     */
    protected function renderInteractiveDashboard(OutputInterface $output, bool $dryRun, array $dedupMessages = []): void
    {
        $this->updateSystemMetrics();

        $desired  = $this->buildDesiredProcesses();
        $state    = $this->loadState();
        $stateById = [];
        foreach ($state as $item) {
            $stateById[(string)$item['id']] = $item;
        }

        $title     = $this->getDashboardTitle();
        $borderLen = $this->terminalWidth - 2;

        $serviceRows  = $this->padDashboardRow('│ Managed Daemons:', $borderLen);
        $runningCount = 0;
        $stoppedCount = 0;
        $issueCount   = 0;

        foreach ($desired as $desiredProcess) {
            $id       = (string)($desiredProcess['id'] ?? 'unknown');
            $existing = $stateById[$id] ?? null;
            $statePid = (int)($existing['pid'] ?? 0);
            $lockFile = (string)($desiredProcess['lockFile'] ?? '');
            $hasLock  = $lockFile !== '' && file_exists($lockFile) && !file_exists($lockFile . '.stop');
            $lockPid  = $hasLock ? $this->readWorkerPidFromLockFile($lockFile) : 0;
            $pid      = $lockPid > 0 ? $lockPid : $statePid;

            if ($hasLock && $lockPid > 0 && $this->isProcessRunning($lockPid)) {
                $status = 'running';
                $pid    = $lockPid;
            } elseif ($hasLock && $lockPid > 0) {
                $status = 'stale-lock';
            } elseif ($hasLock) {
                $status = 'lock-no-pid';
            } elseif ($this->isProcessRunning($pid)) {
                $status = 'running';
            } else {
                $status = 'stopped';
            }

            match ($status) {
                'running' => $runningCount++,
                'stopped' => $stoppedCount++,
                default   => $issueCount++,
            };

            $lastLog     = $this->readLastLogLine($desiredProcess);
            $serviceLine = '│ Service: ' . $this->truncateText($id, 24)
                . ' │ Status: ' . $status
                . ' │ PID: ' . ($pid > 0 ? (string)$pid : '-');
            $serviceRows .= $this->padDashboardRow($serviceLine, $borderLen);

            if (!empty($desiredProcess['profile'])) {
                $serviceRows .= $this->padDashboardLine(
                    'Profile: ' . $this->truncateText((string)$desiredProcess['profile'], max(10, $borderLen - 12)),
                    $borderLen
                );
            }
            $serviceRows .= $this->padDashboardLine(
                'Last Log: ' . $this->truncateText($lastLog, max(10, $borderLen - 12)),
                $borderLen
            );
        }

        if (count($desired) === 0) {
            $serviceRows .= $this->padDashboardRow('│ No daemon definitions are enabled', $borderLen);
        }

        $commandInfo = $this->buildCommandStateSection($borderLen, 'daemon', 'supervising', [
            'Dry Run: ' . ($dryRun ? 'Yes' : 'No'),
            'Interval: ' . $this->reconcileInterval . 's',
            'Managed Daemons: ' . count($desired),
            'Running: ' . $runningCount,
            'Stopped: ' . $stoppedCount,
            'Issues: ' . $issueCount,
        ]);

        $dedupSection = '';
        if (count($dedupMessages) > 0) {
            $dedupSection .= $this->padDashboardRow('│ Dedup Scan:', $borderLen);
            foreach ($dedupMessages as $msg) {
                $dedupSection .= $this->padDashboardLine(
                    $this->truncateText(ltrim(strip_tags($msg), ' '), max(10, $borderLen - 4)),
                    $borderLen
                );
            }
        } else {
            $dedupSection .= $this->padDashboardRow(
                '│ Dedup Scan: ' . date('H:i:s') . ' — no duplicates found',
                $borderLen
            );
        }

        $helpSection = $this->buildDashboardHelpSection($borderLen);

        $this->renderDashboardFrameAutoSystem(
            $output,
            $title,
            [$commandInfo, $serviceRows, $dedupSection, $helpSection],
            $this->terminalWidth
        );
    }

    /**
     * Read the most recent non-empty line from a daemon's log file.
     *
     * @param array<string, mixed> $desiredProcess
     */
    protected function readLastLogLine(array $desiredProcess): string
    {
        $logFile = $this->getProcessLogFile($desiredProcess);
        if (!file_exists($logFile)) {
            return '(no log yet)';
        }

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || count($lines) === 0) {
            return '(log empty)';
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string)$lines[$i]);
            if ($line !== '') {
                return $line;
            }
        }

        return '(log empty)';
    }
}
