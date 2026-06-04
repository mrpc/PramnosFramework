<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;
use Pramnos\Logs\PsrLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Unit tests for Pramnos\Logs\PsrLogger.
 *
 * PsrLogger is a PSR-3 compliant wrapper around the static Logger class.
 * Tests verify: channel binding, placeholder interpolation, level validation,
 * and delegation to Logger for all standard PSR-3 levels.
 */
#[CoversClass(PsrLogger::class)]
class PsrLoggerTest extends TestCase
{
    /** @var string Temp directory for log files. */
    private string $logDir;

    protected function setUp(): void
    {
        // LOG_PATH may already be defined by LoggerTest if both run in the same process
        $this->logDir = defined('LOG_PATH') ? LOG_PATH : sys_get_temp_dir();
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', $this->logDir);
        }
    }

    protected function tearDown(): void
    {
        $logsDir = $this->logDir . DIRECTORY_SEPARATOR . 'logs';
        foreach (glob($logsDir . '/psr_*.log') as $f) {
            @unlink($f);
        }
    }

    // =========================================================================
    // Constructor / getChannel()
    // =========================================================================

    /**
     * getChannel() must return the channel name supplied to the constructor.
     */
    public function testGetChannelReturnsSuppliedChannel(): void
    {
        $logger = new PsrLogger('payments');
        $this->assertSame('payments', $logger->getChannel());
    }

    /**
     * Default channel must be 'pramnosframework' when no argument is given.
     */
    public function testDefaultChannelIsPramnosframework(): void
    {
        $logger = new PsrLogger();
        $this->assertSame('pramnosframework', $logger->getChannel());
    }

    // =========================================================================
    // log() — level validation
    // =========================================================================

    /**
     * log() must throw InvalidArgumentException for an unknown level string.
     */
    public function testLogThrowsForInvalidLevel(): void
    {
        $logger = new PsrLogger('psr_test_invalid');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid PSR-3 log level/');
        $logger->log('not_a_level', 'some message');
    }

    /**
     * log() must accept all eight standard PSR-3 log levels without throwing.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validLevelProvider')]
    public function testLogAcceptsAllStandardLevels(string $level): void
    {
        $logger = new PsrLogger('psr_test_levels');
        // Must not throw
        $logger->log($level, 'test message for ' . $level);
        $this->assertTrue(true); // reached = success
    }

    /** @return array<string, array{string}> */
    public static function validLevelProvider(): array
    {
        return [
            'emergency' => [LogLevel::EMERGENCY],
            'alert'     => [LogLevel::ALERT],
            'critical'  => [LogLevel::CRITICAL],
            'error'     => [LogLevel::ERROR],
            'warning'   => [LogLevel::WARNING],
            'notice'    => [LogLevel::NOTICE],
            'info'      => [LogLevel::INFO],
            'debug'     => [LogLevel::DEBUG],
        ];
    }

    // =========================================================================
    // log() — placeholder interpolation
    // =========================================================================

    /**
     * {placeholder} tokens in the message must be replaced by context values.
     */
    public function testLogInterpolatesContextPlaceholders(): void
    {
        // Arrange
        $logger = new PsrLogger('psr_test_interp');

        // Act
        $logger->log(LogLevel::INFO, 'User {name} logged in from {ip}', [
            'name' => 'Alice',
            'ip'   => '192.168.1.1',
        ]);

        // Assert — the log file must contain the interpolated values
        $path    = Logger::getLogPath('psr_test_interp');
        $content = file_get_contents($path);
        $this->assertStringContainsString('Alice',        $content);
        $this->assertStringContainsString('192.168.1.1',  $content);
        // Original placeholder must NOT appear
        $this->assertStringNotContainsString('{name}', $content);
        $this->assertStringNotContainsString('{ip}',   $content);
    }

    /**
     * When the message contains no {placeholders}, it must be logged as-is.
     */
    public function testLogWithNoPlaceholdersLogsMessageAsIs(): void
    {
        $logger = new PsrLogger('psr_test_noplac');
        $logger->log(LogLevel::DEBUG, 'Plain message without placeholders');

        $path    = Logger::getLogPath('psr_test_noplac');
        $content = file_get_contents($path);
        $this->assertStringContainsString('Plain message without placeholders', $content);
    }

    /**
     * Array and non-stringable object values in context must be skipped
     * (not interpolated), per the PSR-3 spec.
     */
    public function testLogSkipsNonStringableContextValues(): void
    {
        $logger = new PsrLogger('psr_test_skip');
        $logger->log(LogLevel::WARNING, 'Message {arr} {obj}', [
            'arr' => ['nested' => 'array'],
            'obj' => new \stdClass(),          // non-stringable
        ]);

        $path    = Logger::getLogPath('psr_test_skip');
        $content = file_get_contents($path);
        // Placeholder tokens must remain (not replaced by the array/object)
        $this->assertStringContainsString('{arr}', $content);
        $this->assertStringContainsString('{obj}', $content);
    }

    /**
     * A Stringable object in context must be interpolated.
     */
    public function testLogInterpolatesStringableObject(): void
    {
        $stringable = new class {
            public function __toString(): string { return 'StringableValue'; }
        };

        $logger = new PsrLogger('psr_test_stringable');
        $logger->log(LogLevel::INFO, 'Value is {val}', ['val' => $stringable]);

        $path    = Logger::getLogPath('psr_test_stringable');
        $content = file_get_contents($path);
        $this->assertStringContainsString('StringableValue', $content);
    }

    // =========================================================================
    // log() — writes correct level to file
    // =========================================================================

    /**
     * log() must embed the level in the structured JSON entry.
     */
    public function testLogEmbedslevelInEntry(): void
    {
        $logger = new PsrLogger('psr_test_embed');
        $logger->log(LogLevel::ERROR, 'Error occurred');

        $path  = Logger::getLogPath('psr_test_embed');
        $lines = array_filter(explode("\n", trim(file_get_contents($path))));
        $last  = end($lines);
        $data  = json_decode($last, true);

        $this->assertIsArray($data, 'Entry must be valid JSON');
        $this->assertSame('error', $data['level'] ?? null);
    }

    // =========================================================================
    // Convenience PSR-3 methods (from AbstractLogger)
    // =========================================================================

    /**
     * The convenience methods (emergency, alert, critical, etc.) provided by
     * AbstractLogger must all delegate to log() and write to the log file.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('convenienceMethodProvider')]
    public function testConvenienceMethodsWriteToFile(string $method, string $expectedLevel): void
    {
        $channel = 'psr_conv_' . $expectedLevel;
        $logger  = new PsrLogger($channel);
        $logger->$method('convenience test');

        $path = Logger::getLogPath($channel);
        $this->assertFileExists($path, "$method() must create the log file");
        $this->assertStringContainsString($expectedLevel, file_get_contents($path));
    }

    /** @return array<string, array{string, string}> */
    public static function convenienceMethodProvider(): array
    {
        return [
            'emergency' => ['emergency', 'emergency'],
            'alert'     => ['alert',     'alert'],
            'critical'  => ['critical',  'critical'],
            'error'     => ['error',     'error'],
            'warning'   => ['warning',   'warning'],
            'notice'    => ['notice',    'notice'],
            'info'      => ['info',      'info'],
            'debug'     => ['debug',     'debug'],
        ];
    }
}
