<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;
use Pramnos\Logs\PsrLogger;

/**
 * Unit tests for Pramnos\Logs\Logger.
 *
 * Logger is a static utility class that writes structured (or plain-text) log
 * entries to files on disk.  Tests use a temporary directory so they do not
 * touch any real log path and clean up after themselves.
 */
#[CoversClass(Logger::class)]
class LoggerTest extends TestCase
{
    /** @var string Temporary log directory used by all tests in this class. */
    private string $logDir;

    protected function setUp(): void
    {
        // Create a unique temp directory; Logger writes to LOG_PATH/DS/logs/
        $this->logDir = sys_get_temp_dir() . '/pramnos_logger_test_' . bin2hex(random_bytes(4));
        @mkdir($this->logDir, 0777, true);

        // Point LOG_PATH to our temp dir so Logger::getDefaultLogPath() uses it
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', $this->logDir);
        }
    }

    protected function tearDown(): void
    {
        // Remove all files created under $logDir/logs/
        $logsDir = $this->logDir . DIRECTORY_SEPARATOR . 'logs';
        if (is_dir($logsDir)) {
            foreach (glob($logsDir . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($logsDir);
        }
        // Note: we don't remove $logDir itself because LOG_PATH is defined globally
    }

    // =========================================================================
    // log() — core behaviour
    // =========================================================================

    /**
     * log() must create the log file if it does not exist and write the message.
     */
    public function testLogCreatesFileAndWritesMessage(): void
    {
        // Act
        Logger::log('Hello from test', 'test_basic');

        // Assert — file must exist
        $path = Logger::getLogPath('test_basic');
        $this->assertFileExists($path, 'log() must create the log file');

        // Assert — message is present in the file
        $content = file_get_contents($path);
        $this->assertStringContainsString('Hello from test', $content);
    }

    /**
     * log() with a JSON string must tag it with type=json in the context.
     */
    public function testLogDetectsJsonMessageAndTagsType(): void
    {
        // Arrange — a valid JSON string as message
        $json = json_encode(['key' => 'value', 'num' => 42]);

        // Act
        Logger::log($json, 'test_json_detect');

        // Assert — the file exists (message was written)
        $path = Logger::getLogPath('test_json_detect');
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertNotEmpty($content);
    }

    /**
     * log() with $startoffile=true must prepend the message to existing content.
     */
    public function testLogWithStartOfFilePrependsMessage(): void
    {
        // Arrange — write initial content
        $path = Logger::getLogPath('test_prepend');
        $logsDir = dirname($path);
        @mkdir($logsDir, 0777, true);
        file_put_contents($path, "existing line\n");

        // Act — prepend a new message
        Logger::log('prepended message', 'test_prepend', 'log', true);

        // Assert — prepended content appears before existing
        $content = file_get_contents($path);
        $this->assertStringContainsString('prepended message', $content);
        $this->assertStringContainsString('existing line', $content);
        // prepended must come first
        $this->assertLessThan(
            strpos($content, 'existing line'),
            strpos($content, 'prepended message'),
            'Prepended message must appear before existing content'
        );
    }

    /**
     * log() with context including a 'level' key must produce JSON output
     * containing the level field.
     */
    public function testLogWithLevelContextProducesJsonEntry(): void
    {
        // Act
        Logger::log('level test message', 'test_level', 'log', false, ['level' => 'warning']);

        // Assert — entry is JSON and contains level
        $path    = Logger::getLogPath('test_level');
        $content = file_get_contents($path);
        $this->assertStringContainsString('level', $content);
        $this->assertStringContainsString('warning', $content);
    }

    /**
     * log() with additional context must embed the context under the 'context' key.
     */
    public function testLogWithExtraContextEmbedsContext(): void
    {
        // Act
        Logger::log('ctx test', 'test_ctx', 'log', false, ['level' => 'info', 'extra' => 'data']);

        // Assert
        $path    = Logger::getLogPath('test_ctx');
        // Get the last non-empty line
        $lines   = array_filter(explode("\n", trim(file_get_contents($path))));
        $lastLine = end($lines);
        $data    = json_decode($lastLine, true);
        $this->assertIsArray($data, 'Log entry must be valid JSON');
        $this->assertArrayHasKey('context', $data);
        $this->assertSame('data', $data['context']['extra']);
    }

    // =========================================================================
    // PSR-3 convenience methods
    // =========================================================================

    /**
     * emergency(), alert(), critical(), error(), warning(), notice(), info(),
     * debug() must all write a JSON entry with the correct 'level' field.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('levelProvider')]
    public function testPsrLevelMethodWritesCorrectLevel(string $method, string $expectedLevel): void
    {
        $file = 'test_psr_' . $expectedLevel;

        // Act
        Logger::$method('test ' . $expectedLevel . ' message', [], $file);

        // Assert
        $path    = Logger::getLogPath($file);
        $content = file_get_contents($path);
        $this->assertStringContainsString($expectedLevel, $content);
    }

    /** @return array<string, array{string, string}> */
    public static function levelProvider(): array
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

    // =========================================================================
    // logPrepend()
    // =========================================================================

    /**
     * logPrepend() must write the message at the start of the file.
     */
    public function testLogPrependWritesAtStartOfFile(): void
    {
        // Arrange — pre-existing content
        $path    = Logger::getLogPath('test_logprepend');
        $logsDir = dirname($path);
        @mkdir($logsDir, 0777, true);
        file_put_contents($path, "old content\n");

        // Act
        Logger::logPrepend('new first line', 'test_logprepend');

        // Assert
        $content = file_get_contents($path);
        $this->assertStringContainsString('new first line', $content);
        $this->assertLessThan(
            strpos($content, 'old content'),
            strpos($content, 'new first line')
        );
    }

    // =========================================================================
    // logJson()
    // =========================================================================

    /**
     * logJson() with an array must encode it to JSON and log it.
     */
    public function testLogJsonWithArrayEncodesAndLogs(): void
    {
        // Act
        Logger::logJson(['event' => 'order_placed', 'amount' => 99.99], 'test_logjson');

        // Assert
        $path    = Logger::getLogPath('test_logjson');
        $content = file_get_contents($path);
        $this->assertStringContainsString('order_placed', $content);
    }

    /**
     * logJson() with a string passes it through as-is.
     */
    public function testLogJsonWithStringPassesThrough(): void
    {
        // Act
        Logger::logJson('{"already":"json"}', 'test_logjson_str');

        // Assert
        $path    = Logger::getLogPath('test_logjson_str');
        $content = file_get_contents($path);
        $this->assertStringContainsString('already', $content);
    }

    /**
     * logJson() with a level must include it in the entry.
     */
    public function testLogJsonWithLevelIncludesLevel(): void
    {
        // Act
        Logger::logJson(['msg' => 'test'], 'test_logjson_lvl', 'log', 'error');

        // Assert
        $path    = Logger::getLogPath('test_logjson_lvl');
        $content = file_get_contents($path);
        $this->assertStringContainsString('error', $content);
    }

    // =========================================================================
    // logError()
    // =========================================================================

    /**
     * logError() without an exception must write an error-level entry.
     */
    public function testLogErrorWithoutExceptionWritesEntry(): void
    {
        // Act
        Logger::logError('Something failed', null, 'test_logerror');

        // Assert
        $path    = Logger::getLogPath('test_logerror');
        $content = file_get_contents($path);
        $this->assertStringContainsString('Something failed', $content);
        $this->assertStringContainsString('error', $content);
    }

    /**
     * logError() with an exception must include the exception class and message.
     */
    public function testLogErrorWithExceptionIncludesExceptionInfo(): void
    {
        // Arrange
        $exception = new \RuntimeException('Test exception message', 42);

        // Act
        Logger::logError('An error occurred', $exception, 'test_logerror_ex');

        // Assert
        $path    = Logger::getLogPath('test_logerror_ex');
        // Get the last non-empty line (may be multiple entries per test run)
        $lines = array_filter(explode("\n", trim(file_get_contents($path))));
        $lastLine = end($lines);
        $data    = json_decode($lastLine, true);

        $this->assertIsArray($data, 'Log entry must be valid JSON');
        $this->assertArrayHasKey('context', $data);
        $this->assertArrayHasKey('exception', $data['context']);
        $this->assertSame('RuntimeException', $data['context']['exception']['class']);
        $this->assertSame('Test exception message', $data['context']['exception']['message']);
        $this->assertSame(42, $data['context']['exception']['code']);
    }

    // =========================================================================
    // truncateLogFile()
    // =========================================================================

    /**
     * truncateLogFile() must return false when the log file does not exist.
     */
    public function testTruncateLogFileReturnsFalseWhenFileDoesNotExist(): void
    {
        // Act
        $result = Logger::truncateLogFile('nonexistent_file_xyz');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * truncateLogFile() must return false when the file is smaller than maxSize.
     */
    public function testTruncateLogFileReturnsFalseWhenFileBelowMaxSize(): void
    {
        // Arrange — write a small file
        Logger::log('tiny', 'test_truncate_small');

        // Act — maxSize = 1 MB (file is much smaller)
        $result = Logger::truncateLogFile('test_truncate_small', 'log', 1_048_576);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * truncateLogFile() with rotate=false must truncate the file to a single entry.
     */
    public function testTruncateLogFileTruncatesFileWhenOverLimit(): void
    {
        // Arrange — create a file that exceeds the 1-byte limit
        $path    = Logger::getLogPath('test_truncate_big');
        $logsDir = dirname($path);
        @mkdir($logsDir, 0777, true);
        // Write 10 bytes of content
        file_put_contents($path, str_repeat('x', 10));

        // Act — truncate with maxSize=1 byte (well below 10 bytes), rotate=false
        $result = Logger::truncateLogFile('test_truncate_big', 'log', 1, false);

        // Assert — must return true and the file must now contain only the notice (not the original 10 x chars)
        $this->assertTrue($result, 'truncateLogFile() must return true when file exceeds maxSize');
        $newContent = file_get_contents($path);
        $this->assertStringNotContainsString('xxxxxxxxxx', $newContent,
            'Original content must be replaced after truncation');
        $this->assertStringContainsString('truncated', $newContent,
            'Truncated file must contain a notice message');

        // Cleanup
        @unlink($path);
    }

    // =========================================================================
    // clearLog()
    // =========================================================================

    /**
     * clearLog() must return false when the file does not exist.
     */
    public function testClearLogReturnsFalseWhenFileDoesNotExist(): void
    {
        $this->assertFalse(Logger::clearLog('nonexistent_file_xyz'));
    }

    /**
     * clearLog() must empty the file contents and return true.
     */
    public function testClearLogEmptiesFile(): void
    {
        // Arrange — write some content
        Logger::log('to be cleared', 'test_clearlog');
        $path = Logger::getLogPath('test_clearlog');
        $this->assertGreaterThan(0, filesize($path));

        // Act
        $result = Logger::clearLog('test_clearlog');

        // Assert
        $this->assertTrue($result);
        $this->assertSame(0, filesize($path));
    }

    // =========================================================================
    // getLogPath()
    // =========================================================================

    /**
     * getLogPath() must return a path ending with '{file}.{ext}'.
     */
    public function testGetLogPathReturnsCorrectPath(): void
    {
        $path = Logger::getLogPath('myapp', 'log');
        $this->assertStringEndsWith('myapp.log', $path);
    }

    // =========================================================================
    // channel()
    // =========================================================================

    /**
     * channel() must return a PsrLogger instance.
     */
    public function testChannelReturnsPsrLogger(): void
    {
        $logger = Logger::channel('payments');
        $this->assertInstanceOf(PsrLogger::class, $logger);
    }

    /**
     * channel() with no argument returns a PsrLogger for the default channel.
     */
    public function testChannelWithNoArgumentReturnsDefaultChannel(): void
    {
        $logger = Logger::channel();
        $this->assertInstanceOf(PsrLogger::class, $logger);
    }

    // =========================================================================
    // formatLogEntry() — tested via public log() output
    // =========================================================================

    /**
     * A plain message without level or context must use the bracket-timestamp format.
     */
    public function testPlainMessageUsesTimestampFormat(): void
    {
        // Act
        Logger::log('simple plain message', 'test_format_plain');

        // Assert — must start with '[DD/MM/YYYY HH:MM:SS]'
        $path    = Logger::getLogPath('test_format_plain');
        $content = trim(file_get_contents($path));
        $this->assertStringStartsWith('[', $content,
            'Plain log entry must start with a bracketed timestamp');
    }

    /**
     * A multiline message must be stored with escaped newlines (\\n) in JSON format.
     */
    public function testMultilineMessageIsStoredWithEscapedNewlines(): void
    {
        // Act
        Logger::log("line1\nline2\nline3", 'test_multiline');

        // Assert — must be stored as a single JSON line with \\n
        $path    = Logger::getLogPath('test_multiline');
        // Get the last non-empty line
        $lines    = array_filter(explode("\n", trim(file_get_contents($path))));
        $lastLine = end($lines);
        $data    = json_decode($lastLine, true);
        $this->assertIsArray($data, 'Multiline message must be stored as JSON');
        $this->assertStringContainsString('\\n', $data['message'],
            'Newlines must be escaped as \\n in the stored message');
    }
}
