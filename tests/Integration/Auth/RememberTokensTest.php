<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Addon\Auth\UserDatabase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\RememberTokens;
use Pramnos\Tests\Characterization\Auth\SignsInOnEachDatabase;
use Pramnos\User\User;

/**
 * The sign-in credential is a token per sign-in and device, not the password hash — on MySQL and
 * PostgreSQL.
 *
 * What holds: a sign-in's `auth` is a random token, unrelated to the password, stored only as a
 * hash; it is used once to sign in again and then replaced, so a copied cookie stops working;
 * it expires; signing out ends it, and signing out everywhere ends every one and flags the live
 * sessions; an old cookie holding the password hash is accepted once and replaced — unless the
 * application refuses it; and without an auth addon the framework itself signs in from the
 * cookies.
 */
#[CoversClass(RememberTokens::class)]
#[CoversClass(Auth::class)]
#[CoversClass(UserDatabase::class)]
class RememberTokensTest extends TestCase
{
    use SignsInOnEachDatabase;

    /** The `auth` value of a fresh sign-in, and the session cleared after it. */
    private function signIn(): string
    {
        (new Auth())->loginById($this->userId, true);
        $token    = (string) $_SESSION['auth'];
        $_SESSION = [];

        return $token;
    }

    /** The stored password hash. */
    private function passwordHash(): string
    {
        return (string) $this->db->queryBuilder()->table('#PREFIX#users')->select(['password'])->where('userid', $this->userId)->first()->fields['password'];
    }

    /**
     * A sign-in's `auth` is a token — not the password hash, not anything derived from it — and
     * only a hash of its secret half is stored: the table alone signs nobody in.
     */
    #[DataProvider('databases')]
    public function testTheCredentialIsATokenNotThePasswordHash(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $token = $this->signIn();
        [$selector, $validator] = explode('.', $token);
        $row = $this->db->queryBuilder()->table('#PREFIX#remember_tokens')->where('selector', $selector)->first()->fields;

        // Assert
        $this->assertTrue(RememberTokens::isToken($token));
        $this->assertNotSame($this->passwordHash(), $token);
        $this->assertStringNotContainsString($this->passwordHash(), $token);
        $this->assertSame(hash('sha256', $validator), $row['validator_hash']);
        $this->assertStringNotContainsString($validator, json_encode($row), 'the secret half is never stored');
        $this->assertSame($this->userId, (int) $row['userid']);
        $this->assertGreaterThan(time() + 29 * 86400, (int) $row['expires_at'], 'thirty days by default');
    }

    /**
     * A token signs in again once: the sign-in replaces it, so a copy of the cookie stops working
     * as soon as the browser it came from comes back.
     */
    #[DataProvider('databases')]
    public function testATokenIsUsedOnce(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $first = $this->signIn();
        $name  = $this->username();

        // Act
        $this->cookies($name, $first);
        $again  = (new Auth())->rememberLogin();
        $second = (string) $_SESSION['auth'];
        $_SESSION = [];
        $copy = (new Auth())->rememberLogin();

        // Assert
        $this->assertTrue($again);
        $this->assertNotSame($first, $second, 'a new token for the new sign-in');
        $this->assertFalse($copy, 'the old one is spent');
        $this->assertSame($this->userId, RememberTokens::userFor($second));
    }

    /** An expired token signs nobody in, and is deleted on the way. */
    #[DataProvider('databases')]
    public function testAnExpiredTokenIsRefused(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $token = $this->signIn();
        $this->db->queryBuilder()->table('#PREFIX#remember_tokens')->where('userid', $this->userId)->update(['expires_at' => time() - 1]);

        // Act
        $who = RememberTokens::userFor($token);

        // Assert
        $this->assertNull($who);
        $this->assertSame(0, $this->db->queryBuilder()->table('#PREFIX#remember_tokens')->where('userid', $this->userId)->count());
    }

    /**
     * Signing out ends this browser's token; signing out everywhere ends every one and flags each
     * live session to sign out on its next request.
     */
    #[DataProvider('databases')]
    public function testSigningOutEndsTheTokens(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateSessionsTable::class], $this->db);
        $this->db->queryBuilder()->table('#PREFIX#sessions')->where('userid', $this->userId)->delete();
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert(['visitorid' => base64_encode(random_bytes(16)), 'uname' => 'x', 'time' => time(), 'host_addr' => '127.0.0.1',
            'guest' => 0, 'agent' => 'test', 'userid' => $this->userId, 'url' => '/', 'logout' => 0, 'sid' => 'x', 'history' => '']);

        // Act — this browser signs out
        (new Auth())->loginById($this->userId, true);
        $here = (string) $_SESSION['auth'];
        (new Auth())->logout();
        $other1 = $this->signIn();
        $other2 = $this->signIn();
        $count  = (new Auth())->signOutEverywhere($this->userId);
        $flag   = (int) $this->db->queryBuilder()->table('#PREFIX#sessions')->where('userid', $this->userId)->first()->fields['logout'];

        // Assert
        $this->assertNull(RememberTokens::userFor($here), 'signed out here');
        $this->assertSame(2, $count);
        $this->assertSame([null, null], [RememberTokens::userFor($other1), RememberTokens::userFor($other2)]);
        $this->assertSame(1, $flag, 'the live session is told to sign out');
        $this->assertSame(0, RememberTokens::revokeAll($this->userId), 'nothing left');
        $this->db->queryBuilder()->table('#PREFIX#sessions')->where('userid', $this->userId)->delete();
    }

    /**
     * A cookie from before the tokens — the password hash — still signs in once and is replaced
     * with a token, so the upgrade signs nobody out; with `legacy_remember` false it is refused,
     * through the framework's auth addon as through Auth itself.
     */
    #[DataProvider('databases')]
    public function testAnOldPasswordHashCookie(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;
        $name = $this->username();
        $hash = $this->passwordHash();

        try {
            // Act
            $this->cookies($name, $hash);
            (new UserDatabase())->onAuthCheck();
            $accepted = $_SESSION;
            $_SESSION = [];
            $app->applicationInfo['auth'] = ['legacy_remember' => false];
            (new UserDatabase())->onAuthCheck();
            $byAddon = $_SESSION['logged'] ?? false;
            $byAuth  = (new Auth())->rememberLogin();
        } finally {
            $app->applicationInfo = $info;
        }

        // Assert
        $this->assertTrue($accepted['logged'] ?? false, 'accepted, the old way');
        $this->assertTrue(RememberTokens::isToken((string) $accepted['auth']), 'and replaced with a token');
        $this->assertSame([false, false], [$byAddon, $byAuth], 'refused once the application says so');
    }

    /**
     * An application that signs in again by handing the `auth` value back as the stored hash —
     * its own auth addon's onAuthCheck() calling `auth($username, $cookie, true, true)`, a mobile
     * client re-authenticating with the `auth` it was given — still signs in with a token, again
     * and again: nobody is signed out because the value stopped being the hash. Another
     * account's token, a spent one, or an expired one does not.
     */
    #[DataProvider('databases')]
    public function testATokenWhereTheHashWasExpected(string $type, string $host, int $port): void
    {
        // Arrange — two people, each signed in once
        $this->connect($type, $host, $port);
        $token = $this->signIn();
        $name  = $this->username();
        $other = new User();
        $other->username = 'remember_other_' . bin2hex(random_bytes(4));
        $other->email    = $other->username . '@example.com';
        $other->setPassword('x');
        $other->save();
        (new Auth())->loginById((int) $other->userid, true);
        $theirs = (string) $_SESSION['auth'];
        $_SESSION = [];
        $driver = new \Pramnos\Auth\Drivers\DatabaseAuthDriver();

        try {
            // Act — as the application's own addon does, twice; then the wrong ones
            $first   = (new Auth())->auth($name, $token, true, true);
            $_SESSION = [];
            $second  = (new Auth())->auth($name, $token, true, true);
            $_SESSION = [];
            $notMine = $driver->verify($name, $theirs, true)->success;
            $plain   = $driver->verify($name, $token, false)->success;
            RememberTokens::revoke($token);
            $spent   = $driver->verify($name, $token, true)->success;
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#remember_tokens')->where('userid', (int) $other->userid)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', (int) $other->userid)->delete();
        }

        // Assert
        $this->assertSame([true, true], [$first, $second], 'signed in with the token, and again with the same one');
        $this->assertFalse($notMine, 'another account\'s token');
        $this->assertFalse($plain, 'as a password it is nothing');
        $this->assertFalse($spent, 'ended');
    }

    /**
     * Before `migrate` has made the table there is nowhere to keep a token: a sign-in keeps the
     * credential it had and its remember cookie still signs in, so an upgrade deployed before its
     * migration signs nobody out; the rest answer nothing rather than failing. A well-formed
     * token nobody issued is nobody's.
     */
    #[DataProvider('databases')]
    public function testWithoutTheTable(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $unknown = str_repeat('a', 24) . '.' . str_repeat('b', 64);
        $nobodys = RememberTokens::userFor($unknown);
        $hash    = $this->passwordHash();
        $this->db->schema()->dropTableIfExists('remember_tokens');

        try {
            // Act
            $token  = RememberTokens::issue($this->userId);
            $who    = RememberTokens::userFor(str_repeat('c', 24) . '.' . str_repeat('d', 64));
            RememberTokens::revoke('not a token');
            $ended  = RememberTokens::revokeAll($this->userId);
            $signed = (new Auth())->loginById($this->userId, true);
            $kept   = (string) $_SESSION['auth'];
            $_SESSION = [];
            $this->cookies($this->username(), $kept);
            (new UserDatabase())->onAuthCheck();
            $again = $_SESSION['logged'] ?? false;
        } finally {
            $this->connect($type, $host, $port);
        }

        // Assert
        $this->assertNull($nobodys, 'a token nobody issued');
        $this->assertNull($token, 'nowhere to keep it');
        $this->assertNull($who);
        $this->assertSame(0, $ended);
        $this->assertTrue($signed);
        $this->assertSame($hash, $kept, 'the credential it had, until migrate');
        $this->assertTrue($again, 'and the remember cookie still signs in: nobody is signed out by the upgrade');
    }

    /**
     * Without an auth addon the framework signs in from the cookies itself; without them, or with
     * a token for another username or one that is not a token at all, it does not.
     */
    #[DataProvider('databases')]
    public function testTheFrameworkSignsInFromTheCookiesWithoutAnAddon(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $token = $this->signIn();
        $name  = $this->username();
        $auth  = new Auth();

        // Act
        $nothing = $auth->rememberLogin();
        $this->cookies('somebody-else', $token);
        $otherName = $auth->rememberLogin();
        $this->cookies($name, 'zz.not-a-token');
        $garbage = $auth->rememberLogin();
        $this->cookies($name, $token);
        $auth->authCheck();

        // Assert
        $this->assertSame([false, false, false], [$nothing, $otherName, $garbage]);
        $this->assertTrue($_SESSION['logged'] ?? false, 'authCheck() with no auth addon');
        $this->assertNull(RememberTokens::userFor('not a token'));
    }
}
