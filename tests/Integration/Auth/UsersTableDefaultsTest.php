<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Testing\Schema;

/**
 * The users migration gives `usertype`, `sex`, `birthdate` and `modified` a default of 0.
 *
 * Production declares them NOT NULL with no default, so an insert that leaves one out — a
 * seeder, an import, a test — is refused on both drivers, while 0 is what every such writer
 * means. Asserted by inserting a row that names none of them and reading the zeros back.
 *
 * Built in a throwaway database, dropped afterwards: the shared `users` table belongs to
 * the rest of the suite, and MySQL cannot roll back DDL.
 */
#[CoversClass(CreateUsersTable::class)]
class UsersTableDefaultsTest extends TestCase
{
    private const PROBE = 'pramnos_users_defaults_probe';

    private ?Database $admin = null;

    private string $type = '';

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;

        return $db;
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            $this->admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        }
    }

    /**
     * A user row that names none of the four columns is accepted, and they read back 0.
     */
    #[DataProvider('databases')]
    public function testTheFourColumnsDefaultToZero(string $type, string $host, int $port, string $user): void
    {
        // Arrange — a database of its own, with nothing but this migration in it.
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        $this->admin = self::connection($type, $host, $port, $user, TEST_DATABASE);
        try {
            if (!$this->admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $this->admin->query('CREATE DATABASE ' . self::PROBE);

        $probe = self::connection($type, $host, $port, $user, self::PROBE);
        $probe->connect(false);
        Schema::ensure([CreateUsersTable::class], $probe);

        // Act
        $probe->queryBuilder()->table('users')->insert(['username' => 'defaults', 'email' => 'd@example.com']);
        $row = $probe->queryBuilder()->table('users')->where('username', 'defaults')->first();
        $probe->close();

        // Assert
        $this->assertSame(1, (int) $row->numRows, 'the insert was refused');
        foreach (['usertype', 'sex', 'birthdate', 'modified'] as $column) {
            $this->assertSame(0, (int) $row->fields[$column], $column . ' did not default to 0');
        }
    }
}
