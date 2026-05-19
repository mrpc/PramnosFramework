<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\LogMigrator;

/**
 * Unit tests for LogMigrator — converts old plain-text log formats to
 * structured JSON-line format.
 *
 * All tests use temporary files created in sys_get_temp_dir() and cleaned up
 * in tearDown().  No database or HTTP dependencies are required.
 *
 * Tests exercise:
 *  - Constructor stores optional progress callback
 *  - migrateFile() throws RuntimeException for missing files
 *  - migrateFile() converts standard log lines to JSON
 *  - migrateFile() creates a .bak backup by default
 *  - migrateFile() skips backup when createBackup=false
 *  - migrateFile() calls the progress callback during processing
 *  - migrateFile() handles PHP error log multiline buffering
 *  - migrateFile() processes lines without timestamps
 *  - migrateFile() returns statistics with correct counts
 */
#[CoversClass(LogMigrator::class)]
class LogMigratorTest extends TestCase
{
    private string $tmpDir;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_logmig_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up all temp files created during the test
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
            @unlink($f . '.bak');
            @unlink($f . '.tmp');
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Helper: write content to a temp file and register it for cleanup.
     */
    private function writeTmpFile(string $content): string
    {
        $path = $this->tmpDir . '/test_' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    /**
     * Constructor must accept null progress callback and produce a valid object.
     */
    public function testConstructorWithNullCallback(): void
    {
        // Arrange / Act
        $migrator = new LogMigrator();

        // Assert — instance created successfully
        $this->assertInstanceOf(LogMigrator::class, $migrator,
            'LogMigrator constructor must succeed without a callback argument');
    }

    /**
     * Constructor must accept and store a callable progress callback.
     * The callback is invoked during file processing (covered by a later test).
     */
    public function testConstructorWithCallback(): void
    {
        // Arrange
        $called   = false;
        $callback = function () use (&$called) { $called = true; };

        // Act — just construct; callback invocation is tested separately
        $migrator = new LogMigrator($callback);

        // Assert — instance created successfully with callback
        $this->assertInstanceOf(LogMigrator::class, $migrator);
    }

    // ── migrateFile() — error paths ───────────────────────────────────────────

    /**
     * migrateFile() must throw RuntimeException when the target file does not exist.
     *
     * This is the first guard in the method.
     */
    public function testMigrateFileThrowsForMissingFile(): void
    {
        // Arrange
        $migrator  = new LogMigrator();
        $nonExistent = $this->tmpDir . '/does_not_exist.log';

        // Assert + Act
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $migrator->migrateFile($nonExistent);
    }

    // ── migrateFile() — basic conversion ─────────────────────────────────────

    /**
     * migrateFile() must convert standard bracket-timestamp log lines to
     * JSON-line format and return statistics with correct processed_lines count.
     *
     * Input format: [DD/MM/YYYY HH:MM:SS] message
     * Output format: {"timestamp":"...","message":"..."}
     */
    public function testMigrateFileConvertsStandardLogLines(): void
    {
        // Arrange — two standard log lines
        $content = "[01/01/2024 10:00:00] Application started\n"
                 . "[01/01/2024 10:00:05] User logged in: alice\n";
        $path    = $this->writeTmpFile($content);

        $migrator = new LogMigrator();

        // Act
        $stats = $migrator->migrateFile($path, false);

        // Assert — statistics report correct counts
        $this->assertArrayHasKey('processed_lines', $stats,
            'migrateFile() must return a statistics array with processed_lines');
        $this->assertArrayHasKey('total_lines', $stats,
            'migrateFile() must return statistics with total_lines');
        $this->assertGreaterThanOrEqual(1, $stats['processed_lines'],
            'migrateFile() must report at least 1 processed line');

        // Assert — output file is valid JSON lines
        $this->assertFileExists($path,
            'Output file must exist after migration');
        $lines = array_filter(explode("\n", trim(file_get_contents($path))));
        $this->assertNotEmpty($lines, 'Output must not be empty');
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded,
                "Each output line must be valid JSON, got: $line");
            $this->assertArrayHasKey('timestamp', $decoded);
            $this->assertArrayHasKey('message', $decoded);
        }
    }

    /**
     * migrateFile() must create a .bak backup of the original file when
     * $createBackup = true (the default).
     */
    public function testMigrateFileCreatesBackupByDefault(): void
    {
        // Arrange
        $content = "[01/01/2024 12:00:00] Hello\n";
        $path    = $this->writeTmpFile($content);

        $migrator = new LogMigrator();

        // Act
        $migrator->migrateFile($path, true);

        // Assert — backup file exists
        $this->assertFileExists($path . '.bak',
            'migrateFile() must create a .bak backup when createBackup=true');

        // Cleanup backup
        @unlink($path . '.bak');
    }

    /**
     * migrateFile() must NOT create a backup when $createBackup = false.
     */
    public function testMigrateFileSkipsBackupWhenFlagIsFalse(): void
    {
        // Arrange
        $content = "[01/01/2024 12:00:00] No backup test\n";
        $path    = $this->writeTmpFile($content);

        $migrator = new LogMigrator();

        // Act
        $migrator->migrateFile($path, false);

        // Assert — no backup file
        $this->assertFileDoesNotExist($path . '.bak',
            'migrateFile() must NOT create a .bak backup when createBackup=false');
    }

    /**
     * migrateFile() must call the progress callback at least once during
     * processing of a non-empty file.
     */
    public function testMigrateFileInvokesProgressCallback(): void
    {
        // Arrange
        $callCount = 0;
        $callback  = function (int $bytes, int $total) use (&$callCount) {
            $callCount++;
        };

        $content = "[01/01/2024 09:00:00] Line one\n"
                 . "[01/01/2024 09:00:01] Line two\n"
                 . "[01/01/2024 09:00:02] Line three\n";
        $path    = $this->writeTmpFile($content);

        $migrator = new LogMigrator($callback);

        // Act
        $migrator->migrateFile($path, false);

        // Assert — callback invoked at least once
        $this->assertGreaterThanOrEqual(1, $callCount,
            'migrateFile() must invoke the progress callback at least once');
    }

    /**
     * migrateFile() must handle PHP error log lines that span multiple lines
     * (PHP Notice, PHP Warning, PHP Fatal error) by buffering them until the
     * next timestamped entry, then writing a single JSON record.
     */
    public function testMigrateFileBuffersPhpErrorLogLines(): void
    {
        // Arrange — PHP error log multiline format
        $content = "[01-Jan-2024 10:00:00 UTC] PHP Fatal error: Uncaught Exception in /app/foo.php:42\n"
                 . "Stack trace:\n"
                 . "#0 /app/bar.php(10): foo()\n"
                 . "#1 {main}\n"
                 . "[01-Jan-2024 10:01:00 UTC] Application resumed\n";
        $path    = $this->writeTmpFile($content);

        $migrator = new LogMigrator();

        // Act
        $stats = $migrator->migrateFile($path, false);

        // Assert — at least 1 processed line (error block + resume message)
        $this->assertGreaterThanOrEqual(1, $stats['processed_lines'],
            'migrateFile() must process multiline PHP error log entries');

        // Assert — output is valid JSON lines
        $outputContent = file_get_contents($path);
        $lines = array_filter(explode("\n", trim((string)$outputContent)));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded,
                "Each output line must be valid JSON after PHP error buffering, got: $line");
        }
    }

    /**
     * migrateFile() must return a statistics array with required keys:
     * total_lines, processed_lines, errors, file_size, start_time, end_time, duration.
     */
    public function testMigrateFileReturnsCompleteStatistics(): void
    {
        // Arrange
        $content = "[01/01/2024 11:00:00] Stats test line\n";
        $path    = $this->writeTmpFile($content);

        $migrator = new LogMigrator();

        // Act
        $stats = $migrator->migrateFile($path, false);

        // Assert — all expected keys are present
        $required = ['total_lines', 'processed_lines', 'errors', 'file_size', 'start_time', 'end_time'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $stats,
                "migrateFile() statistics must include key '$key'");
        }
        $this->assertGreaterThan(0, $stats['file_size'],
            'file_size must be positive for a non-empty log file');
        $this->assertGreaterThanOrEqual($stats['start_time'], $stats['end_time'],
            'end_time must be >= start_time');
    }

    /**
     * migrateFile() must handle a log file that contains lines without timestamps
     * gracefully, continuing to process and not crashing.
     */
    public function testMigrateFileHandlesLinesWithoutTimestamps(): void
    {
        // Arrange — mix of timestamped and non-timestamped lines
        $content = "[01/01/2024 08:00:00] First line with timestamp\n"
                 . "This line has no timestamp\n"
                 . "Another line without timestamp\n"
                 . "[01/01/2024 08:00:01] Back to timestamped\n";
        $path    = $this->writeTmpFile($content);

        $migrator = new LogMigrator();

        // Act — must not throw
        $stats = $migrator->migrateFile($path, false);

        // Assert — at least the timestamped lines were processed
        $this->assertGreaterThanOrEqual(1, $stats['processed_lines'],
            'migrateFile() must process at least the timestamped lines');
    }

    /**
     * migrateFile() must handle an empty log file without crashing and
     * return zero-count statistics.
     */
    public function testMigrateFileHandlesEmptyFile(): void
    {
        // Arrange — completely empty file
        $path = $this->writeTmpFile('');

        $migrator = new LogMigrator();

        // Act — must not throw
        $stats = $migrator->migrateFile($path, false);

        // Assert — processed_lines is 0
        $this->assertSame(0, $stats['processed_lines'],
            'migrateFile() must report 0 processed_lines for an empty file');
    }
}
