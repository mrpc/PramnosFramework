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

    private function deleteIfExists(string $path): void
    {
        // Arrange
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
