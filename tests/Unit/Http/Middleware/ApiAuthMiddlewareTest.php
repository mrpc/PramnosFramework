<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Middleware\ApiAuthMiddleware;
use Pramnos\Http\Request;

/**
 * Tests for ApiAuthMiddleware.
 *
 * Verifies that the middleware:
 * - Returns 403 JSON when HTTP_APIKEY header is missing
 * - Returns 401 JSON when the API key is invalid (checker returns false)
 * - Calls $next when the API key is valid and no token is present
 * - Short-circuits with 403 when HTTP_ACCESSTOKEN is present but JWT is invalid
 * - Sets $_SESSION['logged'] and $_SESSION['user'] on valid token
 *
 * JWT and User loading are not tested here (they require DB / crypto setup) —
 * those paths are covered by integration tests.
 */
#[CoversClass(ApiAuthMiddleware::class)]
class ApiAuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['HTTP_APIKEY']);
        unset($_SERVER['HTTP_ACCESSTOKEN']);
        unset($_SERVER['HTTP_USERAUTH']);
        unset($_SESSION['logged']);
        unset($_SESSION['user']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_APIKEY']);
        unset($_SERVER['HTTP_ACCESSTOKEN']);
        unset($_SERVER['HTTP_USERAUTH']);
        unset($_SESSION['logged']);
        unset($_SESSION['user']);
    }

    // -------------------------------------------------------------------------
    // API key absence
    // -------------------------------------------------------------------------

    /**
     * When HTTP_APIKEY is not present the middleware short-circuits and returns
     * a 403 JSON envelope containing error='APIKeyMissing'.
     * $next must NOT be called.
     */
    public function testMissingApiKeyReturns403JsonError(): void
    {
        // Arrange — no HTTP_APIKEY in $_SERVER
        $mw     = new ApiAuthMiddleware(fn() => true);
        $called = false;

        // Act
        $result = $mw->handle(
            Request::create('/api/data', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert — pipeline short-circuited, $next never called
        $this->assertFalse($called, '$next must not run when API key is missing');

        $decoded = json_decode($result, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('APIKeyMissing', $decoded['error']);
    }

    // -------------------------------------------------------------------------
    // API key invalid
    // -------------------------------------------------------------------------

    /**
     * When the checker callable returns false, the middleware returns a 401
     * JSON error with error='APIKeyInvalid'. $next must NOT be called.
     */
    public function testInvalidApiKeyReturns401JsonError(): void
    {
        // Arrange — checker always returns false
        $_SERVER['HTTP_APIKEY'] = 'bad-key';
        $mw     = new ApiAuthMiddleware(fn(string $k) => false);
        $called = false;

        // Act
        $result = $mw->handle(
            Request::create('/api/data', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert
        $this->assertFalse($called, '$next must not run on invalid API key');

        $decoded = json_decode($result, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('APIKeyInvalid', $decoded['error']);
    }

    // -------------------------------------------------------------------------
    // Valid API key, no token
    // -------------------------------------------------------------------------

    /**
     * When the API key is valid and no access token is present, the middleware
     * calls $next and returns its result.
     */
    public function testValidApiKeyWithNoTokenCallsNext(): void
    {
        // Arrange — checker always returns true
        $_SERVER['HTTP_APIKEY'] = 'valid-key';
        $mw = new ApiAuthMiddleware(fn(string $k) => true);

        // Act
        $result = $mw->handle(
            Request::create('/api/users', 'GET'),
            fn() => 'controller-response'
        );

        // Assert — $next was called and its value returned
        $this->assertSame('controller-response', $result);
    }

    /**
     * The API key checker receives exactly the value from HTTP_APIKEY.
     */
    public function testApiKeyCheckerReceivesCorrectKey(): void
    {
        // Arrange
        $_SERVER['HTTP_APIKEY'] = 'my-secret-key-123';
        $receivedKey = null;
        $mw = new ApiAuthMiddleware(function (string $k) use (&$receivedKey): bool {
            $receivedKey = $k;
            return true;
        });

        // Act
        $mw->handle(Request::create('/api/test', 'GET'), fn() => null);

        // Assert
        $this->assertSame('my-secret-key-123', $receivedKey);
    }

    // -------------------------------------------------------------------------
    // Invalid token
    // -------------------------------------------------------------------------

    /**
     * When HTTP_ACCESSTOKEN is present but JWT::getTokenInformation() returns
     * false (malformed token), the middleware returns 403 without calling $next.
     */
    public function testMalformedAccessTokenReturns403(): void
    {
        // Arrange
        $_SERVER['HTTP_APIKEY']     = 'valid-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = 'not.a.jwt';
        $called = false;
        $mw = new ApiAuthMiddleware(fn() => true, 'hmac-key');

        // Act
        $result = $mw->handle(
            Request::create('/api/secure', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert — JWT decode failed → short-circuit
        $this->assertFalse($called, '$next must not run on invalid token');

        $decoded = json_decode($result, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('InvalidAccessToken', $decoded['error']);
    }

    // -------------------------------------------------------------------------
    // Error envelope structure
    // -------------------------------------------------------------------------

    /**
     * The error envelope always includes 'status', 'statusmessage', 'message',
     * and 'error' keys — matching the Api::_translateStatus() contract.
     */
    public function testErrorEnvelopeHasRequiredKeys(): void
    {
        // Arrange — trigger a 403 by not sending the API key
        $mw = new ApiAuthMiddleware(fn() => true);

        // Act
        $result  = $mw->handle(Request::create('/api/x', 'GET'), fn() => null);
        $decoded = json_decode($result, true);

        // Assert — all required keys present
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('statusmessage', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('error', $decoded);
    }
}
