<?php

declare(strict_types=1);

namespace Pramnos\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared helpers for lock-based and interactive console commands.
 *
 * Provides: lock-file job guards, terminal control (cursor, clear, size),
 * bordered dashboard rendering, block-character progress bar, and text
 * utilities (formatBytes, formatTime, visibleLength, word-wrap).
 *
 * Usage: extend this class and implement getJobName().
 *
 */
abstract class CommandBase extends Command
{
    /**
     * Output used by signal handlers (stored during beginJob).
     */
    protected ?OutputInterface $signalOutput = null;

    /**
     * Return a unique name identifying this command's lock file.
     *
     * The value becomes the filename under var/<name>.
     */
    abstract protected function getJobName(): string;

    /**
     * Called after terminal size is detected. Override to store dimensions.
     */
    protected function updateTerminalSize(): void
    {
    }

    // ── Time helpers ──────────────────────────────────────────────────────────

    protected function currentTimestamp(): int
    {
        return time();
    }

    protected function now(): int
    {
        return $this->currentTimestamp();
    }

    protected function nowFloat(): float
    {
        return microtime(true);
    }

    // ── OS / extension probes ─────────────────────────────────────────────────

    protected function supportsSysGetLoadAvg(): bool
    {
        return function_exists('sys_getloadavg');
    }

    protected function getLoadAvg(): array
    {
        return sys_getloadavg();
    }

    protected function supportsPosixKill(): bool
    {
        return function_exists('posix_kill');
    }

    protected function canSignalProcess(int $pid): bool
    {
        return @posix_kill($pid, 0);
    }

    protected function hasProcDirectory(int $pid): bool
    {
        return is_dir('/proc/' . $pid);
    }

    protected function executeShell(string $command): string
    {
        return (string)@shell_exec($command);
    }

    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function supportsMbStrSplit(): bool
    {
        return function_exists('mb_str_split');
    }

    protected function mbStrSplit(string $text): array
    {
        return mb_str_split($text);
    }

    protected function supportsMbStrlen(): bool
    {
        return function_exists('mb_strlen');
    }

    protected function mbStringLength(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    protected function supportsPcntl(): bool
    {
        return function_exists('pcntl_signal');
    }

    protected function supportsShellExec(): bool
    {
        return function_exists('shell_exec');
    }

    protected function supportsPosixGetParentPid(): bool
    {
        return function_exists('posix_getppid');
    }

    // ── Lock file management ──────────────────────────────────────────────────

    /**
     * Returns the filesystem path for this command's lock file.
     * Defaults to var/<jobName> relative to ROOT (or sys_get_temp_dir()).
     */
    protected function getJobLockFilePath(): string
    {
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        return $base . '/var/' . $this->getJobName();
    }

    /**
     * Lock files older than this many seconds are treated as stale.
     */
    protected function getLockStaleSeconds(): int
    {
        return 3600 * 2;
    }

    protected function checkIfRunning(): bool
    {
        $file = $this->getJobLockFilePath();
        if (!file_exists($file)) {
            return false;
        }

        $age = $this->currentTimestamp() - filemtime($file);

        if ($age > $this->getLockStaleSeconds()) {
            @unlink($file);
            return false;
        }

        $oldPid = $this->readPidFromLockFile($file);
        if ($oldPid > 0) {
            if (!$this->isProcessStillRunning($oldPid)) {
                @unlink($file);
                return false;
            }
            return true;
        }

        if ($age < 300) {
            return true;
        }

        @unlink($file);
        return false;
    }

    protected function readPidFromLockFile(string $file): int
    {
        try {
            $content = $this->readFileContents($file);
            if (!$content) {
                return 0;
            }
            foreach (explode("\n", $content) as $line) {
                $line = trim($line);
                if (is_numeric($line)) {
                    return (int)$line;
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        return 0;
    }

    /**
     * @return string|false
     */
    protected function readFileContents(string $file)
    {
        return @file_get_contents($file);
    }

    protected function isProcessStillRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if ($this->supportsPosixKill()) {
            return $this->canSignalProcess($pid);
        }
        if ($this->hasProcDirectory($pid)) {
            return true;
        }
        $out = $this->executeShell("ps -p $pid 2>/dev/null | grep -c '^[^P]'");
        return !empty($out) && ((int)$out > 0);
    }

    protected function startJob(): void
    {
        $file = $this->getJobLockFilePath();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $fh = fopen($file, 'w+');
        fwrite($fh, getmypid() . "\n");
        fwrite($fh, 'Command started at: ' . date('d/m/Y H:i') . '.');
        fclose($fh);
    }

    /**
     * Touch the lock file so the orchestrator knows this worker is alive.
     */
    protected function heartbeat(): void
    {
        $file = $this->getJobLockFilePath();
        if (file_exists($file)) {
            touch($file);
        }
    }

    /**
     * Guard startup: prints an error and returns false if already running;
     * creates the lock file and returns true if free.
     */
    protected function beginJob(OutputInterface $output, bool $registerShutdown = true): bool
    {
        if ($this->checkIfRunning()) {
            $file = $this->getJobLockFilePath();
            $time = date('d/m/Y H:i:s', (int)filemtime($file));
            $output->writeln('<error>Command is already running. Started at: ' . $time . '</error>');
            return false;
        }

        $this->configureInterruptHandling($output);
        $this->startJob();

        if ($registerShutdown) {
            $this->addShutdownHandler([$this, 'endJob'], $output);
        }

        return true;
    }

    public function endJob(): void
    {
        $file = $this->getJobLockFilePath();
        $this->removeLockFile($file);

        if ($this->shouldRetryEndJob($file)) {
            $this->sleepSeconds(2);
            $this->endJob();
        }
    }

    protected function removeLockFile(string $file): void
    {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    protected function shouldRetryEndJob(string $file): bool
    {
        return false;
    }

    protected function sleepSeconds(int $seconds): void
    {
        sleep($seconds);
    }

    // ── Terminal control ──────────────────────────────────────────────────────

    /**
     * Detect terminal dimensions as [height, width].
     *
     * @return array{int, int}
     */
    protected function detectTerminalSize(): array
    {
        $width  = 80;
        $height = 24;

        if ($this->isWindows()) {
            // @codeCoverageIgnoreStart
            $out = [];
            exec('mode con', $out);
            foreach ($out as $line) {
                if (preg_match('/Columns:\s+(\d+)/', $line, $m)) {
                    $width = (int)$m[1];
                }
                if (preg_match('/Lines:\s+(\d+)/', $line, $m)) {
                    $height = (int)$m[1];
                }
            }
            // @codeCoverageIgnoreEnd
            return [$height, $width];
        }

        $size = exec('stty size 2>/dev/null');
        if (!empty($size)) {
            // @codeCoverageIgnoreStart
            $parts = explode(' ', $size);
            if (count($parts) === 2) {
                $height = (int)$parts[0];
                $width  = (int)$parts[1];
            }
            // @codeCoverageIgnoreEnd
        }

        return [$height, $width];
    }

    protected function clearScreen(OutputInterface $output): void
    {
        $output->write("\033c");
        $output->write("\033[2J");
        $output->write("\033[H");
    }

    protected function hideCursor(OutputInterface $output): void
    {
        $output->write("\033[?25l");
    }

    protected function showCursor(OutputInterface $output): void
    {
        $output->write("\033[?25h\033[?0c");
    }

    // ── Signal / shutdown handling ────────────────────────────────────────────

    protected function configureInterruptHandling(
        OutputInterface $output,
        string $manualHandlerMethod = 'handleInterruptSignal'
    ): void {
        $isOrchestrated = $this->isRunningUnderOrchestrator();
        $handlerMethod  = method_exists($this, $manualHandlerMethod)
            ? $manualHandlerMethod
            : 'handleInterruptSignal';

        if ($this->supportsPcntl()) {
            $this->signalOutput = $output;
            if ($isOrchestrated) {
                pcntl_signal(SIGINT, SIG_IGN);
            } else {
                pcntl_signal(SIGINT, [$this, $handlerMethod]);
            }
            declare(ticks = 1);
            return;
        }

        $output->writeln('<comment>PCNTL extension not available. Clean shutdown with Ctrl+C may not work properly.</comment>');
    }

    protected function initializeInteractiveTerminal(
        OutputInterface $output,
        bool $registerShutdown = true
    ): void {
        $this->updateTerminalSize();
        $this->clearScreen($output);
        $this->hideCursor($output);

        if ($registerShutdown) {
            $this->registerShutdown($output);
        }
    }

    protected function registerShutdown(OutputInterface $output): void
    {
        $this->addShutdownHandler([$this, 'handleShutdown'], $output);
    }

    protected function addShutdownHandler(callable $callback, OutputInterface $output): void
    {
        register_shutdown_function($callback, $output);
    }

    public function handleShutdown(OutputInterface $output): void
    {
        $this->showCursor($output);
        $this->endJob();
    }

    public function handleInterruptSignal(int $signal = 0): void
    {
        if ($this->signalOutput) {
            $this->signalOutput->writeln("\n<info>Caught shutdown signal. Cleaning up...</info>");
        }
        $this->endJob();
        $this->terminateCommand(130);
    }

    protected function terminateCommand(int $exitCode): void
    {
        $this->exitProcess($exitCode);
    }

    protected function exitProcess(int $exitCode): void
    {
        if ($this->shouldInterceptExit($exitCode)) {
            return;
        }
        // @codeCoverageIgnoreStart
        exit($exitCode);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Override to return true in test harnesses so exit() is not called.
     */
    protected function shouldInterceptExit(int $exitCode): bool
    {
        return false;
    }

    // ── Orchestrator detection ────────────────────────────────────────────────

    /**
     * Return the CLI command name used by the orchestrator process.
     *
     * Override in application CommandBase subclasses to match the actual
     * orchestrator command (e.g. 'daemons:start').
     */
    protected function getOrchestratorCommandName(): string
    {
        return 'daemons:start';
    }

    protected function isRunningUnderOrchestrator(): bool
    {
        if (!$this->supportsPosixGetParentPid()) {
            return false;
        }

        $ppid = $this->getParentProcessId();
        if (!$ppid) {
            return false;
        }

        $needle = $this->getOrchestratorCommandName();

        if ($this->hasParentCmdline($ppid)) {
            $cmdline = $this->readParentCmdline($ppid);
            return stripos((string)$cmdline, $needle) !== false;
        }

        if ($this->supportsShellExec()) {
            $ps = $this->executeShell("ps -p $ppid -o cmd=");
            return $ps && stripos($ps, $needle) !== false;
        }

        return false;
    }

    protected function getParentProcessId(): int
    {
        return (int)posix_getppid();
    }

    protected function hasParentCmdline(int $ppid): bool
    {
        return file_exists("/proc/$ppid/cmdline");
    }

    protected function readParentCmdline(int $ppid): string
    {
        return (string)file_get_contents("/proc/$ppid/cmdline");
    }

    // ── Text utilities ────────────────────────────────────────────────────────

    /**
     * Human-readable byte count (B / KB / MB / GB / TB).
     *
     * @param int|float $bytes
     */
    public function formatBytes($bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max((float)$bytes, 0);
        $pow   = $bytes > 0 ? (int)floor(log($bytes) / log(1024)) : 0;
        $pow   = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Format seconds as HH:MM:SS.
     */
    public function formatTime(int $seconds): string
    {
        $hours   = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        $secs    = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * String length in visible characters, ignoring ANSI escape codes.
     */
    public function visibleLength(string $string): int
    {
        $cleaned = preg_replace('/\033\[[0-9;]*m/', '', $string);
        if ($this->supportsMbStrlen()) {
            return $this->mbStringLength($cleaned);
        }
        if (preg_match_all('/./us', $cleaned, $matches) === 1) {
            return count($matches[0]);
        }
        return strlen($cleaned);
    }

    /**
     * Truncate text to a maximum visible width, appending '...' on overflow.
     */
    public function truncateText(string $text, int $maxLen): string
    {
        if ($maxLen <= 3 || $this->visibleLength($text) <= $maxLen) {
            return $text;
        }

        $characters = $this->supportsMbStrSplit()
            ? $this->mbStrSplit($text)
            : (preg_match_all('/./us', $text, $matches) === 1
                ? ($matches[0] ?? [])
                : str_split($text));

        $result = '';
        foreach ($characters as $char) {
            if ($this->visibleLength($result . $char . '...') > $maxLen) {
                break;
            }
            $result .= $char;
        }

        return $result . '...';
    }

    /**
     * Word-wrap text to a maximum visible width, returning an array of lines.
     *
     * ANSI codes are ignored for width calculation.
     *
     * @return string[]
     */
    public function wrapDashboardText(string $text, int $maxWidth): array
    {
        if ($this->visibleLength($text) <= $maxWidth) {
            return [$text];
        }

        $words   = preg_split('/\s+/', $text) ?: [];
        $lines   = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($this->visibleLength($candidate) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            if ($this->visibleLength($word) <= $maxWidth) {
                $current = $word;
                continue;
            }

            // Word longer than maxWidth — split character by character.
            $line = '';
            foreach ($this->splitDashboardCharacters($word) as $char) {
                if ($this->visibleLength($line . $char) > $maxWidth) {
                    $lines[] = $line;
                    $line    = $char;
                    continue;
                }
                $line .= $char;
            }
            $current = $line;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Split a UTF-8 string into an array of individual characters.
     *
     * @return string[]
     */
    protected function splitDashboardCharacters(string $text): array
    {
        if ($this->supportsMbStrSplit()) {
            return $this->mbStrSplit($text);
        }
        preg_match_all('/./us', $text, $matches);
        return $matches[0] ?? str_split($text);
    }

    // ── Progress bar ──────────────────────────────────────────────────────────

    /**
     * Build a block-character progress bar string.
     *
     * Format: " [████████..........] current of total (percent%)"
     *
     * Use with $output->write("\r" . $this->buildProgressBar(...)) so the
     * line is overwritten on each iteration.
     *
     * @param int $current  Items processed so far.
     * @param int $total    Total items (must be > 0).
     * @param int $width    Number of block characters in the bar (default 50).
     */
    public function buildProgressBar(int $current, int $total, int $width = 50): string
    {
        if ($total <= 0) {
            return ' [' . str_repeat('.', $width) . '] 0 of 0 (0%)';
        }

        $percent = (int)round($current / $total * 100);
        $filled  = (int)round($percent / 100 * $width);
        $empty   = $width - $filled;

        return ' [' . str_repeat('█', $filled) . str_repeat('.', $empty) . '] '
            . $current . ' of ' . $total . ' (' . $percent . '%)';
    }

    // ── Dashboard rendering ───────────────────────────────────────────────────

    /**
     * Build header row: ┌──<centered title>──┐
     */
    public function buildDashboardHeader(string $title, int $borderLen): string
    {
        $leftPad  = (int)floor(($borderLen - strlen($title)) / 2);
        $rightPad = $borderLen - strlen($title) - $leftPad;

        return "┌" . str_repeat("─", $borderLen) . "┐\n"
             . "│" . str_repeat(" ", max(0, $leftPad)) . $title . str_repeat(" ", max(0, $rightPad)) . "│\n";
    }

    /**
     * Build section separator: ├──────┤
     */
    public function buildDashboardSectionSeparator(int $borderLen): string
    {
        return "├" . str_repeat("─", $borderLen) . "┤\n";
    }

    /**
     * Build footer row: └──────┘
     */
    public function buildDashboardFooter(int $borderLen): string
    {
        return "└" . str_repeat("─", $borderLen) . "┘\n";
    }

    /**
     * Pad a content line to fill the dashboard width, appending │ on the right.
     *
     * Adds "│ " prefix automatically.
     */
    public function padDashboardLine(string $content, int $borderLen): string
    {
        return $this->padDashboardRow('│ ' . $content, $borderLen);
    }

    /**
     * Pad a row that already carries its left border character to fill the
     * dashboard width, appending │ on the right.
     */
    public function padDashboardRow(string $line, int $borderLen, ?int $visibleLen = null): string
    {
        $visibleLen = $visibleLen ?? $this->visibleLength($line);
        $padding    = max(0, ($borderLen + 1) - $visibleLen);
        return $line . str_repeat(' ', $padding) . "│\n";
    }

    /**
     * Build one or more dashboard rows from an array of content segments,
     * fitting as many side-by-side as the border allows (separated by " │ ").
     */
    public function buildDashboardRows(array $segments, int $borderLen): string
    {
        $rows    = [];
        $current = '';

        foreach ($segments as $segment) {
            foreach ($this->wrapDashboardText($segment, $borderLen - 1) as $wrapped) {
                $candidate = $current === '' ? $wrapped : $current . ' │ ' . $wrapped;

                if ($this->visibleLength('│ ' . $candidate) <= ($borderLen + 1)) {
                    $current = $candidate;
                    continue;
                }

                if ($current !== '') {
                    $rows[] = $this->padDashboardLine($current, $borderLen);
                }
                $current = $wrapped;
            }
        }

        if ($current !== '') {
            $rows[] = $this->padDashboardLine($current, $borderLen);
        }

        return implode('', $rows);
    }

    /**
     * Build the standard system-status segments shown in daemon dashboards.
     *
     * @return string[]
     */
    public function buildSystemStatusSegments(int $startTime, float $cpuUsage, int|float $memoryUsage): array
    {
        return [
            'Time: '   . date('Y-m-d H:i:s'),
            'Uptime: ' . $this->formatTime(max(0, $this->currentTimestamp() - $startTime)),
            'CPU: '    . sprintf('%.1f', $cpuUsage),
            'Memory: ' . $this->formatBytes($memoryUsage),
        ];
    }

    protected function getDashboardStartTime(): int
    {
        $value = $this->readNumericPropertyValue('startTime');
        return $value !== null ? (int)$value : $this->currentTimestamp();
    }

    protected function getDashboardCpuUsage(): float
    {
        $value = $this->readNumericPropertyValue('cpuUsage');
        return $value !== null ? (float)$value : 0.0;
    }

    /**
     * @return int|float
     */
    protected function getDashboardMemoryUsage()
    {
        $value = $this->readNumericPropertyValue('memoryUsage');
        return $value !== null ? (float)$value : memory_get_usage(true);
    }

    /**
     * Read a numeric property from the concrete command class via reflection.
     */
    protected function readNumericPropertyValue(string $propertyName): ?float
    {
        try {
            $ref = new \ReflectionObject($this);
            while ($ref) {
                if ($ref->hasProperty($propertyName)) {
                    $prop = $ref->getProperty($propertyName);
                    $value = $prop->getValue($this);
                    return is_numeric($value) ? (float)$value : null;
                }
                $ref = $ref->getParentClass() ?: null;
            }
        } catch (\Throwable $e) {
            // ignore reflection errors
        }
        return null;
    }

    protected function buildDefaultSystemSegments(): array
    {
        return $this->buildSystemStatusSegments(
            $this->getDashboardStartTime(),
            $this->getDashboardCpuUsage(),
            $this->getDashboardMemoryUsage()
        );
    }

    /**
     * Build a standard "Mode / State / extras" command-state section.
     *
     * @param string[] $extraSegments
     */
    public function buildCommandStateSection(
        int $borderLen,
        string $mode,
        string $state,
        array $extraSegments = []
    ): string {
        return $this->buildDashboardRows(
            array_merge(['Mode: ' . ucfirst($mode), 'State: ' . ucfirst($state)], $extraSegments),
            $borderLen
        );
    }

    /**
     * Build a one-line help/controls row.
     */
    public function buildDashboardHelpSection(
        int $borderLen,
        string $helpText = 'Controls: Press Ctrl+C to exit'
    ): string {
        return $this->padDashboardRow('│ ' . $helpText, $borderLen);
    }

    /**
     * Build the animated adventure section shown during reconnect failures.
     *
     * Displays a mini-game: runner (R) dodges obstacles (#) along a dot track.
     *
     * @param int $countdown Seconds until next retry; 0 to suppress.
     */
    public function buildDashboardAdventureSection(
        int $borderLen,
        string $title,
        string $statusText,
        int $countdown = 0
    ): string {
        $tick      = $this->now();
        $runnerX   = ($tick % 20) + 1;
        $obstacleX = (($tick * 2) % 20) + 1;
        $track     = str_repeat('.', 24);

        if ($runnerX >= 1 && $runnerX <= 24) {
            $track[$runnerX - 1] = 'R';
        }
        if ($obstacleX >= 1 && $obstacleX <= 24 && $obstacleX !== $runnerX) {
            $track[$obstacleX - 1] = '#';
        }

        $lines = [
            $this->padDashboardRow('│ ' . $title, $borderLen),
            $this->padDashboardLine('Connection hero mini-game: dodge obstacles while services reconnect.', $borderLen),
            $this->padDashboardLine('[' . $track . ']', $borderLen),
            $this->padDashboardLine('R = runner, # = outage gremlin', $borderLen),
            $this->padDashboardLine($statusText, $borderLen),
        ];

        if ($countdown > 0) {
            $lines[] = $this->padDashboardLine('Next retry in ' . $countdown . 's', $borderLen);
        }

        return implode('', $lines);
    }

    /**
     * Render a full bordered dashboard frame (cursor-home → write → erase-below).
     *
     * @param string[]   $systemSegments Segments for the top status row.
     * @param string[]   $sections       Pre-built dashboard section strings.
     */
    public function renderDashboardFrame(
        OutputInterface $output,
        string $title,
        array $systemSegments,
        array $sections,
        int $terminalWidth
    ): void {
        $output->write("\033[H");

        $borderLen = max(20, $terminalWidth - 2);
        $dashboard = $this->buildDashboardHeader($title, $borderLen);
        $dashboard .= $this->buildDashboardRows($systemSegments, $borderLen);

        foreach ($sections as $section) {
            $dashboard .= $this->buildDashboardSectionSeparator($borderLen);
            $dashboard .= $section;
        }

        $dashboard .= $this->buildDashboardFooter($borderLen);
        $output->write($dashboard);
        $output->write("\033[J");
    }

    /**
     * Render a dashboard frame, automatically adding system status segments.
     *
     * @param string[]        $sections
     * @param string[]|null   $systemSegments  Override; null = auto-detect.
     */
    public function renderDashboardFrameAutoSystem(
        OutputInterface $output,
        string $title,
        array $sections,
        int $terminalWidth,
        ?array $systemSegments = null
    ): void {
        $this->renderDashboardFrame(
            $output,
            $title,
            $systemSegments ?? $this->buildDefaultSystemSegments(),
            $sections,
            $terminalWidth
        );
    }

    /**
     * Render the game-mode dashboard displayed during reconnect/failure states.
     */
    public function renderDashboardGameMode(
        OutputInterface $output,
        string $title,
        string $failureTitle,
        string $statusText,
        int $countdown,
        int $terminalWidth
    ): void {
        $borderLen = max(20, $terminalWidth - 2);
        $tick      = $this->now();
        $player    = ($tick % 28) + 1;
        $hazard    = (($tick * 3) % 28) + 1;
        $track     = str_repeat('.', 28);
        $track[$player - 1] = 'R';
        if ($hazard !== $player) {
            $track[$hazard - 1] = '#';
        }

        $game  = $this->padDashboardRow('│ GAME MODE ACTIVE: INCIDENT DETECTED', $borderLen);
        $game .= $this->padDashboardLine('State: Reconnecting', $borderLen);
        $game .= $this->padDashboardLine('Mission: survive outage waves until systems reconnect.', $borderLen);
        $game .= $this->padDashboardLine('[' . $track . ']', $borderLen);
        $game .= $this->padDashboardLine('R = runner, # = outage gremlin', $borderLen);
        $game .= $this->padDashboardLine($statusText, $borderLen);
        if ($countdown > 0) {
            $game .= $this->padDashboardLine('Retry countdown: ' . $countdown . 's', $borderLen);
        }

        $help = $this->buildDashboardHelpSection($borderLen, 'Controls: Ctrl+C to exit, auto-retry runs in background');

        $this->renderDashboardFrameAutoSystem(
            $output,
            $title,
            [
                $this->padDashboardLine($failureTitle, $borderLen),
                $game,
                $help,
            ],
            $terminalWidth
        );
    }
}
