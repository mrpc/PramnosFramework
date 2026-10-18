<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Document\Document;
use Pramnos\Framework\Factory;
use Pramnos\Http\SiteUrl;
use Pramnos\Mcp\Controllers\McpController;
use Pramnos\Mcp\PublicRegistry;

/**
 * A resource lists the scopes it takes, not every scope the server knows (FW-157).
 *
 * An MCP connector asks for everything a resource's `scopes_supported` lists, and the user's role
 * allowed it all: a Root user connecting to `/api/1.0/mcp` got a token carrying `system:admin`,
 * `system:audit_read` and the rest, to call tools that needed `mcp`. So the MCP endpoint lists
 * `mcp`, what its offered tools require and `offline_access`; a path the application configures
 * lists its own; the site itself still lists everything. The endpoint's `401` names the same
 * scopes in `scope="…"`, which the MCP spec has a client prefer.
 */
#[CoversClass(Discovery::class)]
#[CoversClass(McpController::class)]
class ResourceScopesTest extends TestCase
{
    private string|false $appUrl;

    protected function setUp(): void
    {
        $this->appUrl = getenv('APP_URL');
        putenv('APP_URL=https://example.test/');
        SiteUrl::reset();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        Document::reset();
        PublicRegistry::reset();
    }

    protected function tearDown(): void
    {
        $this->appUrl === false ? putenv('APP_URL') : putenv('APP_URL=' . $this->appUrl);
        SiteUrl::reset();
        $_GET = [];
        unset($_SERVER['REQUEST_URI']);
        PublicRegistry::reset();
    }

    /**
     * The protected-resource document for a path, rendered by the controller.
     *
     * @param array<string, mixed> $info The application's settings
     * @return array<string, mixed>
     */
    private function metadata(string $path, array $info = []): array
    {
        $_GET['resource_path'] = $path;
        $discovery = new Discovery(null);
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->applicationInfo = $info;
        $discovery->application = $app;
        $discovery->oauthProtectedResource();

        return (array) json_decode((string) Factory::getDocument('raw')->render(), true);
    }

    /** A tool on the public endpoint that needs `mcp:diagnostics`. */
    private static function offerATool(): void
    {
        PublicRegistry::offer('health', 'mcp:diagnostics', 'The health report', [], static fn (array $input): array => []);
    }

    /**
     * The MCP endpoint lists `mcp`, the scopes its tools require and `offline_access`, and none
     * of the server's other scopes — `system:admin` least of all.
     */
    public function testTheMcpEndpointListsWhatItsToolsTake(): void
    {
        // Arrange
        self::offerATool();

        // Act
        $scopes = $this->metadata('api/1.0/mcp')['scopes_supported'] ?? null;

        // Assert
        $this->assertSame(['mcp', 'mcp:diagnostics', 'offline_access'], $scopes);
        $this->assertNotContains('system:admin', $scopes);
    }

    /**
     * A path the application names in `oauth.resource_scopes` lists those scopes; the site
     * itself, and a path nothing names, list every scope, as before.
     */
    public function testAConfiguredPathListsItsOwnAndTheRestListEverything(): void
    {
        // Arrange
        $info = ['oauth' => ['resource_scopes' => ['api/1.0/reports' => ['reports:read', 'offline_access']]]];
        $all  = array_keys(\Pramnos\Auth\Scopes::getScopeDescriptions());

        // Act
        $reports = $this->metadata('api/1.0/reports', $info)['scopes_supported'] ?? null;
        $site    = $this->metadata('', $info)['scopes_supported'] ?? null;
        $other   = $this->metadata('api/1.0/things', $info)['scopes_supported'] ?? null;

        // Assert
        $this->assertSame(['reports:read', 'offline_access'], $reports);
        $this->assertSame($all, $site);
        $this->assertSame($all, $other);
    }

    /**
     * The endpoint's refusal names its metadata and the scopes it takes, the ones its metadata
     * lists — so a client that reads the header asks for the same few.
     */
    public function testTheRefusalNamesTheScopes(): void
    {
        // Arrange
        self::offerATool();
        $_SERVER['REQUEST_URI'] = '/api/1.0/mcp';
        $controller = new class (null) extends McpController {
            public function __construct($a)
            {
            }

            /** The challenge, out where a test can read it. */
            public function challengeForTest(): string
            {
                return $this->challenge();
            }
        };

        // Act
        $challenge = $controller->challengeForTest();

        // Assert
        $this->assertSame('Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource/api/1.0/mcp", scope="mcp mcp:diagnostics offline_access"', $challenge);
    }
}
