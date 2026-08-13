<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\McpServe;
use Pramnos\Mcp\McpServer;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the `mcp:serve` console command.
 *
 * The command has two server-resolution strategies:
 * 1. If the internal Application's container has an 'mcp.server' binding
 *    (registered by McpServiceProvider::boot()), that server is used — this
 *    is how applications inject custom tools.
 * 2. Otherwise a default McpServer is built with the five built-in tools and
 *    the standard file resources (CLAUDE.md, README.md, app/app.php).
 *
 * run() itself blocks on STDIN, so the execute() test injects a mocked
 * McpServer through the container; the fallback builder is exercised via
 * reflection on the private resolveServer() method.
 */
#[CoversClass(McpServe::class)]
class McpServeTest extends TestCase
{
    /**
     * The command must register under the canonical 'mcp:serve' name with a
     * non-empty description — this is what `.mcp.json` configs reference.
     */
    public function testConfigureSetsNameAndDescription(): void
    {
        // Arrange / Act — configure() runs in the constructor
        $command = new McpServe();

        // Assert
        $this->assertSame('mcp:serve', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * When the internal Application's container has an 'mcp.server' binding,
     * execute() must use that server (calling run() exactly once) and return
     * the SUCCESS exit code. This is the path used by applications that
     * register custom tools via McpServiceProvider.
     */
    public function testExecuteUsesContainerBoundServer(): void
    {
        // Arrange — container with a mocked server whose run() is a no-op
        // (the real run() would block reading STDIN)
        $server = $this->createMock(McpServer::class);
        $server->expects($this->once())->method('run');

        $container = new \Pramnos\Application\Container();
        $container->instance('mcp.server', $server);

        // Application mock handing back the container. getContainer() is what
        // the command calls now — ->container was always null, because nothing
        // created it.
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->method('getContainer')->willReturn($container);

        $consoleApp = new \Pramnos\Console\Application();
        $consoleApp->internalApplication = $app;

        $command = new McpServe();
        $command->setApplication($consoleApp);

        // Act
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([]);

        // Assert — run() expectation verified by the mock; exit code is SUCCESS
        $this->assertSame(0, $exitCode);
    }

    /**
     * The banner goes to the error stream, and STDOUT stays empty.
     *
     * STDOUT is the JSON-RPC channel: a greeting there is not cosmetic damage —
     * the client parses the stream, fails on the first line and reports the
     * server as broken. But printing nothing at all was its own problem, because
     * run by hand the command blocks on STDIN with no output, which is
     * indistinguishable from a hang.
     */
    public function testItAnnouncesItselfOnStderrAndKeepsStdoutClean(): void
    {
        // Arrange — a server whose run() returns immediately
        $server = $this->createMock(McpServer::class);
        $server->method('run');

        $container = new \Pramnos\Application\Container();
        $container->instance('mcp.server', $server);

        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->method('getContainer')->willReturn($container);

        $consoleApp = new \Pramnos\Console\Application();
        $consoleApp->internalApplication = $app;

        [$command, $stderr] = $this->commandWithCapturedStderr();
        $command->setApplication($consoleApp);

        // Act
        $tester = new CommandTester($command);
        $tester->execute([]);

        // Assert
        $this->assertSame('', $tester->getDisplay(), 'nothing may be written to stdout');
        $this->assertStringContainsString('MCP server ready on stdio', $stderr->fetch());
    }

    /**
     * The banner lists what is actually being served.
     *
     * "Ready" alone does not say whether the tools an assistant needs are there —
     * and a server with no database registers fewer of them, silently.
     */
    public function testTheBannerNamesTheToolsAndResources(): void
    {
        // Arrange — the fallback path, which registers the built-in tools.
        // announce() is driven directly rather than through execute(): the server
        // the fallback builds is real, and its run() would block on STDIN.
        $db  = $this->createMock(\Pramnos\Database\Database::class);
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->database = $db;
        $app->method('getContainer')->willReturn(new \Pramnos\Application\Container());

        [$command, $stderr] = $this->commandWithCapturedStderr();

        $server = (new \ReflectionMethod($command, 'resolveServer'))->invoke($command, $app);

        // Act
        (new \ReflectionMethod($command, 'announce'))->invoke($command, $server, new BufferedOutput());

        // Assert
        $text = $stderr->fetch();
        $this->assertStringContainsString('5 tools', $text);
        $this->assertStringContainsString('list-tables', $text);
        $this->assertStringContainsString('route-list', $text);
        // Resources are only mentioned when there are any, so this asserts the
        // branch as well as the text.
        $this->assertStringContainsString('resources:', $text);
        // The sentence that answers "why is nothing happening?"
        $this->assertStringContainsString('Waiting for JSON-RPC on stdin', $text);
    }

    /**
     * With no separate error stream, nothing is announced at all.
     *
     * The alternative would be writing the banner to the only stream there is —
     * which is the JSON-RPC channel. Silence is the safe half of that choice.
     */
    public function testWithNoErrorStreamNothingIsAnnounced(): void
    {
        // Arrange — a plain (non-console) output, as an embedded runner provides.
        // The server is mocked because the real one blocks reading STDIN, which in
        // a test is a hang, not a failure.
        $server = $this->createMock(McpServer::class);
        $server->method('run');

        $container = new \Pramnos\Application\Container();
        $container->instance('mcp.server', $server);

        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->method('getContainer')->willReturn($container);

        $consoleApp = new \Pramnos\Console\Application();
        $consoleApp->internalApplication = $app;

        $command = new McpServe();
        $command->setApplication($consoleApp);

        // Act
        $tester = new CommandTester($command);
        $tester->execute([]);

        // Assert
        $this->assertSame('', $tester->getDisplay());
    }

    /**
     * A command whose error stream is a buffer this test can read.
     *
     * Symfony's own `capture_stderr_separately` would do this, at the cost of a
     * ReflectionProperty::setAccessible() deprecation on PHP 8.5 — from vendor
     * code, so unfixable here. Overriding the seam keeps the suite clean.
     *
     * @return array{0: McpServe, 1: BufferedOutput}
     */
    private function commandWithCapturedStderr(): array
    {
        $buffer  = new BufferedOutput();
        $command = new class($buffer) extends McpServe {
            public function __construct(private BufferedOutput $buffer)
            {
                parent::__construct();
            }

            protected function errorOutput(OutputInterface $output): ?OutputInterface
            {
                return $this->buffer;
            }
        };

        return [$command, $buffer];
    }

    /**
     * The command starts against an application that was never initialised.
     *
     * This is the reported crash, exactly: `./app mcp:serve` died with "Call to
     * a member function has() on null" before printing anything. The console
     * reaches the application without calling init(), nothing had created a
     * container, and `$app->container` is a magic property that reads back null.
     *
     * A partial mock with no mocked methods is deliberate: the real
     * getContainer() has to run, because it is the thing under test. A
     * createMock() would stub it to null and reproduce nothing.
     */
    public function testResolveServerSurvivesAnUninitialisedApplication(): void
    {
        // Arrange — no init(), no container, no bindings
        $app     = $this->createPartialMock(\Pramnos\Application\Application::class, []);
        $command = new McpServe();
        $method  = new \ReflectionMethod($command, 'resolveServer');

        // Act
        $server = $method->invoke($command, $app);

        // Assert — it falls back to a built server instead of dying
        $this->assertInstanceOf(McpServer::class, $server);
        // And the container now exists, so a provider binding into it later has
        // something to bind to.
        $this->assertInstanceOf(\Pramnos\Application\Container::class, $app->getContainer());
    }

    /**
     * resolveServer(null) — e.g. when the command runs outside a Pramnos
     * console application — must fall back to a bare default server with no
     * tools and no resources (there is no app to source them from).
     */
    public function testResolveServerWithoutAppBuildsBareServer(): void
    {
        // Arrange
        $command = new McpServe();
        $method  = new \ReflectionMethod($command, 'resolveServer');

        // Act
        $server = $method->invoke($command, null);

        // Assert — a default server is returned, with nothing registered
        $this->assertInstanceOf(McpServer::class, $server);
        $this->assertSame([], $server->getTools());
        $this->assertSame([], $server->getResources());
    }

    /**
     * The server names the application, so a client can tell two projects apart.
     *
     * An MCP client lists its servers by this string. Reported from a project where
     * every one of them said "Pramnos App": the name was read from a database-stored
     * setting and a constant, while `app/app.php` — which the console already reads,
     * with no database involved — has it.
     */
    public function testTheServerIsNamedAfterTheApplication(): void
    {
        // Arrange — an application whose app.php declares a name
        $container = new \Pramnos\Application\Container();
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->applicationInfo = ['name' => 'Radio Chat Box'];
        $app->method('getContainer')->willReturn($container);

        $command = new McpServe();

        // Act
        $server = (new \ReflectionMethod($command, 'resolveServer'))->invoke($command, $app);

        // Assert
        $this->assertSame('Radio Chat Box', $server->getName());
    }

    /**
     * With no configured name it falls back, rather than shipping an empty one.
     *
     * A blank name in a client's picker is worse than a generic one.
     */
    public function testTheServerNameFallsBackWhenNothingDeclaresOne(): void
    {
        // Arrange — no name in app.php
        $container = new \Pramnos\Application\Container();
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->applicationInfo = [];
        $app->method('getContainer')->willReturn($container);

        $command = new McpServe();

        // Act
        $server = (new \ReflectionMethod($command, 'resolveServer'))->invoke($command, $app);

        // Assert — something non-empty, and not a stray blank
        $this->assertNotSame('', $server->getName());
    }

    /**
     * resolveServer() with an app that has no 'mcp.server' binding must build
     * the default server containing all five built-in tools. With a database
     * available, ListTablesTool and QuerySchemaTool are included too; the
     * repository's own CLAUDE.md / README.md are picked up as resources
     * (ROOT points at the repo root in the test environment).
     */
    public function testResolveServerFallbackRegistersBuiltinToolsAndResources(): void
    {
        // Arrange — empty container (no 'mcp.server'), mocked connected DB
        $container = new \Pramnos\Application\Container();
        $db        = $this->createMock(\Pramnos\Database\Database::class);

        // 'database' is a declared public property — set it directly. The
        // container comes from getContainer(), which the application creates on
        // demand.
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->database = $db;
        $app->method('getContainer')->willReturn($container);

        $command = new McpServe();
        $method  = new \ReflectionMethod($command, 'resolveServer');

        // Act
        $server = $method->invoke($command, $app);

        // Assert — all five built-in tools registered, keyed by tool name
        $tools = $server->getTools();
        $this->assertCount(5, $tools);
        $names = array_map(fn($t) => $t->name(), $tools);
        sort($names);
        $this->assertSame(
            ['list-tables', 'migration-status', 'model-inspect', 'query-schema', 'route-list'],
            $names
        );

        // Repo root contains CLAUDE.md and README.md → registered as resources
        $resourceNames = array_map(fn($r) => $r->name, $server->getResources());
        $this->assertContains('Claude Code guide', $resourceNames);
        $this->assertContains('Project README', $resourceNames);
    }
}
