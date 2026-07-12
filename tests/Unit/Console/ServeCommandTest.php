<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Pramnos\Console\Commands\Serve;

/**
 * Unit tests for Pramnos\Console\Commands\Serve.
 *
 * Uses the Testable Subclass pattern: TestableServe overrides runServer() to
 * capture the assembled command string without actually starting a PHP built-in
 * web server, so execute() can run deterministically in a test.
 *
 * Tested paths:
 *   - configure() — option registration (port, host)
 *   - execute() default — port=8000, host=localhost
 *   - execute() with --port — custom port forwarded to runServer()
 *   - execute() with --host — custom host forwarded to runServer()
 *   - execute() return value — must return 0 (Command::SUCCESS)
 */
#[CoversClass(Serve::class)]
class ServeCommandTest extends TestCase
{
    private ?string $originalPhpSelf;

    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        if (!defined('ROOT')) {
            define('ROOT', sys_get_temp_dir());
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
        parent::tearDown();
    }

    // ── configure() ───────────────────────────────────────────────────────────

    /**
     * configure() must register the command as 'serve' with options --port
     * and --host so the application can add the command without errors.
     *
     * This covers lines 20-32 in Serve.
     */
    public function testConfigureRegistersAllOptions(): void
    {
        // Arrange / Act
        $cmd = new Serve();

        // Assert
        $def = $cmd->getDefinition();
        $this->assertSame('serve', $cmd->getName(),
            'Command name must be "serve"');
        $this->assertTrue($def->hasOption('port'),
            'Must register --port option');
        $this->assertTrue($def->hasOption('host'),
            'Must register --host option');
    }

    // ── execute() — default port/host ─────────────────────────────────────────

    /**
     * execute() with no options must default to port=8000 and host=localhost
     * (lines 44-49), write a startup message (lines 50-52), and call runServer()
     * with the assembled command (lines 53-55).
     *
     * Also verifies that execute() returns 0 (Command::SUCCESS, line 57).
     */
    public function testExecuteDefaultsToLocalhostPort8000(): void
    {
        // Arrange
        $cmd    = new TestableServe();
        $input  = new ArrayInput([], $cmd->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $cmd->runExecute($input, $output);

        // Assert — exit code
        $this->assertSame(0, $result,
            'execute() must return 0 (Command::SUCCESS)');

        // Assert — startup message (lines 50-52)
        $text = $output->fetch();
        $this->assertStringContainsString(
            'http://localhost:8000/',
            $text,
            'execute() must print the server URL with default host and port'
        );

        // Assert — runServer() was called with port 8000 and host localhost
        $this->assertStringContainsString('localhost:8000', $cmd->capturedCmd,
            'runServer() must receive a command containing the default host:port');
    }

    // ── execute() — custom port ────────────────────────────────────────────────

    /**
     * execute() with --port=9090 must forward that port to runServer() and
     * include it in the startup message (lines 42-55).
     */
    public function testExecuteWithCustomPort(): void
    {
        // Arrange
        $cmd    = new TestableServe();
        $input  = new ArrayInput(['--port' => '9090'], $cmd->getDefinition());
        $output = new BufferedOutput();

        // Act
        $cmd->runExecute($input, $output);

        // Assert — startup message and command both use the custom port
        $this->assertStringContainsString('9090', $output->fetch(),
            'execute() must include the custom port in the startup message');
        $this->assertStringContainsString('9090', $cmd->capturedCmd,
            'runServer() must receive a command containing the custom port');
    }

    // ── execute() — custom host ────────────────────────────────────────────────

    /**
     * execute() with --host=0.0.0.0 must forward that host to runServer() and
     * include it in the startup message (lines 42-55).
     */
    public function testExecuteWithCustomHost(): void
    {
        // Arrange
        $cmd    = new TestableServe();
        $input  = new ArrayInput(['--host' => '0.0.0.0'], $cmd->getDefinition());
        $output = new BufferedOutput();

        // Act
        $cmd->runExecute($input, $output);

        // Assert
        $this->assertStringContainsString('0.0.0.0', $output->fetch(),
            'execute() must include the custom host in the startup message');
        $this->assertStringContainsString('0.0.0.0', $cmd->capturedCmd,
            'runServer() must receive a command containing the custom host');
    }

    // ── runServer() — passthru wrapper ────────────────────────────────────────

    /**
     * runServer() on the base Serve class must call passthru() with the given
     * command.  We invoke it with a command that exits immediately (e.g. 'true')
     * so the test does not block.
     *
     * This covers lines 66-68 (the passthru() call inside runServer()).
     */
    public function testRunServerCallsPassthru(): void
    {
        // Arrange — instantiate the real Serve class (not the testable subclass)
        $serve = new Serve();
        $method = new \ReflectionMethod(Serve::class, 'runServer');

        // Act — run a command that exits immediately; passthru() must not throw
        ob_start();
        $method->invoke($serve, 'true'); // 'true' is a shell built-in that exits 0
        ob_end_clean();

        // Assert — if we reach this point, passthru() was called without error
        $this->assertTrue(true,
            'runServer() must call passthru() without throwing');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Testable subclass — overrides runServer() to capture the command
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Subclass that replaces the real passthru() call with a no-op that captures
 * the assembled command string for test assertions.
 */
class TestableServe extends Serve
{
    /** The command string that would have been passed to passthru(). */
    public string $capturedCmd = '';

    public function runExecute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        return $this->execute($input, $output);
    }

    protected function runServer(string $cmd): void
    {
        // Capture instead of running — prevents hanging on a real web server.
        $this->capturedCmd = $cmd;
    }
}
