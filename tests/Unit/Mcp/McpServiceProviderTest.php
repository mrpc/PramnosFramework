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
        // getContainer(), not the magic property: nothing ever assigned
        // ->container, so register() died on null the moment this feature was
        // enabled. The application creates the container on demand now.
        $app->method('getContainer')->willReturn($container);

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
        // getContainer(), not the magic property: nothing ever assigned
        // ->container, so register() died on null the moment this feature was
        // enabled. The application creates the container on demand now.
        $app->method('getContainer')->willReturn($container);

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
        $this->assertContains('schema-drift', $toolNames);
        $this->assertContains('status', $toolNames);
        $this->assertContains('request-debug', $toolNames);

        // Assert — standard resources are registered if files exist
        $resources = $server->getResources();
        $resourceUris = array_map(fn($r) => $r->uri, $resources);

        // CLAUDE.md and README.md always exist in the project root
        $this->assertContains('file://CLAUDE.md', $resourceUris);
        $this->assertContains('file://README.md', $resourceUris);
    }

    /**
     * Every markdown file in the project's `docs/` is a resource, without being named.
     *
     * The listed version was wrong in the way a listed version always is: a project's own
     * notes — a request log, a decisions file, whatever that project calls it — are exactly the
     * documents somebody wants in context from the start, and they were never going to be
     * named in the framework. A directory scan cannot go out of date.
     */
    public function testEveryProjectDocIsAResource(): void
    {
        // Arrange
        $container = new Container();
        $server    = new McpServer('TestApp', '1.0.0');
        $container->singleton('mcp.server', fn () => $server);

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        // Act
        (new McpServiceProvider($app))->boot();

        // Assert — this repository keeps its guides in docs/
        $uris = array_map(static fn ($resource) => $resource->uri, $server->getResources());

        $this->assertContains('file://docs/Pramnos_Email_Guide.md', $uris);
        $this->assertContains('file://docs/Pramnos_Framework_Guide.md', $uris);

        foreach ($uris as $uri) {
            $this->assertDoesNotMatchRegularExpression(
                '~^file://docs/.+/~',
                $uri,
                'top level only: a recursive scan picks up a vendored copy of somebody else\'s manual'
            );
        }
    }

    /**
     * Test boot() does not crash or register tools if mcp.server is missing from the container.
     */
    public function testBootDoesNothingIfServerNotRegistered(): void
    {
        // Arrange
        $container = new Container(); // Empty container, no 'mcp.server'
        $app = $this->createMock(Application::class);
        // getContainer(), not the magic property: nothing ever assigned
        // ->container, so register() died on null the moment this feature was
        // enabled. The application creates the container on demand now.
        $app->method('getContainer')->willReturn($container);

        $provider = new McpServiceProvider($app);

        // Act
        $provider->boot();

        // Assert — no exception thrown, code executed cleanly
        $this->assertFalse($container->has('mcp.server'));
    }
}
