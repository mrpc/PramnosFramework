<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Addon\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Addon\User\User;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Http\Request;
use Pramnos\Http\Session as HttpSession;
use Pramnos\Translator\Language;

/**
 * Unit tests for Pramnos\Addon\User\User (the session lifecycle addon).
 *
 * The addon is deprecated since v1.2 (the lifecycle is now built into
 * Auth::auth() / Auth::logout()), but it remains fully BC-compatible.
 *
 * These tests exercise every public method — onLogin() and onLogout() — by
 * replacing the framework singletons (Database, Request, Session, Language)
 * with test doubles that record what was called.  No real DB connection is
 * needed.
 *
 * Key invariants checked:
 *  - onLogin() returns false when mandatory fields are missing.
 *  - onLogin() returns false when status != true.
 *  - onLogin() sets $_SESSION correctly for valid data.
 *  - onLogin() sets cookies only when uid > 1.
 *  - onLogin() executes two SQL queries (UPDATE sessions, UPDATE users).
 *  - onLogin() silently absorbs a DB exception on the sessions UPDATE.
 *  - onLogout() deletes the session row when $_SESSION['username'] is set.
 *  - onLogout() clears cookies and resets the session.
 *  - onLogout() silently absorbs a DB exception on the DELETE.
 */
#[CoversClass(User::class)]
class AddonUserTest extends TestCase
{
    // ── Saved singleton originals (restored in tearDown) ────────────────────

    private mixed $dbOriginal;
    private mixed $sessionOriginal;
    private mixed $langOriginal;
    private mixed $requestOriginal;
    private mixed $requestInstanceOriginal;

    // ── Cookie / query capture arrays ────────────────────────────────────────

    /** @var array<string, mixed> Cookies written via Request::cookieset() */
    protected array $cookies = [];

    /** @var string[] SQL queries passed to Database::query() */
    protected array $queriesExecuted = [];

    /** Whether the HttpSession::reset() method was called */
    protected bool $sessionResetCalled = false;

    protected function setUp(): void
    {
        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true);
        }

        // Ensure a known $_SESSION / $_SERVER state before every test
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->cookies           = [];
        $this->queriesExecuted   = [];
        $this->sessionResetCalled = false;

        // ── Database mock ───────────────────────────────────────────────────
        $dbMock = $this->createMock(Database::class);
        $dbMock->type = 'mysql';

        // prepareQuery() returns a recognisable stub SQL string so queries
        // can be inspected in assertions.
        $dbMock->method('prepareQuery')->willReturnCallback(
            function (string $query, ...$args): string {
                $formatted = $query;
                foreach ($args as $arg) {
                    if (is_int($arg)) {
                        $formatted = preg_replace('/%d/', (string) $arg, $formatted, 1);
                    } else {
                        $formatted = preg_replace('/%s/', (string) $arg, $formatted, 1);
                    }
                }
                return $formatted;
            }
        );

        $dbMock->method('query')->willReturnCallback(function (string $sql): mixed {
            $this->queriesExecuted[] = $sql;
            $res = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows = 0;
            $res->fields  = [];
            return $res;
        });

        // ── Request mock ────────────────────────────────────────────────────
        $requestMock = $this->createMock(Request::class);
        $requestMock->method('cookieset')->willReturnCallback(
            function (string $name, mixed $value, mixed $time = null): void {
                $this->cookies[$name] = $value;
            }
        );

        // ── Session mock ────────────────────────────────────────────────────
        $sessionMock = $this->createMock(HttpSession::class);
        $sessionMock->method('reset')->willReturnCallback(function (): void {
            $this->sessionResetCalled = true;
        });

        // ── Language mock ───────────────────────────────────────────────────
        $langMock = $this->createMock(Language::class);
        $langMock->method('currentlang')->willReturn('en');

        // ── Swap singletons ─────────────────────────────────────────────────
        $this->dbOriginal      = Database::getInstance();
        $dbSingleton           = &Database::getInstance();
        $dbSingleton           = $dbMock;

        $this->sessionOriginal = Factory::getSession();
        $sessionSingleton      = &Factory::getSession();
        $sessionSingleton      = $sessionMock;

        $this->langOriginal    = Factory::getLanguage();
        $langSingleton         = &Factory::getLanguage();
        $langSingleton         = $langMock;

        // The User addon calls \Pramnos\Http\Request::getInstance() directly,
        // not Factory::getRequest(). We must swap BOTH singletons so the mock
        // is picked up from either path.
        $this->requestOriginal = Factory::getRequest();
        $requestSingleton      = &Factory::getRequest();
        $requestSingleton      = $requestMock;

        // Request::getInstance() returns by reference — swap its static too
        $this->requestInstanceOriginal = Request::getInstance();
        $requestInstance               = &Request::getInstance();
        $requestInstance               = $requestMock;
    }

    protected function tearDown(): void
    {
        // Restore superglobals
        $_SESSION = [];

        // Restore singletons
        $db = &Database::getInstance();
        $db = $this->dbOriginal;

        $ses = &Factory::getSession();
        $ses = $this->sessionOriginal;

        $lang = &Factory::getLanguage();
        $lang = $this->langOriginal;

        $req = &Factory::getRequest();
        $req = $this->requestOriginal;

        // Restore Request::getInstance() static variable
        $reqInst = &Request::getInstance();
        $reqInst = $this->requestInstanceOriginal;
    }

    // =========================================================================
    // onLogin() — input validation
    // =========================================================================

    /**
     * onLogin() must return false when the $info array is completely empty.
     *
     * The method requires status, username, uid, email, and auth to all be
     * present; a missing key at any position must short-circuit to false
     * without touching the database.
     */
    public function testOnLoginReturnsFalseWhenInfoEmpty(): void
    {
        // Arrange
        $addon = new User();

        // Act
        $result = $addon->onLogin([]);

        // Assert — must refuse, not crash
        $this->assertFalse($result, 'onLogin() must return false for empty info array');
        // No queries should have been issued
        $this->assertEmpty($this->queriesExecuted, 'No DB queries must fire for empty info');
    }

    /**
     * onLogin() must return false when 'status' is missing from $info,
     * even if all other required keys are present.
     */
    public function testOnLoginReturnsFalseWhenStatusMissing(): void
    {
        // Arrange
        $addon = new User();
        $info  = ['username' => 'alice', 'uid' => 5, 'email' => 'a@test.com', 'auth' => 'tok'];

        // Act
        $result = $addon->onLogin($info);

        // Assert
        $this->assertFalse($result);
        $this->assertEmpty($this->queriesExecuted);
    }

    /**
     * onLogin() must return false when 'uid' is missing from $info.
     */
    public function testOnLoginReturnsFalseWhenUidMissing(): void
    {
        // Arrange
        $addon = new User();
        $info  = ['status' => true, 'username' => 'alice', 'email' => 'a@test.com', 'auth' => 'tok'];

        // Act
        $result = $addon->onLogin($info);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * onLogin() must return false when status is false (login rejected by the
     * upstream authentication logic).
     */
    public function testOnLoginReturnsFalseWhenStatusIsFalse(): void
    {
        // Arrange
        $addon = new User();
        $info  = [
            'status'   => false,
            'username' => 'alice',
            'uid'      => 5,
            'email'    => 'a@test.com',
            'auth'     => 'tok',
        ];

        // Act
        $result = $addon->onLogin($info);

        // Assert — status=false must deny the login
        $this->assertFalse($result);
        $this->assertEmpty($this->queriesExecuted);
    }

    // =========================================================================
    // onLogin() — successful login
    // =========================================================================

    /**
     * onLogin() with valid info must populate $_SESSION with the login state.
     *
     * The three core session keys — logged, uid, username — must be set so
     * that the rest of the application can read them on the next request.
     */
    public function testOnLoginSetsSessionVariables(): void
    {
        // Arrange
        $addon = new User();
        $info  = [
            'status'   => true,
            'username' => 'alice',
            'uid'      => 7,
            'email'    => 'alice@test.com',
            'auth'     => 'tok-abc',
        ];

        // Act
        $result = $addon->onLogin($info);

        // Assert — return value
        $this->assertTrue($result, 'onLogin() must return true for valid login info');

        // Assert — session was populated
        $this->assertTrue($_SESSION['logged'],       '$_SESSION["logged"] must be true');
        $this->assertSame(7, $_SESSION['uid'],       '$_SESSION["uid"] must match info uid');
        $this->assertSame('alice', $_SESSION['username'], '$_SESSION["username"] must match info username');
        $this->assertSame('tok-abc', $_SESSION['auth'],   '$_SESSION["auth"] must match info auth');
    }

    /**
     * onLogin() must set cookies for uid > 1 (regular users).
     *
     * Cookies logged, uid, username, auth, and language must all be written
     * so that the browser-side session persists across requests.
     */
    public function testOnLoginSetsCookiesForRegularUser(): void
    {
        // Arrange
        $addon = new User();
        $info  = [
            'status'   => true,
            'username' => 'bob',
            'uid'      => 10,
            'email'    => 'bob@test.com',
            'auth'     => 'auth-xyz',
        ];

        // Act
        $addon->onLogin($info);

        // Assert — every expected cookie was set
        $this->assertArrayHasKey('logged',   $this->cookies, '"logged" cookie must be set');
        $this->assertArrayHasKey('uid',      $this->cookies, '"uid" cookie must be set');
        $this->assertArrayHasKey('username', $this->cookies, '"username" cookie must be set');
        $this->assertArrayHasKey('auth',     $this->cookies, '"auth" cookie must be set');
        $this->assertArrayHasKey('language', $this->cookies, '"language" cookie must be set');
        $this->assertSame(10, $this->cookies['uid']);
        $this->assertSame('bob', $this->cookies['username']);
    }

    /**
     * onLogin() must NOT set cookies when uid === 1 (the system / guest account).
     *
     * uid=1 is the framework's built-in anonymous/guest sentinel. Giving it a
     * real login cookie would corrupt the guest session on subsequent requests.
     */
    public function testOnLoginDoesNotSetCookiesForSystemUser(): void
    {
        // Arrange
        $addon = new User();
        $info  = [
            'status'   => true,
            'username' => 'admin',
            'uid'      => 1,
            'email'    => 'admin@test.com',
            'auth'     => 'adminauth',
        ];

        // Act
        $addon->onLogin($info);

        // Assert — no cookies for the system account
        $this->assertArrayNotHasKey('logged',   $this->cookies, '"logged" cookie must NOT be set for uid=1');
        $this->assertArrayNotHasKey('uid',      $this->cookies, '"uid" cookie must NOT be set for uid=1');
        $this->assertArrayNotHasKey('username', $this->cookies, '"username" cookie must NOT be set for uid=1');
    }

    /**
     * onLogin() must execute two UPDATE statements:
     *  1. UPDATE sessions — marks the session row as logged-in.
     *  2. UPDATE users   — records the lastlogin timestamp and language.
     *
     * Both writes are required for the session lifecycle to work correctly on
     * subsequent page loads.
     */
    public function testOnLoginExecutesTwoUpdateQueries(): void
    {
        // Arrange
        $addon = new User();
        $info  = [
            'status'   => true,
            'username' => 'charlie',
            'uid'      => 3,
            'email'    => 'c@test.com',
            'auth'     => 'auth-c',
        ];

        // Act
        $addon->onLogin($info);

        // Assert — exactly two queries issued
        $this->assertCount(2, $this->queriesExecuted, 'onLogin() must execute exactly 2 SQL statements');

        // Assert — first query targets the sessions table
        $this->assertStringContainsString('sessions', $this->queriesExecuted[0],
            'First query must UPDATE the sessions table');
        $this->assertStringContainsString('charlie', $this->queriesExecuted[0],
            'Sessions UPDATE must contain the username');

        // Assert — second query targets the users table
        $this->assertStringContainsString('users', $this->queriesExecuted[1],
            'Second query must UPDATE the users table');
        $this->assertStringContainsString('lastlogin', $this->queriesExecuted[1],
            'Users UPDATE must set the lastlogin column');
    }

    /**
     * onLogin() must silently absorb a database exception thrown during the
     * sessions UPDATE and still return true.
     *
     * The sessions row is informational (used for active-sessions display); a
     * transient write failure must not prevent the user from logging in.
     */
    public function testOnLoginContinuesWhenSessionsUpdateThrows(): void
    {
        // Arrange — replace the DB mock to throw on the first query
        $throwCount = 0;
        $dbMock = $this->createMock(Database::class);
        $dbMock->type = 'mysql';
        $dbMock->method('prepareQuery')->willReturnCallback(
            function (string $query, ...$args): string {
                return $query;
            }
        );
        $dbMock->method('query')->willReturnCallback(function (string $sql) use (&$throwCount): mixed {
            $this->queriesExecuted[] = $sql;
            $throwCount++;
            if ($throwCount === 1) {
                throw new \RuntimeException('Connection lost');
            }
            $res = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows = 0;
            return $res;
        });

        $dbSingleton = &Database::getInstance();
        $dbSingleton = $dbMock;

        $addon = new User();
        $info  = [
            'status'   => true,
            'username' => 'dave',
            'uid'      => 4,
            'email'    => 'd@test.com',
            'auth'     => 'auth-d',
        ];

        // Act — must not propagate the exception
        $result = $addon->onLogin($info);

        // Assert — still returns true; the users UPDATE still ran
        $this->assertTrue($result, 'onLogin() must return true even when sessions UPDATE fails');
        $this->assertCount(2, $this->queriesExecuted, 'Both SQL statements must be attempted');
    }

    // =========================================================================
    // onLogout()
    // =========================================================================

    /**
     * onLogout() must DELETE the sessions row for the current username when
     * $_SESSION['username'] is populated.
     *
     * Without this cleanup, stale rows accumulate in the sessions table and
     * the online-users count remains incorrect.
     */
    public function testOnLogoutDeletesSessionRowWhenUsernameIsSet(): void
    {
        // Arrange
        $_SESSION['username'] = 'eve';
        $addon = new User();

        // Act
        $addon->onLogout();

        // Assert — DELETE was issued
        $this->assertCount(1, $this->queriesExecuted, 'onLogout() must execute exactly 1 SQL statement');
        $this->assertStringContainsString('DELETE', $this->queriesExecuted[0],
            'Query must be a DELETE statement');
        $this->assertStringContainsString('sessions', $this->queriesExecuted[0],
            'DELETE must target the sessions table');
        $this->assertStringContainsString('eve', $this->queriesExecuted[0],
            'DELETE must filter by the current username');
    }

    /**
     * onLogout() must NOT execute any SQL when $_SESSION['username'] is not set.
     *
     * An unauthenticated visitor has no sessions row to delete; attempting a
     * DELETE without a username would produce a SQL error or corrupt data.
     */
    public function testOnLogoutSkipsQueryWhenUsernameNotInSession(): void
    {
        // Arrange — session is empty
        $addon = new User();

        // Act
        $addon->onLogout();

        // Assert — no queries fired
        $this->assertEmpty($this->queriesExecuted,
            'onLogout() must not issue any SQL when username is absent from $_SESSION');
    }

    /**
     * onLogout() must clear the five auth-related cookies:
     * logged, uid, username, auth, language.
     *
     * These cookies are set on login; clearing them ensures the browser no
     * longer sends them and the user is fully logged out on the next request.
     */
    public function testOnLogoutClearsCookies(): void
    {
        // Arrange
        $addon = new User();

        // Act
        $addon->onLogout();

        // Assert — all auth cookies must have been overwritten (with empty string)
        $this->assertArrayHasKey('logged',   $this->cookies, '"logged" cookie must be cleared');
        $this->assertArrayHasKey('uid',      $this->cookies, '"uid" cookie must be cleared');
        $this->assertArrayHasKey('username', $this->cookies, '"username" cookie must be cleared');
        $this->assertArrayHasKey('auth',     $this->cookies, '"auth" cookie must be cleared');
        $this->assertArrayHasKey('language', $this->cookies, '"language" cookie must be cleared');

        // Values set to empty string (the cookie value when expiry is in the past)
        $this->assertSame('', $this->cookies['logged'],   '"logged" cookie value must be empty string');
        $this->assertSame('', $this->cookies['uid'],      '"uid" cookie value must be empty string');
        $this->assertSame('', $this->cookies['username'], '"username" cookie value must be empty string');
    }

    /**
     * onLogout() must call Session::reset() to destroy the PHP session data.
     *
     * Without this call, $_SESSION values from the previous login would remain
     * visible for the rest of the current request.
     */
    public function testOnLogoutResetsSession(): void
    {
        // Arrange
        $addon = new User();

        // Act
        $addon->onLogout();

        // Assert — Session::reset() was called
        $this->assertTrue($this->sessionResetCalled,
            'onLogout() must call Session::reset() to clear session data');
    }

    /**
     * onLogout() must silently absorb a database exception thrown by the
     * sessions DELETE and still clear cookies and reset the session.
     *
     * A transient DB failure during logout must not prevent the cookie / session
     * cleanup; the user must always end up logged out.
     */
    public function testOnLogoutContinuesWhenDeleteThrows(): void
    {
        // Arrange — replace DB mock to throw on DELETE
        $dbMock = $this->createMock(Database::class);
        $dbMock->type = 'mysql';
        $dbMock->method('prepareQuery')->willReturn('DELETE QUERY');
        $dbMock->method('query')->willThrowException(new \RuntimeException('DB gone'));

        $dbSingleton = &Database::getInstance();
        $dbSingleton = $dbMock;

        $_SESSION['username'] = 'frank';
        $addon = new User();

        // Act — must not propagate the exception
        $addon->onLogout();

        // Assert — cookies were still cleared and session was still reset
        $this->assertArrayHasKey('logged', $this->cookies,
            'Cookies must be cleared even when DELETE throws');
        $this->assertTrue($this->sessionResetCalled,
            'Session::reset() must be called even when DELETE throws');
    }
}
