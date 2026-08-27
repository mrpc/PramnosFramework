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

    /**
     * APP_DEBUG as it was before the test, so tearDown can put it back.
     *
     * @var string|false
     */
    private $originalAppDebug;

    protected function setUp(): void
    {
        // The identity is request-scoped, and a test run is one process: a
        // sealed caller from one test would answer for the next.
        \Pramnos\Http\RequestIdentity::reset();
        unset($_SERVER['HTTP_APIKEY']);
        unset($_SERVER['HTTP_ACCESSTOKEN']);
        unset($_SERVER['HTTP_USERAUTH']);
        unset($_SESSION['logged']);
        unset($_SESSION['user']);
        unset($_SESSION['usertoken'], $_SESSION['uid'], $_SESSION['csrf_token']);
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_XSRF_TOKEN']);

        $this->originalAppDebug = getenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();
        unset($_SERVER['HTTP_APIKEY']);
        unset($_SERVER['HTTP_ACCESSTOKEN']);
        unset($_SERVER['HTTP_USERAUTH']);
        unset($_SESSION['logged']);
        unset($_SESSION['user']);
        unset($_SESSION['usertoken'], $_SESSION['uid'], $_SESSION['csrf_token']);
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_XSRF_TOKEN']);

        // Whether the process is "developing" is ambient state; a test that
        // changes it puts it back, or it decides the answer for everything that
        // runs after it.
        if ($this->originalAppDebug === false) {
            putenv('APP_DEBUG');
        } else {
            putenv('APP_DEBUG=' . $this->originalAppDebug);
        }
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
     * A token-less API request must be ANONYMOUS: any ambient session identity
     * (e.g. a same-domain web-login cookie) is cleared, so it can never
     * authenticate an API call. Only a valid accessToken authenticates.
     */
    public function testNoTokenMeansAnonymousWithoutDestroyingTheSession(): void
    {
        // Arrange — a web session is present, but no accessToken is sent
        $_SERVER['HTTP_APIKEY'] = 'valid-key';
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 42;
        $_SESSION['user']   = (object) ['userid' => 42];
        $before = $_SESSION;

        $mw = new ApiAuthMiddleware(fn(string $k) => true);

        // Act
        $result = $mw->handle(Request::create('/api/me', 'GET'), fn() => 'ok');

        // Assert — the request is anonymous...
        $this->assertSame('ok', $result);
        $this->assertTrue(\Pramnos\Http\RequestIdentity::isSealed());
        $this->assertNull(\Pramnos\Http\RequestIdentity::user());
        $this->assertFalse(\Pramnos\User\User::getCurrentUser());

        // ...and the browser's session is untouched.
        //
        // It used to be destroyed here, to make sure a cookie could not
        // authenticate an API call. It achieved that by signing the user out of
        // the website: in an application serving both from one origin, a single
        // anonymous poll from a widget logged its own reader out. Declining to
        // read the session does the same job without the collateral.
        $this->assertSame($before, $_SESSION);
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

        // Assert — pipeline continued and the request has an identity.
        //
        // The identity, not the session: a token authenticates the call it
        // arrived on, and writing the session would make it authenticate the
        // browser's next page too.
        $this->assertSame('controller-response', $result);
        $identity = \Pramnos\Http\RequestIdentity::user();
        $this->assertInstanceOf(
            \Pramnos\Tests\Fixtures\ApiAuthApp\User::class,
            $identity
        );
        $this->assertSame(42, $identity->userid);
        $this->assertSame('accessToken', \Pramnos\Http\RequestIdentity::via());
        $this->assertArrayNotHasKey('logged', $_SESSION, 'the API writes no session');
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

        // Assert — short-circuited, and the request is left with nobody
        $this->assertFalse($called, '$next must not run for an unknown user');
        $this->assertNull(\Pramnos\Http\RequestIdentity::user());

        $decoded = json_decode($result, true);
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('InvalidAccessToken', $decoded['error']);
    }

    /**
     * An expired token (exp beyond the 60s leeway) must be rejected with 403
     * and the envelope must carry a 'data' field with the underlying JWT
     * exception message — covering the catch branch and the optional-data
     * arm of error().
     *
     * The detail is disclosed only while developing, so this test says so.
     * It used to rely on a sibling test file having set APP_DEBUG and left it
     * set: it passed for `--filter Middleware` and failed for this class alone.
     */
    public function testExpiredTokenReturns403WithExceptionDetail(): void
    {
        // Arrange — the detail is debug-only.
        putenv('APP_DEBUG=1');

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
     * The same envelope outside development must withhold the detail.
     *
     * What the JWT library says describes the token, not the caller's mistake,
     * and a rejected caller has already been told everything they are entitled
     * to. This branch had no test, which is how the one above came to depend on
     * ambient state unnoticed — nothing asserted that the state mattered.
     */
    public function testExpiredTokenWithholdsExceptionDetailOutsideDevelopment(): void
    {
        // Arrange — DEVELOPMENT is a constant and cannot be unset, so when it is
        // true for this process the non-debug branch is unreachable by design.
        if (defined('DEVELOPMENT') && DEVELOPMENT === true) {
            $this->markTestSkipped(
                'DEVELOPMENT is true for this process, so the detail is '
                . 'disclosed by design.'
            );
        }
        putenv('APP_DEBUG=0');

        $_SERVER['HTTP_APIKEY']      = 'valid-key';
        $_SERVER['HTTP_ACCESSTOKEN'] = \Pramnos\Auth\JWT::encode(
            ['sub' => 42, 'exp' => time() - 3600],
            self::HMAC_KEY
        );

        $mw = new ApiAuthMiddleware(fn() => true, self::HMAC_KEY);

        // Act
        $result  = $mw->handle(Request::create('/api/secure', 'GET'), fn() => 'ok');
        $decoded = json_decode($result, true);

        // Assert — the rejection is intact, the internals are not shared.
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('InvalidAccessToken', $decoded['error']);
        $this->assertArrayNotHasKey('data', $decoded,
            'the JWT exception message must not leave the server outside development');
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

        // Assert — user rebuilt from the session uid and published as this
        // request's identity. The deprecated header *is* a session credential,
        // so reading the session is correct here; writing it back is not.
        $this->assertSame('ok', $result);
        $this->assertSame('userAuth', \Pramnos\Http\RequestIdentity::via());
        $this->assertInstanceOf(
            \Pramnos\Tests\Fixtures\ApiAuthApp\User::class,
            \Pramnos\Http\RequestIdentity::user()
        );
        $this->assertSame(7, \Pramnos\Http\RequestIdentity::user()->userid);

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

    // -------------------------------------------------------------------------
    // The application's own signed-in page — no API key, session + CSRF instead
    // -------------------------------------------------------------------------

    /**
     * A same-origin request from a signed-in page is let through without an API key.
     *
     * The one caller that legitimately has none: a page cannot be given a key, since
     * anything the document can read a reader of the document can read. Until this
     * existed a server-rendered screen could not call its own endpoint at all — the
     * framework's own search box answered 403 on every project.
     */
    public function testSignedInPageWithCsrfTokenIsLetThroughWithoutAnApiKey(): void
    {
        // Arrange — a live web session, and the token only our own document can read
        $csrf = $this->injectWebSession(7);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;

        $mw     = new ApiAuthMiddleware(fn() => true);
        $called = false;

        // Act
        $result = $mw->handle(
            Request::create('/api/1.0/admin/search', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert
        $this->assertTrue($called, 'a signed-in page must reach its own endpoint');
        $this->assertSame('ok', $result);
        $this->assertSame(
            7,
            (int) (\Pramnos\User\User::getCurrentUser()->userid ?? 0),
            'and be identified as the user whose session it is'
        );
    }

    /**
     * The session cookie alone is not enough.
     *
     * It is attached to a cross-site request too, so accepting it by itself would
     * turn every API endpoint into one any other site could call on a visitor's
     * behalf. The CSRF token is the half that proves the caller read our page.
     */
    public function testSessionWithoutTheCsrfHeaderIsStillRefused(): void
    {
        // Arrange — signed in, but the request carries no CSRF header
        $this->injectWebSession(7);

        $mw     = new ApiAuthMiddleware(fn() => true);
        $called = false;

        // Act
        $result = $mw->handle(
            Request::create('/api/1.0/admin/search', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert
        $this->assertFalse($called, '$next must not run on a cookie alone');
        $this->assertSame('APIKeyMissing', json_decode($result, true)['error']);
    }

    /**
     * A wrong CSRF token is refused, which is the whole point of comparing it.
     */
    public function testSessionWithAMismatchedCsrfTokenIsRefused(): void
    {
        // Arrange
        $this->injectWebSession(7);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'not-the-session-token';

        $mw     = new ApiAuthMiddleware(fn() => true);
        $called = false;

        // Act
        $result = $mw->handle(
            Request::create('/api/1.0/admin/search', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert
        $this->assertFalse($called);
        $this->assertSame('APIKeyMissing', json_decode($result, true)['error']);
    }

    /**
     * A session belonging to the anonymous account authenticates nothing.
     *
     * User 1 is the anonymous/system account: letting it through would publish it as
     * the caller's identity, and every `isAuthenticated()` check downstream reads
     * that as a signed-in user.
     */
    public function testAnonymousSessionIsRefusedRatherThanPublishedAsAnIdentity(): void
    {
        // Arrange
        $csrf = $this->injectWebSession(1);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;

        $mw     = new ApiAuthMiddleware(fn() => true);
        $called = false;

        // Act
        $result = $mw->handle(
            Request::create('/api/1.0/admin/search', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert
        $this->assertFalse($called);
        $this->assertSame(401, json_decode($result, true)['status']);
    }

    /**
     * A token of some other type in the session is not a web session.
     *
     * `$_SESSION['usertoken']` is written by more than the login path, and an
     * access token that happened to be parked there must not become a way past the
     * API key check.
     */
    public function testATokenThatIsNotAWebSessionDoesNotOpenThePath(): void
    {
        // Arrange
        $csrf = $this->injectWebSession(7);
        $_SESSION['usertoken']->tokentype = \Pramnos\User\Token::TYPE_ACCESS_TOKEN;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;

        $mw     = new ApiAuthMiddleware(fn() => true);
        $called = false;

        // Act
        $result = $mw->handle(
            Request::create('/api/1.0/admin/search', 'GET'),
            function () use (&$called): string { $called = true; return 'ok'; }
        );

        // Assert
        $this->assertFalse($called);
        $this->assertSame('APIKeyMissing', json_decode($result, true)['error']);
    }

    /**
     * A signed-in browser session, as the login path leaves it.
     *
     * @param int $userid Who the session belongs to
     * @return string The session's CSRF token, to be echoed as the header
     */
    private function injectWebSession(int $userid): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $csrf = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrf;

        $token            = new \Pramnos\User\Token();
        $token->tokentype = \Pramnos\User\Token::TYPE_WEB_SESSION;
        $token->status    = 1;
        $token->tokenid   = 42;
        $token->userid    = $userid;
        $_SESSION['usertoken'] = $token;
        $_SESSION['uid']       = $userid;

        // Pre-set, so the middleware needs no database to answer who this is.
        $user         = new \stdClass();
        $user->userid = $userid;
        $_SESSION['user'] = $user;

        return $csrf;
    }
}
