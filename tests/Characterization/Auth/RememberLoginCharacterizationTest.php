<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Addon\Auth\UserDatabase;
use Pramnos\Auth\Auth;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * The built-in sign-in, the "remember me" sign-in from cookies, and sign-out — what an
 * application relies on, on MySQL and PostgreSQL.
 *
 * What holds: a sign-in establishes the session with `auth` set and the same value in the
 * response; that value, carried back in the `auth` cookie with the `username` one, signs the
 * person in again once the session is gone; a cookie that is not theirs signs nobody in; a
 * password changed since makes the old cookie worthless; and signing out ends the session.
 * Written before `auth` stopped being the password hash, and kept to show the change kept all of
 * this.
 */
#[CoversClass(Auth::class)]
#[CoversClass(UserDatabase::class)]
class RememberLoginCharacterizationTest extends TestCase
{
    use SignsInOnEachDatabase;

    /**
     * A sign-in establishes the session — signed in, the account, and an `auth` value that the
     * response carries too — and signing out ends it.
     */
    #[DataProvider('databases')]
    public function testASignInEstablishesTheSessionAndSigningOutEndsIt(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $auth = new Auth();

        // Act
        $signedIn = $auth->loginById($this->userId, true);
        $session  = $_SESSION;
        $response = $auth->lastResponse;
        $auth->logout();

        // Assert
        $this->assertTrue($signedIn);
        $this->assertTrue($session['logged']);
        $this->assertSame($this->userId, (int) $session['uid']);
        $this->assertNotSame('', (string) $session['auth']);
        $this->assertSame($session['auth'], $response['auth'], 'the response carries what the session holds');
        $this->assertFalse($_SESSION['logged'] ?? false, 'signed out');
    }

    /**
     * The `auth` value, carried back as a cookie with the username, signs the person in again
     * once the session is gone — "remember me"; a value that is not theirs, or another person's
     * name with it, signs nobody in.
     */
    #[DataProvider('databases')]
    public function testTheRememberCookieSignsThePersonInAgain(string $type, string $host, int $port): void
    {
        // Arrange — signed in, then the session is gone
        $this->connect($type, $host, $port);
        (new Auth())->loginById($this->userId, true);
        $remembered = (string) $_SESSION['auth'];
        $username   = $this->username();
        $_SESSION   = [];

        // Act
        $this->cookies($username, $remembered);
        (new UserDatabase())->onAuthCheck();
        $again = $_SESSION;
        $_SESSION = [];
        $this->cookies($username, 'not-the-value');
        (new UserDatabase())->onAuthCheck();
        $wrong = $_SESSION;
        $_SESSION = [];
        $this->cookies('somebody-else', $remembered);
        (new UserDatabase())->onAuthCheck();
        $otherName = $_SESSION;

        // Assert
        $this->assertTrue($again['logged'] ?? false, 'signed in again from the cookies');
        $this->assertSame($this->userId, (int) ($again['uid'] ?? 0));
        $this->assertFalse($wrong['logged'] ?? false, 'a value that is not theirs');
        $this->assertFalse($otherName['logged'] ?? false, 'another person\'s name with it');
    }

    /** A password changed since the cookie was set makes it worthless. */
    #[DataProvider('databases')]
    public function testAChangedPasswordEndsTheRememberCookie(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        (new Auth())->loginById($this->userId, true);
        $remembered = (string) $_SESSION['auth'];
        $username   = $this->username();
        $_SESSION   = [];
        $user = new User($this->userId);
        $user->setPassword('second-secret');
        $user->save();

        // Act
        $this->cookies($username, $remembered);
        (new UserDatabase())->onAuthCheck();

        // Assert
        $this->assertFalse($_SESSION['logged'] ?? false);
    }
}
