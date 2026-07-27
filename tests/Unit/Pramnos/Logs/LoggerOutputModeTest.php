<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;

/**
 * Tests the Logger output-mode addition: 'file' (default, read by the LogViewer),
 * 'stream' (STDERR — 12-factor logging for containers), and 'both'.
 *
 * The stream target is swapped for an in-memory stream so writes can be asserted
 * without touching real STDERR, and each test uses a unique log filename so the
 * file-vs-stream behaviour can be checked independently.
 */
#[CoversClass(Logger::class)]
class LoggerOutputModeTest extends TestCase
{
    private string $logFile;
    private string $filePath;

    protected function setUp(): void
    {
        $this->logFile  = 'modetest_' . substr(md5((string) getmypid() . $this->name()), 0, 8);
        $base = defined('LOG_PATH') ? \LOG_PATH : sys_get_temp_dir();
        $this->filePath = $base . \DS . 'logs' . \DS . $this->logFile . '.log';
        @unlink($this->filePath);
    }

    protected function tearDown(): void
    {
        // Reset the mode override to the default (resolve-from-env) and the stream target.
        (new \ReflectionProperty(Logger::class, 'outputMode'))->setValue(null, null);
        Logger::setStreamTarget(null);
        @unlink($this->filePath);
    }

    private function memoryStream()
    {
        $s = fopen('php://memory', 'r+');
        Logger::setStreamTarget($s);
        return $s;
    }

    private function streamContents($s): string
    {
        rewind($s);
        return (string) stream_get_contents($s);
    }

    public function testDefaultModeIsFile(): void
    {
        (new \ReflectionProperty(Logger::class, 'outputMode'))->setValue(null, null);
        Logger::setStreamTarget(null);
        putenv('PRAMNOS_LOG_MODE'); // clear
        $this->assertSame(Logger::OUTPUT_FILE, Logger::getOutputMode());
    }

    public function testStreamModeWritesToStreamNotFile(): void
    {
        $s = $this->memoryStream();
        Logger::setOutputMode(Logger::OUTPUT_STREAM);

        Logger::log('hello-stream', $this->logFile);

        $this->assertStringContainsString('hello-stream', $this->streamContents($s));
        $this->assertFileDoesNotExist($this->filePath, 'stream mode must not write the log file');
    }

    public function testFileModeWritesToFileNotStream(): void
    {
        $s = $this->memoryStream();
        Logger::setOutputMode(Logger::OUTPUT_FILE);

        Logger::log('hello-file', $this->logFile);

        $this->assertSame('', $this->streamContents($s), 'file mode must not write to the stream');
        $this->assertFileExists($this->filePath);
        $this->assertStringContainsString('hello-file', (string) file_get_contents($this->filePath));
    }

    public function testBothModeWritesToFileAndStream(): void
    {
        $s = $this->memoryStream();
        Logger::setOutputMode(Logger::OUTPUT_BOTH);

        Logger::log('hello-both', $this->logFile);

        $this->assertStringContainsString('hello-both', $this->streamContents($s));
        $this->assertFileExists($this->filePath);
        $this->assertStringContainsString('hello-both', (string) file_get_contents($this->filePath));
    }

    public function testEnvVarSelectsModeWhenNoOverride(): void
    {
        (new \ReflectionProperty(Logger::class, 'outputMode'))->setValue(null, null);
        putenv('PRAMNOS_LOG_MODE=stream');
        $this->assertSame(Logger::OUTPUT_STREAM, Logger::getOutputMode());
        putenv('PRAMNOS_LOG_MODE'); // clear
    }
}
