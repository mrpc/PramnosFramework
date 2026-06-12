<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pramnos\Logs\LogManager;
use Pramnos\Framework\Factory;

#[CoversClass(LogManager::class)]
class LogManagerTest extends TestCase
{
    private string $testLogDir;
    private array $createdFiles = [];

    protected function setUp(): void
    {
        // Get the default log directory
        $rp = new \ReflectionMethod(LogManager::class, 'getDefaultLogPath');
        $this->testLogDir = $rp->invoke(null);

        if (!file_exists($this->testLogDir)) {
            mkdir($this->testLogDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up created files
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        // Clean up archives directory if created
        $archivePath = $this->testLogDir . DIRECTORY_SEPARATOR . 'archives';
        if (file_exists($archivePath)) {
            foreach (glob($archivePath . DIRECTORY_SEPARATOR . '*') as $archivedFile) {
                @unlink($archivedFile);
            }
            @rmdir($archivePath);
        }
    }

    private function createTestLog(string $name, string $content, ?int $mtime = null): string
    {
        $filePath = LogManager::getLogFilePath($name, 'log');
        file_put_contents($filePath, $content);
        if ($mtime !== null) {
            touch($filePath, $mtime);
        }
        $this->createdFiles[] = $filePath;
        return $filePath;
    }

    #[Test]
    public function testGetLogFiles(): void
    {
        $this->createTestLog('test_a', 'line1');
        $this->createTestLog('test_b', 'line2');

        // Without sizes/paths
        $files = LogManager::getLogFiles();
        $this->assertContains('test_a.log', $files);
        $this->assertContains('test_b.log', $files);

        // With sizes
        $filesWithSize = LogManager::getLogFiles(false, true);
        $found = false;
        foreach ($filesWithSize as $f) {
            if ($f['name'] === 'test_a.log') {
                $found = true;
                $this->assertEquals(5, $f['size']);
                $this->assertNotEmpty($f['size_formatted']);
                $this->assertIsInt($f['modified']);
                $this->assertNotEmpty($f['modified_formatted']);
            }
        }
        $this->assertTrue($found);

        // With paths
        $filesWithPaths = LogManager::getLogFiles(true, false);
        $this->assertContains(LogManager::getLogFilePath('test_a'), $filesWithPaths);
    }

    #[Test]
    public function testGetLogFileStats(): void
    {
        $jsonContent = json_encode(['timestamp' => '2026-06-12 12:00:00', 'level' => 'error', 'message' => 'database error']) . "\n"
                     . json_encode(['timestamp' => '2026-06-12 12:01:00', 'level' => 'info', 'message' => 'all good']) . "\n"
                     . "standard log line\n";
        
        $this->createTestLog('stats_test', $jsonContent);

        $stats = LogManager::getLogFileStats('stats_test');
        $this->assertNotNull($stats);
        $this->assertEquals('stats_test.log', $stats['name']);
        $this->assertEquals(3, $stats['lines']);
        $this->assertEquals(67, $stats['json_percentage']); // 2 out of 3 sample lines are JSON
        $this->assertArrayHasKey('error', $stats['level_distribution']);
        $this->assertArrayHasKey('info', $stats['level_distribution']);
        $this->assertEquals(1, $stats['level_distribution']['error']);

        // Stats for non-existent file
        $this->assertNull(LogManager::getLogFileStats('non_existent_file_xyz'));
    }

    #[Test]
    public function testClearAllLogs(): void
    {
        $fileA = $this->createTestLog('clear_a', 'some content');
        $fileB = $this->createTestLog('clear_b', 'some content');

        // Clear specific file list
        $cleared = LogManager::clearAllLogs(['clear_a']);
        $this->assertEquals(1, $cleared);
        $this->assertEquals('', file_get_contents($fileA));
        $this->assertEquals('some content', file_get_contents($fileB));

        // Clear all files
        $clearedAll = LogManager::clearAllLogs();
        $this->assertGreaterThanOrEqual(1, $clearedAll);
        $this->assertEquals('', file_get_contents($fileB));
    }

    #[Test]
    public function testArchiveOldLogs(): void
    {
        $oldTime = time() - (40 * 86400); // 40 days old
        $oldFile = $this->createTestLog('old_log', 'old log content', $oldTime);
        $newFile = $this->createTestLog('new_log', 'new log content');

        $result = LogManager::archiveOldLogs(30);

        if (class_exists('ZipArchive')) {
            $this->assertEquals(1, $result['archived']);
            $this->assertFileExists($result['archive_file']);
            $this->assertFileDoesNotExist($oldFile);
            $this->assertFileExists($newFile);
            
            // Clean up zip file
            if (file_exists($result['archive_file'])) {
                @unlink($result['archive_file']);
            }
        } else {
            $this->assertNotEmpty($result['errors']);
        }
    }

    #[Test]
    public function testSearchInLogs(): void
    {
        $this->createTestLog('search_a', "line one\nerror occurred here\nline three");
        $this->createTestLog('search_b', "line one\nall systems stable\nline three");

        // Case insensitive search
        $results = LogManager::searchInLogs('ERROR');
        $this->assertCount(1, $results);
        $this->assertEquals('search_a.log', $results[0]['file']);
        $this->assertEquals(1, $results[0]['count']);
        
        $match = $results[0]['matches'][0];
        $this->assertEquals(2, $match['line']);
        $this->assertTrue($match['context'][2]['match']);

        // Case sensitive search
        $resultsSensitive = LogManager::searchInLogs('ERROR', null, 2, true);
        $this->assertEmpty($resultsSensitive);
        
        // Search specific files
        $resultsSpecific = LogManager::searchInLogs('one', ['search_b']);
        $this->assertCount(1, $resultsSpecific);
        $this->assertEquals('search_b.log', $resultsSpecific[0]['file']);
    }

    #[Test]
    public function testProcessLogFileWithCallback(): void
    {
        $this->createTestLog('callback_test', "lineA\nlineB");

        $lines = [];
        $success = LogManager::processLogFileWithCallback('callback_test', 'log', function($line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertTrue($success);
        $this->assertEquals(['lineA', 'lineB'], $lines);

        // Fail for non-existent file
        $this->assertFalse(LogManager::processLogFileWithCallback('non_existent', 'log', function() {}));
    }

    #[Test]
    public function testGetLogFilePathGitDeploy(): void
    {
        $path = LogManager::getLogFilePath('GitDeploy', 'log');
        $this->assertStringContainsString('www/api/GitDeploy.log', str_replace('\\', '/', $path));

        $path2 = LogManager::getLogFilePath('GitWebhookDebug', 'log');
        $this->assertStringContainsString('www/api/GitWebhookDebug.log', str_replace('\\', '/', $path2));
    }

    #[Test]
    public function testGetLogAnalytics(): void
    {
        $startTime = time() - 3600; // 1 hour ago
        $dateStr = date('d/m/Y H:i:s', $startTime);
        
        $content = "[{$dateStr}] error: database down\n"
                 . "[12/06/2026 12:00:00] info: exception thrown\n";
                     
        $this->createTestLog('analytics_test', $content);

        $analytics = LogManager::getLogAnalytics('analytics_test', 'log', $startTime - 10, time() + 10, 'hour');
        
        $this->assertNotEmpty($analytics);
        $this->assertGreaterThan(0, $analytics['totalEntries']);
        $this->assertArrayHasKey('error', $analytics['levels']);
    }

    #[Test]
    public function testGetFilteredLogEntries(): void
    {
        $time = time();
        $dateStr1 = date('d/m/Y H:i:s', $time);
        $dateStr2 = date('d/m/Y H:i:s', $time + 1);
        
        $content = "[{$dateStr1}] error: first message admin\n"
                 . "[{$dateStr2}] info: second message\n";
                     
        $this->createTestLog('filter_test', $content);

        // Filter by level
        $entries = LogManager::getFilteredLogEntries('filter_test', 'log', ['error']);
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('first message', $entries[0]['message']);

        // Filter by query
        $entriesQuery = LogManager::getFilteredLogEntries('filter_test', 'log', [], null, null, 'second');
        $this->assertCount(1, $entriesQuery);
        $this->assertStringContainsString('second message', $entriesQuery[0]['message']);
        
        // Filter by text query
        $entriesContext = LogManager::getFilteredLogEntries('filter_test', 'log', [], null, null, 'admin');
        $this->assertCount(1, $entriesContext);
        $this->assertStringContainsString('first message', $entriesContext[0]['message']);
    }
}
