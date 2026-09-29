<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Auth\OAuth2\OAuth2ServerFactory;
use Pramnos\Http\SiteUrl;
use Pramnos\Mcp\Controllers\McpController;

/**
 * RFC 8707 resource indicators: a token names the resource it was issued for, and the resource
 * refuses one issued for another.
 *
 * An MCP client sends `resource=<the endpoint URL>` on the token request, and the MCP
 * authorization spec requires the server to check the token's audience — otherwise a token a
 * client obtained for some other resource, and passed on or had taken, works on this one too.
 * The token side is proven end to end in `FullAuthorizationCodeFlowTest`; this file covers
 * what the token endpoint refuses and what the MCP endpoint accepts.
 */
#[CoversClass(Oauth::class)]
#[CoversClass(Discovery::class)]
#[CoversClass(McpController::class)]
#[CoversClass(OAuth2ServerFactory::class)]
class ResourceIndicatorsTest extends TestCase
{
    private string|false $appUrl;

    protected function setUp(): void
    {
        $this->appUrl = getenv('APP_URL');
        putenv('APP_URL=https://example.test/');
        SiteUrl::reset();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $this->appUrl === false ? putenv('APP_URL') : putenv('APP_URL=' . $this->appUrl);
        SiteUrl::reset();
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_ACCESSTOKEN'], $_SERVER['REQUEST_URI']);
    }

    /**
     * Only this site's own endpoints can be a resource.
     *
     * This server issues tokens for itself. A token audience-bound to another host is one it
     * cannot vouch that host will honour, and a fragment is forbidden by RFC 8707 §2.
     */
    #[DataProvider('resources')]
    public function testOnlyThisSitesOwnUrisAreResources(string $uri, bool $own): void
    {
        // Act + Assert
        $this->assertSame($own, Discovery::isOwnResource($uri));
    }

    /** @return array<string, array{0:string,1:bool}> */
    public static function resources(): array
    {
        return [
            'the MCP endpoint'      => ['https://example.test/api/1.0/mcp', true],
            'the site root'         => ['https://example.test', true],
            'case in the host'      => ['https://EXAMPLE.test/api/1.0/mcp', true],
            'another host'          => ['https://evil.test/api/1.0/mcp', false],
            'another scheme'        => ['http://example.test/api/1.0/mcp', false],
            'another port'          => ['https://example.test:8443/api/1.0/mcp', false],
            'a fragment'            => ['https://example.test/api/1.0/mcp#x', false],
            'relative'              => ['/api/1.0/mcp', false],
        ];
    }

    /**
     * A resource that is not ours is refused, not ignored.
     *
     * Ignoring it would hand back a token the client believes is audience-bound when it is not.
     * `invalid_target` is the error RFC 8707 §2 defines for this.
     */
    public function testTheTokenEndpointRefusesAForeignResource(): void
    {
        // Arrange
        $_POST = ['grant_type' => 'authorization_code', 'resource' => 'https://evil.test/mcp'];
        $factory = $this->createMock(OAuth2ServerFactory::class);
        $factory->expects($this->never())->method('createAuthorizationServer');

        // Act
        $response = $this->oauth($factory)->token();

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_target', json_decode($response->getBody(), true)['error']);
    }

    /**
     * A resource that is ours reaches the factory, which puts it into the token's `aud`.
     *
     * The trailing slash is dropped, because the audience is compared as a string and
     * `…/mcp/` and `…/mcp` would otherwise be two resources.
     */
    public function testAnOwnResourceIsHandedToTheFactory(): void
    {
        // Arrange
        $_POST = ['grant_type' => 'authorization_code', 'resource' => 'https://example.test/api/1.0/mcp/'];
        $factory = $this->createMock(OAuth2ServerFactory::class);
        $factory->expects($this->once())->method('forResource')
            ->with('https://example.test/api/1.0/mcp')->willReturnSelf();
        // What happens after is League's and is tested end to end; stop here.
        $factory->method('createAuthorizationServer')->willThrowException(new \Exception('stop'));

        // Act
        $response = $this->oauth($factory)->token();

        // Assert — the stop, rendered as the endpoint renders any unexpected failure
        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * Which tokens the MCP endpoint takes.
     *
     * Bound to this endpoint: yes. Bound only to another resource: no. Bound to none — the `aud`
     * is only a client id, as every token issued before resource binding is and as `mcp:token`'s
     * still are: yes, because refusing those would break every connection configured by hand.
     */
    #[DataProvider('audiences')]
    public function testTheMcpEndpointTakesOnlyTokensForItselfOrForNoResource(mixed $aud, bool $accepted): void
    {
        // Arrange
        $_SERVER['REQUEST_URI']        = '/api/1.0/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt(['aud' => $aud]);

        // Act
        $result = $this->mcp()->forThisEndpoint();

        // Assert
        $this->assertSame($accepted, $result);
    }

    /** @return array<string, array{0:mixed,1:bool}> */
    public static function audiences(): array
    {
        return [
            'this endpoint'            => [['client-1', 'https://example.test/api/1.0/mcp'], true],
            'this endpoint, slashed'   => [['client-1', 'https://example.test/api/1.0/mcp/'], true],
            'another resource only'    => [['client-1', 'https://example.test/api/1.0/other'], false],
            'another host'             => ['https://evil.test/api/1.0/mcp', false],
            'a client id only'         => ['client-1', true],
            'no aud at all'            => [null, true],
        ];
    }

    /**
     * A call with no bearer at all is not this check's business.
     *
     * The caller was identified some other way — the same-origin session — and there is no
     * audience to compare.
     */
    public function testNoBearerIsNotRefusedHere(): void
    {
        // Act + Assert
        $this->assertTrue($this->mcp()->forThisEndpoint());
    }

    /**
     * The refusal is the discovery `401`, so the client goes and gets a token for this endpoint.
     */
    public function testATokenForAnotherResourceGetsTheDiscovery401(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD']     = 'POST';
        $_SERVER['REQUEST_URI']        = '/api/1.0/mcp';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt(['aud' => ['c', 'https://example.test/other']]);
        $mcp = $this->mcp();

        // Act
        $mcp->display();

        // Assert
        $this->assertSame(401, $mcp->status);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /** An unsigned token with these claims — the MCP check reads claims the middleware verified. */
    private function jwt(array $claims): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']) . '.' . $encode(array_filter($claims, static fn ($v) => $v !== null)) . '.sig';
    }

    private function oauth(OAuth2ServerFactory $factory): Oauth
    {
        $oauth = (new \ReflectionClass(Oauth::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($oauth, 'oauth2Factory'))->setValue($oauth, $factory);

        return $oauth;
    }

    private function mcp(): object
    {
        return new class (null) extends McpController {
            public int $status = 0;

            public function __construct($a)
            {
            }

            public function forThisEndpoint(): bool
            {
                return $this->tokenIsForThisEndpoint();
            }

            protected function authenticatedUser(): ?\Pramnos\User\User
            {
                $user = (new \ReflectionClass(\Pramnos\User\User::class))->newInstanceWithoutConstructor();
                $user->userid = 42;

                return $user;
            }

            protected function json(mixed $body, int $status = 200): mixed
            {
                $this->status = $status;

                return null;
            }
        };
    }
}
