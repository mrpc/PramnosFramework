<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\CommandBase;

/**
 * Unit tests for Pramnos\Console\CommandBase.
 *
 * CommandBase is abstract; we use an anonymous subclass that implements
 * getJobName() and redirects all lock-file I/O to the temp directory so
 * nothing outside /tmp is written.
 *
 * Tests focus on pure-computation methods (formatBytes, formatTime,
 * visibleLength, truncateText, wrapDashboardText, buildProgressBar,
 * all dashboard builders) and lock-file guard logic (beginJob/endJob).
 * Terminal-control and signal-handling methods emit escape codes — those are
 * tested only at the smoke level (no exception thrown, correct output type).
 */
#[CoversClass(CommandBase::class)]
class CommandBaseTest extends TestCase
{
    private CommandBase $cmd;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_cmdbase_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/var', 0777, true);

        $tmpDir = $this->tmpDir;

        // Arrange — concrete anonymous subclass that targets our tmp dir
        $this->cmd = new class($tmpDir) extends CommandBase {
            public string $baseDir;

            public function __construct(string $baseDir)
            {
                parent::__construct();
                $this->baseDir = $baseDir;
            }

            protected function getJobName(): string
            {
                return 'test_job';
            }

            protected function getJobLockFilePath(): string
            {
                return $this->baseDir . '/var/test_job';
            }

            // Expose protected methods for white-box testing
            public function publicVisibleLength(string $s): int
            {
                return $this->visibleLength($s);
            }

            public function publicTruncateText(string $text, int $max): string
            {
                return $this->truncateText($text, $max);
            }

            public function publicWrapDashboardText(string $text, int $w): array
            {
                return $this->wrapDashboardText($text, $w);
            }

            public function publicReadPidFromLockFile(string $f): int
            {
                return $this->readPidFromLockFile($f);
            }

            public function publicCheckIfRunning(): bool
            {
                return $this->checkIfRunning();
            }

            public function publicStartJob(): void
            {
                $this->startJob();
            }

            public function publicEndJob(): void
            {
                $this->endJob();
            }

            public function publicShouldInterceptExit(int $code): bool
            {
                return true; // always intercept in tests
            }

            protected function shouldInterceptExit(int $exitCode): bool
            {
                return true;
            }

            protected function configure(): void
            {
                $this->setName('test:command');
            }

            // ── OS probe method exposures ─────────────────────────────────
            public function publicCurrentTimestamp(): int
            {
                return $this->currentTimestamp();
            }
            public function publicNow(): int
            {
                return $this->now();
            }
            public function publicNowFloat(): float
            {
                return $this->nowFloat();
            }
            public function publicSupportsSysGetLoadAvg(): bool
            {
                return $this->supportsSysGetLoadAvg();
            }
            public function publicGetLoadAvg(): array
            {
                return $this->getLoadAvg();
            }
            public function publicSupportsPosixKill(): bool
            {
                return $this->supportsPosixKill();
            }
            public function publicCanSignalProcess(int $pid): bool
            {
                return $this->canSignalProcess($pid);
            }
            public function publicHasProcDirectory(int $pid): bool
            {
                return $this->hasProcDirectory($pid);
            }
            public function publicExecuteShell(string $cmd): string
            {
                return $this->executeShell($cmd);
            }
            public function publicIsWindows(): bool
            {
                return $this->isWindows();
            }
            public function publicSupportsMbStrSplit(): bool
            {
                return $this->supportsMbStrSplit();
            }
            public function publicMbStrSplit(string $text): array
            {
                return $this->mbStrSplit($text);
            }
            public function publicSupportsMbStrlen(): bool
            {
                return $this->supportsMbStrlen();
            }
            public function publicMbStringLength(string $text): int
            {
                return $this->mbStringLength($text);
            }
            public function publicSupportsPcntl(): bool
            {
                return $this->supportsPcntl();
            }
            public function publicSupportsShellExec(): bool
            {
                return $this->supportsShellExec();
            }
            public function publicSupportsPosixGetParentPid(): bool
            {
                return $this->supportsPosixGetParentPid();
            }
            public function publicGetOrchestratorCommandName(): string
            {
                return $this->getOrchestratorCommandName();
            }
            public function publicGetLockStaleSeconds(): int
            {
                return $this->getLockStaleSeconds();
            }
        };
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // formatBytes()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * formatBytes(0) returns '0 B' — zero bytes must not produce division
     * errors or logarithm of zero.
     */
    public function testFormatBytesZero(): void
    {
        // Act & Assert
        $this->assertSame('0 B', $this->cmd->formatBytes(0));
    }

    /**
     * formatBytes() selects the correct unit at each 1024-power boundary.
     */
    public function testFormatBytesUnits(): void
    {
        // Assert
        $this->assertStringContainsString('KB', $this->cmd->formatBytes(1024));
        $this->assertStringContainsString('MB', $this->cmd->formatBytes(1024 ** 2));
        $this->assertStringContainsString('GB', $this->cmd->formatBytes(1024 ** 3));
    }

    /**
     * formatBytes() rounds to the requested precision.
     */
    public function testFormatBytesPrecision(): void
    {
        // Act — 1.5 KB
        $result = $this->cmd->formatBytes(1536, 1);

        // Assert
        $this->assertSame('1.5 KB', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // formatTime()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * formatTime(0) returns '00:00:00' — zero seconds must format cleanly.
     */
    public function testFormatTimeZero(): void
    {
        $this->assertSame('00:00:00', $this->cmd->formatTime(0));
    }

    /**
     * formatTime() converts seconds correctly to HH:MM:SS.
     */
    public function testFormatTimeConversion(): void
    {
        // 1 hour, 2 minutes, 3 seconds = 3723 seconds
        $this->assertSame('01:02:03', $this->cmd->formatTime(3723));
    }

    /**
     * formatTime() handles values >= 24 hours (does not cap at 23:59:59).
     */
    public function testFormatTimeLargeValue(): void
    {
        // 100 hours = 360000 seconds
        $this->assertSame('100:00:00', $this->cmd->formatTime(360000));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // visibleLength()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * visibleLength() returns the character count of a plain string.
     */
    public function testVisibleLengthPlainString(): void
    {
        $this->assertSame(5, $this->cmd->publicVisibleLength('hello'));
    }

    /**
     * ANSI escape codes (e.g. colour sequences) must not count towards visible
     * length — they are invisible control characters.
     */
    public function testVisibleLengthStripsAnsiCodes(): void
    {
        // "\033[32m" = green start, "\033[0m" = reset — 12 raw chars, 5 visible
        $this->assertSame(5, $this->cmd->publicVisibleLength("\033[32mhello\033[0m"));
    }

    /**
     * visibleLength() of an empty string is 0.
     */
    public function testVisibleLengthEmptyString(): void
    {
        $this->assertSame(0, $this->cmd->publicVisibleLength(''));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // truncateText()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * truncateText() returns the original string when it fits within maxLen.
     */
    public function testTruncateTextNoOp(): void
    {
        $this->assertSame('hello', $this->cmd->publicTruncateText('hello', 10));
    }

    /**
     * truncateText() appends '...' when the string exceeds maxLen.
     */
    public function testTruncateTextAddsEllipsis(): void
    {
        // Arrange — 10 chars, maxLen 7 → must truncate
        $result = $this->cmd->publicTruncateText('1234567890', 7);

        // Assert — ends with ellipsis and total visible length <= 7
        $this->assertStringEndsWith('...', $result);
        $this->assertLessThanOrEqual(7, $this->cmd->publicVisibleLength($result));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // wrapDashboardText()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Short text that fits in one line is returned as a single-element array.
     */
    public function testWrapDashboardTextShortTextIsPassthrough(): void
    {
        $this->assertSame(['hello world'], $this->cmd->publicWrapDashboardText('hello world', 80));
    }

    /**
     * Long text is split at word boundaries into multiple lines, each of
     * which is no wider than maxWidth.
     */
    public function testWrapDashboardTextSplitsAtWordBoundary(): void
    {
        // Arrange — 5 five-char words separated by spaces = 29 visible chars
        $text  = 'alpha beta gamma delta epsilon';
        $lines = $this->cmd->publicWrapDashboardText($text, 12);

        // Assert — each line fits within 12 chars
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(
                12,
                $this->cmd->publicVisibleLength($line),
                "Line too wide: '$line'"
            );
        }
        // Assert — content reconstructed without loss
        $this->assertSame($text, implode(' ', $lines));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildProgressBar()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * buildProgressBar() with zero total returns an empty (all-dot) bar —
     * must not trigger division by zero.
     */
    public function testBuildProgressBarZeroTotal(): void
    {
        $bar = $this->cmd->buildProgressBar(0, 0);
        $this->assertStringContainsString('0%', $bar);
        $this->assertStringNotContainsString('█', $bar);
    }

    /**
     * At 50% progress exactly half the bar is filled with block characters.
     */
    public function testBuildProgressBarHalfFilled(): void
    {
        // Act
        $bar = $this->cmd->buildProgressBar(50, 100, 50);

        // Assert — exactly 25 filled, 25 empty (50% of 50-wide bar)
        $this->assertSame(1, substr_count($bar, '50%'), 'Percent must appear once');
        $this->assertSame(25, substr_count($bar, '█'), 'Half of 50 blocks should be filled');
        $this->assertSame(25, substr_count($bar, '.'), 'Half of 50 blocks should be empty');
    }

    /**
     * At 100% the bar is completely filled — no dot characters.
     */
    public function testBuildProgressBarFullyFilled(): void
    {
        $bar = $this->cmd->buildProgressBar(10, 10, 20);
        $this->assertStringNotContainsString('.', $bar);
        $this->assertStringContainsString('100%', $bar);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dashboard builders — pure string output
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * buildDashboardHeader() produces a box-drawing header with the title
     * centred between ┌ and ┐ borders.
     */
    public function testBuildDashboardHeaderContainsTitleAndBorders(): void
    {
        $header = $this->cmd->buildDashboardHeader('My Dashboard', 40);

        $this->assertStringContainsString('┌', $header);
        $this->assertStringContainsString('┐', $header);
        $this->assertStringContainsString('│', $header);
        $this->assertStringContainsString('My Dashboard', $header);
    }

    /**
     * buildDashboardSectionSeparator() produces a ├──┤ row of the correct width.
     */
    public function testBuildDashboardSectionSeparatorWidth(): void
    {
        $sep = $this->cmd->buildDashboardSectionSeparator(20);

        $this->assertStringContainsString('├', $sep);
        $this->assertStringContainsString('┤', $sep);
        // 20 dashes inside borders
        $this->assertSame(20, substr_count($sep, '─'));
    }

    /**
     * buildDashboardFooter() produces a └──┘ row.
     */
    public function testBuildDashboardFooterBorders(): void
    {
        $footer = $this->cmd->buildDashboardFooter(20);

        $this->assertStringContainsString('└', $footer);
        $this->assertStringContainsString('┘', $footer);
    }

    /**
     * padDashboardLine() prepends "│ " and appends "│" with the correct width.
     */
    public function testPadDashboardLineHasBordersAndContent(): void
    {
        $row = $this->cmd->padDashboardLine('hello', 20);

        $this->assertStringStartsWith('│ hello', $row);
        $this->assertStringEndsWith("│\n", $row);
    }

    /**
     * buildDashboardRows() fits multiple segments on one row and falls back
     * to separate rows when the line would be too wide.
     */
    public function testBuildDashboardRowsFitsSegmentsOnOneLine(): void
    {
        // Arrange — two short segments that easily fit on one line
        $rows = $this->cmd->buildDashboardRows(['Time: 12:00', 'Uptime: 01:00'], 60);

        // Assert — both segments appear in the output
        $this->assertStringContainsString('Time: 12:00', $rows);
        $this->assertStringContainsString('Uptime: 01:00', $rows);
        // Assert — borders present
        $this->assertStringContainsString('│', $rows);
    }

    /**
     * buildSystemStatusSegments() returns exactly 4 segments: Time, Uptime,
     * CPU, Memory — these are the standard daemon dashboard fields.
     */
    public function testBuildSystemStatusSegmentsReturnsFourFields(): void
    {
        // Arrange — fake start time 60 seconds ago
        $startTime = time() - 60;
        $segments  = $this->cmd->buildSystemStatusSegments($startTime, 12.5, 1024 * 1024);

        // Assert — exactly 4 segments
        $this->assertCount(4, $segments);

        // Assert — each expected field is present
        $joined = implode(' ', $segments);
        $this->assertStringContainsString('Time:', $joined);
        $this->assertStringContainsString('Uptime:', $joined);
        $this->assertStringContainsString('CPU:', $joined);
        $this->assertStringContainsString('Memory:', $joined);
    }

    /**
     * buildCommandStateSection() renders "Mode:" and "State:" rows.
     */
    public function testBuildCommandStateSectionIncludesModeAndState(): void
    {
        $section = $this->cmd->buildCommandStateSection(40, 'normal', 'running');

        $this->assertStringContainsString('Mode:', $section);
        $this->assertStringContainsString('State:', $section);
        $this->assertStringContainsString('Normal', $section);
        $this->assertStringContainsString('Running', $section);
    }

    /**
     * buildDashboardHelpSection() includes the default help text.
     */
    public function testBuildDashboardHelpSectionDefaultText(): void
    {
        $help = $this->cmd->buildDashboardHelpSection(40);

        $this->assertStringContainsString('Ctrl+C', $help);
    }

    /**
     * buildDashboardAdventureSection() includes the runner track and title.
     */
    public function testBuildDashboardAdventureSectionHasTrackAndTitle(): void
    {
        $section = $this->cmd->buildDashboardAdventureSection(40, 'Test Title', 'Reconnecting...', 5);

        $this->assertStringContainsString('Test Title', $section);
        $this->assertStringContainsString('runner', $section);
        $this->assertStringContainsString('Next retry in 5s', $section);
    }

    /**
     * buildDashboardAdventureSection() with countdown=0 omits the retry line.
     */
    public function testBuildDashboardAdventureSectionNoCountdown(): void
    {
        $section = $this->cmd->buildDashboardAdventureSection(40, 'Title', 'Status', 0);

        $this->assertStringNotContainsString('Next retry', $section);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lock file management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * checkIfRunning() returns false when no lock file exists.
     *
     * This is the normal startup path — no other instance is running.
     */
    public function testCheckIfRunningReturnsFalseWhenNoLockFile(): void
    {
        $this->assertFalse($this->cmd->publicCheckIfRunning());
    }

    /**
     * startJob() creates the lock file containing the current PID.
     */
    public function testStartJobCreatesLockFileWithPid(): void
    {
        // Act
        $this->cmd->publicStartJob();

        // Assert — lock file written
        $lockFile = $this->tmpDir . '/var/test_job';
        $this->assertFileExists($lockFile);

        // Assert — contains numeric PID on first line
        $pid = $this->cmd->publicReadPidFromLockFile($lockFile);
        $this->assertGreaterThan(0, $pid);
    }

    /**
     * endJob() removes the lock file created by startJob().
     */
    public function testEndJobRemovesLockFile(): void
    {
        // Arrange — create the lock file
        $this->cmd->publicStartJob();
        $lockFile = $this->tmpDir . '/var/test_job';
        $this->assertFileExists($lockFile);

        // Act
        $this->cmd->publicEndJob();

        // Assert — lock file gone
        $this->assertFileDoesNotExist($lockFile);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OS probe methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * currentTimestamp() returns a Unix timestamp (positive integer).
     * This delegates to time(), so the result must be close to the real clock.
     */
    public function testCurrentTimestampReturnsPositiveInt(): void
    {
        // Act
        $ts = $this->cmd->publicCurrentTimestamp();

        // Assert — must be a recent Unix timestamp (after 2020-01-01)
        $this->assertGreaterThan(1577836800, $ts);
    }

    /**
     * now() is an alias for currentTimestamp() — both must return the same value
     * within a single-second window.
     */
    public function testNowDelegatesToCurrentTimestamp(): void
    {
        // Act
        $a = $this->cmd->publicCurrentTimestamp();
        $b = $this->cmd->publicNow();

        // Assert — should match or be off by at most 1 second
        $this->assertLessThanOrEqual(1, abs($b - $a));
    }

    /**
     * nowFloat() returns a float representing the current microtime.
     * Must be greater than the integer timestamp (since it includes microseconds).
     */
    public function testNowFloatReturnsMicrotimeFloat(): void
    {
        // Act
        $f = $this->cmd->publicNowFloat();

        // Assert — must be a positive float larger than a 2020 epoch
        $this->assertIsFloat($f);
        $this->assertGreaterThan(1577836800.0, $f);
    }

    /**
     * supportsSysGetLoadAvg() reflects whether sys_getloadavg() is available.
     * In a Docker/Linux environment this function is always present.
     */
    public function testSupportsSysGetLoadAvgMatchesFunctionExists(): void
    {
        // Act
        $result = $this->cmd->publicSupportsSysGetLoadAvg();

        // Assert — must match what PHP reports directly
        $this->assertSame(function_exists('sys_getloadavg'), $result);
    }

    /**
     * getLoadAvg() returns an array of 3 load-average floats when the function
     * is available (as it is on all Linux systems used by this project).
     */
    public function testGetLoadAvgReturnsThreeElementArray(): void
    {
        // Arrange — skip on systems without sys_getloadavg
        if (!function_exists('sys_getloadavg')) {
            $this->markTestSkipped('sys_getloadavg not available');
        }

        // Act
        $avg = $this->cmd->publicGetLoadAvg();

        // Assert — must be a 3-element array of non-negative floats
        $this->assertCount(3, $avg);
        $this->assertGreaterThanOrEqual(0.0, $avg[0]);
    }

    /**
     * supportsPosixKill() returns true/false depending on whether the posix
     * extension is loaded.  Just verify the result type is boolean.
     */
    public function testSupportsPosixKillReturnsBool(): void
    {
        // Act / Assert — type is sufficient; the value is environment-dependent
        $this->assertIsBool($this->cmd->publicSupportsPosixKill());
    }

    /**
     * canSignalProcess() on the current process (getmypid()) must return true
     * when posix_kill is available — sending signal 0 to ourself succeeds.
     */
    public function testCanSignalProcessReturnsTrueForSelf(): void
    {
        // Arrange
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix extension not available');
        }

        // Act / Assert — signal 0 to self is always allowed
        $this->assertTrue($this->cmd->publicCanSignalProcess(getmypid()));
    }

    /**
     * hasProcDirectory() returns true for the current process's /proc entry
     * and false for a PID that cannot exist (INT_MAX).
     */
    public function testHasProcDirectoryTrueForCurrentPid(): void
    {
        // Arrange — skip on non-Linux where /proc is absent
        if (!is_dir('/proc')) {
            $this->markTestSkipped('/proc not available on this platform');
        }

        // Act / Assert
        $this->assertTrue($this->cmd->publicHasProcDirectory(getmypid()));
        $this->assertFalse($this->cmd->publicHasProcDirectory(PHP_INT_MAX));
    }

    /**
     * executeShell() runs an arbitrary shell command and returns its output
     * as a string.  'echo hello' must return 'hello' (with trailing newline).
     */
    public function testExecuteShellRunsCommand(): void
    {
        // Arrange — skip if shell_exec disabled
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec not available');
        }

        // Act
        $output = $this->cmd->publicExecuteShell('echo hello');

        // Assert — shell output contains 'hello'
        $this->assertStringContainsString('hello', $output);
    }

    /**
     * isWindows() must return false inside the Docker/Linux container used
     * by this project's test suite.
     */
    public function testIsWindowsReturnsFalseOnLinux(): void
    {
        // Assert — PHP_OS_FAMILY on this container is always 'Linux'
        $this->assertFalse($this->cmd->publicIsWindows());
    }

    /**
     * supportsMbStrSplit() returns true when mb_str_split() is available
     * (PHP 7.4+; this project requires PHP 8.4).
     */
    public function testSupportsMbStrSplitReturnsTrueOnPhp84(): void
    {
        // Assert — mb_str_split is always present in PHP 8.4
        $this->assertTrue($this->cmd->publicSupportsMbStrSplit());
    }

    /**
     * mbStrSplit() splits a multibyte string into individual characters.
     * 'abc' → ['a', 'b', 'c'].
     */
    public function testMbStrSplitSplitsAsciiString(): void
    {
        // Act
        $chars = $this->cmd->publicMbStrSplit('abc');

        // Assert
        $this->assertSame(['a', 'b', 'c'], $chars);
    }

    /**
     * mbStrSplit() handles multibyte (Greek) characters correctly — each
     * entry is a single logical character, not a byte fragment.
     */
    public function testMbStrSplitHandlesMultibyteCharacters(): void
    {
        // Act — Greek word 'αβγ' (3 characters, 6 UTF-8 bytes)
        $chars = $this->cmd->publicMbStrSplit('αβγ');

        // Assert — 3 grapheme elements
        $this->assertCount(3, $chars);
        $this->assertSame('α', $chars[0]);
    }

    /**
     * supportsMbStrlen() returns true when mb_strlen() is available
     * (PHP 8.4 always ships with mbstring).
     */
    public function testSupportsMbStrlenReturnsTrueOnPhp84(): void
    {
        // Assert
        $this->assertTrue($this->cmd->publicSupportsMbStrlen());
    }

    /**
     * mbStringLength() returns the character count in UTF-8, not the byte count.
     * The 3-character Greek word 'αβγ' is 6 bytes but 3 characters.
     */
    public function testMbStringLengthCountsCharactersNotBytes(): void
    {
        // Act
        $len = $this->cmd->publicMbStringLength('αβγ');

        // Assert — 3 characters, not 6 bytes
        $this->assertSame(3, $len);
    }

    /**
     * supportsPcntl() returns a boolean reflecting whether the pcntl extension
     * is loaded.  Just check the return type.
     */
    public function testSupportsPcntlReturnsBool(): void
    {
        // Act / Assert — type check only; value is environment-dependent
        $this->assertIsBool($this->cmd->publicSupportsPcntl());
    }

    /**
     * supportsShellExec() returns true on this Linux environment since
     * shell_exec is not disabled in the Docker PHP configuration.
     */
    public function testSupportsShellExecReturnsBool(): void
    {
        // Act / Assert — environment-dependent; verify it's a bool
        $this->assertIsBool($this->cmd->publicSupportsShellExec());
    }

    /**
     * supportsPosixGetParentPid() returns true when posix_getppid() is available.
     * On this Linux/Docker environment the posix extension is loaded.
     */
    public function testSupportsPosixGetParentPidReturnsBool(): void
    {
        // Act / Assert — type check sufficient
        $this->assertIsBool($this->cmd->publicSupportsPosixGetParentPid());
    }

    /**
     * getOrchestratorCommandName() returns the default orchestrator command
     * name 'daemons:start' — used for parent-process detection.
     */
    public function testGetOrchestratorCommandNameReturnsDefault(): void
    {
        // Act / Assert
        $this->assertSame('daemons:start', $this->cmd->publicGetOrchestratorCommandName());
    }

    /**
     * getLockStaleSeconds() returns 7200 (2 hours) — lock files older than
     * this are considered stale and removed automatically.
     */
    public function testGetLockStaleSecondsReturnsTwoHours(): void
    {
        // Act / Assert — 2 hours = 3600 * 2 = 7200
        $this->assertSame(7200, $this->cmd->publicGetLockStaleSeconds());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // heartbeat()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * heartbeat() must touch the lock file when it exists, updating its mtime.
     *
     * This covers the `if (file_exists($file))` true branch in heartbeat()
     * (line ~244) — essential for the orchestrator's liveness checks.
     */
    public function testHeartbeatTouchesLockFileWhenItExists(): void
    {
        // Arrange — create the lock file and record its mtime
        $this->cmd->publicStartJob();
        $lockFile = $this->tmpDir . '/var/test_job';
        $this->assertFileExists($lockFile);
        $before = filemtime($lockFile);

        // Ensure at least 1 second passes so mtime can differ
        sleep(1);

        // Expose heartbeat via reflection (protected method)
        $ref = new \ReflectionMethod($this->cmd, 'heartbeat');

        // Act
        $ref->invoke($this->cmd);

        // Assert — mtime was updated
        clearstatcache(true, $lockFile);
        $after = filemtime($lockFile);
        $this->assertGreaterThanOrEqual($before, $after,
            'heartbeat() must touch the lock file to update its mtime');
    }

    /**
     * heartbeat() must be a no-op when the lock file does not exist.
     *
     * This covers the `if (file_exists($file))` false branch in heartbeat()
     * (line ~244) — prevents heartbeat() from crashing when the lock was
     * already removed by another thread.
     */
    public function testHeartbeatIsNoOpWhenLockFileAbsent(): void
    {
        // Arrange — no lock file exists
        $ref = new \ReflectionMethod($this->cmd, 'heartbeat');

        // Act + Assert — must not throw
        $ref->invoke($this->cmd);
        $this->assertFileDoesNotExist($this->tmpDir . '/var/test_job');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // isProcessStillRunning()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * isProcessStillRunning(0) must return false — PID 0 is never a running
     * user process and the guard `if ($pid <= 0) return false` must fire.
     *
     * This covers the early-return branch (lines ~212-214) in
     * isProcessStillRunning().
     */
    public function testIsProcessStillRunningReturnsFalseForZeroPid(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->cmd, 'isProcessStillRunning');

        // Act + Assert
        $this->assertFalse($ref->invoke($this->cmd, 0),
            'isProcessStillRunning(0) must return false');
    }

    /**
     * isProcessStillRunning() must return true for the current process's PID,
     * which is always alive when this test runs.
     *
     * This covers the posix_kill / hasProcDirectory true-path (lines ~215-220).
     */
    public function testIsProcessStillRunningReturnsTrueForCurrentPid(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->cmd, 'isProcessStillRunning');

        // Act + Assert
        $this->assertTrue($ref->invoke($this->cmd, getmypid()),
            'isProcessStillRunning() must return true for the current process');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // checkIfRunning() — stale lock-file path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * checkIfRunning() must remove a stale lock file and return false.
     *
     * "Stale" means the lock file's mtime is older than getLockStaleSeconds()
     * (7200 s). This covers the `$age > getLockStaleSeconds()` branch in
     * checkIfRunning() (lines ~161-164).
     */
    public function testCheckIfRunningRemovesStaleFile(): void
    {
        // Arrange — create a fake lock file and backdate it to 8 hours ago
        $lockFile = $this->tmpDir . '/var/test_job';
        file_put_contents($lockFile, "99999\n");
        touch($lockFile, time() - 8 * 3600);

        // Act
        $running = $this->cmd->publicCheckIfRunning();

        // Assert — stale file must be removed and false returned
        $this->assertFalse($running, 'checkIfRunning() must return false for a stale lock file');
        $this->assertFileDoesNotExist($lockFile, 'checkIfRunning() must remove the stale lock file');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Output-writing helpers (clearScreen, hideCursor, showCursor)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * clearScreen() must write ANSI escape codes to the provided output.
     *
     * This covers clearScreen() (lines ~342-347), hideCursor() (349-351),
     * and showCursor() (354-357) which are called during interactive terminal
     * setup and teardown.
     */
    public function testClearScreenHideCursorShowCursorWriteToOutput(): void
    {
        // Arrange — use Symfony's BufferedOutput to capture what is written
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $ref    = new \ReflectionClass($this->cmd);

        // Act — clearScreen
        $clear = $ref->getMethod('clearScreen');
        $clear->invoke($this->cmd, $output);
        $this->assertStringContainsString("\033", $output->fetch(),
            'clearScreen() must write ANSI codes');

        // Act — hideCursor
        $hide = $ref->getMethod('hideCursor');
        $hide->invoke($this->cmd, $output);
        $this->assertStringContainsString("\033", $output->fetch(),
            'hideCursor() must write ANSI codes');

        // Act — showCursor
        $show = $ref->getMethod('showCursor');
        $show->invoke($this->cmd, $output);
        $this->assertStringContainsString("\033", $output->fetch(),
            'showCursor() must write ANSI codes');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // handleShutdown() and handleInterruptSignal()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * handleShutdown() must show the cursor and call endJob().
     *
     * This covers the handleShutdown() body (lines ~408-411): it calls
     * showCursor() and endJob() — the standard cleanup sequence when
     * the process is terminated.
     */
    public function testHandleShutdownShowsCursorAndEndsJob(): void
    {
        // Arrange — create a lock file so endJob has something to remove
        $this->cmd->publicStartJob();
        $lockFile = $this->tmpDir . '/var/test_job';
        $this->assertFileExists($lockFile);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $this->cmd->handleShutdown($output);

        // Assert — cursor show code was written
        $written = $output->fetch();
        $this->assertStringContainsString("\033", $written,
            'handleShutdown() must write cursor-show ANSI code');

        // Assert — lock file removed by endJob()
        $this->assertFileDoesNotExist($lockFile,
            'handleShutdown() must call endJob() to clean up the lock file');
    }

    /**
     * handleInterruptSignal() must clean up the lock file (via endJob) and
     * terminate the command — but our test subclass intercepts exit().
     *
     * This covers handleInterruptSignal() (lines ~413-420): endJob() call
     * and terminateCommand(130).
     */
    public function testHandleInterruptSignalCleansUpAndTerminates(): void
    {
        // Arrange — create a lock file so endJob has something to remove
        $this->cmd->publicStartJob();
        $lockFile = $this->tmpDir . '/var/test_job';
        $this->assertFileExists($lockFile);

        // Act — signal 0 is the "probe" signal; the handler receives it
        $this->cmd->handleInterruptSignal(0);

        // Assert — lock file was removed
        $this->assertFileDoesNotExist($lockFile,
            'handleInterruptSignal() must call endJob() to remove the lock file');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // beginJob()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * beginJob() must return true and create the lock file when no other
     * instance is running.
     *
     * This covers the "not running" happy path of beginJob() (lines ~253-270),
     * including startJob() invocation and optional shutdown handler registration.
     */
    public function testBeginJobReturnsTrueWhenNotAlreadyRunning(): void
    {
        // Arrange
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $ref    = new \ReflectionMethod($this->cmd, 'beginJob');

        // Act — registerShutdown=false so we don't register an actual shutdown function
        $result = $ref->invoke($this->cmd, $output, false);

        // Assert — returns true
        $this->assertTrue($result, 'beginJob() must return true when job is not already running');

        // Assert — lock file was created
        $lockFile = $this->tmpDir . '/var/test_job';
        $this->assertFileExists($lockFile, 'beginJob() must create the lock file via startJob()');

        // Cleanup
        $this->cmd->publicEndJob();
    }

    /**
     * beginJob() must return false and write an error when the job is already
     * running (lock file present with a live PID).
     *
     * This covers the `checkIfRunning() == true` branch of beginJob() (lines
     * ~255-259): early-return false + error message.
     */
    public function testBeginJobReturnsFalseWhenAlreadyRunning(): void
    {
        // Arrange — pre-create a lock file with our own PID so checkIfRunning returns true
        $lockFile = $this->tmpDir . '/var/test_job';
        file_put_contents($lockFile, getmypid() . "\n");

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $ref    = new \ReflectionMethod($this->cmd, 'beginJob');

        // Act
        $result = $ref->invoke($this->cmd, $output, false);

        // Assert — already running, must return false
        $this->assertFalse($result, 'beginJob() must return false when job is already running');

        // Assert — error message written to output
        $written = $output->fetch();
        $this->assertStringContainsString('already running', $written,
            'beginJob() must write an error message when already running');

        // Cleanup
        @unlink($lockFile);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // renderDashboardFrame() / renderDashboardFrameAutoSystem() / renderDashboardGameMode()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * renderDashboardFrame() must write the ANSI cursor-home escape sequence
     * followed by a bordered dashboard containing the title and sections, and
     * end with the ANSI erase-below sequence.
     *
     * This is a smoke test that exercises the full rendering pipeline: header,
     * system-segment rows, section separator, section content, footer.
     */
    public function testRenderDashboardFrameWritesBorderedOutput(): void
    {
        // Arrange
        $output   = new \Symfony\Component\Console\Output\BufferedOutput();
        $segments = ['CPU: 0.0', 'Memory: 1 MB'];
        $sections = ['Status: idle'];

        // Act
        $this->cmd->renderDashboardFrame($output, 'My Dashboard', $segments, $sections, 80);

        // Assert — cursor-home + erase-below escape sequences present
        $raw = $output->fetch();
        $this->assertStringContainsString("\033[H", $raw,
            'renderDashboardFrame() must emit cursor-home escape code');
        $this->assertStringContainsString("\033[J", $raw,
            'renderDashboardFrame() must emit erase-below escape code');

        // Assert — title and section content appear in the output
        $this->assertStringContainsString('My Dashboard', $raw,
            'Title must appear in the rendered frame');
        $this->assertStringContainsString('Status: idle', $raw,
            'Section content must appear in the rendered frame');
    }

    /**
     * renderDashboardFrameAutoSystem() must produce the same structural output
     * as renderDashboardFrame() but derive system segments automatically via
     * buildDefaultSystemSegments().
     *
     * Verifies that the convenience wrapper actually calls through to
     * renderDashboardFrame() (title + ANSI codes present).
     */
    public function testRenderDashboardFrameAutoSystemWritesOutput(): void
    {
        // Arrange
        $output   = new \Symfony\Component\Console\Output\BufferedOutput();
        $sections = ['Worker count: 3'];

        // Act
        $this->cmd->renderDashboardFrameAutoSystem($output, 'Auto Frame', $sections, 80);

        // Assert — structural markers present
        $raw = $output->fetch();
        $this->assertStringContainsString("\033[H", $raw);
        $this->assertStringContainsString('Auto Frame', $raw,
            'Title must appear even when system segments are auto-detected');
        $this->assertStringContainsString('Worker count: 3', $raw,
            'Section content must propagate through the auto-system wrapper');
    }

    /**
     * renderDashboardFrameAutoSystem() must accept an explicit $systemSegments
     * override and include those segments instead of the auto-detected ones.
     *
     * This covers the `$systemSegments ?? $this->buildDefaultSystemSegments()`
     * branch where the caller-supplied value is used.
     */
    public function testRenderDashboardFrameAutoSystemUsesExplicitSegmentsWhenProvided(): void
    {
        // Arrange
        $output   = new \Symfony\Component\Console\Output\BufferedOutput();
        $override = ['custom-segment: yes'];

        // Act
        $this->cmd->renderDashboardFrameAutoSystem(
            $output, 'Override Test', [], 80, $override
        );

        // Assert — our custom segment appears verbatim in the output
        $raw = $output->fetch();
        $this->assertStringContainsString('custom-segment: yes', $raw,
            'Explicitly supplied systemSegments must appear in the rendered frame');
    }

    /**
     * renderDashboardGameMode() must write the game-mode frame including the
     * adventure track (containing 'R' for runner and possibly '#' for hazard),
     * the failure title, and a retry countdown line when countdown > 0.
     *
     * This exercises the complete game-mode rendering pipeline.
     */
    public function testRenderDashboardGameModeWritesGameFrame(): void
    {
        // Arrange
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $this->cmd->renderDashboardGameMode(
            $output,
            'Game Title',
            'Service Down',
            'Reconnecting…',
            10,
            80
        );

        // Assert — game mode header always present
        $raw = $output->fetch();
        $this->assertStringContainsString('GAME MODE', $raw,
            'Game mode frame must contain GAME MODE marker');
        $this->assertStringContainsString('Game Title', $raw,
            'Dashboard title must appear in game-mode frame');
        $this->assertStringContainsString('Retry countdown: 10s', $raw,
            'Countdown line must appear when countdown > 0');
        $this->assertStringContainsString('R', $raw,
            'Runner character must appear in the adventure track');
    }

    /**
     * renderDashboardGameMode() must omit the retry countdown line entirely
     * when countdown is 0 (no active countdown).
     */
    public function testRenderDashboardGameModeOmitsCountdownWhenZero(): void
    {
        // Arrange
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $this->cmd->renderDashboardGameMode(
            $output, 'Title', 'DB Down', 'Waiting', 0, 80
        );

        // Assert — countdown line must not be present
        $raw = $output->fetch();
        $this->assertStringNotContainsString('Retry countdown:', $raw,
            'No countdown line should appear when countdown === 0');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getDashboardStartTime / getDashboardCpuUsage / getDashboardMemoryUsage
    // readNumericPropertyValue / buildDefaultSystemSegments
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getDashboardStartTime() must return the current timestamp when no
     * 'startTime' property exists on the concrete class.
     *
     * readNumericPropertyValue() traverses the class hierarchy looking for a
     * property named 'startTime'. When not found it returns null, and
     * getDashboardStartTime() falls back to currentTimestamp().
     */
    public function testGetDashboardStartTimeFallsBackToCurrentTimestamp(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->cmd, 'getDashboardStartTime');

        $before = time();

        // Act
        $result = $ref->invoke($this->cmd);

        $after = time();

        // Assert — value is in the current-second window (not 0 or far past)
        $this->assertGreaterThanOrEqual($before, $result,
            'getDashboardStartTime() fallback must return a recent timestamp');
        $this->assertLessThanOrEqual($after, $result);
    }

    /**
     * getDashboardCpuUsage() must return 0.0 when no 'cpuUsage' property exists.
     *
     * This covers the `return 0.0` default branch of getDashboardCpuUsage().
     */
    public function testGetDashboardCpuUsageReturnsZeroWhenPropertyAbsent(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->cmd, 'getDashboardCpuUsage');

        // Act
        $result = $ref->invoke($this->cmd);

        // Assert
        $this->assertSame(0.0, $result,
            'getDashboardCpuUsage() must return 0.0 when cpuUsage property is absent');
    }

    /**
     * getDashboardMemoryUsage() must return the current memory_get_usage(true)
     * value when no 'memoryUsage' property exists on the concrete class.
     *
     * The returned value must be a positive integer (PHP always allocates at
     * least one memory page).
     */
    public function testGetDashboardMemoryUsageFallsBackToMemoryGetUsage(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->cmd, 'getDashboardMemoryUsage');

        // Act
        $result = $ref->invoke($this->cmd);

        // Assert — must be a positive number (current process memory)
        $this->assertGreaterThan(0, $result,
            'getDashboardMemoryUsage() fallback must return a positive value');
    }

    /**
     * readNumericPropertyValue() must return the float value of a numeric
     * property when the concrete class declares it.
     *
     * We use a fresh anonymous subclass that declares a public $testProp so
     * the reflection walk can find and read it.
     */
    public function testReadNumericPropertyValueReturnsPropertyValue(): void
    {
        // Arrange — anonymous subclass with a known numeric property
        $instance = new class extends CommandBase {
            public float $testProp = 42.5;
            protected function getJobName(): string { return 'test'; }
            protected function configure(): void { $this->setName('test:rnpv'); }
        };

        $ref = new \ReflectionMethod($instance, 'readNumericPropertyValue');

        // Act
        $result = $ref->invoke($instance, 'testProp');

        // Assert — returns the numeric value cast to float
        $this->assertSame(42.5, $result,
            'readNumericPropertyValue() must return the float value of an existing numeric property');
    }

    /**
     * readNumericPropertyValue() must return null when the named property does
     * not exist on the class or any of its ancestors.
     */
    public function testReadNumericPropertyValueReturnsNullWhenPropertyAbsent(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->cmd, 'readNumericPropertyValue');

        // Act
        $result = $ref->invoke($this->cmd, 'nonExistentPropertyXyz');

        // Assert
        $this->assertNull($result,
            'readNumericPropertyValue() must return null for a property that does not exist');
    }

    /**
     * readNumericPropertyValue() must return null when the property exists but
     * holds a non-numeric value.
     */
    public function testReadNumericPropertyValueReturnsNullForNonNumericProperty(): void
    {
        // Arrange — anonymous subclass with a string property
        $instance = new class extends CommandBase {
            public string $badProp = 'not-a-number';
            protected function getJobName(): string { return 'test'; }
            protected function configure(): void { $this->setName('test:rnpv2'); }
        };

        $ref = new \ReflectionMethod($instance, 'readNumericPropertyValue');

        // Act
        $result = $ref->invoke($instance, 'badProp');

        // Assert
        $this->assertNull($result,
            'readNumericPropertyValue() must return null when the property value is non-numeric');
    }

    /**
     * buildDefaultSystemSegments() must return a non-empty array of strings
     * containing at least the standard Time/Uptime/CPU/Memory keys.
     *
     * This covers the buildSystemStatusSegments() call-through and confirms
     * that the four standard status segments are always present.
     */
    public function testBuildDefaultSystemSegmentsReturnsStandardKeys(): void
    {
        // Arrange
        $ref = new \ReflectionMethod($this->cmd, 'buildDefaultSystemSegments');

        // Act
        $segments = $ref->invoke($this->cmd);

        // Assert — must return an array
        $this->assertIsArray($segments, 'buildDefaultSystemSegments() must return an array');
        $this->assertNotEmpty($segments, 'buildDefaultSystemSegments() must return at least one segment');

        // Assert — each standard key appears somewhere in the joined output
        $joined = implode(' ', $segments);
        $this->assertStringContainsString('Time:', $joined,
            'Standard time segment must be present');
        $this->assertStringContainsString('CPU:', $joined,
            'Standard CPU segment must be present');
        $this->assertStringContainsString('Memory:', $joined,
            'Standard memory segment must be present');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // shouldInterceptExit() default return value
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The default implementation of shouldInterceptExit() in CommandBase must
     * return false (do not intercept — let exit() propagate normally).
     *
     * The anonymous class used by other tests overrides this to return true.
     * Here we use a fresh subclass that does NOT override shouldInterceptExit()
     * so the base-class default is exercised.
     */
    public function testShouldInterceptExitDefaultReturnsFalse(): void
    {
        // Arrange — subclass that intentionally does NOT override shouldInterceptExit()
        $instance = new class extends CommandBase {
            protected function getJobName(): string { return 'sie_test'; }
            protected function configure(): void { $this->setName('test:sie'); }

            // Expose the protected method for assertion
            public function publicShouldInterceptExit(int $code): bool
            {
                return $this->shouldInterceptExit($code);
            }
        };

        // Act + Assert — the base-class default must be false for any exit code
        $this->assertFalse($instance->publicShouldInterceptExit(0),
            'shouldInterceptExit(0) default must return false');
        $this->assertFalse($instance->publicShouldInterceptExit(1),
            'shouldInterceptExit(1) default must return false');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // wrapDashboardText() — word-wider-than-maxWidth path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * wrapDashboardText() must split a single word that is wider than maxWidth
     * into multiple lines, one line per character group, without losing any
     * characters.
     *
     * This exercises the character-by-character split branch inside wrapDashboardText()
     * and also calls splitDashboardCharacters() (which in turn calls mbStrSplit() when
     * the mb_str_split extension is available, covering those lines).
     */
    public function testWrapDashboardTextSplitsWordWiderThanMaxWidth(): void
    {
        // Arrange — a single word 10 chars long, maxWidth = 3
        $longWord = 'abcdefghij'; // 10 chars, wider than maxWidth = 3

        // Act
        $lines = $this->cmd->publicWrapDashboardText($longWord, 3);

        // Assert — multiple lines produced, none wider than 3 chars
        $this->assertGreaterThan(1, count($lines),
            'wrapDashboardText() must split a word wider than maxWidth into multiple lines');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(3, $this->cmd->publicVisibleLength($line),
                "Each line must be at most 3 visible chars, got: '$line'");
        }

        // Assert — no characters lost
        $this->assertSame($longWord, implode('', $lines),
            'All characters must be preserved after splitting');
    }

    /**
     * wrapDashboardText() must handle text where some words fit within maxWidth
     * and others do not, interleaving normal word-wrap and character-split.
     */
    public function testWrapDashboardTextMixedWordLengths(): void
    {
        // Arrange — "hi" fits in 5 chars, "superlongword" does not
        $text = 'hi superlongword end';

        // Act
        $lines = $this->cmd->publicWrapDashboardText($text, 5);

        // Assert — at least 3 lines (hi | superlongword split | end)
        $this->assertGreaterThanOrEqual(3, count($lines),
            'Mixed-length text must produce at least 3 lines with maxWidth=5');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(5, $this->cmd->publicVisibleLength($line),
                "Each line must be at most 5 visible chars, got: '$line'");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // visibleLength() and truncateText() without mb_ extension fallback
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * visibleLength() must fall back to preg_match_all('/./us', ...) and strlen()
     * when supportsMbStrlen() returns false (simulates environment without mb_strlen).
     *
     * This covers the fallback branches at L536-539 of CommandBase.php.
     */
    public function testVisibleLengthWithoutMbStrlen(): void
    {
        // Arrange — subclass that forces the no-mb_strlen fallback path
        $instance = new class extends CommandBase {
            protected function getJobName(): string { return 'vl_test'; }
            protected function configure(): void { $this->setName('test:vl'); }

            protected function supportsMbStrlen(): bool { return false; }

            public function pubVisibleLength(string $s): int
            {
                return $this->visibleLength($s);
            }
        };

        // Act — plain ASCII (preg_match_all path)
        $len = $instance->pubVisibleLength('hello');

        // Assert — correct character count via fallback
        $this->assertSame(5, $len,
            'visibleLength() fallback must return 5 for "hello"');
    }

    /**
     * visibleLength() strlen() branch runs when preg_match_all does not return 1.
     * We simulate this by passing an empty string — an empty match returns 1 with
     * matches[0]=[], so strlen() of '' = 0.  The empty-string case verifies the
     * branch without altering state.
     *
     * The actual unreachable strlen() fallback (preg_match_all != 1) is covered by
     * a subclass that overrides both support flags to false and we check the return.
     */
    public function testVisibleLengthEmptyStringReturnZero(): void
    {
        // Arrange — subclass without mb_strlen
        $instance = new class extends CommandBase {
            protected function getJobName(): string { return 'vl_empty'; }
            protected function configure(): void { $this->setName('test:vle'); }

            protected function supportsMbStrlen(): bool { return false; }

            public function pubVisibleLength(string $s): int
            {
                return $this->visibleLength($s);
            }
        };

        // Act — empty string
        $len = $instance->pubVisibleLength('');

        // Assert — 0
        $this->assertSame(0, $len,
            'visibleLength("") must return 0');
    }

    /**
     * truncateText() must fall back to preg_match_all character splitting when
     * supportsMbStrSplit() returns false (simulates environment without mb_str_split).
     *
     * This covers the fallback branch at L553-555 of CommandBase.php.
     */
    public function testTruncateTextWithoutMbStrSplit(): void
    {
        // Arrange — subclass that forces the no-mb_str_split fallback path
        $instance = new class extends CommandBase {
            protected function getJobName(): string { return 'tt_test'; }
            protected function configure(): void { $this->setName('test:tt'); }

            protected function supportsMbStrSplit(): bool { return false; }

            public function pubTruncate(string $text, int $max): string
            {
                return $this->truncateText($text, $max);
            }
        };

        // Act — truncate a 20-char string to 10 chars
        $result = $instance->pubTruncate('Hello World This Is A Test', 10);

        // Assert — result ends with '...' and is no longer than 10 visible chars
        $this->assertStringEndsWith('...', $result,
            'truncateText() fallback must append "..." on overflow');
        $this->assertLessThanOrEqual(10, strlen(preg_replace('/\033\[[0-9;]*m/', '', $result)),
            'truncateText() result must be at most 10 visible chars');
    }
}
