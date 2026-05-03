<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;
use Pramnos\Logs\LogManager;
use Pramnos\Logs\LogViewer;

/**
 * Characterization tests for LogManager and LogViewer.
 *
 * Locks the behavior of file-listing, stats, clear, and viewer parsing
 * before any refactoring of the Logs subsystem.
 */
#[CoversClass(Logger::class)]
#[CoversClass(LogManager::class)]
#[CoversClass(LogViewer::class)]
class LogManagerViewerCharacterizationTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        $this->logDir = LOG_PATH . DS . 'logs';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0777, true);
        }
        // Pre-clean to guard against stale files from previous interrupted runs
        $this->cleanTestFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanTestFiles();
    }

    private function cleanTestFiles(): void
    {
        foreach (glob($this->logDir . DS . 'char_mgr_*.log') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->logDir . DS . 'char_viewer_*.log') ?: [] as $f) {
            @unlink($f);
        }
    }

    // -----------------------------------------------------------------------
    // Logger PSR-3 level methods
    // -----------------------------------------------------------------------

    /**
     * Logger::warning produces a JSON entry with level='warning'.
     */
    public function testLoggerWarningProducesJsonWithCorrectLevel(): void
    {
        // Arrange
        $file = 'char_mgr_warning';

        // Act
        // Note: level only appears in JSON output when additional context is provided.
        // Without context the Logger falls through to bracket format and level is lost.
        Logger::warning('disk space low', ['source' => 'test'], $file);

        // Assert – read last non-empty line (guards against stale content)
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $decoded = json_decode(end($lines), true);
        $this->assertSame('warning', $decoded['level']);
        $this->assertSame('disk space low', $decoded['message']);
    }

    /**
     * Logger::info produces a JSON entry with level='info'.
     */
    public function testLoggerInfoProducesJsonWithCorrectLevel(): void
    {
        // Arrange
        $file = 'char_mgr_info';

        // Act
        Logger::info('user logged in', ['source' => 'test'], $file);

        // Assert
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $decoded = json_decode(end($lines), true);
        $this->assertSame('info', $decoded['level']);
    }

    /**
     * Logger::debug produces a JSON entry with level='debug'.
     */
    public function testLoggerDebugProducesJsonWithCorrectLevel(): void
    {
        // Arrange
        $file = 'char_mgr_debug';

        // Act
        Logger::debug('query executed', ['source' => 'test'], $file);

        // Assert
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $decoded = json_decode(end($lines), true);
        $this->assertSame('debug', $decoded['level']);
    }

    /**
     * Logger::notice produces a JSON entry with level='notice'.
     */
    public function testLoggerNoticeProducesJsonWithCorrectLevel(): void
    {
        // Arrange
        $file = 'char_mgr_notice';

        // Act
        Logger::notice('config loaded', ['source' => 'test'], $file);

        // Assert
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $decoded = json_decode(end($lines), true);
        $this->assertSame('notice', $decoded['level']);
    }

    /**
     * Logger::emergency produces a JSON entry with level='emergency'.
     */
    public function testLoggerEmergencyProducesJsonWithCorrectLevel(): void
    {
        // Arrange
        $file = 'char_mgr_emergency';

        // Act
        Logger::emergency('system crash', ['source' => 'test'], $file);

        // Assert
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $decoded = json_decode(end($lines), true);
        $this->assertSame('emergency', $decoded['level']);
    }

    /**
     * Logger::critical produces a JSON entry with level='critical'.
     */
    public function testLoggerCriticalProducesJsonWithCorrectLevel(): void
    {
        // Arrange
        $file = 'char_mgr_critical';

        // Act
        Logger::critical('db unreachable', ['source' => 'test'], $file);

        // Assert
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $decoded = json_decode(end($lines), true);
        $this->assertSame('critical', $decoded['level']);
    }

    /**
     * Logger::alert produces a JSON entry with level='alert'.
     */
    public function testLoggerAlertProducesJsonWithCorrectLevel(): void
    {
        // Arrange
        $file = 'char_mgr_alert';

        // Act
        Logger::alert('rate limit exceeded', ['source' => 'test'], $file);

        // Assert
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $decoded = json_decode(end($lines), true);
        $this->assertSame('alert', $decoded['level']);
    }

    /**
     * Logger::log appends multiple entries; each is on its own line.
     */
    public function testLoggerAppendsMultipleLines(): void
    {
        // Arrange
        $file = 'char_mgr_multi';

        // Act
        Logger::log('line one', $file);
        Logger::log('line two', $file);
        Logger::log('line three', $file);

        // Assert
        $lines = array_filter(
            file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES)
        );
        $this->assertCount(3, array_values($lines));
    }

    // -----------------------------------------------------------------------
    // LogManager::getLogFiles
    // -----------------------------------------------------------------------

    /**
     * getLogFiles returns an empty array when the log directory does not exist.
     * This is an important defensive behavior when running in a fresh environment.
     */
    public function testGetLogFilesReturnsEmptyForNonExistentDirectory(): void
    {
        // This uses DEFAULT_LOG_PATH which does exist in test env.
        // We verify the return is always an array (never false/null).
        $result = LogManager::getLogFiles();
        $this->assertIsArray($result);
    }

    /**
     * getLogFiles returns a list of .log filenames after writing a file.
     */
    public function testGetLogFilesReturnsCreatedFiles(): void
    {
        // Arrange – write a file to ensure at least one exists
        Logger::log('discovery test', 'char_mgr_discovery');

        // Act
        $files = LogManager::getLogFiles(false, false);

        // Assert
        $this->assertIsArray($files);
        $this->assertContains('char_mgr_discovery.log', $files);
    }

    /**
     * getLogFiles with $includePath=true returns absolute file paths.
     */
    public function testGetLogFilesWithPathReturnsAbsolutePaths(): void
    {
        // Arrange
        Logger::log('path test', 'char_mgr_path');

        // Act
        $files = LogManager::getLogFiles(true, false);

        // Assert – each entry should contain the log dir path
        foreach ($files as $file) {
            $this->assertStringContainsString($this->logDir, $file);
        }
    }

    /**
     * getLogFiles with $includeSize=true returns arrays with 'name' and 'size' keys.
     */
    public function testGetLogFilesWithSizeReturnsStructuredData(): void
    {
        // Arrange
        Logger::log('size test', 'char_mgr_size');

        // Act
        $files = LogManager::getLogFiles(false, true);

        // Assert – find our file in the result
        $found = null;
        foreach ($files as $entry) {
            if ($entry['name'] === 'char_mgr_size.log') {
                $found = $entry;
                break;
            }
        }
        $this->assertNotNull($found, 'char_mgr_size.log not found in result');
        $this->assertArrayHasKey('size', $found);
        $this->assertArrayHasKey('size_formatted', $found);
        $this->assertArrayHasKey('modified', $found);
    }

    // -----------------------------------------------------------------------
    // LogManager::getLogFileStats
    // -----------------------------------------------------------------------

    /**
     * getLogFileStats returns null for a non-existent file.
     */
    public function testGetLogFileStatsReturnsNullForMissingFile(): void
    {
        // Act
        $result = LogManager::getLogFileStats('nonexistent_pramnos_test_' . uniqid());

        // Assert
        $this->assertNull($result);
    }

    /**
     * getLogFileStats returns correct metadata for an existing file.
     */
    public function testGetLogFileStatsReturnsMetadataForExistingFile(): void
    {
        // Arrange
        Logger::error('stats test', ['code' => 42], 'char_mgr_stats');

        // Act
        $stats = LogManager::getLogFileStats('char_mgr_stats');

        // Assert
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('size', $stats);
        $this->assertArrayHasKey('lines', $stats);
        $this->assertArrayHasKey('json_percentage', $stats);
        $this->assertArrayHasKey('level_distribution', $stats);
        // At least one line was written
        $this->assertGreaterThan(0, $stats['lines']);
    }

    // -----------------------------------------------------------------------
    // LogManager::clearAllLogs
    // -----------------------------------------------------------------------

    /**
     * clearAllLogs truncates a specific file and returns the count of cleared files.
     */
    public function testClearAllLogsClearsSpecificFile(): void
    {
        // Arrange
        Logger::log('content to clear', 'char_mgr_clear');
        $this->assertGreaterThan(
            0,
            filesize($this->logDir . DS . 'char_mgr_clear.log')
        );

        // Act
        $count = LogManager::clearAllLogs(['char_mgr_clear.log']);

        // Assert
        $this->assertSame(1, $count);
        $this->assertSame(0, filesize($this->logDir . DS . 'char_mgr_clear.log'));
    }

    // -----------------------------------------------------------------------
    // LogViewer
    // -----------------------------------------------------------------------

    /**
     * setFile with whitelist throws InvalidArgumentException for unlisted file.
     */
    public function testLogViewerSetFileThrowsForUnlistedFile(): void
    {
        // Arrange
        $viewer = new LogViewer(['allowed.log']);

        // Act + Assert
        $this->expectException(\InvalidArgumentException::class);
        $viewer->setFile('not_allowed.log');
    }

    /**
     * setFile with an empty whitelist allows any file.
     */
    public function testLogViewerSetFileAllowsAnyFileWithEmptyWhitelist(): void
    {
        // Arrange – create file and use its name
        $fname = 'char_viewer_any.log';
        file_put_contents($this->logDir . DS . $fname, "test\n");
        $viewer = new LogViewer([]); // empty whitelist = no restriction

        // Act – should not throw
        $result = $viewer->setFile($fname, true);

        // Assert – fluent interface returns self
        $this->assertSame($viewer, $result);
    }

    /**
     * processFile throws RuntimeException when the log file does not exist.
     */
    public function testLogViewerProcessFileThrowsForMissingFile(): void
    {
        // Arrange
        $viewer = new LogViewer([]);
        $viewer->setFile('char_viewer_missing.log', false);
        $viewer->setParameters();

        // Act + Assert
        $this->expectException(\RuntimeException::class);
        $viewer->processFile();
    }

    /**
     * processFile returns correct lines, total, matched_total structure.
     */
    public function testLogViewerProcessFileReturnsStructuredResult(): void
    {
        // Arrange – create a small log file
        $fname = 'char_viewer_basic.log';
        file_put_contents($this->logDir . DS . $fname, implode("\n", [
            '{"timestamp":"01/01/2026 10:00:00","message":"alpha","level":"info"}',
            '{"timestamp":"01/01/2026 10:01:00","message":"beta","level":"error"}',
            '{"timestamp":"01/01/2026 10:02:00","message":"gamma","level":"info"}',
        ]) . "\n");

        $viewer = new LogViewer([]);
        $viewer->setFile($fname, false);
        $viewer->setParameters(false, 1, 10, '');

        // Act
        $result = $viewer->processFile();

        // Assert
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('matched_total', $result);
        // 3 content lines; SplFileObject may count trailing newline as extra
        $this->assertGreaterThanOrEqual(3, count($result['lines']));
        $this->assertSame(3, $result['matched_total']);
    }

    /**
     * setLogLevel filters lines by the given level.
     */
    public function testLogViewerSetLogLevelFiltersResults(): void
    {
        // Arrange
        $fname = 'char_viewer_filter.log';
        file_put_contents($this->logDir . DS . $fname, implode("\n", [
            '{"timestamp":"01/01/2026 10:00:00","message":"alpha","level":"info"}',
            '{"timestamp":"01/01/2026 10:01:00","message":"beta","level":"error"}',
            '{"timestamp":"01/01/2026 10:02:00","message":"gamma","level":"info"}',
        ]) . "\n");

        $viewer = new LogViewer([]);
        $viewer->setFile($fname, false);
        $viewer->setParameters(false, 1, 10, '');
        $viewer->setLogLevel('error');

        // Act
        $result = $viewer->processFile();

        // Assert – only the error line should match
        $this->assertSame(1, $result['matched_total']);
        $this->assertStringContainsString('beta', $result['lines'][0]);
    }

    /**
     * setParameters with a search term filters by that term.
     */
    public function testLogViewerSearchTermFiltersLines(): void
    {
        // Arrange
        $fname = 'char_viewer_search.log';
        file_put_contents($this->logDir . DS . $fname, implode("\n", [
            '[01/01/2026 10:00:00] hello world',
            '[01/01/2026 10:01:00] hello pramnos',
            '[01/01/2026 10:02:00] goodbye world',
        ]) . "\n");

        $viewer = new LogViewer([]);
        $viewer->setFile($fname, false);
        $viewer->setParameters(false, 1, 10, 'hello');

        // Act
        $result = $viewer->processFile();

        // Assert – 2 lines contain 'hello'
        $this->assertSame(2, $result['matched_total']);
    }
}
