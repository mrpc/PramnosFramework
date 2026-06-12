<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\McpServiceProvider;
use Pramnos\Mcp\McpServer;
use Pramnos\Application\Application;
use Pramnos\Application\Container;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Unit tests for McpServiceProvider.
 *
 * Verifies that the service provider binds the McpServer singleton into the container
 * during the register phase, and attaches built-in tools (ListTablesTool, QuerySchemaTool, etc.)
 * and file resources during the boot phase.
 */
#[CoversClass(McpServiceProvider::class)]
class McpServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::clearSettings();
    }

    protected function tearDown(): void
    {
        Settings::clearSettings();
    }

    /**
     * Test register() binds a singleton instance of McpServer to the container.
     */
    public function testRegisterBindsMcpServerSingleton(): void
    {
        // Arrange
        Settings::setSetting('title', 'Test Pramnos App', false);
        
        $container = new Container();
        $app = $this->createMock(Application::class);
        $app->method('__isset')->willReturnCallback(fn($name) => $name === 'container');
        $app->method('__get')->willReturnCallback(fn($name) => $name === 'container' ? $container : null);

        $provider = new McpServiceProvider($app);

        // Act
        $provider->register();

        // Assert — singleton is bound
        $this->assertTrue($container->has('mcp.server'));
        
        $server = $container->get('mcp.server');
        $this->assertInstanceOf(McpServer::class, $server);
        
        // Assert info carries over
        $ref = new \ReflectionProperty(McpServer::class, 'appName');
        $this->assertSame('Test Pramnos App', $ref->getValue($server));
    }

    /**
     * Test boot() registers tools and resources when mcp.server is bound.
     */
    public function testBootRegistersToolsAndResources(): void
    {
        // Arrange
        $container = new Container();
        $server = new McpServer('TestApp', '1.0.0');
        $container->singleton('mcp.server', fn() => $server);

        $db = $this->createMock(Database::class);
        $db->connected = true;
        $db->type = 'mysql';

        $app = $this->createMock(Application::class);
        // Directly assign the database property because it is a declared public property
        $app->database = $db;
        $app->method('__isset')->willReturnCallback(fn($name) => $name === 'container');
        $app->method('__get')->willReturnCallback(fn($name) => $name === 'container' ? $container : null);

        $provider = new McpServiceProvider($app);

        // Act
        $provider->boot();

        // Assert — tools are registered
        $tools = $server->getTools();
        $toolNames = array_map(fn($t) => $t->name(), $tools);

        $this->assertContains('list-tables', $toolNames);
        $this->assertContains('query-schema', $toolNames);
        $this->assertContains('migration-status', $toolNames);
        $this->assertContains('model-inspect', $toolNames);
        $this->assertContains('route-list', $toolNames);

        // Assert — standard resources are registered if files exist
        $resources = $server->getResources();
        $resourceUris = array_map(fn($r) => $r->uri, $resources);

        // CLAUDE.md and README.md always exist in the project root
        $this->assertContains('file://CLAUDE.md', $resourceUris);
        $this->assertContains('file://README.md', $resourceUris);
    }

    /**
     * Test boot() does not crash or register tools if mcp.server is missing from the container.
     */
    public function testBootDoesNothingIfServerNotRegistered(): void
    {
        // Arrange
        $container = new Container(); // Empty container, no 'mcp.server'
        $app = $this->createMock(Application::class);
        $app->method('__isset')->willReturnCallback(fn($name) => $name === 'container');
        $app->method('__get')->willReturnCallback(fn($name) => $name === 'container' ? $container : null);

        $provider = new McpServiceProvider($app);

        // Act
        $provider->boot();

        // Assert — no exception thrown, code executed cleanly
        $this->assertFalse($container->has('mcp.server'));
    }
}
