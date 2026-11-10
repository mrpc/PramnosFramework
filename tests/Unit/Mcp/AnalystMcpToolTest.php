<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Mcp\AnalystMcpTool;
use Pramnos\Mcp\McpServiceProvider;

/**
 * The analyst's own read tools on the MCP endpoint (`mcp.analyst`): offered behind the scope the
 * application gives each, only where the analyst would offer them, under the name the application
 * gives them, answering for the token's person; a tool with no scope, a key that is no tool, and
 * one the analyst would not offer here are left out; an application's tool of the same name wins.
 */
#[CoversClass(AnalystMcpTool::class)]
#[CoversClass(McpServiceProvider::class)]
class AnalystMcpToolTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    /** The application's settings kept. */
    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        \Pramnos\Metrics\Metrics::reset();
    }

    /** Given back, and the identity forgotten. */
    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        \Pramnos\Metrics\Metrics::reset();
        \Pramnos\Http\RequestIdentity::reset();
    }

    /** The traffic tool, renamed and scoped; goals left out with no metrics declared; the rest refused. */
    public function testTheConfiguredToolsAreOffered(): void
    {
        // Arrange
        $app = Application::getInstance();
        $app->applicationInfo['visits'] = ['sites' => ['main' => ['hosts' => ['site.test']]], 'tool' => 'myapp_visits'];
        $app->applicationInfo['metrics'] = ['catalogue' => []];
        $app->applicationInfo['mcp']['analyst'] = ['visits' => 'myapp:admin.read', 'goals' => 'myapp:admin.read', 'todos' => ' ', 'nothing' => 'x'];
        $app->applicationInfo['mcp']['tools'] = [];
        $app->applicationInfo['llm']['admin_tools'] = [];
        $person = new \Pramnos\User\User(0);
        $person->userid = 42;
        \Pramnos\Http\RequestIdentity::seal($person, 'token');

        // Act
        $tools = McpServiceProvider::applicationTools($app);
        $visits = $tools[0] ?? null;

        // Assert
        $this->assertCount(1, $tools, 'goals: no metrics declared; todos: no scope; nothing: no such tool');
        $this->assertInstanceOf(AnalystMcpTool::class, $visits);
        $this->assertSame(['myapp_visits', 'myapp:admin.read'], [$visits->name(), $visits->requiredScope()]);
        $this->assertStringContainsString('traffic', $visits->description());
        $this->assertArrayHasKey('breakdown', $visits->inputSchema()['properties']);
    }

    /** An application's own tool of the same name wins over the framework's. */
    public function testTheApplicationsOwnToolWins(): void
    {
        // Arrange
        $app  = Application::getInstance();
        $mine = new class implements \Pramnos\Mcp\McpToolInterface {
            /** Its name: the framework's. */
            public function name(): string { return 'visits'; }
            /** Its words. */
            public function description(): string { return 'mine'; }
            /** @return array<string, mixed> */
            public function inputSchema(): array { return ['type' => 'object']; }
            /** @param array<string, mixed> $input */
            public function execute(array $input): mixed { return 'mine'; }
        };
        $app->applicationInfo['visits'] = ['sites' => ['main' => ['hosts' => ['site.test']]]];
        $app->applicationInfo['mcp']['analyst'] = ['visits' => 'x:read'];
        $app->applicationInfo['mcp']['tools'] = [$mine];
        $app->applicationInfo['llm']['admin_tools'] = [];

        // Act
        $tools = McpServiceProvider::applicationTools($app);

        // Assert
        $this->assertSame([$mine], $tools);
        $this->assertSame([], AnalystMcpTool::configured([]), 'no mcp.analyst, nothing offered');
    }
}
