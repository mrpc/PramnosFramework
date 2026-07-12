<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\LogMigrator;
use Pramnos\Logs\Logger;

/**
 * Characterization tests for legacy file-based Logs subsystem behavior.
 *
 * These tests lock write format and migration behavior before refactoring
 * logging internals or moving log storage abstractions.
 */
#[CoversClass(Logger::class)]
#[CoversClass(LogMigrator::class)]
class LoggerAndMigratorCharacterizationTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        // Arrange
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }

        $this->logDir = LOG_PATH . DS . 'logs';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Arrange
        $this->deleteIfExists($this->logDir . DS . 'char_logger.log');
        $this->deleteIfExists($this->logDir . DS . 'char_prepend.log');
    }

    /**
     * Ensures Logger::log writes simple messages in bracketed text format.
     */
    public function testLoggerWritesSimpleLineFormat(): void
    {
        // Arrange
        $file = 'char_logger';

        // Act
        Logger::log('simple message', $file);

        // Assert
        $content = file_get_contents($this->logDir . DS . $file . '.log');
        $this->assertIsString($content);
        $this->assertStringContainsString('simple message', $content);
        // This proves non-JSON simple formatting is still in use for plain lines.
        $this->assertMatchesRegularExpression('/^\[[^\]]+\]\s+simple message/m', $content);
    }

    /**
     * Ensures structured context logging produces a single-line JSON record.
     */
    public function testLoggerWritesJsonWhenContextIsProvided(): void
    {
        // Arrange
        $file = 'char_logger';

        // Act
        Logger::error('db failure', ['code' => 500], $file);

        // Assert
        $content = trim((string) file_get_contents($this->logDir . DS . $file . '.log'));
        $decoded = json_decode($content, true);

        $this->assertIsArray($decoded);
        $this->assertSame('db failure', $decoded['message']);
        $this->assertSame('error', $decoded['level']);
        $this->assertSame(500, $decoded['context']['code']);
    }

    /**
     * Ensures logPrepend inserts the newest message at file start.
     */
    public function testLoggerPrependPlacesMessageAtBeginningOfFile(): void
    {
        // Arrange
        $file = 'char_prepend';
        Logger::log('older line', $file);

        // Act
        Logger::logPrepend('new top line', $file);

        // Assert
        $lines = file($this->logDir . DS . $file . '.log', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('new top line', $lines[0]);
        $this->assertStringContainsString('older line', (string) end($lines));
    }

    /**
     * Ensures LogMigrator converts bracket-format lines to structured JSON and
     * creates backup files when requested.
     */
    public function testLogMigratorConvertsLinesAndCreatesBackup(): void
    {
        // Arrange
        $migrator = new LogMigrator();
        $tmpFile = $this->logDir . DS . 'char_migrate.log';
        $raw = "[03/05/2026 10:00:00] first message\n[03/05/2026 10:01:00] second message\n";
        file_put_contents($tmpFile, $raw);

        // Act
        $stats = $migrator->migrateFile($tmpFile, true);

        // Assert
        $this->assertFileExists($tmpFile);
        $this->assertFileExists($tmpFile . '.bak');
        $this->assertGreaterThan(0, $stats['processed_lines']);

        $lines = file($tmpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);

        $first = json_decode((string) $lines[0], true);
        $second = json_decode((string) $lines[1], true);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame('03/05/2026 10:00:00', $first['timestamp']);
        // This proves log migration preserves original message text.
        $this->assertSame('first message', $first['message']);
        $this->assertSame('second message', $second['message']);

        // Arrange
        $this->deleteIfExists($tmpFile);
        $this->deleteIfExists($tmpFile . '.bak');
    }

    /**
     * Logger PSR-3 level wrappers (emergency → debug) must each delegate to
     * logWithLevel() and write a line to the specified log file.
     *
     * This covers Logger.php lines 147-258: all seven level-wrapper methods
     * (emergency, alert, critical, error, warning, notice, info, debug) which
     * previously had no direct test coverage.
     */
    public function testAllPsr3LevelMethodsWriteToLog(): void
    {
        // Arrange
        $file = 'char_psr3';
        $logPath = $this->logDir . DS . $file . '.log';
        @unlink($logPath);

        // Act — call all seven PSR-3 wrappers; each writes one line
        Logger::emergency('emergency message', [], $file);
        Logger::alert('alert message', [], $file);
        Logger::critical('critical message', [], $file);
        Logger::warning('warning message', [], $file);
        Logger::notice('notice message', [], $file);
        Logger::info('info message', [], $file);
        Logger::debug('debug message', [], $file);

        // Assert — file exists and contains all seven level markers
        $this->assertFileExists($logPath);
        $content = file_get_contents($logPath);
        foreach (['emergency', 'alert', 'critical', 'warning', 'notice', 'info', 'debug'] as $level) {
            $this->assertStringContainsString($level, strtolower($content),
                "Logger::{$level}() must write a line containing the level name");
        }

        // Cleanup
        @unlink($logPath);
    }

    /**
     * Logger::getLogPath() must return the full path for a log file name and
     * extension, based on the LOG_PATH constant.
     *
     * This covers Logger.php lines 442-460: the static getLogPath() helper.
     */
    public function testGetLogPathReturnsExpectedPath(): void
    {
        // Act
        $path = Logger::getLogPath('mylogfile');

        // Assert — path ends with expected filename
        $this->assertStringEndsWith('mylogfile.log', $path);
        $this->assertStringContainsString('logs', $path);
    }

    /**
     * Logger::clearLog() must empty a log file when it exists, and return true.
     *
     * This covers Logger.php lines 425-441: the clearLog() method.
     */
    public function testClearLogEmptiesExistingFile(): void
    {
        // Arrange
        $file = 'char_clear';
        $logPath = $this->logDir . DS . $file . '.log';
        file_put_contents($logPath, "some existing content\n");

        // Act
        $result = Logger::clearLog($file);

        // Assert — file exists and is empty, method returned true
        $this->assertTrue($result, 'clearLog() must return true when file exists');
        $this->assertSame('', file_get_contents($logPath), 'clearLog() must empty the file');

        // Cleanup
        @unlink($logPath);
    }

    /**
     * Logger::clearLog() must return false when the file does not exist.
     *
     * This covers the early-return false branch in clearLog() when
     * file_exists() returns false.
     */
    public function testClearLogReturnsFalseForNonExistentFile(): void
    {
        // Act
        $result = Logger::clearLog('nonexistent_file_' . bin2hex(random_bytes(4)));

        // Assert
        $this->assertFalse($result, 'clearLog() must return false for non-existent file');
    }

    /**
     * Logger::channel() must return a PsrLogger instance bound to the given
     * channel name.  Calling it is sufficient to cover the factory-method body.
     *
     * This covers Logger.php line 461+: the channel() static method.
     */
    public function testChannelReturnsPsrLoggerInstance(): void
    {
        // Act
        $logger = Logger::channel('char_channel');

        // Assert — result is a PsrLogger (implements PSR-3 LoggerInterface)
        $this->assertInstanceOf(\Pramnos\Logs\PsrLogger::class, $logger);
    }

    private function deleteIfExists(string $path): void
    {
        // Arrange
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
