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
    private ?Database $db = null;

    private ?Database $previous = null;

    private int $userId = 0;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->userId > 1) {
            foreach (['remember_tokens', 'usertokens', 'users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $this->userId)->delete();
                } catch (\Throwable) {
                    // A table this run never made.
                }
            }
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $_SESSION = [];
        $_COOKIE  = [];
        User::forgetCurrentUser();
    }

    /** A connection, made the one the framework uses, with the users' tables; a person in them. */
    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;

        User::setupDb();
        $migrations = glob(ROOT . '/database/migrations/framework/auth/*remember_tokens*.php') ?: [];
        foreach ($migrations as $file) {
            require_once $file;
            preg_match('/^class (\w+)/m', (string) file_get_contents($file), $m);
            $class = 'Pramnos\\Framework\\Migrations\\Auth\\' . $m[1];
            $app   = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
            $app->database = $db;
            (new $class($app))->up();
        }

        $user           = new User();
        $user->username = 'remember_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->setPassword('first-secret');
        $user->save();
        $this->userId = (int) $user->userid;
        $_SESSION     = [];
        $_COOKIE      = [];
    }

    /** The remember-me cookies as the browser sends them back. */
    private function cookies(string $username, string $auth): void
    {
        $prefix = substr(md5('pcms'), 0, 10);
        $_COOKIE[$prefix] = [str_rot13('username') => $username, str_rot13('auth') => $auth];
    }

    /** The person's username. */
    private function username(): string
    {
        return (string) (new User($this->userId))->username;
    }

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
