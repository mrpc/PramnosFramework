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
 * - Falls back to RSA-key lookup when the token header declares RS256
 * - Handles the deprecated HTTP_USERAUTH session path
 *
 * Real HS256 tokens are produced with \Pramnos\Auth\JWT::encode(); user
 * loading is isolated from the database via the ApiAuthApp fixture User
 * (see tests/Fixtures/ApiAuthApp/User.php).
 */
#[CoversClass(ApiAuthMiddleware::class)]
class ApiAuthMiddlewareTest extends TestCase
{
    /**
     * HMAC key for HS256 tokens — web-token enforces a minimum of 256 bits
     * (32 bytes) for HS256 keys, so a short throwaway string won't do.
     */
    private const HMAC_KEY = 'unit-test-hmac-key-0123456789abcdef';

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
        $mw = new ApiAuthMiddleware(fn() => true, self::HMAC_KEY);

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
    // Valid token — full HS256 round-trip with a DB-free User double
    // -------------------------------------------------------------------------

    /**
     * Happy path: a correctly signed, unexpired HS256 token must log the user
     * in ($_SESSION['logged']=true, $_SESSION['user']=User) and call $next.
     *
     * Uses the ApiAuthApp fixture namespace so resolveUser() picks the
     * database-free User double, whose loadByToken() assigns userid=42.
     */
    public function testValidHs256TokenLogsUserInAndCallsNext(): void
    {
        // Arrange — real JWT signed with the same HMAC key the middleware uses
        $_SERVER['HTTP_APIKEY']      = 'valid-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = \Pramnos\Auth\JWT::encode(
            ['sub' => 42, 'exp' => time() + 3600],
            self::HMAC_KEY
        );

        \Pramnos\Tests\Fixtures\ApiAuthApp\User::reset();
        \Pramnos\Tests\Fixtures\ApiAuthApp\User::$loadByTokenUserid = 42;

        $mw = new ApiAuthMiddleware(
            fn() => true,
            self::HMAC_KEY,
            'Pramnos\\Tests\\Fixtures\\ApiAuthApp'
        );

        // Act
        $result = $mw->handle(
            Request::create('/api/secure', 'GET'),
            fn() => 'controller-response'
        );

        // Assert — pipeline continued and the session was populated
        $this->assertSame('controller-response', $result);
        $this->assertTrue($_SESSION['logged']);
        $this->assertInstanceOf(
            \Pramnos\Tests\Fixtures\ApiAuthApp\User::class,
            $_SESSION['user']
        );
        $this->assertSame(42, $_SESSION['user']->userid);
        // loadByToken received the exact raw token from the header
        $this->assertSame(
            [$_SERVER['HTTP_ACCESSTOKEN']],
            \Pramnos\Tests\Fixtures\ApiAuthApp\User::$loadedTokens
        );
    }

    /**
     * A cryptographically valid token that resolves to no real user
     * (userid <= 1, i.e. anonymous) must be rejected with 403 and
     * $_SESSION['user'] must be cleared. This proves signature validity alone
     * is not enough — the token must map to an actual account.
     */
    public function testValidJwtForUnknownUserReturns403(): void
    {
        // Arrange — signed token, but loadByToken() leaves userid at 1 (anonymous)
        $_SERVER['HTTP_APIKEY']      = 'valid-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = \Pramnos\Auth\JWT::encode(
            ['sub' => 999, 'exp' => time() + 3600],
            self::HMAC_KEY
        );

        \Pramnos\Tests\Fixtures\ApiAuthApp\User::reset(); // userid stays 1

        $called = false;
        $mw     = new ApiAuthMiddleware(
            fn() => true,
            self::HMAC_KEY,
            'Pramnos\\Tests\\Fixtures\\ApiAuthApp'
        );

        // Act
        $result = $mw->handle(
            Request::create('/api/secure', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert — short-circuited with the user cleared from the session
        $this->assertFalse($called, '$next must not run for an unknown user');
        $this->assertNull($_SESSION['user']);

        $decoded = json_decode($result, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('InvalidAccessToken', $decoded['error']);
    }

    /**
     * An expired token (exp beyond the 60s leeway) must be rejected with 403
     * and the envelope must carry a 'data' field with the underlying JWT
     * exception message — covering the catch branch and the optional-data
     * arm of error().
     */
    public function testExpiredTokenReturns403WithExceptionDetail(): void
    {
        // Arrange — token expired one hour ago (leeway is only 60 seconds)
        $_SERVER['HTTP_APIKEY']      = 'valid-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = \Pramnos\Auth\JWT::encode(
            ['sub' => 42, 'exp' => time() - 3600],
            self::HMAC_KEY
        );

        $mw = new ApiAuthMiddleware(fn() => true, self::HMAC_KEY);

        // Act
        $result  = $mw->handle(Request::create('/api/secure', 'GET'), fn() => 'ok');
        $decoded = json_decode($result, true);

        // Assert — 403 envelope with the JWT exception detail attached
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('InvalidAccessToken', $decoded['error']);
        $this->assertArrayHasKey('data', $decoded, 'exception message must be exposed as data');
        $this->assertNotEmpty($decoded['data']);
    }

    /**
     * A token whose header declares RS256 must route through the RSA-key
     * branch: the middleware searches for public.key files (none exist in
     * the test environment) and allows both HS256/RS256 during decode.
     * The forged signature then fails verification → 403.
     */
    public function testRs256TokenHeaderTriggersRsaBranchAndFailsVerification(): void
    {
        // Arrange — hand-built JWT with an RS256 header and a bogus signature
        $b64 = fn(array $part): string => rtrim(
            strtr(base64_encode((string) json_encode($part)), '+/', '-_'),
            '='
        );
        $_SERVER['HTTP_APIKEY']      = 'valid-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = $b64(['typ' => 'JWT', 'alg' => 'RS256'])
            . '.' . $b64(['sub' => 42, 'exp' => time() + 3600])
            . '.Zm9yZ2VkLXNpZ25hdHVyZQ';

        $called = false;
        $mw     = new ApiAuthMiddleware(fn() => true, self::HMAC_KEY);

        // Act
        $result = $mw->handle(
            Request::create('/api/secure', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert — verification failed, pipeline short-circuited
        $this->assertFalse($called, '$next must not run on a forged RS256 token');

        $decoded = json_decode($result, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('InvalidAccessToken', $decoded['error']);
    }

    // -------------------------------------------------------------------------
    // Legacy HTTP_USERAUTH path (@deprecated since v1.2)
    // -------------------------------------------------------------------------

    /**
     * Legacy auth: when HTTP_USERAUTH matches the session's stored auth hash
     * and the session is logged in, the user object is rebuilt from
     * $_SESSION['uid'] and stored in $_SESSION['user']; $next runs normally.
     */
    public function testLegacyUserauthMatchingSessionRebuildsUser(): void
    {
        // Arrange — pre-authenticated session matching the header hash
        $_SERVER['HTTP_APIKEY']   = 'valid-key';
        $_SERVER['HTTP_USERAUTH'] = 'session-auth-hash';
        $_SESSION['logged']       = true;
        $_SESSION['auth']         = 'session-auth-hash';
        $_SESSION['uid']          = 7;

        $mw = new ApiAuthMiddleware(
            fn() => true,
            self::HMAC_KEY,
            'Pramnos\\Tests\\Fixtures\\ApiAuthApp'
        );

        // Act
        $result = $mw->handle(Request::create('/api/data', 'GET'), fn() => 'ok');

        // Assert — user rebuilt with the session uid, pipeline continued
        $this->assertSame('ok', $result);
        $this->assertInstanceOf(
            \Pramnos\Tests\Fixtures\ApiAuthApp\User::class,
            $_SESSION['user']
        );
        $this->assertSame(7, $_SESSION['user']->userid);

        unset($_SESSION['auth'], $_SESSION['uid']);
    }

    /**
     * Legacy auth with a NON-matching hash must not rebuild the user — but it
     * also must not block the request: the deprecated path degrades silently
     * and $next still runs (authorization happens downstream).
     */
    public function testLegacyUserauthMismatchSkipsUserButCallsNext(): void
    {
        // Arrange — header hash differs from the session's stored hash
        $_SERVER['HTTP_APIKEY']   = 'valid-key';
        $_SERVER['HTTP_USERAUTH'] = 'wrong-hash';
        $_SESSION['logged']       = true;
        $_SESSION['auth']         = 'session-auth-hash';
        $_SESSION['uid']          = 7;

        $mw = new ApiAuthMiddleware(fn() => true);

        // Act
        $result = $mw->handle(Request::create('/api/data', 'GET'), fn() => 'ok');

        // Assert — request continues, but no user object was attached
        $this->assertSame('ok', $result);
        $this->assertArrayNotHasKey('user', $_SESSION);

        unset($_SESSION['auth'], $_SESSION['uid']);
    }

    /**
     * When the configured application namespace has no User class,
     * resolveUser() must fall back to \Pramnos\User\User instead of fataling
     * on an unknown class. Proven via the malformed-token path, which
     * resolves the user before validating the token.
     */
    public function testUnknownAppNamespaceFallsBackToFrameworkUser(): void
    {
        // Arrange — namespace that cannot resolve to a User class
        $_SERVER['HTTP_APIKEY']      = 'valid-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = 'not.a.jwt';

        $mw = new ApiAuthMiddleware(fn() => true, self::HMAC_KEY, 'No\\Such\\Namespace');

        // Act — must not throw despite the bogus namespace
        $result  = $mw->handle(Request::create('/api/secure', 'GET'), fn() => 'ok');
        $decoded = json_decode($result, true);

        // Assert — normal token-validation error, i.e. resolveUser() survived
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
