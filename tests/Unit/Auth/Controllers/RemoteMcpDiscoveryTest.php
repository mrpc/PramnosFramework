<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Document\Document;
use Pramnos\Framework\Factory;
use Pramnos\Http\SiteUrl;
use Pramnos\Mcp\Controllers\McpController;

/**
 * What a remote MCP client reads before it will connect, as the documents actually render.
 *
 * The chain, as Claude.ai or ChatGPT walks it: call the endpoint, get `401` with a
 * `WWW-Authenticate` naming a metadata document, check that document's `resource` is the URL it
 * was given, read the authorization server's metadata, register itself, and run code-with-PKCE.
 * glideday measured five places where this installation broke that chain in production; these
 * tests pin the four that live in the documents and the refusal.
 *
 * The site sits at `https://example.test/` while the API — where the MCP endpoint is — has its
 * own `sURL`, which is the trap one of those gaps fell into.
 */
#[CoversClass(Discovery::class)]
#[CoversClass(McpController::class)]
class RemoteMcpDiscoveryTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->appUrl === false ? putenv('APP_URL') : putenv('APP_URL=' . $this->appUrl);
        SiteUrl::reset();
        $_GET = [];
        unset($_SERVER['REQUEST_URI']);
        http_response_code(200);
    }

    /** @return array<string, mixed> */
    private function render(string $action, bool $registration = false): array
    {
        $discovery = new Discovery(null);
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->applicationInfo = ['oauth_dynamic_registration' => $registration];
        $discovery->application = $app;

        $discovery->$action();

        return (array) json_decode((string) Factory::getDocument('raw')->render(), true);
    }

    /**
     * The path-suffixed document names the endpoint, not the site.
     *
     * RFC 9728 §3.3: the client checks that `resource` is the URL it started from. The bare
     * document says `https://example.test`, which does not match `…/api/1.0/mcp`, and the
     * client stops there.
     */
    public function testTheSuffixedDocumentNamesTheEndpointAsItsResource(): void
    {
        // Arrange — what the rewrite rule passes for /.well-known/oauth-protected-resource/api/1.0/mcp
        $_GET['resource_path'] = 'api/1.0/mcp';

        // Act
        $metadata = $this->render('oauthProtectedResource');

        // Assert
        $this->assertSame('https://example.test/api/1.0/mcp', $metadata['resource']);
        $this->assertSame(['header'], $metadata['bearer_methods_supported']);
    }

    /**
     * A suffix that is not a plain path is not an endpoint, and is not echoed back.
     *
     * The document is served to anybody, so reflecting whatever followed the well-known path
     * would hand a stranger a JSON document on this origin carrying their text.
     */
    public function testASuffixThatIsNotAPathIsNotFound(): void
    {
        // Arrange
        $_GET['resource_path'] = '../etc/passwd';

        // Act
        $answer = $this->render('oauthProtectedResource');

        // Assert
        $this->assertSame(404, http_response_code());
        $this->assertSame(['error' => 'not_found'], $answer);
    }

    /**
     * The address a `401` points at is built from the site's origin, per RFC 9728 §3.1.
     *
     * The well-known segment goes between the host and the endpoint's path. A port is kept,
     * and a directory the site is served from is not part of the origin.
     */
    public function testTheMetadataAddressIsTheOriginPlusTheWellKnownPathPlusTheEndpoint(): void
    {
        // Act
        $plain = Discovery::protectedResourceMetadataUrl('/api/1.0/mcp');
        $root  = Discovery::protectedResourceMetadataUrl('');

        putenv('APP_URL=https://example.test:8443/app/');
        SiteUrl::reset();
        $withPort = Discovery::protectedResourceMetadataUrl('api/1.0/mcp/');

        // Assert
        $this->assertSame('https://example.test/.well-known/oauth-protected-resource/api/1.0/mcp', $plain);
        $this->assertSame('https://example.test/.well-known/oauth-protected-resource', $root);
        $this->assertSame('https://example.test:8443/.well-known/oauth-protected-resource/api/1.0/mcp', $withPort);
    }

    /**
     * The authorization server's metadata says what a public client looks for.
     *
     * An MCP client refuses to start when `code_challenge_methods_supported` is absent, and
     * registers as `none` — so a list without `none` tells it the server cannot take it. S256
     * was implemented all along; the document just did not say so.
     */
    public function testTheServerMetadataAdvertisesPkceAndPublicClients(): void
    {
        // Act
        $metadata = $this->render('oauth2Metadata');

        // Assert
        $this->assertContains('S256', $metadata['code_challenge_methods_supported']);
        $this->assertContains('none', $metadata['token_endpoint_auth_methods_supported']);
    }

    /**
     * No registration endpoint is published unless registration is open.
     *
     * It used to point at the sign-up page for people, which answers a JSON registration with
     * `200` and HTML — a success, as far as the client can tell, carrying no client id.
     */
    public function testNoRegistrationEndpointIsPublishedWhileRegistrationIsClosed(): void
    {
        // Act
        $metadata = $this->render('oauth2Metadata');
        $oidc     = $this->render('configuration');

        // Assert
        $this->assertArrayNotHasKey('registration_endpoint', $metadata);
        $this->assertArrayNotHasKey('registration_endpoint', $oidc);
    }

    /**
     * Open, both documents name the real endpoint.
     */
    public function testAnOpenRegistrationIsPublishedInBothDocuments(): void
    {
        // Act
        $metadata = $this->render('oauth2Metadata', registration: true);
        $oidc     = $this->render('configuration', registration: true);

        // Assert
        $this->assertSame(sURL . 'oauth/register', $metadata['registration_endpoint']);
        $this->assertSame(sURL . 'oauth/register', $oidc['registration_endpoint']);
    }

    /**
     * The endpoint's refusal points at its own metadata, at the site's origin.
     *
     * Inside the API `sURL` is the API's base, so the header used to name
     * `/api/.well-known/oauth-protected-resource` — a 403. It is built from the request's own
     * path now, so it names the suffixed document that answers with this endpoint.
     */
    public function testTheUnauthenticatedRefusalPointsAtTheSuffixedDocument(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/1.0/mcp';
        $controller = $this->mcp();

        // Act
        $controller->display();

        // Assert
        $this->assertSame(401, $controller->status);
        $this->assertSame(
            'https://example.test/.well-known/oauth-protected-resource/api/1.0/mcp',
            $controller->body['error']['data']['resource_metadata']
        );
    }

    /**
     * A browser opening the endpoint is told the same address.
     */
    public function testAGetIsToldWhereTheMetadataIs(): void
    {
        // Arrange
        $_SERVER['REQUEST_URI'] = '/api/1.0/mcp?x=1';
        $controller = $this->mcp();

        // Act
        $controller->display();

        // Assert — the query string is not part of the resource
        $this->assertSame(405, $controller->status);
        $this->assertSame(
            'https://example.test/.well-known/oauth-protected-resource/api/1.0/mcp',
            $controller->body['resource']
        );
    }

    /** The MCP controller with nobody signed in and its response captured. */
    private function mcp(): object
    {
        return new class (null) extends McpController {
            public mixed $body = null;
            public int $status = 0;

            public function __construct($a)
            {
            }

            protected function authenticatedUser(): ?\Pramnos\User\User
            {
                return null;
            }

            protected function json(mixed $body, int $status = 200): mixed
            {
                $this->body   = $body;
                $this->status = $status;

                return null;
            }
        };
    }
}
