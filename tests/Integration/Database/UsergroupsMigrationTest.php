<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\UserGroups\CreateUsergroupsTables;
use Pramnos\Framework\Testing\Schema;

/**
 * The usergroups feature's migration, on MySQL and PostgreSQL, fresh and on an
 * installation that built the groups table itself.
 *
 * Built in a throwaway database: the migration's whole job is creating tables, and the
 * shared ones belong to the rest of the suite.
 */
#[CoversClass(CreateUsergroupsTables::class)]
class UsergroupsMigrationTest extends TestCase
{
    private const PROBE = 'pramnos_usergroups_probe';

    private ?Database $admin = null;

    private ?Database $db = null;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private function boot(string $type, string $host, int $port, string $user): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $make = static function (string $name) use ($type, $host, $port, $user): Database {
            $db           = new Database();
            $db->type     = $type;
            $db->server   = $host;
            $db->port     = $port;
            $db->user     = $user;
            $db->password = 'secret';
            $db->database = $name;

            return $db;
        };

        $this->admin = $make('pramnos_test');
        try {
            if (!$this->admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $this->admin->query('CREATE DATABASE ' . self::PROBE);

        $this->db = $make(self::PROBE);
        $this->db->connect(false);
        Schema::ensure([CreateUsersTable::class], $this->db);
    }

    protected function tearDown(): void
    {
        $this->db?->close();
        $this->admin?->query('DROP DATABASE IF EXISTS ' . self::PROBE);
    }

    private function rows(string $table): int
    {
        return (int) $this->db->queryBuilder()->table($table)->count();
    }

    /**
     * Fresh: both tables, and a membership goes with its user and with its group.
     */
    #[DataProvider('databases')]
    public function testAFreshInstallationCascadesBothWays(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Schema::ensure([CreateUsergroupsTables::class], $this->db);
        $this->db->queryBuilder()->table('users')->insert(['username' => 'a', 'email' => 'a@example.com']);
        $this->db->queryBuilder()->table('users')->insert(['username' => 'b', 'email' => 'b@example.com']);
        $ua = (int) $this->db->queryBuilder()->table('users')->where('username', 'a')->first()->fields['userid'];
        $ub = (int) $this->db->queryBuilder()->table('users')->where('username', 'b')->first()->fields['userid'];
        $this->db->queryBuilder()->table('usergroups')->insert(['name' => 'One']);
        $this->db->queryBuilder()->table('usergroups')->insert(['name' => 'Two']);
        $g1 = (int) $this->db->queryBuilder()->table('usergroups')->where('name', 'One')->first()->fields['groupid'];
        $g2 = (int) $this->db->queryBuilder()->table('usergroups')->where('name', 'Two')->first()->fields['groupid'];
        foreach ([[$ua, $g1], [$ub, $g1], [$ub, $g2]] as [$u, $g]) {
            $this->db->queryBuilder()->table('userstogroups')->insert(['userid' => $u, 'groupid' => $g]);
        }

        // Act
        $this->db->queryBuilder()->table('users')->where('userid', $ua)->delete();
        $this->db->queryBuilder()->table('usergroups')->where('groupid', $g2)->delete();

        // Assert — only b's membership of One survives.
        $this->assertSame(1, $this->rows('userstogroups'));
        $row = $this->db->queryBuilder()->table('userstogroups')->first()->fields;
        $this->assertSame([$ub, $g1], [(int) $row['userid'], (int) $row['groupid']]);
    }

    /**
     * An installation that built `usergroups` itself keeps it, rows and all, and gains the
     * membership table — without a key to a `groupid` whose type it cannot know.
     */
    #[DataProvider('databases')]
    public function testAHandBuiltGroupsTableIsKept(string $type, string $host, int $port, string $user): void
    {
        // Arrange — the shape such installations have.
        $this->boot($type, $host, $port, $user);
        $this->db->query($type === 'mysql'
            ? 'CREATE TABLE usergroups (groupid mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(80) NOT NULL, description text NOT NULL, `order` tinyint DEFAULT NULL)'
            : 'CREATE TABLE usergroups (groupid serial PRIMARY KEY, name varchar(80) NOT NULL, description text NOT NULL, "order" smallint DEFAULT NULL)');
        $this->db->queryBuilder()->table('usergroups')->insert(['name' => 'Legacy', 'description' => 'kept']);

        // Act
        Schema::ensure([CreateUsergroupsTables::class], $this->db);
        $this->db->queryBuilder()->table('users')->insert(['username' => 'a', 'email' => 'a@example.com']);
        $ua = (int) $this->db->queryBuilder()->table('users')->where('username', 'a')->first()->fields['userid'];
        // A group id that does not exist is accepted: there is no key to refuse it.
        $this->db->queryBuilder()->table('userstogroups')->insert(['userid' => $ua, 'groupid' => 424242]);

        // Assert
        $this->assertSame('kept', $this->db->queryBuilder()->table('usergroups')->first()->fields['description']);
        $this->assertSame(1, $this->rows('userstogroups'), 'no foreign key to the hand-built groups table');
    }

    /**
     * `down()` removes both, membership first.
     */
    #[DataProvider('databases')]
    public function testDownRemovesBothTables(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Schema::ensure([CreateUsergroupsTables::class], $this->db);
        $application = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
        $application->database = $this->db;

        // Act
        (new CreateUsergroupsTables($application))->down();

        // Assert
        $this->assertFalse($this->db->schema()->hasTable('userstogroups'));
        $this->assertFalse($this->db->schema()->hasTable('usergroups'));
    }
}
