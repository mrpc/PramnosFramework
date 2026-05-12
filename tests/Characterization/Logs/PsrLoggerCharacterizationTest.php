<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pramnos\Logs\Logger;
use Pramnos\Logs\PsrLogger;

/**
 * Characterization tests for PsrLogger — the PSR-3 adapter.
 *
 * These tests verify that PsrLogger correctly implements the PSR-3 contract:
 * - It is an instance of Psr\Log\LoggerInterface.
 * - All 8 severity-level convenience methods are forwarded through log().
 * - {placeholder} tokens in messages are replaced from $context.
 * - An invalid level throws Psr\Log\InvalidArgumentException.
 * - The logger writes to the file-based Logger infrastructure.
 */
#[CoversClass(PsrLogger::class)]
class PsrLoggerCharacterizationTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        // Arrange — ensure LOG_PATH constant and log directory exist
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
        // Remove test log files written during this test run
        foreach (glob($this->logDir . DS . 'psr_test_*.log') as $file) {
            @unlink($file);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * PsrLogger must satisfy the Psr\Log\LoggerInterface type hint so that
     * any PSR-3 aware library can accept it without instanceof checks.
     */
    public function testImplementsPsrLoggerInterface(): void
    {
        // Arrange
        $logger = new PsrLogger('psr_test_iface');

        // Act / Assert
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    /**
     * getChannel() must return the file name passed to the constructor so that
     * callers can inspect which log file the logger is writing to.
     */
    public function testGetChannelReturnsConstructorArgument(): void
    {
        // Arrange
        $logger = new PsrLogger('psr_test_channel');

        // Act / Assert
        $this->assertSame('psr_test_channel', $logger->getChannel());
    }

    /**
     * Logger::channel() is a factory shortcut that must return a PsrLogger
     * bound to the requested channel.
     */
    public function testLoggerChannelFactoryReturnsPsrLogger(): void
    {
        // Act
        $logger = Logger::channel('psr_test_factory');

        // Assert
        $this->assertInstanceOf(PsrLogger::class, $logger);
        $this->assertSame('psr_test_factory', $logger->getChannel());
    }

    /**
     * log() must accept every valid PSR-3 level constant without throwing.
     * This ensures the level validation whitelist is complete and correct.
     */
    public function testAllValidLevelsAreAccepted(): void
    {
        // Arrange
        $logger = new PsrLogger('psr_test_levels');
        $levels = [
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL,
            LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE,
            LogLevel::INFO, LogLevel::DEBUG,
        ];

        // Act / Assert — each call must complete without throwing
        foreach ($levels as $level) {
            $logger->log($level, "Level test: {$level}");
        }

        $this->addToAssertionCount(count($levels)); // explicit assertion count
    }

    /**
     * log() must throw Psr\Log\InvalidArgumentException for any string that
     * is not in the PSR-3 LogLevel constant list, per the PSR-3 spec.
     */
    public function testInvalidLevelThrowsInvalidArgumentException(): void
    {
        // Arrange
        $logger = new PsrLogger('psr_test_invalid');

        // Act / Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid PSR-3 log level/');
        $logger->log('super_verbose', 'this level does not exist');
    }

    /**
     * {key} placeholders in the message must be replaced with the matching
     * value from $context before the message is passed to Logger::log().
     * This is a core PSR-3 requirement.
     */
    public function testPlaceholderInterpolationReplacesTokens(): void
    {
        // Arrange
        $logger  = new PsrLogger('psr_test_interp');
        $channel = 'psr_test_interp';
        $logFile = $this->logDir . DS . $channel . '.log';

        // Act
        $logger->info('User {username} signed in from {ip}', [
            'username' => 'alice',
            'ip'       => '127.0.0.1',
        ]);

        // Assert — the written log line must contain the substituted values
        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('alice', $content);
        $this->assertStringContainsString('127.0.0.1', $content);
        // Confirm tokens themselves were replaced, not left verbatim
        $this->assertStringNotContainsString('{username}', $content);
        $this->assertStringNotContainsString('{ip}', $content);
    }

    /**
     * A message with no placeholders must be written unchanged.
     * This ensures the fast-path (no `{` present) preserves the message.
     */
    public function testMessageWithoutPlaceholdersIsWrittenVerbatim(): void
    {
        // Arrange
        $logger  = new PsrLogger('psr_test_notoken');
        $logFile = $this->logDir . DS . 'psr_test_notoken.log';

        // Act
        $logger->warning('Connection pool exhausted');

        // Assert
        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Connection pool exhausted', $content);
    }

    /**
     * The convenience shortcut methods (info, error, debug, etc.) inherited
     * from AbstractLogger must delegate to log() with the correct level.
     * We verify via info() — the written entry must carry the "info" level.
     */
    public function testInfoConvenienceMethodWritesCorrectLevel(): void
    {
        // Arrange
        $logger  = new PsrLogger('psr_test_conv');
        $logFile = $this->logDir . DS . 'psr_test_conv.log';

        // Act
        $logger->info('convenience method test');

        // Assert
        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('info', $content);
    }

    /**
     * A Stringable object as $message must be cast to string before logging.
     * PSR-3 allows `string|\Stringable` for the $message argument.
     */
    public function testStringableMessageIsAccepted(): void
    {
        // Arrange
        $logger  = new PsrLogger('psr_test_stringable');
        $logFile = $this->logDir . DS . 'psr_test_stringable.log';

        $message = new class () {
            public function __toString(): string
            {
                return 'stringable message content';
            }
        };

        // Act — must not throw
        $logger->debug($message);

        // Assert
        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('stringable message content', $content);
    }
}
