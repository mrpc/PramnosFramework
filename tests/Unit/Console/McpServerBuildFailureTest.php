<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\McpCall;
use Pramnos\Console\Commands\McpServe;
use Pramnos\Mcp\McpServer;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A tool that cannot be built must not cost the command its voice.
 *
 * `registerDefaults()` registers twenty-one tools in one call, so anything thrown
 * while constructing one happens before the command can report. The process ended
 * at exit **255 with nothing on stdout or stderr** — the fatal reaching only
 * `php_error.log` — so somebody asking for `status`, a tool that touches no
 * database, got silence for a reason two subsystems away.
 *
 * The specific cause is fixed (a foreign `Database` class, see
 * {@see \Pramnos\Tests\Unit\Mcp\ForeignDatabaseTest}). This is the class of it.
 */
#[CoversClass(McpCall::class)]
#[CoversClass(McpServe::class)]
class McpServerBuildFailureTest extends TestCase
{
    /**
     * `mcp:call` names what could not be registered instead of dying.
     *
     * The message has to carry the original class and text: "a tool could not be
     * registered" alone sends somebody to the wrong file.
     */
    public function testMcpCallReportsWhatCouldNotBeRegistered(): void
    {
        // Arrange
        $command = new class extends McpCall {
            protected function registerDefaults(
                McpServer $server,
                ?\Pramnos\Application\Application $app
            ): void {
                throw new \TypeError('Argument #1 ($db) must be of type Pramnos\Database\Database');
            }
        };

        $application = new ConsoleApplication();
        $application->add($command);
        $application->setCatchExceptions(false);

        $tester = new CommandTester($application->find('mcp:call'));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tool is available/');
        $this->expectExceptionMessageMatches('/TypeError/');
        $this->expectExceptionMessageMatches('/must be of type/');

        // Act
        $tester->execute(array('tool' => 'status'));
    }

    /**
     * `mcp:serve` writes it to stderr, and only stderr.
     *
     * This process speaks JSON-RPC on stdout. A diagnostic written there is a
     * protocol violation the client reports as something else entirely — so the one
     * place a message can safely go is the one nobody was using.
     */
    public function testMcpServeWritesTheReasonToStderrAndRethrows(): void
    {
        // Arrange
        $command = new class extends McpServe {
            protected function registerDefaults(
                McpServer $server,
                ?\Pramnos\Application\Application $app
            ): void {
                throw new \TypeError('a tool constructor refused its argument');
            }

            /** The seam under test, reached without running the stdio loop. */
            public function exposeBuild(): McpServer
            {
                return $this->resolveServer(null);
            }
        };

        // Act
        $thrown = null;

        try {
            $command->exposeBuild();
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        // Assert — rethrown rather than swallowed, with the cause intact. The stderr
        // line beside it cannot be captured in-process (STDERR is a constant bound at
        // startup); what is asserted here is that the command does not end in silence
        // and does not pretend the server was built.
        $this->assertInstanceOf(\TypeError::class, $thrown);
                $this->assertStringContainsString('refused its argument', $thrown->getMessage());
    }
}
