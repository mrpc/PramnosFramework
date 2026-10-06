<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\FatalErrorReporter;
use Pramnos\Logs\Logger;

/**
 * A fatal error is reported with what was running, not only where it stopped.
 *
 * PHP's own line for "Allowed memory size … exhausted" names wherever the last allocation fell,
 * which in production was a getter in `Model` — and said nothing about the command that had
 * loaded the rows. These tests pin the context, the redaction of what could be a credential,
 * and, in a real process, that the report is still written after the memory ran out.
 */
#[CoversClass(FatalErrorReporter::class)]
class FatalErrorReporterTest extends TestCase
{
    /** @var resource|null */
    private $stream = null;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($this->stream);
    }

    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        Logger::setOutputMode(Logger::OUTPUT_FILE);
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** Everything logged during the test. */
    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * A warning is not reported: it has its own line, and the process went on.
     */
    public function testANonFatalErrorIsNotReported(): void
    {
        // Act
        $written = FatalErrorReporter::report(['type' => E_WARNING, 'message' => 'just a warning']);

        // Assert
        $this->assertFalse($written);
        $this->assertSame('', $this->logged());
        $this->assertFalse(FatalErrorReporter::report(null), 'a clean shutdown has no last error');
    }

    /**
     * A fatal is reported with the command line, and a secret option's value is hidden.
     *
     * The command line is what answers "what loaded all those rows". It can also carry a
     * password, and this file is read by whoever investigates.
     */
    public function testAFatalIsReportedWithTheCommandLineAndNoSecrets(): void
    {
        // Arrange
        $savedArgv       = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['urbanwater.php', 'maintenance', '--db-password=hunter2', '--zone=7'];

        try {
            // Act
            $written = FatalErrorReporter::report([
                'type'    => E_ERROR,
                'message' => 'Allowed memory size of 3221225472 bytes exhausted',
                'file'    => '/app/vendor/Model.php',
                'line'    => 1741,
            ]);
        } finally {
            $_SERVER['argv'] = $savedArgv;
        }

        // Assert
        $log = $this->logged();
        $this->assertTrue($written);
        $this->assertStringContainsString('Allowed memory size', $log);
        $this->assertStringContainsString('urbanwater.php maintenance', $log, 'the command is not named');
        $this->assertStringContainsString('--zone=7', $log);
        $this->assertStringNotContainsString('hunter2', $log, 'a password reached the log');
        $this->assertStringContainsString('Model.php:1741', $log);
    }

    /**
     * A request is named by method and path, without its query string.
     */
    public function testARequestIsNamedWithoutItsQueryString(): void
    {
        // Arrange
        $saved = [$_SERVER['REQUEST_METHOD'] ?? null, $_SERVER['REQUEST_URI'] ?? null];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/reports/run?token=abc123';

        try {
            // Act
            $context = FatalErrorReporter::context(['file' => 'x.php', 'line' => 1], 'fpm-fcgi');
        } finally {
            [$_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']] = $saved;
        }

        // Assert
        $this->assertSame('GET /reports/run', $context['entry']);
        $this->assertArrayHasKey('peak_memory_mb', $context);
        $this->assertArrayHasKey('memory_limit', $context);
        $this->assertArrayHasKey('elapsed_seconds', $context);
    }

    /**
     * Registering twice registers once, and shutdown with a fatal reports it.
     *
     * Every application built in a process calls register(); a second shutdown function would
     * write the same report twice. The shutdown path is driven here with an error of the test's
     * own, since the real one is error_get_last() at the end of the process.
     */
    public function testRegisteringIsOnceAndShutdownReportsAFatal(): void
    {
        // Arrange
        FatalErrorReporter::register();
        FatalErrorReporter::register();
        $registered = (new \ReflectionProperty(FatalErrorReporter::class, 'registered'))->getValue();
        $limit      = ini_get('memory_limit');

        try {
            // Act
            $written = FatalErrorReporter::onShutdown([
                'type'    => E_ERROR,
                'message' => 'Allowed memory size of 1024 bytes exhausted',
                'file'    => 'x.php',
                'line'    => 3,
            ]);
        } finally {
            ini_set('memory_limit', (string) $limit);
        }

        // Assert
        $this->assertTrue($registered);
        $this->assertTrue($written);
        $this->assertStringContainsString('Allowed memory size of 1024', $this->logged());
        $this->assertNull(
            (new \ReflectionProperty(FatalErrorReporter::class, 'reserve'))->getValue(),
            'the reserve is released before the report is written'
        );
    }

    /**
     * In a real process that runs out of memory, the report is written all the same.
     *
     * The point of the reserve and of raising the limit at shutdown: without them, writing the
     * report needs memory the process no longer has, and it dies the same way, silently.
     */
    public function testAProcessThatRunsOutOfMemoryStillLeavesTheReport(): void
    {
        // Arrange — a child process with a low limit and a loop that exhausts it
        $dir = sys_get_temp_dir() . '/pf-fatal-' . bin2hex(random_bytes(4));
        mkdir($dir . '/logs', 0777, true);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script   = $dir . '/oom.php';
        file_put_contents($script, '<?php
            require ' . var_export($autoload, true) . ';
            define("LOG_PATH", ' . var_export($dir, true) . ');
            \Pramnos\Logs\FatalErrorReporter::register();
            $rows = [];
            // Small pieces, as a loop over rows does: the limit is reached with no room left,
            // which is the case the reserve exists for. Megabyte pieces leave a margin.
            while (true) { $rows[] = str_repeat("x", 64); }
        ');

        // Act
        exec(PHP_BINARY . ' -d memory_limit=32M ' . escapeshellarg($script) . ' import:everything 2>&1', $output);
        $log = (string) @file_get_contents($dir . '/logs/fatal.log');
        exec('rm -rf ' . escapeshellarg($dir));

        // Assert
        $this->assertStringContainsString('Allowed memory size', $log, 'no report after running out of memory: ' . implode("\n", $output));
        $this->assertStringContainsString('import:everything', $log, 'the report does not say what was running');
    }
}
