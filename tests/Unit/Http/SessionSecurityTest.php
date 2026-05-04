<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Session;
use Pramnos\Http\Request;

#[CoversClass(Session::class)]
#[CoversClass(Request::class)]
class SessionSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SERVER['HTTPS']);
    }

    // =========================================================================
    // Session::isHttps()
    // =========================================================================

    /**
     * isHttps() returns true when HTTPS is 'on' — the value set by Apache
     * and most web servers when the connection is TLS.
     */
    public function testIsHttpsReturnsTrueWhenHttpsIsOn(): void
    {
        // Arrange
        $_SERVER['HTTPS'] = 'on';

        // Act / Assert
        $this->assertTrue(Session::isHttps());

        // Cleanup
        unset($_SERVER['HTTPS']);
    }

    /**
     * isHttps() returns true when HTTPS is '1' — the value some servers
     * (e.g. IIS, certain CGI configurations) set instead of 'on'.
     */
    public function testIsHttpsReturnsTrueWhenHttpsIsOne(): void
    {
        // Arrange
        $_SERVER['HTTPS'] = '1';

        // Act / Assert
        $this->assertTrue(Session::isHttps());

        // Cleanup
        unset($_SERVER['HTTPS']);
    }

    /**
     * isHttps() returns false when HTTPS is absent — a plain HTTP request.
     * The session cookie must NOT be marked secure in this case, or it would
     * never be sent and the session would break.
     */
    public function testIsHttpsReturnsFalseWhenHttpsAbsent(): void
    {
        // Arrange
        unset($_SERVER['HTTPS']);

        // Act / Assert
        $this->assertFalse(Session::isHttps());
    }

    /**
     * isHttps() returns false when HTTPS is 'off' — some servers explicitly
     * set 'off' for non-TLS connections.
     */
    public function testIsHttpsReturnsFalseWhenHttpsIsOff(): void
    {
        // Arrange
        $_SERVER['HTTPS'] = 'off';

        // Act / Assert
        $this->assertFalse(Session::isHttps());

        // Cleanup
        unset($_SERVER['HTTPS']);
    }

    // =========================================================================
    // Request::isHttps()
    // =========================================================================

    /**
     * Request::isHttps() must accept '1' as a truthy HTTPS value — for
     * consistency with Session::isHttps() and to handle IIS/CGI environments.
     */
    public function testRequestIsHttpsReturnsTrueForOne(): void
    {
        // Arrange
        $_SERVER['HTTPS'] = '1';
        $request = new Request();

        // Act / Assert
        $this->assertTrue($request->isHttps());

        // Cleanup
        unset($_SERVER['HTTPS']);
    }

    /**
     * Request::isHttps() returns true for 'on' — the standard Apache value.
     */
    public function testRequestIsHttpsReturnsTrueForOn(): void
    {
        // Arrange
        $_SERVER['HTTPS'] = 'on';
        $request = new Request();

        // Act / Assert
        $this->assertTrue($request->isHttps());

        // Cleanup
        unset($_SERVER['HTTPS']);
    }

    /**
     * Request::isHttps() returns false when HTTPS is absent.
     */
    public function testRequestIsHttpsReturnsFalseWhenAbsent(): void
    {
        // Arrange
        unset($_SERVER['HTTPS']);
        $request = new Request();

        // Act / Assert
        $this->assertFalse($request->isHttps());
    }

    // =========================================================================
    // Session::start() — use_strict_mode
    // =========================================================================

    /**
     * When start() is called before any session is active, it must enable
     * use_strict_mode=1 before calling session_start(). This prevents PHP
     * from accepting attacker-supplied session IDs (session fixation via
     * URL or pre-set cookie).
     *
     * Note: ini_set() for session.* is only valid before session_start().
     * When a session is already active, the call is a no-op (PHP emits a
     * notice). This test runs in a clean state (session destroyed in setUp).
     */
    public function testStartEnablesUseStrictModeBeforeSessionStart(): void
    {
        // Arrange — close any active session first, then reset the ini to '0'
        // (ini_set on session.* is only allowed when no session is active).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            session_id('');   // reset stored session ID so session_id() returns ''
        }
        // Set to '0' so we can verify that start() changes it to '1'.
        // The restore is intentionally omitted after start() runs because
        // changing session.* while a session is active triggers a PHP warning,
        // and '1' is the correct secure value anyway.
        ini_set('session.use_strict_mode', '0');

        $session = Session::getInstance();

        // Act
        $session->start();

        // Assert — ini was set to '1' inside the session_start() branch
        $this->assertSame('1', ini_get('session.use_strict_mode'));
        // No restore: '1' is the secure value; restoring to '0' after an active
        // session would also trigger a PHP warning (ini_set on session.* while active).
    }

    // =========================================================================
    // Session::reset() — session_regenerate_id + token rotation
    // =========================================================================

    /**
     * reset() must change the PHP session ID to prevent session fixation.
     * An attacker who plants a session ID before login must be locked out
     * after the privilege change.
     */
    public function testResetRegeneratesSessionId(): void
    {
        // Arrange
        $session = Session::getInstance();
        $session->start();
        $beforeId = session_id();

        // Act
        $session->reset();

        // Assert
        $this->assertNotSame($beforeId, session_id(),
            'session_id() must change after reset() to prevent session fixation');
    }

    /**
     * reset() must also rotate the CSRF token so that any CSRF token captured
     * before logout cannot be reused after the new login.
     */
    public function testResetRotatesCsrfToken(): void
    {
        // Arrange
        $session = Session::getInstance();
        $session->start();
        $before = $session->getCsrfToken();

        // Act
        $session->reset();

        // Assert
        $this->assertNotSame($before, $session->getCsrfToken(),
            'CSRF token must be rotated on reset() to prevent pre-login token reuse');
    }

    /**
     * reset() must also rotate the fingerprint (session) token so that any
     * form token captured before logout cannot be reused after login.
     */
    public function testResetRotatesFingerprintToken(): void
    {
        // Arrange
        $session = Session::getInstance();
        $session->start();
        $before = $session->getToken();

        // Act
        $session->reset();

        // Assert
        $this->assertNotSame($before, $session->getToken(),
            'Fingerprint token must be rotated on reset()');
    }
}
