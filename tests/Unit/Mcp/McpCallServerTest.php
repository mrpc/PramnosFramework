<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\McpCall;
use Pramnos\Mcp\McpServer;

/**
 * Which MCP server `mcp:call` talks to.
 *
 * Ten statements, never executed, and the branch matters more than the count: **an application's
 * own server is used when there is one.** An application registers its tools on the container's
 * `mcp.server`, so building a fresh one here would answer `mcp:call` with the framework's defaults
 * and none of the application's — a tool list that is correct about the framework and silently
 * wrong about the project, which is the shape of report somebody trusts.
 *
 * The fallback is for the case with no application at all: `mcp:call` from a directory that is not
 * a project, where the framework's own tools are the honest answer.
 */
#[CoversClass(McpCall::class)]
class McpCallServerTest extends TestCase
{
    /** Reaches the one seam this command has. */
    private function serverFor(?Application $app): McpServer
    {
        return (new \ReflectionMethod(McpCall::class, 'server'))
            ->invoke(new McpCall(), $app);
    }

    /**
     * The application's own server is used, not a new one.
     *
     * Asserted by identity: the container's instance must come back, because anything else means
     * the application's registered tools are absent from the answer.
     */
    public function testTheApplicationsOwnServerIsUsed(): void
    {
        // Arrange
        $application = new class extends Application {
            public function __construct() {}
        };

        $own = new McpServer('The Application', '9.9.9');
        $application->getContainer()->instance('mcp.server', $own);

        // Act
        $server = $this->serverFor($application);

        // Assert
        $this->assertSame($own, $server, 'a fresh server was built over the application\'s own');
    }

    /**
     * With no application, a server is built and the framework's tools are registered on it.
     *
     * The tools are the point: a bare `McpServer` with nothing on it would make `mcp:call` report
     * that this installation has no tools, which is a different claim from "there is no
     * application here".
     */
    public function testWithNoApplicationTheFrameworkToolsAreRegistered(): void
    {
        // Act
        $server = $this->serverFor(null);

        // Assert
        $this->assertInstanceOf(McpServer::class, $server);
        $this->assertNotEmpty(
            $server->getTools(),
            'a server with no tools tells the caller this installation has none'
        );
    }

    /**
     * An application whose container has no server gets one built for it.
     *
     * The middle case, and the reason the check is `has()` rather than `!== null`: an application
     * that has not booted the MCP provider is ordinary — the feature is opt-in — and asking its
     * container for a service it never registered must not be an error.
     */
    public function testAnApplicationWithoutARegisteredServerGetsOne(): void
    {
        // Arrange — a container with nothing in it
        $application = new class extends Application {
            public function __construct() {}
        };

        // Act
        $server = $this->serverFor($application);

        // Assert
        $this->assertInstanceOf(McpServer::class, $server);
        $this->assertNotEmpty($server->getTools());
    }

    /**
     * The built server is named after the installation when it has a title.
     *
     * `TITLE` is what an MCP client displays as the server's name, so an installation that set one
     * should see it rather than `Pramnos App` — which is the fallback for a project that has not.
     */
    public function testTheBuiltServerIsNamedAfterTheInstallation(): void
    {
        // Act
        $server = $this->serverFor(null);

        // Assert
        $expected = defined('TITLE') && TITLE !== '' ? (string) TITLE : 'Pramnos App';
        $this->assertSame($expected, $server->getName());
    }
}
