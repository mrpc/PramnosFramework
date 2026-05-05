<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

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
}
