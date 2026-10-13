<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * A `User` that did not load, and one whose row went away, on MySQL and PostgreSQL.
 *
 * `new User($id)` for an id with no row kept `_isnew = 0` and the default `userid` of 1, so a
 * save ran `UPDATE users … WHERE userid = 1` — the Guest row, or nothing — and then wrote its
 * details against that id. An application creating accounts from `new User($_SESSION['uid'])`
 * lost every one of them, with nothing logged.
 */
#[CoversClass(User::class)]
class UserThatDidNotLoadTest extends TestCase
{
    private Database $db;

    private ?Database $previousSingleton = null;

    private string $username = '';

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /** Connect, make the database the framework's, and make sure the user tables exist. */
    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        \Pramnos\Application\Application::getInstance();

        $this->db           = new Database();
        $this->db->type     = $type;
        $this->db->server   = $host;
        $this->db->port     = $port;
        $this->db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $this->db->password = 'secret';
        $this->db->database = TEST_DATABASE;

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        User::setupDb();
        $this->username = 'never_loaded_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->connected && $this->username !== '') {
            foreach ($this->db->queryBuilder()->table('users')->where('username', $this->username)->getAll() as $row) {
                $this->db->queryBuilder()->table('userdetails')->where('userid', (int) $row['userid'])->delete();
            }
            $this->db->queryBuilder()->table('users')->where('username', $this->username)->delete();
        }
        if ($this->previousSingleton !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previousSingleton;
        }
    }

    /**
     * A user that did not load is saved as a new one, with its own id, and user 1 is untouched.
     */
    #[DataProvider('databases')]
    public function testAUserThatDidNotLoadIsSavedAsANewOne(string $type, string $host, int $port): void
    {
        // Arrange — an id nobody has, and the Guest row as it is
        $this->connect($type, $host, $port);
        $guestBefore = $this->db->queryBuilder()->table('users')->where('userid', 1)->getAll();
        $user = new User(2_000_000_000 + random_int(1, 1_000_000));
        $user->username = $this->username;
        $user->email    = $this->username . '@example.com';
        $user->setinfo_signup_source = 'google';

        // Act
        $user->save();

        // Assert
        $this->assertSame([], $user->_errors);
        $this->assertGreaterThan(1, (int) $user->userid);
        $row = $this->db->queryBuilder()->table('userdetails')->where('userid', (int) $user->userid)
            ->where('fieldname', 'setinfo_signup_source')->getAll();
        $this->assertSame('google', $row[0]['value'] ?? null, 'its details are written against its own id');
        $this->assertSame($guestBefore, $this->db->queryBuilder()->table('users')->where('userid', 1)->getAll(), 'the Guest row is not overwritten');
    }

    /**
     * Saving a user whose row is gone is an error, and writes no details against its id.
     */
    #[DataProvider('databases')]
    public function testSavingAUserWhoseRowIsGoneIsRefused(string $type, string $host, int $port): void
    {
        // Arrange — a user, loaded, then deleted underneath
        $this->connect($type, $host, $port);
        $user = new User();
        $user->username = $this->username;
        $user->email    = $this->username . '@example.com';
        $user->save();
        $id     = (int) $user->userid;
        $loaded = new User($id);
        $this->db->queryBuilder()->table('users')->where('userid', $id)->delete();
        $loaded->setinfo_orphan = 'x';

        // Act
        $loaded->save();

        // Assert
        $this->assertSame(['There is no user ' . $id . ' to save: it does not exist.'], $loaded->_errors);
        $this->assertSame(0, $this->db->queryBuilder()->table('userdetails')->where('userid', $id)->count());
    }

    /**
     * Saving a loaded user with nothing changed is still a save: MySQL reports zero rows for an
     * UPDATE that changed nothing, and that must not read as a missing user.
     */
    #[DataProvider('databases')]
    public function testSavingAnUnchangedUserIsNotAnError(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $user = new User();
        $user->username = $this->username;
        $user->email    = $this->username . '@example.com';
        $user->save();
        $loaded = new User((int) $user->userid);

        // Act
        $loaded->save();

        // Assert
        $this->assertSame([], $loaded->_errors);
    }
}
