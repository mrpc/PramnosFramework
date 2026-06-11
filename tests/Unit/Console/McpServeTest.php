<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\McpServe;
use Pramnos\Mcp\McpServer;
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

        // Application mock exposing the container via Base::__get magic
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->method('__get')->willReturnCallback(
            fn(string $name) => $name === 'container' ? $container : null
        );

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

        // 'database' is a declared public property — set it directly.
        // 'container' is NOT declared, so it goes through Base::__get magic.
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->database = $db;
        $app->method('__get')->willReturnCallback(
            fn(string $name) => $name === 'container' ? $container : null
        );

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
