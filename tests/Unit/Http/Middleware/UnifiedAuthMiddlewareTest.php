<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\UnifiedAuthMiddleware;
use Pramnos\Http\Request;
use Pramnos\User\Token;

/**
 * Unit tests for UnifiedAuthMiddleware.
 *
 * The middleware accepts two credential types:
 *   1. Bearer JWT token — validated via JWT::decode() + usertokens lookup
 *   2. Session cookie + X-CSRF-Token header — web-session token path (Phase 16)
 *
 * These tests verify the public contract: what HTTP status / error key is
 * returned for each auth scenario, without actually hitting the database.
 * The Bearer-path tests rely on JWT integration and are marked as needing
 * a live database; the session-cookie tests are pure unit tests.
 */
class UnifiedAuthMiddlewareTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        $this->request = new Request();

        // Reset server superglobal slices used by the middleware
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_X_CSRF_TOKEN'],
            $_SERVER['HTTP_X_XSRF_TOKEN'],
        );
        unset($_SESSION['usertoken'], $_SESSION['user'], $_SESSION['logged']);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Build the middleware under test.
     */
    private function make(string $authKey = 'test-key', ?string $ns = null): UnifiedAuthMiddleware
    {
        return new UnifiedAuthMiddleware(authKey: $authKey, appNamespace: $ns);
    }

    /**
     * A $next callable that always succeeds, returning the sentinel string 'OK'.
     */
    private function nextOk(): callable
    {
        return fn(Request $r) => 'OK';
    }

    // -------------------------------------------------------------------------
    // No-credentials path
    // -------------------------------------------------------------------------

    /**
     * When no Bearer token and no session credentials are present, the
     * middleware must short-circuit with a 401 JSON error envelope.
     * This prevents unauthenticated access to the route.
     */
    public function testNoCredentialsReturns401(): void
    {
        // Arrange
        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('Unauthenticated', $decoded['error']);
    }

    /**
     * The $next callable must NOT be invoked when credentials are absent.
     * Short-circuiting means the controller is never reached.
     */
    public function testNoCredentialsDoesNotCallNext(): void
    {
        // Arrange
        $mw   = $this->make();
        $called = false;
        $next   = function (Request $r) use (&$called): string {
            $called = true;
            return 'OK';
        };

        // Act
        $mw->handle($this->request, $next);

        // Assert
        $this->assertFalse($called, '$next must not be called without valid credentials');
    }

    // -------------------------------------------------------------------------
    // Session-cookie path — structural tests (no DB required)
    // -------------------------------------------------------------------------

    /**
     * When a TYPE_WEB_SESSION token is in the session AND the X-CSRF-Token
     * header matches the session CSRF token, the middleware must let the
     * request through (call $next and return its result).
     */
    public function testSessionCookieWithValidCsrfCallsNext(): void
    {
        // Arrange
        $csrfToken = bin2hex(random_bytes(16));
        $this->injectSessionCsrf($csrfToken);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;

        $token               = new Token();
        $token->tokentype    = Token::TYPE_WEB_SESSION;
        $token->status       = 1;
        $token->tokenid      = 99;
        $token->userid       = 5;
        $_SESSION['usertoken'] = $token;
        $_SESSION['uid']       = 5;
        // Pre-set user so handleSessionCookie does not attempt a DB load
        $mockUser = new \stdClass();
        $mockUser->userid = 5;
        $_SESSION['user'] = $mockUser;

        $mw   = $this->make();
        $called = false;
        $next   = function (Request $r) use (&$called): string {
            $called = true;
            return 'OK';
        };

        // Act
        $result = $mw->handle($this->request, $next);

        // Assert
        $this->assertTrue($called, '$next must be called when session + CSRF are valid');
        $this->assertSame('OK', $result);
    }

    /**
     * A web-session token in the session with a WRONG CSRF token must be
     * rejected with 401.  An attacker cannot fake the CSRF header without
     * reading the victim's session.
     */
    public function testSessionCookieWithWrongCsrfReturns401(): void
    {
        // Arrange
        $this->injectSessionCsrf('correct-token');
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong-token';

        $token            = new Token();
        $token->tokentype = Token::TYPE_WEB_SESSION;
        $token->status    = 1;
        $token->tokenid   = 99;
        $_SESSION['usertoken'] = $token;

        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status']);
    }

    /**
     * When the token in the session is NOT a TYPE_WEB_SESSION token, the
     * session-cookie path must be skipped — the middleware falls through to
     * "no credentials" and returns 401.
     */
    public function testNonWebSessionTokenInSessionIsRejected(): void
    {
        // Arrange
        $csrfToken = bin2hex(random_bytes(16));
        $this->injectSessionCsrf($csrfToken);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;

        $token            = new Token();
        $token->tokentype = Token::TYPE_API; // wrong type
        $token->status    = 1;
        $token->tokenid   = 42;
        $_SESSION['usertoken'] = $token;

        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status'],
            'An API token in the session must not satisfy the web-session cookie path');
    }

    /**
     * When the session token status is not active (status !== 1), the
     * session-cookie path must also be rejected.
     */
    public function testInactiveSessionTokenIsRejected(): void
    {
        // Arrange
        $csrfToken = bin2hex(random_bytes(16));
        $this->injectSessionCsrf($csrfToken);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;

        $token            = new Token();
        $token->tokentype = Token::TYPE_WEB_SESSION;
        $token->status    = 0; // inactive
        $token->tokenid   = 7;
        $_SESSION['usertoken'] = $token;

        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status'],
            'An inactive token must not pass the session-cookie auth path');
    }

    /**
     * When the CSRF header is absent entirely, the session-cookie path must
     * refuse the request.  This prevents CSRF attacks where the attacker
     * tricks the victim's browser into making API calls.
     */
    public function testMissingCsrfHeaderIsRejected(): void
    {
        // Arrange
        $csrfToken = bin2hex(random_bytes(16));
        $this->injectSessionCsrf($csrfToken);
        // No HTTP_X_CSRF_TOKEN set

        $token            = new Token();
        $token->tokentype = Token::TYPE_WEB_SESSION;
        $token->status    = 1;
        $token->tokenid   = 8;
        $_SESSION['usertoken'] = $token;

        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status'],
            'Requests without X-CSRF-Token must be rejected even with a valid session token');
    }

    /**
     * The middleware also accepts the X-XSRF-TOKEN header (Angular / Axios default)
     * as an alias for X-CSRF-Token.
     */
    public function testXsrfTokenHeaderIsAlsoAccepted(): void
    {
        // Arrange
        $csrfToken = bin2hex(random_bytes(16));
        $this->injectSessionCsrf($csrfToken);
        $_SERVER['HTTP_X_XSRF_TOKEN'] = $csrfToken; // Axios default header name

        $token            = new Token();
        $token->tokentype = Token::TYPE_WEB_SESSION;
        $token->status    = 1;
        $token->tokenid   = 9;
        $token->userid    = 3;
        $_SESSION['usertoken'] = $token;
        $_SESSION['uid']       = 3;
        $mockUser = new \stdClass();
        $mockUser->userid = 3;
        $_SESSION['user'] = $mockUser;

        $mw = $this->make();

        // Act
        $result = $mw->handle($this->request, $this->nextOk());

        // Assert
        $this->assertSame('OK', $result,
            'X-XSRF-TOKEN must be accepted as alias for X-CSRF-Token');
    }

    // -------------------------------------------------------------------------
    // Bearer path — structural tests (error envelope shape)
    // -------------------------------------------------------------------------

    /**
     * When a malformed Bearer token is sent (not a valid JWT), the middleware
     * must return a 401 JSON envelope with error key 'InvalidToken'.
     */
    public function testInvalidBearerTokenReturns401(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer this-is-not-a-jwt';
        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('InvalidToken', $decoded['error']);
    }

    /**
     * Bearer takes priority over the session-cookie path.
     * When both are present, only the Bearer path is evaluated.
     */
    public function testBearerTakesPriorityOverSessionCookie(): void
    {
        // Arrange — valid session credentials
        $csrfToken = bin2hex(random_bytes(16));
        $this->injectSessionCsrf($csrfToken);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrfToken;

        $token            = new Token();
        $token->tokentype = Token::TYPE_WEB_SESSION;
        $token->status    = 1;
        $_SESSION['usertoken'] = $token;

        // But also set an invalid Bearer — the middleware must try Bearer first
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer malformed-jwt';

        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert — 401 from the Bearer path, NOT 'OK' from the session path
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('InvalidToken', $decoded['error'],
            'Bearer header must be tried before session cookie — session alone must not pass');
    }

    // -------------------------------------------------------------------------
    // Token type constants
    // -------------------------------------------------------------------------

    /**
     * Verify the full set of token-type constants introduced in Phase 16.
     * These replace arbitrary strings in existing code that creates tokens.
     */
    public function testTokenTypeConstantsAreDefinedCorrectly(): void
    {
        // Assert — value matches the string used in existing DB rows
        $this->assertSame('web_session',    Token::TYPE_WEB_SESSION);
        $this->assertSame('auth',           Token::TYPE_API);
        $this->assertSame('access_token',   Token::TYPE_ACCESS_TOKEN);
        $this->assertSame('refresh_token',  Token::TYPE_REFRESH_TOKEN);
        $this->assertSame('auth_code',      Token::TYPE_AUTH_CODE);
        $this->assertSame('apns',           Token::TYPE_APNS);
        $this->assertSame('gcm',            Token::TYPE_GCM);
    }

    // -------------------------------------------------------------------------
    // csrfMeta helper
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Bearer extraction edge cases
    // -------------------------------------------------------------------------

    /**
     * The middleware must also read the REDIRECT_HTTP_AUTHORIZATION header —
     * Apache/FastCGI setups often forward Authorization under that name.
     * An invalid JWT in the redirect header proves extraction succeeded
     * (the response is InvalidToken, not Unauthenticated).
     */
    public function testBearerExtractedFromRedirectHttpAuthorization(): void
    {
        // Arrange — only the REDIRECT_ variant is set
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer not-a-real-jwt';
        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert — Bearer path was entered (InvalidToken), not the 401 fallback
        $decoded = json_decode($response, true);
        $this->assertSame('InvalidToken', $decoded['error'],
            'REDIRECT_HTTP_AUTHORIZATION must be honoured as a Bearer source');
    }

    /**
     * An Authorization header of exactly "Bearer " (empty token) must NOT be
     * treated as a credential — the middleware falls through to the
     * no-credentials 401.
     */
    public function testEmptyBearerValueFallsThroughToUnauthenticated(): void
    {
        // Arrange — "Bearer " with no token after the space
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert — generic Unauthenticated, not the Bearer-path InvalidToken
        $decoded = json_decode($response, true);
        $this->assertSame('Unauthenticated', $decoded['error'],
            'An empty Bearer value must not enter the Bearer validation path');
    }

    /**
     * An RS256-signed token must trigger the public-key lookup branch; with no
     * key file installed in this project the HS256 fallback key fails the
     * signature check and the error envelope must carry the decode detail.
     */
    public function testRs256TokenTriggersKeyLookupAndDetailInError(): void
    {
        // Arrange — well-formed JWT with alg=RS256 (signature is garbage)
        $header  = rtrim(strtr(base64_encode(json_encode(
            ['typ' => 'JWT', 'alg' => 'RS256']
        )), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(
            ['sub' => 1, 'exp' => time() + 3600]
        )), '+/', '-_'), '=');
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$header}.{$payload}.invalidsig";
        $mw = $this->make();

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert — Bearer validation failed and the exception detail is exposed
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('InvalidToken', $decoded['error']);
        $this->assertSame('Bearer token validation failed.', $decoded['message']);
        $this->assertArrayHasKey('data', $decoded,
            'The decode exception message must be returned as detail');
    }

    // -------------------------------------------------------------------------
    // Bearer path with valid JWTs (real database)
    // -------------------------------------------------------------------------

    /**
     * Ensure the test database connection and the users/usertokens schema
     * exist for the JWT round-trip tests below.
     */
    private function bootDatabase(): \Pramnos\Database\Database
    {
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        \Pramnos\User\User::setupDb();
        return $db;
    }

    /**
     * A correctly signed HS256 JWT that matches an active usertokens row must
     * authenticate the user: $next is called and the session is marked logged.
     *
     * Full happy path: extractBearer → JWT::decode OK → loadByToken finds the
     * row → userid > 1 → pipeline continues.
     */
    public function testValidBearerTokenWithDbRowAuthenticates(): void
    {
        // Arrange — real DB row whose token column holds the JWT itself
        $db  = $this->bootDatabase();
        $jwt = \Pramnos\Auth\JWT::encode(
            ['sub' => 661, 'exp' => time() + 3600], 'test-key-0123456789-0123456789-0123'
        );
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("DELETE FROM `usertokens` WHERE `userid` = 661");
        $db->query("DELETE FROM `users` WHERE `userid` = 661");
        $db->query(
            "INSERT INTO `users` (`userid`, `username`, `email`, `active`)
             VALUES (661, 'jwtuser', 'jwt@test.com', 1)"
        );
        $db->query($db->prepareQuery(
            "INSERT INTO `usertokens`
             (`userid`, `tokentype`, `token`, `created`, `status`, `expires`)
             VALUES (661, 'auth', %s, %d, 1, 0)",
            $jwt, time()
        ));
        $db->query("SET FOREIGN_KEY_CHECKS=1");

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        $mw = $this->make('test-key-0123456789-0123456789-0123');

        try {
            // Act
            $result = $mw->handle($this->request, $this->nextOk());

            // Assert — pipeline continued and the session was authenticated
            $this->assertSame('OK', $result,
                'A valid Bearer token backed by an active DB row must pass');
            $this->assertTrue($_SESSION['logged'] ?? false);
            $this->assertSame(661, (int) ($_SESSION['user']->userid ?? 0),
                'The matched user must be stored in the session');
        } finally {
            // Cleanup — remove the rows and session state we created
            $db->query("DELETE FROM `usertokens` WHERE `userid` = 661");
            $db->query("DELETE FROM `users` WHERE `userid` = 661");
            unset($_SESSION['logged'], $_SESSION['user'], $_SESSION['usertoken']);
        }
    }

    /**
     * A correctly signed JWT with NO matching usertokens row must be rejected
     * with "Token not found or expired" — signature validity alone is not
     * enough; the token must also be active in the database.
     */
    public function testValidJwtWithoutDbRowIsRejected(): void
    {
        // Arrange — valid signature, no DB row inserted
        $this->bootDatabase();
        $jwt = \Pramnos\Auth\JWT::encode(
            ['sub' => 999999, 'exp' => time() + 3600], 'test-key-0123456789-0123456789-0123'
        );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        $mw = $this->make('test-key-0123456789-0123456789-0123');

        // Act
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert
        $decoded = json_decode($response, true);
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('InvalidToken', $decoded['error']);
        $this->assertSame('Token not found or expired.', $decoded['message']);
        unset($_SESSION['usertoken']);
    }

    /**
     * resolveUser() with an appNamespace whose User class does not exist must
     * fall back to the framework \Pramnos\User\User — verified indirectly via
     * the rejected-JWT path (no fatal "class not found" error).
     */
    public function testUnknownAppNamespaceFallsBackToFrameworkUser(): void
    {
        // Arrange
        $this->bootDatabase();
        $jwt = \Pramnos\Auth\JWT::encode(
            ['sub' => 999998, 'exp' => time() + 3600], 'test-key-0123456789-0123456789-0123'
        );
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        $mw = $this->make('test-key-0123456789-0123456789-0123', 'TotallyMissingNamespace12345');

        // Act — must not fatal on the missing class
        $response = $mw->handle($this->request, $this->nextOk());

        // Assert — normal rejection envelope, proving the fallback was used
        $decoded = json_decode($response, true);
        $this->assertSame('InvalidToken', $decoded['error']);
        unset($_SESSION['usertoken']);
    }

    // -------------------------------------------------------------------------
    // csrfMeta helper
    // -------------------------------------------------------------------------

    /**
     * CsrfMiddleware::csrfMeta() must return a valid <meta> tag that JS can
     * read to populate the X-CSRF-Token header (Phase 16 pattern).
     */
    public function testCsrfMetaReturnsMetaTag(): void
    {
        // Arrange — start a session so CsrfMiddleware::token() works
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Act
        $meta = \Pramnos\Http\Middleware\CsrfMiddleware::csrfMeta();

        // Assert
        $this->assertStringStartsWith('<meta name="csrf"', $meta);
        $this->assertStringContainsString('content="', $meta);
        $this->assertStringEndsWith(' />', $meta);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Inject a known CSRF token directly into the session so tests can
     * control what `Session::getCsrfToken()` returns without relying on
     * the actual session object internals.
     */
    private function injectSessionCsrf(string $token): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Session::getCsrfToken() reads $_SESSION['csrf_token']
        $_SESSION['csrf_token'] = $token;
    }
}
