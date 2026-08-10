<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * Covers how an access token is read off the incoming request.
 *
 * The framework's own header is `accessToken`, but every generic client — curl,
 * Postman, RapiDoc's "Authorize" button, an OpenAPI-generated SDK — sends
 * `Authorization: Bearer …`. Before this, such a request was simply anonymous,
 * which presents as "my token does not work" rather than "you used the wrong
 * header name". The precedence below is the contract: the framework header
 * still wins, the standard one is accepted when it is absent.
 */
class RequestAccessTokenTest extends TestCase
{
    /** @var array<string, mixed> $_SERVER as it was before the test */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        unset(
            $_SERVER['HTTP_ACCESSTOKEN'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    /**
     * A request with no credentials at all yields null — the caller must be
     * able to tell "anonymous" apart from "empty token".
     */
    public function testNoHeadersYieldNull(): void
    {
        // Act + Assert
        $this->assertNull(Request::accessToken());
    }

    /**
     * The framework's own header keeps working exactly as before.
     */
    public function testAccessTokenHeaderIsUsed(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCESSTOKEN'] = 'framework-token';

        // Act + Assert
        $this->assertSame('framework-token', Request::accessToken());
    }

    /**
     * A standard Authorization header is honoured when the framework header is
     * absent — the whole point of the fallback.
     */
    public function testAuthorizationBearerIsAcceptedAsFallback(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer standard-token';

        // Act + Assert
        $this->assertSame('standard-token', Request::accessToken());
    }

    /**
     * RFC 7235 makes the scheme case-insensitive, and clients do send
     * lowercase "bearer".
     */
    public function testBearerSchemeIsCaseInsensitive(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer lowercase-token';

        // Act + Assert
        $this->assertSame('lowercase-token', Request::accessToken());
    }

    /**
     * When both are present the framework header wins, so nothing changes for
     * an existing client that sends both.
     */
    public function testAccessTokenHeaderTakesPrecedence(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCESSTOKEN']  = 'framework-token';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer standard-token';

        // Act + Assert
        $this->assertSame('framework-token', Request::accessToken());
    }

    /**
     * Apache with CGI/FastCGI hands the header over as
     * REDIRECT_HTTP_AUTHORIZATION after a rewrite — a very common deployment,
     * where dropping it would look like a server-specific auth bug.
     */
    public function testRedirectAuthorizationIsAccepted(): void
    {
        // Arrange
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirected-token';

        // Act + Assert
        $this->assertSame('redirected-token', Request::accessToken());
    }

    /**
     * A different auth scheme is not an access token: Basic credentials must
     * not be handed to the JWT decoder.
     */
    public function testOtherAuthorizationSchemesAreIgnored(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        // Act + Assert
        $this->assertNull(Request::accessToken());
    }

    /**
     * "Bearer" with nothing after it is not a token — treating the empty string
     * as one would send a blank credential into token validation.
     */
    public function testEmptyBearerValueYieldsNull(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer    ';

        // Act + Assert
        $this->assertNull(Request::accessToken());
    }

    /**
     * An empty accessToken header falls through to the Authorization header
     * instead of shadowing it — clients that always send the header, empty or
     * not, still authenticate.
     */
    public function testEmptyAccessTokenHeaderFallsThrough(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCESSTOKEN']   = '   ';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer standard-token';

        // Act + Assert
        $this->assertSame('standard-token', Request::accessToken());
    }

    /**
     * Surrounding whitespace is stripped, so a header pasted with a trailing
     * space does not produce a token that fails to validate.
     */
    public function testTokenIsTrimmed(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer  padded-token  ';

        // Act + Assert
        $this->assertSame('padded-token', Request::accessToken());
    }
}
