<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Addon\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Addon\Auth\UserDatabase;
use Pramnos\Auth\Auth;
use Pramnos\Http\Session;

/**
 * Unit tests for Pramnos\Addon\Auth\UserDatabase.
 *
 * UserDatabase is a thin adapter that delegates onAuth() to DatabaseAuthDriver
 * and onAuthCheck() to the Auth singleton.  The onAuth() method hits a real
 * database, so its behaviour is verified by the integration tests in
 * tests/Integration/Database/UserDatabaseMySQLTest.php.
 *
 * These unit tests focus on onAuthCheck(), verifying:
 *  - When both 'auth' and 'username' cookies are present, Auth::auth() is called
 *    with the cookie values and encryptedPassword=true.
 *  - When the 'auth' cookie is null, Auth::auth() is NOT called.
 *  - When the 'username' cookie is null, Auth::auth() is NOT called.
 *
 * Mock injection technique:
 *  - Factory::getSession() returns &$instance where $instance is a static var.
 *    We take a reference and overwrite it with a mock so the production code
 *    calls our mock during the test.
 *  - Auth::getInstance() similarly uses a reference-return static.
 */
class UserDatabaseTest extends TestCase
{
    private UserDatabase $addon;
    private Session $origSession;
    private Auth $origAuth;

    protected function setUp(): void
    {
        $this->addon = new UserDatabase();

        // Snapshot the real singletons so tearDown can restore them.
        $this->origSession = \Pramnos\Framework\Factory::getSession();
        $this->origAuth    = \Pramnos\Auth\Auth::getInstance();
    }

    protected function tearDown(): void
    {
        // Restore the real singletons.
        $sessionRef = &\Pramnos\Framework\Factory::getSession();
        $sessionRef = $this->origSession;

        $authRef = &\Pramnos\Auth\Auth::getInstance();
        $authRef = $this->origAuth;
    }

    // ── onAuthCheck() — both cookies present ──────────────────────────────────

    /**
     * When both 'auth' and 'username' cookies are present, onAuthCheck() must
     * delegate to Auth::auth() with encryptedPassword=true and remember=true.
     *
     * This triggers the automatic re-authentication from a persistent cookie,
     * which is the core purpose of the onAuthCheck hook.
     */
    public function testOnAuthCheckCallsAuthWhenBothCookiesPresent(): void
    {
        // Arrange — session returns both cookies
        $mockSession = $this->createMock(Session::class);
        $mockSession->method('cookieget')
            ->willReturnMap([
                ['auth',     'hashed_cookie_token'],
                ['username', 'alice'],
            ]);

        $mockAuth = $this->createMock(Auth::class);
        $mockAuth->expects($this->once())
            ->method('auth')
            ->with('alice', 'hashed_cookie_token', true, true);

        // Inject mocks into the singletons that onAuthCheck() reads.
        $sessionRef = &\Pramnos\Framework\Factory::getSession();
        $sessionRef = $mockSession;

        $authRef = &\Pramnos\Auth\Auth::getInstance();
        $authRef = $mockAuth;

        // Act
        $this->addon->onAuthCheck();

        // Assert — verified by mock expectation above
    }

    // ── onAuthCheck() — auth cookie is null ───────────────────────────────────

    /**
     * When the 'auth' cookie is null, the condition evaluates to false because
     * of the `!== null` check, so Auth::auth() must NOT be called.
     */
    public function testOnAuthCheckSkipsAuthWhenAuthCookieIsNull(): void
    {
        // Arrange — 'auth' cookie is absent (null)
        $mockSession = $this->createMock(Session::class);
        $mockSession->method('cookieget')
            ->willReturnMap([
                ['auth',     null],
                ['username', 'alice'],
            ]);

        $mockAuth = $this->createMock(Auth::class);
        $mockAuth->expects($this->never())->method('auth');

        $sessionRef = &\Pramnos\Framework\Factory::getSession();
        $sessionRef = $mockSession;

        $authRef = &\Pramnos\Auth\Auth::getInstance();
        $authRef = $mockAuth;

        // Act
        $this->addon->onAuthCheck();

        // Assert — verified by $mockAuth->never() above
    }

    // ── onAuthCheck() — username cookie is null ───────────────────────────────

    /**
     * When the 'username' cookie is null the condition evaluates to false, so
     * Auth::auth() must NOT be called.  Prevents auth attempts with no username.
     */
    public function testOnAuthCheckSkipsAuthWhenUsernameCookieIsNull(): void
    {
        // Arrange — 'username' cookie is absent (null)
        $mockSession = $this->createMock(Session::class);
        $mockSession->method('cookieget')
            ->willReturnMap([
                ['auth',     'hashed_cookie_token'],
                ['username', null],
            ]);

        $mockAuth = $this->createMock(Auth::class);
        $mockAuth->expects($this->never())->method('auth');

        $sessionRef = &\Pramnos\Framework\Factory::getSession();
        $sessionRef = $mockSession;

        $authRef = &\Pramnos\Auth\Auth::getInstance();
        $authRef = $mockAuth;

        // Act
        $this->addon->onAuthCheck();

        // Assert — verified by $mockAuth->never() above
    }
}
