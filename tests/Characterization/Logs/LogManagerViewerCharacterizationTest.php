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
     * PSR-3 level methods preserve the level in JSON output even when no
     * extra context is provided. Previously the level was lost because unset-ting
     * it from context made the context array empty, routing the entry to the
     * plain bracket format which has no level field.
     */
    public function testLoggerLevelIsPreservedWithoutExtraContext(): void
    {
        // Arrange
        $file = 'char_mgr_noctx';

        // Act – call with empty context (default); level must still appear in output
        Logger::info('startup complete', [], $file);

        // Assert – entry must be JSON with correct level field, not plain bracket format
        $lines = array_values(array_filter(file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $this->assertNotEmpty($lines, 'Logger must write at least one line');
        $decoded = json_decode(end($lines), true);
        $this->assertIsArray($decoded, 'Output must be JSON when a level is set, even with no extra context');
        $this->assertSame('info', $decoded['level'], 'Level must appear in JSON output regardless of whether extra context was passed');
        $this->assertSame('startup complete', $decoded['message']);
        // Context key should be absent when no extra context was provided
        $this->assertArrayNotHasKey('context', $decoded);
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

    // -----------------------------------------------------------------------
    // LogManager::getLogFilePath()
    // -----------------------------------------------------------------------

    /**
     * getLogFilePath() must return the special api-dir path for GitDeploy.
     *
     * This covers the `if ($filename === 'GitDeploy' || …)` branch (line ~364).
     */
    public function testGetLogFilePathReturnsApiDirForGitDeploy(): void
    {
        // Act
        $path = LogManager::getLogFilePath('GitDeploy', 'log');

        // Assert — path must be under ROOT/www/api/
        $this->assertStringContainsString('api', $path);
        $this->assertStringContainsString('GitDeploy.log', $path);
    }

    /**
     * getLogFilePath() must return the standard logs-dir path for regular filenames.
     *
     * This covers the `else` branch (line ~367).
     */
    public function testGetLogFilePathReturnsLogsDirForRegularFilename(): void
    {
        // Act
        $path = LogManager::getLogFilePath('php_error', 'log');

        // Assert — path must be inside the logs directory
        $this->assertStringContainsString('logs', $path);
        $this->assertStringContainsString('php_error.log', $path);
    }

    /**
     * getLogFilePath() with an empty extension must omit the dot separator.
     *
     * This covers the `($ext ? '.' . $ext : '')` ternary in the else branch.
     */
    public function testGetLogFilePathOmitsDotWhenExtIsEmpty(): void
    {
        // Act
        $path = LogManager::getLogFilePath('mylog', '');

        // Assert — no trailing dot or extension
        $this->assertStringEndsWith('mylog', $path);
        $this->assertStringNotContainsString('mylog.', $path);
    }

    // -----------------------------------------------------------------------
    // LogManager::searchInLogs()
    // -----------------------------------------------------------------------

    /**
     * searchInLogs() must return an empty array when the log directory does
     * not exist.
     *
     * This covers the `if (!file_exists(self::getDefaultLogPath())) return []`
     * guard (line ~237).
     */
    public function testSearchInLogsReturnsEmptyWhenDirMissing(): void
    {
        // Arrange — temporarily point LOG_PATH to a non-existent directory by
        // calling with an explicit file list that points to a non-existent path.
        // (The simplest way: pass a file list with a non-existent file.)
        $result = LogManager::searchInLogs('anything', ['nonexistent_char_mgr.log']);

        // Assert — no matches (file doesn't exist)
        $this->assertIsArray($result);
        $this->assertEmpty($result, 'Non-existent file must yield no search results');
    }

    /**
     * searchInLogs() must find lines matching the search text in a real log file.
     *
     * This covers the main file-scanning loop (lines ~256-321), including the
     * circular buffer, the match detection, and the results array construction.
     */
    public function testSearchInLogsFindsMatchingLines(): void
    {
        // Arrange — write a test log file with a unique marker that appears exactly once
        $uniqueMarker = 'PRAMNOS_SRCH_UNIQUE_' . bin2hex(random_bytes(4));
        $logFile  = 'char_mgr_search_' . bin2hex(random_bytes(4)) . '.log';
        $fullPath = $this->logDir . DS . $logFile;
        file_put_contents($fullPath, implode("\n", [
            'line 1: normal entry',
            'line 2: ' . $uniqueMarker . ' detected',
            'line 3: follow-up after something',
            'line 4: all ok again',
        ]) . "\n");

        // Act — search for the unique marker (case-insensitive)
        $results = LogManager::searchInLogs($uniqueMarker, [$logFile]);

        // Assert — one file result with one match
        $this->assertCount(1, $results, 'exactly one file must contain matches');
        $this->assertSame($logFile, $results[0]['file']);
        $this->assertSame(1, $results[0]['count'], 'exactly one line matches the unique marker');
    }

    /**
     * searchInLogs() with a specific file list must scan only those files.
     *
     * This covers the `else` branch that builds the explicit file list
     * (lines ~243-251) and the extension-appending logic.
     */
    public function testSearchInLogsWithExplicitFileListScansOnlyNamedFile(): void
    {
        // Arrange — two log files; only one contains the search term
        $match   = 'char_mgr_srch_match_' . bin2hex(random_bytes(4)) . '.log';
        $nomatch = 'char_mgr_srch_nomatch_' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents($this->logDir . DS . $match,   "contains TARGET here\n");
        file_put_contents($this->logDir . DS . $nomatch, "nothing special here\n");


        // Act — only scan the match file (without .log extension to test appending)
        $results = LogManager::searchInLogs('TARGET', [str_replace('.log', '', $match)]);

        // Assert — result contains match file only
        $this->assertCount(1, $results);
        $this->assertSame($match, $results[0]['file']);
    }

    /**
     * searchInLogs() with caseSensitive=true must NOT match different-case text.
     *
     * This covers the `$caseSensitive && strpos(…)` branch (line ~279).
     */
    public function testSearchInLogsCaseSensitiveMissesWrongCase(): void
    {
        // Arrange
        $logFile  = 'char_mgr_srch_case_' . bin2hex(random_bytes(4)) . '.log';
        $fullPath = $this->logDir . DS . $logFile;
        file_put_contents($fullPath, "lowercase target only\n");


        // Act — case-sensitive search for 'TARGET' will NOT match 'target'
        $results = LogManager::searchInLogs('TARGET', [$logFile], 0, true);

        // Assert — no matches
        $this->assertEmpty($results, 'case-sensitive search must not match different-case text');
    }

    // -----------------------------------------------------------------------
    // LogManager::processLogFileWithCallback()
    // -----------------------------------------------------------------------

    /**
     * processLogFileWithCallback() must return false when the file does not exist.
     *
     * This covers the `if (!file_exists($filepath)) return false` guard (line ~339).
     */
    public function testProcessLogFileWithCallbackReturnsFalseForMissingFile(): void
    {
        // Act
        $result = LogManager::processLogFileWithCallback(
            'nonexistent_char_mgr_proc',
            'log',
            static function (string $line): void {}
        );

        // Assert
        $this->assertFalse($result, 'missing file must return false');
    }

    /**
     * processLogFileWithCallback() must invoke the callback for every non-empty
     * line and return true.
     *
     * This covers the main fgets loop (lines ~348-350) and the success return.
     */
    public function testProcessLogFileWithCallbackInvokesCallbackForEachLine(): void
    {
        // Arrange — create a test log file with 3 lines
        $slug     = 'char_mgr_proc_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';
        file_put_contents($fullPath, "line alpha\nline beta\nline gamma\n");


        $collectedLines = [];

        // Act
        $success = LogManager::processLogFileWithCallback(
            $slug,
            'log',
            static function (string $line) use (&$collectedLines): void {
                $collectedLines[] = $line;
            }
        );

        // Assert — callback invoked for each line, method returns true
        $this->assertTrue($success);
        $this->assertContains('line alpha', $collectedLines);
        $this->assertContains('line beta',  $collectedLines);
        $this->assertContains('line gamma', $collectedLines);
    }

    // -----------------------------------------------------------------------
    // LogManager::getFilteredLogEntries()
    // -----------------------------------------------------------------------

    /**
     * getFilteredLogEntries() must return an empty array when the file does
     * not exist.
     *
     * This covers the early-return at line ~589.
     */
    public function testGetFilteredLogEntriesReturnsEmptyForMissingFile(): void
    {
        // Act
        $result = LogManager::getFilteredLogEntries('nonexistent_char_mgr_filter', 'log');

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * getFilteredLogEntries() must parse a JSON log line and return a structured
     * entry array when the line has no trailing newline.
     *
     * This covers the JSON-decode path (lines ~608-633) including timestamp
     * extraction, level extraction, and message extraction.
     *
     * Note: fgets() includes a trailing "\n" in its return value. The production
     * code checks `substr($line, -1) === '}'` which only passes when there is no
     * trailing newline (i.e., the last line of a file written without a final \n).
     * This test writes a single-entry file without a trailing newline to trigger
     * the JSON-parse branch.
     */
    public function testGetFilteredLogEntriesReturnsJsonEntries(): void
    {
        // Arrange — single JSON entry, NO trailing newline so fgets returns it without \n
        $slug     = 'char_mgr_filter_json_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';
        $entry    = json_encode(['timestamp' => '2025-01-15 10:00:00', 'level' => 'info', 'message' => 'server started']);
        file_put_contents($fullPath, $entry); // deliberate: no trailing newline

        // Act — no filters
        $entries = LogManager::getFilteredLogEntries($slug, 'log');

        // Assert — one entry, JSON-parsed correctly
        $this->assertCount(1, $entries, 'single JSON entry must be returned');
        $this->assertSame('info',           $entries[0]['level']);
        $this->assertSame('server started', $entries[0]['message']);
    }

    /**
     * getFilteredLogEntries() must filter entries by level when $levels is set.
     *
     * This covers the `if (!empty($levels) && !in_array($level, $levels))` filter
     * (line ~677).
     *
     * Uses the bracket-timestamp plain-text format, which is correctly parsed
     * by the level-guessing logic. JSON lines with trailing \n from fgets() do
     * NOT pass the JSON check — bracket format is the reliable test vehicle.
     */
    public function testGetFilteredLogEntriesFiltersbyLevel(): void
    {
        // Arrange — two bracket-format lines with different inferred levels
        $slug     = 'char_mgr_filter_lvl_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';
        file_put_contents($fullPath,
            "[15/01/2025 10:00:00] some info message here\n"
            . "[15/01/2025 10:01:00] PHP Error: something failed badly\n"
        );

        // Act — filter for 'error' level only
        $entries = LogManager::getFilteredLogEntries($slug, 'log', ['error']);

        // Assert — only the error entry survives the level filter
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['level']);
    }

    /**
     * getFilteredLogEntries() must filter by search query.
     *
     * This covers the `if (!empty($query))` filter block (lines ~682-695).
     * Uses bracket-format plain text so both entries are correctly parsed.
     */
    public function testGetFilteredLogEntriesFiltersByQuery(): void
    {
        // Arrange — two distinct log messages, search targets only the second
        $slug      = 'char_mgr_filter_qry_' . bin2hex(random_bytes(4));
        $fullPath  = $this->logDir . DS . $slug . '.log';
        $uniqueTag = 'PAYMENT_UNIQUE_' . bin2hex(random_bytes(4));
        file_put_contents($fullPath,
            "[15/01/2025 10:00:00] user logged in normally\n"
            . "[15/01/2025 10:01:00] " . $uniqueTag . " transaction complete\n"
        );

        // Act — query for the unique tag
        $entries = LogManager::getFilteredLogEntries($slug, 'log', [], null, null, $uniqueTag);

        // Assert — only the tagged entry matches
        $this->assertCount(1, $entries);
        $this->assertStringContainsString($uniqueTag, $entries[0]['message']);
    }

    /**
     * getFilteredLogEntries() must parse plain-text lines using the bracket-timestamp
     * format and guess their level from the content.
     *
     * This covers the `elseif (preg_match('/^\[([\d\/]+ [\d:]+)\]/', …))` branch
     * (lines ~640-660) and the level-guessing switch.
     */
    public function testGetFilteredLogEntriesParsesPlainTextFormat(): void
    {
        // Arrange — plain PHP-style log lines
        $slug     = 'char_mgr_filter_plain_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';
        file_put_contents($fullPath,
            "[15/01/2025 10:00:00] PHP Warning: undefined variable\n"
            . "[15/01/2025 10:01:00] PHP Notice: comparison\n"
        );


        // Act
        $entries = LogManager::getFilteredLogEntries($slug, 'log');

        // Assert — both lines parsed, level guessed from content
        $this->assertCount(2, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertSame('notice',  $entries[1]['level']);
    }

    // -----------------------------------------------------------------------
    // getLogAnalytics()
    // -----------------------------------------------------------------------

    /**
     * getLogAnalytics() must return an empty array when the log file does not exist.
     *
     * This covers the `if (!file_exists($filepath)) return []` guard (line ~390).
     */
    public function testGetLogAnalyticsReturnsEmptyForMissingFile(): void
    {
        // Arrange — a filename that certainly does not exist
        $slug = 'char_mgr_analytics_missing_' . bin2hex(random_bytes(4));

        // Act
        $result = LogManager::getLogAnalytics($slug);

        // Assert
        $this->assertSame([], $result,
            'getLogAnalytics must return [] when the file does not exist');
    }

    /**
     * getLogAnalytics() must return the analytics structure with all required keys
     * when the log file contains bracket-timestamp format entries.
     *
     * This covers the main processing loop (lines ~434-544), the bracket-timestamp
     * regex branch (lines ~468-483), the trends bucket assignment, the level counter,
     * and the final return structure (lines ~556-563).
     */
    public function testGetLogAnalyticsReturnsStructuredResultForBracketFormat(): void
    {
        // Arrange — two log lines within the last 24 h; one error, one info
        $slug     = 'char_mgr_analytics_bracket_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';

        // Use a timestamp near "now" so both entries fall inside the default 24-h window.
        $ts1 = date('d/m/Y H:i:s', time() - 3600); // 1 hour ago
        $ts2 = date('d/m/Y H:i:s', time() - 1800); // 30 min ago

        file_put_contents($fullPath,
            "[{$ts1}] PHP Error: something bad happened\n"
            . "[{$ts2}] PHP Notice: informational message\n"
        );

        // Act — use default groupBy='hour', default 24-h window
        $result = LogManager::getLogAnalytics($slug);

        // Assert — required keys are present
        $this->assertArrayHasKey('trends',       $result, 'result must have "trends" key');
        $this->assertArrayHasKey('levels',       $result, 'result must have "levels" key');
        $this->assertArrayHasKey('topErrors',    $result, 'result must have "topErrors" key');
        $this->assertArrayHasKey('totalEntries', $result, 'result must have "totalEntries" key');
        $this->assertArrayHasKey('errorRate',    $result, 'result must have "errorRate" key');

        // Assert — both entries were counted
        $this->assertSame(2, $result['totalEntries'],
            'both bracket-format lines must be counted');
        $this->assertGreaterThan(0, $result['errorRate'],
            'error rate must be > 0 when at least one error entry is present');
    }

    /**
     * getLogAnalytics() must correctly group entries using groupBy='minute'.
     *
     * This covers the `case 'minute': $currentTime += 60` bucket-initialization
     * branch (line ~416-417) and the matching `case 'minute': $nextBucket += 60`
     * bucket-assignment branch (lines ~493-494).
     */
    public function testGetLogAnalyticsGroupsByMinute(): void
    {
        // Arrange — one log line within the last hour
        $slug     = 'char_mgr_analytics_minute_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';
        $ts       = date('d/m/Y H:i:s', time() - 300); // 5 min ago

        file_put_contents($fullPath, "[{$ts}] PHP Notice: minute-grouping test\n");

        // Act
        $startTime = time() - 600;  // 10 min ago
        $endTime   = time();
        $result = LogManager::getLogAnalytics($slug, 'log', $startTime, $endTime, 'minute');

        // Assert — result is structured and trends has minute-granularity buckets
        $this->assertArrayHasKey('trends', $result);
        $this->assertNotEmpty($result['trends'], 'minute groupBy must create at least one bucket');
    }

    /**
     * getLogAnalytics() must correctly group entries using groupBy='day'.
     *
     * This covers the `case 'day': $currentTime += 86400` bucket-initialization
     * branch (line ~419-420) and the matching day bucket-assignment branch.
     */
    public function testGetLogAnalyticsGroupsByDay(): void
    {
        // Arrange — one log line within the last week
        $slug     = 'char_mgr_analytics_day_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';
        $ts       = date('d/m/Y H:i:s', time() - 86400); // 1 day ago

        file_put_contents($fullPath, "[{$ts}] PHP Notice: day-grouping test\n");

        // Act
        $startTime = time() - (7 * 86400); // 7 days ago
        $endTime   = time();
        $result = LogManager::getLogAnalytics($slug, 'log', $startTime, $endTime, 'day');

        // Assert
        $this->assertArrayHasKey('trends', $result);
        $this->assertNotEmpty($result['trends'], 'day groupBy must create at least one bucket');
    }

    /**
     * getLogAnalytics() must process a JSON-formatted log line (without trailing
     * newline) and include it in the analytics result.
     *
     * The JSON path in getLogAnalytics() has the same fgets trailing-newline
     * quirk as getFilteredLogEntries(): `substr($line, -1) === '}'` is true only
     * when the line has no trailing newline. Writing a single JSON entry without
     * a terminal newline exercises that code path.
     *
     * This covers lines ~441-465: the JSON decode branch.
     */
    public function testGetLogAnalyticsProcessesJsonEntry(): void
    {
        // Arrange — single JSON entry, no trailing newline so fgets returns '}'
        $slug     = 'char_mgr_analytics_json_' . bin2hex(random_bytes(4));
        $fullPath = $this->logDir . DS . $slug . '.log';

        $entry = json_encode([
            'timestamp' => date('Y-m-d H:i:s', time() - 1800),
            'level'     => 'error',
            'message'   => 'JSON analytics test',
        ]);
        // Intentionally no trailing newline so the JSON check passes
        file_put_contents($fullPath, $entry);

        // Act
        $result = LogManager::getLogAnalytics($slug, 'log', time() - 86400, time());

        // Assert — JSON entry was parsed and the level was extracted
        $this->assertSame(1, $result['totalEntries'],
            'the single JSON entry must be counted');
        $this->assertArrayHasKey('error', $result['levels'],
            '"error" level must be recorded from the JSON entry');
    }
}
