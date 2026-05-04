<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Session;
use Pramnos\Http\Middleware\CsrfMiddleware;
use Pramnos\Http\Request;

#[CoversClass(Session::class)]
#[CoversClass(CsrfMiddleware::class)]
class CsrfTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Setup / teardown
    // ──────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        // Start a PHP session if not already active so $_SESSION is available
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Clear CSRF-related session keys before each test for isolation
        unset($_SESSION['csrf_token'], $_SESSION['token']);
        // Clear superglobals that tests might set
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    private function makeRequest(string $method = 'GET'): Request
    {
        return Request::create('/', $method);
    }

    private function passThroughNext(): callable
    {
        return fn(Request $r) => 'ok';
    }

    // =========================================================================
    // Session::getCsrfToken()
    // =========================================================================

    /**
     * getCsrfToken() returns a non-empty string on first call — the token is
     * generated lazily and stored in the session.
     */
    public function testGetCsrfTokenReturnsNonEmptyStringOnFirstCall(): void
    {
        // Arrange
        $session = Session::getInstance();

        // Act
        $token = $session->getCsrfToken();

        // Assert
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    /**
     * getCsrfToken() returns the same token on repeated calls within the same
     * session.  Stability is required so that forms rendered on different
     * page-loads within the same session share the same token.
     */
    public function testGetCsrfTokenIsStableWithinSameSession(): void
    {
        // Arrange
        $session = Session::getInstance();

        // Act
        $first  = $session->getCsrfToken();
        $second = $session->getCsrfToken();

        // Assert
        $this->assertSame($first, $second);
    }

    /**
     * The CSRF token must be at least 64 hex characters (256-bit entropy).
     * Shorter tokens are brute-forceable; 256 bits is the current standard.
     */
    public function testGetCsrfTokenHasSufficientEntropy(): void
    {
        // Arrange / Act
        $token = Session::getInstance()->getCsrfToken();

        // Assert — 32 bytes → 64 hex chars
        $this->assertGreaterThanOrEqual(64, strlen($token));
    }

    // =========================================================================
    // Session::verifyCsrfToken()
    // =========================================================================

    /**
     * verifyCsrfToken() returns true when the submitted value equals the
     * token stored in the session.
     */
    public function testVerifyCsrfTokenReturnsTrueForCorrectToken(): void
    {
        // Arrange
        $session = Session::getInstance();
        $token   = $session->getCsrfToken();

        // Act / Assert
        $this->assertTrue($session->verifyCsrfToken($token));
    }

    /**
     * verifyCsrfToken() returns false for an incorrect token.
     * This is the core security invariant: wrong token must never pass.
     */
    public function testVerifyCsrfTokenReturnsFalseForWrongToken(): void
    {
        // Arrange
        $session = Session::getInstance();
        $session->getCsrfToken(); // ensure one is generated

        // Act / Assert
        $this->assertFalse($session->verifyCsrfToken('not-the-right-token'));
    }

    /**
     * verifyCsrfToken() returns false for an empty string.
     * An attacker submitting an empty token must be rejected.
     */
    public function testVerifyCsrfTokenReturnsFalseForEmptyString(): void
    {
        // Arrange
        $session = Session::getInstance();
        $session->getCsrfToken();

        // Act / Assert
        $this->assertFalse($session->verifyCsrfToken(''));
    }

    /**
     * verifyCsrfToken() returns false when no token has been generated yet
     * (empty $_SESSION['csrf_token']).  A missing token must not accidentally
     * pass verification.
     */
    public function testVerifyCsrfTokenReturnsFalseWhenNoTokenGenerated(): void
    {
        // Arrange — ensure csrf_token is absent
        unset($_SESSION['csrf_token']);

        // Act / Assert
        $this->assertFalse(Session::getInstance()->verifyCsrfToken('anything'));
    }

    // =========================================================================
    // Session::regenerateCsrfToken()
    // =========================================================================

    /**
     * regenerateCsrfToken() replaces the stored token with a new one.
     * Call this after login/logout to prevent session fixation of the CSRF token.
     */
    public function testRegenerateCsrfTokenChangesToken(): void
    {
        // Arrange
        $session = Session::getInstance();
        $before  = $session->getCsrfToken();

        // Act
        $session->regenerateCsrfToken();
        $after = $session->getCsrfToken();

        // Assert
        $this->assertNotSame($before, $after);
    }

    // =========================================================================
    // Session::getFingerprint() — HMAC-SHA256
    // =========================================================================

    /**
     * getFingerprint() now uses HMAC-SHA256, so the output is a 64-char hex
     * string — not the old 32-char MD5 output.  This proves the algorithm was
     * actually upgraded (not just claimed).
     */
    public function testGetFingerprintIsHmacSha256(): void
    {
        // Arrange
        $_SESSION['token']  = bin2hex(random_bytes(32));
        $session = Session::getInstance();
        $session->start();  // loads the token into _token

        // Act
        $fp = $session->getFingerprint();

        // Assert — SHA-256 HMAC hex = 64 chars; MD5 = 32 chars
        $this->assertSame(64, strlen($fp));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fp);
    }

    /**
     * getFingerprint() result changes when useIp=true vs useIp=false,
     * proving the IP-pinning path is distinct.
     */
    public function testGetFingerprintDiffersWithAndWithoutIp(): void
    {
        // Arrange
        $_SESSION['token'] = bin2hex(random_bytes(32));
        $session = Session::getInstance();
        $session->start();
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        // Act
        $withoutIp = $session->getFingerprint(false);
        $withIp    = $session->getFingerprint(true);

        // Assert
        $this->assertNotSame($withoutIp, $withIp);

        // Cleanup
        unset($_SERVER['REMOTE_ADDR']);
    }

    // =========================================================================
    // Session::regenerateToken() — entropy
    // =========================================================================

    /**
     * regenerateToken() must produce a token of at least 64 hex characters
     * (256-bit entropy).  The previous 10-char (40-bit) token was too short.
     */
    public function testRegenerateTokenProduces256BitToken(): void
    {
        // Arrange
        $session = Session::getInstance();

        // Act
        $session->regenerateToken();
        $token = $session->getToken();

        // Assert — 32 bytes → 64 hex chars
        $this->assertGreaterThanOrEqual(64, strlen($token));
    }

    // =========================================================================
    // CsrfMiddleware — safe methods pass through
    // =========================================================================

    /**
     * GET requests must always pass through CsrfMiddleware without any token
     * check.  Requiring a CSRF token on GET would break browser navigation,
     * bookmarks, and search engine crawlers.
     */
    public function testGetRequestPassesThroughWithoutTokenCheck(): void
    {
        // Arrange
        $mw     = new CsrfMiddleware();
        $called = false;

        // Act
        $result = $mw->handle($this->makeRequest('GET'), function () use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('ok', $result);
    }

    /**
     * HEAD and OPTIONS are also safe methods and must pass through.
     */
    public function testHeadAndOptionsPassThrough(): void
    {
        // Arrange
        $mw = new CsrfMiddleware();

        foreach (['HEAD', 'OPTIONS'] as $method) {
            $called = false;
            $mw->handle($this->makeRequest($method), function () use (&$called) {
                $called = true;
                return 'ok';
            });
            $this->assertTrue($called, "{$method} was blocked by CSRF middleware");
        }
    }

    // =========================================================================
    // CsrfMiddleware — POST validation
    // =========================================================================

    /**
     * A POST request with a valid '_csrf_token' field must pass through and
     * call $next.  This is the normal form-submission path.
     */
    public function testPostWithValidFieldTokenPassesThrough(): void
    {
        // Arrange
        $session = Session::getInstance();
        $token   = $session->getCsrfToken();
        $_POST['_csrf_token'] = $token;

        $mw     = new CsrfMiddleware();
        $called = false;

        // Act
        $result = $mw->handle($this->makeRequest('POST'), function () use (&$called) {
            $called = true;
            return 'created';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('created', $result);
    }

    /**
     * A POST request with an invalid token must throw a 419 exception and
     * must NOT call $next.  This is the core CSRF-rejection path.
     */
    public function testPostWithInvalidTokenThrows419(): void
    {
        // Arrange
        $session = Session::getInstance();
        $session->getCsrfToken(); // generate one in session
        $_POST['_csrf_token'] = 'wrong-token';

        $mw     = new CsrfMiddleware();
        $called = false;

        // Act
        try {
            $mw->handle($this->makeRequest('POST'), function () use (&$called) {
                $called = true;
                return 'should-not-reach';
            });
            $this->fail('Expected 419 exception');
        } catch (\Exception $e) {
            // Assert
            $this->assertSame(419, $e->getCode());
            $this->assertFalse($called, '$next was called despite invalid CSRF token');
        }
    }

    /**
     * A POST request with no token at all must throw 419.
     * An absent token is just as bad as a wrong one.
     */
    public function testPostWithMissingTokenThrows419(): void
    {
        // Arrange — ensure _csrf_token is not in $_POST
        unset($_POST['_csrf_token']);
        $session = Session::getInstance();
        $session->getCsrfToken();

        $mw = new CsrfMiddleware();

        // Act
        try {
            $mw->handle($this->makeRequest('POST'), $this->passThroughNext());
            $this->fail('Expected 419 exception');
        } catch (\Exception $e) {
            $this->assertSame(419, $e->getCode());
        }
    }

    /**
     * A POST request with a valid X-CSRF-Token header must pass through.
     * This is the AJAX / fetch API path where forms can't include hidden fields
     * but can set custom headers.
     */
    public function testPostWithValidHeaderTokenPassesThrough(): void
    {
        // Arrange
        $session = Session::getInstance();
        $token   = $session->getCsrfToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        unset($_POST['_csrf_token']);

        $mw     = new CsrfMiddleware();
        $called = false;

        // Act
        $result = $mw->handle($this->makeRequest('POST'), function () use (&$called) {
            $called = true;
            return 'ajax-ok';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('ajax-ok', $result);
    }

    /**
     * PUT, PATCH, and DELETE are also state-changing and must be protected.
     */
    public function testPutPatchDeleteAreProtected(): void
    {
        // Arrange — no token set
        $session = Session::getInstance();
        $session->getCsrfToken();
        unset($_POST['_csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        $mw = new CsrfMiddleware();

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            try {
                $mw->handle($this->makeRequest($method), $this->passThroughNext());
                $this->fail("{$method} request was not blocked by CSRF middleware");
            } catch (\Exception $e) {
                $this->assertSame(419, $e->getCode(), "{$method} did not throw 419");
            }
        }
    }

    /**
     * CsrfMiddleware accepts a custom field name via the constructor.
     * This allows projects to use a legacy field name without changing forms.
     */
    public function testCustomFieldNameIsRespected(): void
    {
        // Arrange
        $session = Session::getInstance();
        $token   = $session->getCsrfToken();
        $_POST['my_token'] = $token;

        $mw     = new CsrfMiddleware(fieldName: 'my_token');
        $called = false;

        // Act
        $mw->handle($this->makeRequest('POST'), function () use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called);
    }

    // =========================================================================
    // CsrfMiddleware::token() and tokenField()
    // =========================================================================

    /**
     * CsrfMiddleware::token() returns the same value as Session::getCsrfToken().
     * It is a convenience static proxy for templates that don't want to
     * instantiate the Session object directly.
     */
    public function testStaticTokenMatchesSessionCsrfToken(): void
    {
        // Arrange
        $session = Session::getInstance();
        $expected = $session->getCsrfToken();

        // Act
        $actual = CsrfMiddleware::token();

        // Assert
        $this->assertSame($expected, $actual);
    }

    /**
     * CsrfMiddleware::tokenField() returns a hidden <input> whose value is the
     * CSRF token.  The token must be HTML-escaped so special characters in the
     * token value don't break the HTML structure.
     */
    public function testTokenFieldReturnsHiddenInput(): void
    {
        // Arrange / Act
        $html = CsrfMiddleware::tokenField();

        // Assert
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="_csrf_token"', $html);
        $this->assertStringContainsString(CsrfMiddleware::token(), $html);
    }

    /**
     * tokenField() with a custom field name uses that name in the output.
     */
    public function testTokenFieldWithCustomFieldName(): void
    {
        // Arrange / Act
        $html = CsrfMiddleware::tokenField('form_token');

        // Assert
        $this->assertStringContainsString('name="form_token"', $html);
    }
}
