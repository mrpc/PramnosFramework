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

        /*
         * The count and the names come from the server, not from a literal.
         *
         * This test hard-coded the number three times in one day and broke every time a tool
         * was added — which taught nothing except that the number had changed. What matters is
         * that the banner is *complete*: a tool registered and not announced is a tool an
         * assistant has no reason to believe exists.
         */
        $tools = $server->getTools();
        $this->assertStringContainsString(count($tools) . ' tools', $text);

        foreach ($tools as $tool) {
            $this->assertStringContainsString($tool->name(), $text,
                $tool->name() . ' is registered and not announced');
        }
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
     * resolveServer(null) — e.g. when the command runs outside a Pramnos console
     * application — falls back to a server carrying the application-independent tools and
     * nothing else.
     *
     * The five others and the three resources all describe *this application*, so with no
     * application there is genuinely nothing for them to report. The five here are the
     * exceptions, on purpose: two read the guides vendored beside the class and check the
     * project against them, one reads source files, and two read the log directory. None
     * needs a database or any configuration — and a server booting without an application is
     * exactly when somebody is asking how any of this is supposed to work, or why it did not.
     */
    public function testResolveServerWithoutAppStillOffersTheApplicationIndependentTools(): void
    {
        // Arrange
        $command = new McpServe();
        $method  = new \ReflectionMethod($command, 'resolveServer');

        // Act
        $server = $method->invoke($command, null);

        // Assert
        $this->assertInstanceOf(McpServer::class, $server);

        $names = array_keys($server->getTools());

        /*
         * Asserted as a property rather than as a list, because the list grows and the
         * property does not: whatever is here must not need an application, and the tools that
         * introspect *this* application must not be here. Enumerating instead meant this test
         * broke every time a tool was added, which is noise pretending to be coverage.
         */
        foreach (['framework-docs', 'pramnos-check'] as $expected) {
            $this->assertContains($expected, $names,
                $expected . ' answers the same in every project and needs nothing to boot');
        }

        foreach (['list-tables', 'query-schema', 'migration-status', 'model-inspect', 'route-list'] as $needsApp) {
            $this->assertNotContains($needsApp, $names,
                $needsApp . ' describes an application, and there is no application');
        }

        // Nothing application-shaped, because there is no application

        // Nothing application-shaped, because there is no application
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
     * The command's fallback offers exactly what the provider offers.
     *
     * This is the test that should have existed already. `mcp:serve` builds its own server
     * whenever no container has one — which is the normal case, because the console reaches an
     * application without initialising it — and it used to do that from a second, hand-written
     * copy of the tool catalogue. The copy went stale: two tools were registered in
     * `McpServiceProvider` and the command went on advertising seven, so the new tools were
     * unreachable through the documented way of starting the server. Both lists looked right on
     * their own; there was nothing to compare them against.
     *
     * There is one list now, and this asserts it stays one. Counting is not enough — two
     * catalogues of the same size can hold different tools — so the names are compared.
     */
    public function testTheFallbackOffersExactlyWhatTheProviderDoes(): void
    {
        // Arrange — the same application for both, so any difference is the catalogue's
        $container = new \Pramnos\Application\Container();
        $db        = $this->createMock(\Pramnos\Database\Database::class);
        $app       = $this->createMock(\Pramnos\Application\Application::class);
        $app->database = $db;
        $app->method('getContainer')->willReturn($container);

        // Act — the command's fallback, and the provider's own registration
        $fromCommand = (new \ReflectionMethod(new McpServe(), 'resolveServer'))
            ->invoke(new McpServe(), $app);

        $fromProvider = new McpServer('Test', '1.0.0');
        \Pramnos\Mcp\McpServiceProvider::registerDefaults($fromProvider, $app);

        // Assert
        $names = static function (McpServer $server): array {
            $list = array_map(static fn ($tool) => $tool->name(), $server->getTools());
            sort($list);

            return $list;
        };

        $this->assertSame(
            $names($fromProvider),
            $names($fromCommand),
            'the command and the provider must not keep separate tool catalogues'
        );

        $this->assertSame(
            array_keys($fromProvider->getResources()),
            array_keys($fromCommand->getResources()),
            'nor separate resource lists'
        );
    }

    /**
     * resolveServer() with an app that has no 'mcp.server' binding must build the default
     * server carrying every built-in tool. With a database available, `list-tables` and
     * `query-schema` are included too; the repository's own CLAUDE.md / README.md are picked
     * up as resources (ROOT points at the repo root in the test environment).
     *
     * This branch used to hold its own copy of the catalogue, and the copy went stale — two
     * tools were added to the provider and the command went on offering seven. The next test
     * is the one that stops that happening again; this one is the list itself.
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

        /*
         * Containment, not an exact list.
         *
         * The five application-introspection tools have to be here — that is what this branch
         * is for — and the two that need a database have to be among them, because one was
         * provided. Asserting the *whole* catalogue instead made this test a copy of it, and
         * the copy broke on every addition while catching nothing: the test that actually
         * guards the catalogue is the one below, which compares this branch against the
         * provider.
         */
        $names = array_map(static fn ($t) => $t->name(), $server->getTools());

        foreach ([
            'list-tables',
            'query-schema',
            'migration-status',
            'model-inspect',
            'route-list',
        ] as $expected) {
            $this->assertContains($expected, $names, $expected . ' needs the application');
        }

        $this->assertGreaterThan(5, count($names),
            'the application-independent tools are registered here too');

        // Repo root contains CLAUDE.md and README.md → registered as resources
        $resourceNames = array_map(fn($r) => $r->name, $server->getResources());
        $this->assertContains('Claude Code guide', $resourceNames);
        $this->assertContains('Project README', $resourceNames);
    }
}
