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
 * The framework's own schedule worker (`work`) is supervised alongside them
 * without the application asking — see {@see collectDesiredProcesses()} — because
 * the framework schedules periodic work that otherwise nothing runs. Override
 * {@see includeScheduler()} to `false` if a crontab already does it.
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

    // ── The schedule ──────────────────────────────────────────────────────────

    /**
     * Every process the orchestrator should be supervising.
     *
     * The application's list, plus the framework's schedule worker. Everything
     * inside this class reads *this*, not {@see buildDesiredProcesses()} — an
     * application answers for its own daemons and should not have to remember
     * the framework's.
     *
     * It had to remember, and did not. The framework declares periodic work of
     * its own in {@see \Pramnos\Scheduling\FrameworkSchedule} — `spool:drain`
     * every minute, `timescale:drain` hourly, `queue:cleanup` daily — and a
     * schedule only happens when something runs it. An installation supervising
     * three application daemons and no scheduler ran none of it: rows written
     * through the WriteSpool stayed in a file, and every report reading the
     * drained table showed "no data" for ever. That is a stack with a daemon
     * orchestrator, no cron, and a framework quietly waiting for a cron.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectDesiredProcesses(): array
    {
        $desired = $this->buildDesiredProcesses();

        if (!$this->includeScheduler()) {
            return $desired;
        }

        // A console that does not have `work` cannot run it, and a supervised
        // entry that cannot start reports [failed-start] on every cycle for ever.
        // The framework's own console registers it, so this only excludes an
        // application that built its console by hand and left it out — for which
        // doing nothing is the right answer, not a permanent error in its
        // dashboard. A null console means this is not attached to one at all
        // (a test, or a subclass used directly), and the framework's answer
        // applies.
        $console = $this->getApplication();
        if ($console !== null && !$console->has('work')) {
            return $desired;
        }

        // An application that already supervises `work` — under any id — keeps
        // its own entry. Two schedulers would not corrupt anything (the tasks
        // take their own overlap locks and `work` holds a single-instance lock
        // of its own), but the second would sit there failing to start and
        // reporting itself as unhealthy for ever.
        foreach ($desired as $process) {
            if ($this->isSchedulerProcess($process)) {
                return $desired;
            }
        }

        $desired[] = $this->schedulerProcess();

        return $desired;
    }

    /**
     * Whether the orchestrator supervises the framework's schedule worker.
     *
     * Override to `false` when the schedule is run some other way — a crontab
     * line calling `schedule:run`, or a systemd timer. Running both is safe but
     * pointless.
     */
    protected function includeScheduler(): bool
    {
        return true;
    }

    /**
     * The supervised entry for `work`, the framework's schedule worker.
     *
     * `work` wakes on an interval, runs whatever is due, and sleeps — the same
     * schedule a crontab line for `schedule:run` would run, in a process a
     * supervisor can see. It takes the lock at `var/pramnos-work.lock` through
     * the same protocol every other managed daemon uses, so the orchestrator's
     * health checks apply to it unchanged.
     *
     * @return array<string, mixed>
     */
    protected function schedulerProcess(): array
    {
        $base = defined('ROOT') ? \ROOT : sys_get_temp_dir();

        return [
            'id'       => 'schedule',
            'daemon'   => 'schedule',
            'workerId' => 'schedule-1',
            'lockFile' => $base . '/var/pramnos-work.lock',
            'tokens'   => ['work'],
            'profile'  => 'framework + application schedule',
        ];
    }

    /**
     * Whether a desired-process entry is already the schedule worker.
     *
     * Recognised by what it runs rather than by its id, because an application
     * that added one before this existed chose its own name for it.
     *
     * @param  array<string, mixed> $process One desired-process entry
     * @return bool
     */
    protected function isSchedulerProcess(array $process): bool
    {
        if ((string)($process['id'] ?? '') === 'schedule') {
            return true;
        }

        $tokens = (array)($process['tokens'] ?? []);
        if (in_array('work', $tokens, true)) {
            return true;
        }

        $shell = (string)($process['shellCommand'] ?? '');

        return $shell !== '' && preg_match('/\bwork\b/', $shell) === 1;
    }

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

        // Runtime area for orchestrator/worker lock + heartbeat + log files.
        // Auto-create it (and the logs subdir) so a fresh deployment/checkout
        // does not need a manual mkdir — var/ is a runtime dir, typically
        // gitignored, so it will not exist on first run. Only fail if it truly
        // cannot be created or written (a real permissions problem).
        $varDir = (defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var';
        if (!is_dir($varDir)) {
            @mkdir($varDir . '/logs', 0775, true);
        }
        if (!is_dir($varDir) || !is_writable($varDir)) {
            $output->writeln('<error>ERROR: var/ directory is missing and could not be created (or is not writable): ' . $varDir . '</error>');
            $output->writeln('<comment>Create it writable by the service user, e.g. mkdir -p ' . $varDir . ' && chown &lt;user&gt; ' . $varDir . '</comment>');
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
                    // Also reload THIS process's code, so newly-declared workers in
                    // buildDesiredProcesses() take effect on a redeploy without a
                    // manual service restart. Returns only if it could not self-exec
                    // (then we keep the old behaviour: workers restarted, orchestrator
                    // code unchanged until the service is restarted).
                    $this->reExecOrchestrator($output);
                }
                if ($currentHash !== '') {
                    $lastGitHash = $currentHash;
                }
                $lastGitCheck = time();
            }

            /*
             * **Reap before reconciling**, so a worker that has just exited is gone from the
             * process table by the time the loop asks whether it is running. Reconciling first
             * would read the same tick's fresh corpse.
             */
            $this->reapExitedChildren();

            $reconcileOutput = $interactive ? new NullOutput() : $output;
            $this->reconcile($phpBinary, $dryRun, $reconcileOutput);

            $dedupMessages = [];
            if (!$dryRun) {
                $runDedup = $interactive || ($cycleCount % static::DEDUP_SCAN_INTERVAL === 0);
                if ($runDedup) {
                    $dedupOut = new \Symfony\Component\Console\Output\BufferedOutput();
                    $this->deduplicateRunningProcesses($this->collectDesiredProcesses(), $this->loadState(), $dedupOut);
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
        $desired    = $this->collectDesiredProcesses();
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

            // A `.stop` sentinel is this orchestrator's own instruction to that
            // process, and it is true whether or not the process keeps a lock.
            // It used to be read as part of the lock check, so a daemon declared
            // with `requireLockFile => false` was reported healthy while a stop
            // it was ignoring sat on disk beside it — which is how a worker
            // survived three deploys and was logged as `[ok]` throughout.
            $stopRequested   = $lockFile !== '' && file_exists($lockFile . '.stop');
            $hasHealthyLock  = !$stopRequested
                && (!$requiresLock || ($lockFile !== '' && file_exists($lockFile)));

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
                        // "(pid alive)", because that is all that was checked.
                        // This branch is reached when no lock pid was read — a
                        // daemon declared with `requireLockFile => false`, or one
                        // whose lock could not be parsed — and it used to say
                        // "(lock active)" about a file it had not looked at, and
                        // in one reported case about a file that did not exist.
                        // A wedged daemon satisfies "pid alive"; that is the
                        // weaker claim, and the words should say so.
                        $output->writeln('<info>[ok]</info> ' . $id . ' pid=' . $pid . ' (pid alive)');
                    }
                    continue;
                }
            }

            // Old instance still alive but a stop file was written.
            if ($pidAlive && !$hasHealthyLock) {
                unset($this->announcedHealthyPids[$id]);

                // A stop request needs a deadline, and this path had none.
                //
                // The teardown path below records `stoppingAt` and escalates to
                // SIGTERM after the grace period. A stop asked for *here* — by a
                // redeploy, or by the orchestrator being disabled — recorded
                // nothing, so the grace period ten lines away never started and a
                // daemon that does not poll its sentinel was never stopped, never
                // signalled, and never reported as anything but healthy. Measured
                // downstream: one worker, 1h32m, three deploys, `.stop` on disk
                // throughout.
                //
                // The deadline is also started here rather than only in
                // `requestStopAll()`, so a sentinel that arrived another way — an
                // operator's `touch`, a state file written before this existed —
                // gets one too.
                $stoppingAt = $existing['stoppingAt'] ?? null;

                if ($stoppingAt === null) {
                    $stateById[$id]['stoppingAt'] = date('c');
                    $output->writeln(
                        '<comment>[waiting]</comment> ' . $id . ' pid=' . $pid
                        . ' — gracefully stopping, will restart when done'
                    );
                    continue;
                }

                $waited = time() - (int)strtotime((string)$stoppingAt);

                if ($waited >= static::STOP_GRACE_SECONDS) {
                    if (!$dryRun && function_exists('posix_kill')) {
                        @posix_kill($pid, defined('SIGTERM') ? \SIGTERM : 15);
                    }
                    // Reported as its own thing: "this worker had to be signalled"
                    // is a fact about the worker, not noise about the deploy.
                    $output->writeln(
                        '<error>[stop-timeout]</error> ' . $id . ' pid=' . $pid
                        . ' — ignored the stop sentinel for ' . $waited . 's, sent SIGTERM'
                    );
                    if (!$dryRun) {
                        $this->clearStopFile($lockFile);
                    }
                    unset($stateById[$id]);
                    continue;
                }

                $output->writeln(
                    '<comment>[waiting]</comment> ' . $id . ' pid=' . $pid
                    . ' — gracefully stopping, will restart when done ('
                    . (static::STOP_GRACE_SECONDS - $waited) . 's before SIGTERM)'
                );
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

        $pid = (int) trim((string) shell_exec(
            $this->buildSpawnShellCommand($phpBinary, $desiredProcess)
        ));

        return max(0, $pid);
    }

    /**
     * The shell command used to spawn a worker.
     *
     * Separate from {@see startDesiredProcess()} because that one runs it: this is the
     * only place the exported environment can be asserted without starting a process.
     *
     * @param array<string, mixed> $desiredProcess
     */
    protected function buildSpawnShellCommand(string $phpBinary, array $desiredProcess): string
    {
        $logFile = $this->getProcessLogFile($desiredProcess);

        if (isset($desiredProcess['shellCommand'])) {
            $command = trim((string)$desiredProcess['shellCommand']);
        } else {
            $tokens  = (array)($desiredProcess['tokens'] ?? []);
            $command = escapeshellarg($phpBinary)
                . ' ' . escapeshellarg($this->getEntryPoint())
                . ' ' . $this->buildShellTokens($tokens);
        }

        // Hand the resolved lock path down rather than letting the child compute its
        // own. Both ends used to resolve it independently — the orchestrator from the
        // declared `lockFile`, the worker from CommandBase::getJobLockFilePath() — and
        // a disagreement was undetectable: a sentinel read where nothing writes is
        // indistinguishable from no sentinel, so the worker reported itself healthy
        // and ignored every stop request. Exporting it makes an override that
        // disagrees impossible instead of imperceptible.
        $lockFile = (string) ($desiredProcess['lockFile'] ?? '');
        $exports  = $lockFile === ''
            ? ''
            : \Pramnos\Console\CommandBase::LOCK_FILE_ENV . '=' . escapeshellarg($lockFile) . ' ';

        return 'nohup setsid ' . $exports . $command
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
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
     *
     * **A zombie is not a running daemon.** `posix_kill($pid, 0)` answers *"may I signal
     * this"*, and a process that has exited but not been reaped still accepts that — the PID
     * stays in the table until somebody waits on it. So a supervisor asking this question
     * about its own dead child is told the child is fine, and never respawns it.
     *
     * That is not a theoretical state, it is the normal one inside a container. Workers are
     * started with `nohup setsid … &`, so the intermediate shell exits and the worker is
     * orphaned — and an orphan is reparented to **PID 1**, which in a container is the
     * orchestrator itself. It therefore inherits every daemon it starts, reaps none of them
     * (there is no wait loop, and `pcntl` is frequently not built into the image), and each
     * graceful stop leaves behind a `<defunct>` entry it reads as a healthy worker.
     *
     * Observed in a project's development stack on 2026-08-18: three of four daemons had
     * been zombies for fourteen hours after a redeploy asked them to stop. `ps` showed
     * `[php] <defunct>`, the orchestrator's own log showed nothing wrong, and the features
     * behind those workers — now-playing, airplay statistics, feed-health tiering — were
     * simply empty. **A supervisor that cannot tell a corpse from a worker is worse than no
     * supervisor**, because it reports success.
     *
     * `/proc/<pid>/stat` carries the state as its third field, `Z` for a zombie, and it is
     * read first for exactly that reason. Where there is no `/proc` (BSD, macOS) the old
     * `posix_kill()` answer stands — the previous behaviour, unchanged.
     */
    protected function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $state = $this->processState($pid);

        if ($state !== null) {
            return $state !== 'Z' && $state !== 'X';
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return file_exists('/proc/' . $pid);
    }

    /**
     * The single-letter process state from `/proc`, or null where it cannot be read.
     *
     * Null means *"this platform cannot answer"* and is deliberately distinct from `'Z'`: the
     * caller falls back to the older, weaker check rather than deciding the process is gone.
     */
    protected function processState(int $pid): ?string
    {
        $raw = @file_get_contents('/proc/' . $pid . '/stat');

        if ($raw === false || $raw === '') {
            return null;
        }

        /*
         * The second field is the executable name **in parentheses and unescaped**, so it may
         * contain spaces and parentheses of its own — `[php] <defunct>` among them. Splitting
         * on whitespace is what makes a zombie parse as a running process, so the state is
         * taken from after the *last* `)`.
         */
        $close = strrpos($raw, ')');

        if ($close === false) {
            return null;
        }

        $rest = trim(substr($raw, $close + 1));

        return $rest === '' ? null : substr($rest, 0, 1);
    }

    /**
     * Reap any child that has already exited, so its PID leaves the table.
     *
     * Called from the supervisor loop. Without this a containerised orchestrator — PID 1, and
     * therefore the parent of every orphaned worker — accumulates one zombie per restart for
     * the life of the service. `isProcessRunning()` no longer *believes* them, so this is
     * hygiene rather than the fix; where `pcntl` is not built in there is nothing portable to
     * call and the zombies are harmless but visible in `ps`.
     */
    protected function reapExitedChildren(): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }

        $guard = 0;

        while ($guard++ < 64) {
            $status = 0;
            $pid    = @pcntl_waitpid(-1, $status, defined('WNOHANG') ? \WNOHANG : 1);

            if ($pid <= 0) {
                return;
            }
        }
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
        $state   = $this->loadState();
        $changed = false;

        foreach ($state as $index => $item) {
            $lockFile = (string)($item['lockFile'] ?? '');
            if ($lockFile === '') {
                continue;
            }

            $this->requestStop($lockFile);

            // When the stop was asked for, written where it survives this
            // process. A redeploy re-execs the orchestrator immediately
            // afterwards, and the new image knows nothing except what is in the
            // state file — so without this the grace period could never start,
            // and a daemon that ignores its sentinel was supervised for ever as
            // healthy. See the `[stop-timeout]` branch in reconcile().
            if (!isset($state[$index]['stoppingAt'])) {
                $state[$index]['stoppingAt'] = date('c');
                $changed = true;
            }

            $output->writeln('<comment>[stop-all]</comment> stop requested for ' . ($item['id'] ?? '?'));
        }

        if ($changed) {
            $this->saveState(array_values($state));
        }
    }

    /**
     * Replace this orchestrator process with a fresh one (same PID) so a git
     * redeploy loads the new code — most importantly any newly-declared workers
     * in buildDesiredProcesses(). Without this, a redeploy only bounces the
     * workers, which respawn from the OLD desired-process list, so a brand-new
     * worker never appears until the service is restarted by hand.
     *
     * requestStopAll() has already been issued, so the re-exec'd instance
     * respawns the workers with the new code. The singleton lock fd is inherited
     * across exec and would deadlock the new image, so it is released first.
     *
     * BC-safe: where pcntl_exec is unavailable (or exec fails) this returns and
     * the caller keeps the previous behaviour (workers restarted; orchestrator
     * code reloaded only on the next manual service restart).
     */
    protected function reExecOrchestrator(OutputInterface $output): void
    {
        $argv = $_SERVER['argv'] ?? [];
        // Never replace the process while running under PHPUnit — a re-exec would
        // hijack the test runner into an endless loop.
        if (class_exists(\PHPUnit\Framework\TestCase::class, false)
            || !function_exists('pcntl_exec')
            || !is_array($argv)
            || $argv === []
        ) {
            $output->writeln(
                '<comment>[git]</comment> cannot self-exec (unavailable in this environment) — '
                . 'restart the service to load new orchestrator code.'
            );
            return;
        }

        $output->writeln('<info>[git]</info> re-executing the orchestrator to load the new code…');
        $this->releaseOrchestratorLock();

        // Replaces the process image in place (same PID → systemd keeps tracking).
        @pcntl_exec(PHP_BINARY, array_values($argv));

        // Only reached if exec failed; report and continue (the manual restart
        // fallback still applies).
        $output->writeln('<error>[git]</error> re-exec failed — restart the service to load new code.');
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

    /**
     * Read-only health snapshot of this orchestrator, for status dashboards.
     *
     * Returns the orchestrator's own liveness plus its managed daemons WITHOUT
     * running a reconcile cycle — so an admin/API endpoint can report "is the
     * supervisor up" by constructing the orchestrator and calling status():
     *
     *   $status = (new MyDaemons())->status();
     *   // ['running' => bool, 'pid' => ?int, 'heartbeat_age_seconds' => ?int,
     *   //  'daemons' => [ ['id'=>.., 'pid'=>?int, 'running'=>bool, ...], ... ]]
     *
     * `running`/`pid` come from the singleton lock file; `heartbeat_age_seconds`
     * is the age of the state file, which the reconcile loop rewrites every cycle
     * (so a fresh mtime means the orchestrator is actively cycling); `daemons`
     * is the last-persisted managed-process state, each enriched with live
     * process status.
     *
     * @return array{running:bool,pid:?int,heartbeat_age_seconds:?int,daemons:array<int,array<string,mixed>>}
     */
    public function status(): array
    {
        $lockFile = $this->getOrchestratorLockFile();
        $pid      = file_exists($lockFile) ? $this->readOrchestratorPidFromLock($lockFile) : 0;
        $running  = $pid > 0 && $this->isProcessRunning($pid);

        $stateFile    = $this->getStateFile();
        $heartbeatAge = is_file($stateFile)
            ? max(0, time() - (int) @filemtime($stateFile))
            : null;

        $daemons = [];
        foreach ($this->loadState() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $daemonPid = (int) ($entry['pid'] ?? 0);
            $daemons[] = [
                'id'      => $entry['id'] ?? ($entry['daemon'] ?? null),
                'pid'     => $daemonPid > 0 ? $daemonPid : null,
                'running' => $this->daemonLooksAlive($daemonPid, (string) ($entry['lockFile'] ?? '')),
            ] + $entry;
        }

        return [
            'running'               => $running,
            'pid'                   => $running ? $pid : null,
            'heartbeat_age_seconds' => $heartbeatAge,
            'daemons'               => $daemons,
        ];
    }

    /**
     * Whether a managed daemon is alive — by its heartbeat first, its pid second.
     *
     * **A pid answers a question about *this* process table.** `status()` is read by whatever
     * asks, and what asks is frequently not the process that started the daemons: a web request
     * on the same host, an admin panel, or — in a containerised development stack — a *different
     * container*, where pid 20 is either nothing or somebody else entirely.
     *
     * Reported from a project on 2026-08-18: the panel's Workers screen showed all four daemons
     * **down** while all four were running, and `/api/realtime-config` therefore advertised SSE
     * with a healthy WebSocket worker listening two containers away. The pid check was not wrong
     * about pids; it was answering a different question from the one being asked.
     *
     * The lock file is the shared evidence. Every managed worker touches it on each heartbeat and
     * it lives on the volume both sides can read, so *"touched within the stale window"* is a fact
     * about the daemon rather than about the reader's namespace. It is also the check that survives
     * the failure this class learned the same day: an unreaped zombie satisfies `posix_kill` and
     * touches nothing.
     *
     * The pid is still consulted, and still first when it can answer — on a single-host install
     * with no lock file (a daemon that declares none), it is all there is.
     */
    protected function daemonLooksAlive(int $pid, string $lockFile): bool
    {
        if ($pid > 0 && $this->isProcessRunning($pid)) {
            return true;
        }

        if ($lockFile === '' || !is_file($lockFile) || is_file($lockFile . '.stop')) {
            return false;
        }

        $age = time() - (int) @filemtime($lockFile);

        return $age >= 0 && $age <= static::HEARTBEAT_STALE_SECONDS;
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
        // Workers using CommandBase now write a JSON lock (via WorkerLock); read
        // the `pid` field, falling back to a legacy plain-text "<pid>\n..." lock.
        return WorkerLock::pidFromFile($lockFile);
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

        $desired  = $this->collectDesiredProcesses();
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
