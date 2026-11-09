<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Drivers\DatabaseAuthDriver;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\AddCaseInsensitiveLoginIndexesToUsers;
use Pramnos\User\User;

/**
 * Signing in finds the account whatever the case its name or address is typed in, with the
 * spaces around it gone — on PostgreSQL, which compares case as written, as on MySQL, whose
 * collation never did. Of two accounts that differ only in case, the one typed exactly is
 * meant; typed neither way, none is guessed. Found by address too for a password reset.
 */
#[CoversClass(User::class)]
#[CoversClass(DatabaseAuthDriver::class)]
#[CoversClass(AddCaseInsensitiveLoginIndexesToUsers::class)]
class CaseInsensitiveSignInTest extends TestCase
{
    /** The throwaway database. */
    private const PROBE = 'pf_signin_case_probe';

    /** The connection the suite had, put back. */
    private ?Database $previous = null;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /**
     * "Polina Naoumi" signs in as "polina naoumi" and " POLINA NAOUMI ", and by "x@y.com" for
     * "X@Y.com"; a wrong password is still refused. "Anna" and "anna": each typed exactly is its
     * own, "ANNA" is neither. The password reset finds the address whatever its case; on
     * PostgreSQL the lower-case indexes are made.
     */
    #[DataProvider('databases')]
    public function testANameOrAddressIsFoundWhateverItsCase(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $db = $this->probe($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/auth/2026_11_09_000009_add_case_insensitive_login_indexes_to_users.php';
        $app = new Application();
        $app->database = $db;
        $hash = password_hash('right horse', PASSWORD_DEFAULT);
        $users = static fn () => $db->queryBuilder()->table('#PREFIX#users');
        foreach ([['Polina Naoumi', 'X@Y.com'], ['Anna', 'anna1@example.test'], ['anna', 'anna2@example.test']] as [$name, $mail]) {
            $users()->insert(['username' => $name, 'email' => $mail, 'password' => $hash, 'active' => 1, 'validated' => 1]);
        }
        // Exactly as written: MySQL's own comparison would find "Anna" for "anna".
        $id = static fn (string $name): int => (int) (array_values(array_filter($users()->select(['userid', 'username'])->getAllOrFail(),
            static fn (array $r): bool => $r['username'] === $name))[0]['userid'] ?? 0);
        $driver = new DatabaseAuthDriver();

        try {
            // Act
            (new AddCaseInsensitiveLoginIndexesToUsers($app))->up();
            $lower   = $driver->verify('polina naoumi', 'right horse');
            $shouted = $driver->verify(' POLINA NAOUMI ', 'right horse');
            $mail    = $driver->verify('x@y.com', 'right horse');
            $wrong   = $driver->verify('polina naoumi', 'wrong horse');
            $anna    = $driver->verify('Anna', 'right horse');
            $annaToo = $driver->verify('anna', 'right horse');
            $either  = $driver->verify('ANNA', 'right horse');
            $reset   = User::getuserid('x@y.com', 'email');
            $index   = $type === 'postgresql'
                ? $db->query("SELECT 1 FROM pg_indexes WHERE indexname = 'idx_users_lower_email'")->numRows : null;

            // Assert
            $this->assertTrue($lower->success, 'lower case');
            $this->assertTrue($shouted->success, 'capitals and spaces around');
            $this->assertTrue($mail->success, 'the address, lower case');
            $this->assertSame($id('Polina Naoumi'), $mail->uid);
            $this->assertFalse($wrong->success, 'a wrong password, still refused');
            $this->assertSame($id('Anna'), $anna->uid, 'typed exactly: that one');
            $this->assertSame($id('anna'), $annaToo->uid);
            $this->assertFalse($either->success, 'two that differ only in case, neither typed: none guessed');
            $this->assertSame($id('Polina Naoumi'), (int) $reset);
            if ($type === 'postgresql') {
                $this->assertSame(1, $index, 'the address indexed in lower case');
            }
        } finally {
            $this->drop($type, $host, $port, $user);
        }
    }

    /** A database of its own with the framework's users table, made the application's. */
    private function probe(string $type, string $host, int $port, string $user): Database
    {
        $admin = $this->connection($type, $host, $port, $user, TEST_DATABASE);
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->query('CREATE DATABASE ' . self::PROBE);
        $admin->close();
        $db = $this->connection($type, $host, $port, $user, self::PROBE);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateUsersTable::class], $db);
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;

        return $db;
    }

    /** The suite's connection back, and the database gone. */
    private function drop(string $type, string $host, int $port, string $user): void
    {
        $singleton = &Factory::getDatabase();
        $singleton->close();
        $singleton = $this->previous;
        $admin     = $this->connection($type, $host, $port, $user, TEST_DATABASE);
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->close();
    }

    /** A connection to a database by name, or the test skipped where the server is not there. */
    private function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        return $db;
    }
}
