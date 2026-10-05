<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\AccountErasure;
use Pramnos\Auth\Role;
use Pramnos\Database\Database;
use Pramnos\Event\Event;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\AuthServer;
use Pramnos\Framework\Migrations\Notifications;
use Pramnos\Framework\Testing\Schema;

/**
 * Erasing an account deletes everything the framework holds about it, and nobody else's.
 *
 * Against real tables built from their migrations, on MySQL and PostgreSQL, in a throwaway
 * database. The tables here are the ones the erase used to leave behind: none of them has a
 * cascading foreign key to `users`, so a deleted account kept its passkeys, its trusted
 * devices, its roles, its memberships, its push subscriptions and its notifications.
 */
#[CoversClass(AccountErasure::class)]
class AccountErasureTest extends TestCase
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    private const PROBE = 'pramnos_erasure_probe';

    private ?Database $admin = null;

    private ?Database $db = null;

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /** A throwaway database with every table the erase touches, built by its migration. */
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

        $this->admin = $make(TEST_DATABASE);
        try {
            if (!$this->admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        // Built once per class and engine, emptied for each test; see ReusesProbeDatabase.
        $this->db = $this->openProbe($this->admin, self::PROBE, $make, [
            \Pramnos\Framework\Migrations\Core\CreateFrameworkPoliciesTable::class,
            CreateUsersTable::class,
            AuthServer\CreateOrganizationsTable::class,
            AuthServer\CreateAuthserverRolesTable::class,
            AuthServer\CreateAuthserverUserRolesTable::class,
            AuthServer\CreateAuthserverUserOrganizationsTable::class,
            AuthServer\CreatePasskeyCredentialsTable::class,
            AuthServer\CreateAuthserverTrustedDevicesTable::class,
            Notifications\CreateNotificationsTable::class,
            Notifications\CreatePushSubscriptionsTable::class,
            Notifications\CreatePushLogTable::class,
            Notifications\CreatePushtestsTable::class,
        ]);

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
    }

    protected function tearDown(): void
    {
        Event::forget(AccountErasure::EVENT);
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->close();
    }

    /** A user, and one row of theirs in every table the erase must reach. */
    private function userWithEverything(string $name): int
    {
        $this->db->queryBuilder()->table('users')->insert(['username' => $name, 'email' => $name . '@example.com']);
        $userId = (int) $this->db->queryBuilder()->table('users')->where('username', $name)->first()->fields['userid'];
        $hash   = hash('sha256', $name);

        $this->db->queryBuilder()->table('organizations')->insert(['name' => 'Org of ' . $name]);
        $orgId = (int) $this->db->queryBuilder()->table('organizations')->where('name', 'Org of ' . $name)->first()->fields['organization_id'];
        $this->db->queryBuilder()->table('authserver.roles')->insert(['role_name' => 'Role of ' . $name, 'is_active' => true]);
        $roleId = (int) $this->db->queryBuilder()->table('authserver.roles')->where('role_name', 'Role of ' . $name)->first()->fields['roleid'];

        $rows = [
            Role::assignmentTable()          => ['userid' => $userId, 'roleid' => $roleId, 'is_active' => true],
            Role::membershipTable()          => ['userid' => $userId, 'organization_id' => $orgId, 'is_active' => true],
            'authserver.passkey_credentials' => ['userid' => $userId, 'credential_id' => 'cred-' . $name, 'public_key' => 'key'],
            'authserver.trusted_devices'     => ['userid' => $userId, 'token_lookup' => substr($hash, 0, 64), 'created_at' => time(), 'expires_at' => time() + 3600],
            'pramnos.pushsubscriptions'      => ['userid' => $userId, 'endpoint' => 'https://push.example/' . $name, 'endpoint_hash' => $hash],
            'pramnos.pushlog'                => ['userid' => $userId, 'endpoint_hash' => $hash],
            \Pramnos\Push\TestPush::TABLE    => ['token_hash' => $hash, 'userid' => $userId, 'endpoint_hash' => $hash, 'sent_at' => time(), 'expires_at' => time() + 60],
            'notifications'                  => [
                'id' => substr($hash, 0, 36), 'type' => 'Probe', 'notifiable_type' => \Pramnos\User\User::class,
                'notifiable_id' => $userId, 'data' => '{}', 'created_at' => date('Y-m-d H:i:s'),
            ],
        ];
        foreach ($rows as $table => $row) {
            $this->db->queryBuilder()->table($table)->insert($row);
        }

        return $userId;
    }

    /** How many rows of this user's each table still holds. @return array<string, int> */
    private function leftOf(int $userId): array
    {
        $left = [];
        foreach ([
            'users', Role::assignmentTable(), Role::membershipTable(), 'authserver.passkey_credentials',
            'authserver.trusted_devices', 'pramnos.pushsubscriptions', 'pramnos.pushlog', \Pramnos\Push\TestPush::TABLE,
        ] as $table) {
            $left[$table] = $this->db->queryBuilder()->table($table)->where('userid', $userId)->count();
        }
        $left['notifications'] = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $userId)->count();

        return $left;
    }

    /**
     * Every row of the erased account goes, and every row of the other account stays.
     */
    #[DataProvider('databases')]
    public function testEverythingOfTheAccountGoesAndNothingElse(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        $staying = $this->userWithEverything('staying');
        $this->assertNotContains(0, $this->leftOf($leaving), 'the fixture is missing a row: ' . json_encode($this->leftOf($leaving)));

        // Act
        (new AccountErasure($this->db))->erase($leaving);

        // Assert
        $this->assertSame([], array_filter($this->leftOf($leaving)), 'the erase left these behind');
        $this->assertNotContains(0, $this->leftOf($staying), "another account's rows went too");
    }

    /**
     * A notification addressed to something that is not a user, with the same number, stays.
     *
     * `notifications` is keyed by class and id, and an organisation can be notifiable too.
     */
    #[DataProvider('databases')]
    public function testANotificationToSomethingElseWithTheSameIdStays(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        $this->db->queryBuilder()->table('notifications')->insert([
            'id' => 'org-notification-0000000000000000000', 'type' => 'Probe', 'notifiable_type' => 'App\\Organization',
            'notifiable_id' => $leaving, 'data' => '{}', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Act
        (new AccountErasure($this->db))->erase($leaving);

        // Assert
        $types = [];
        $rows  = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $leaving)->get();
        while ($rows->fetch()) {
            $types[] = $rows->fields['notifiable_type'];
        }
        $this->assertSame(['App\\Organization'], $types);
    }

    /**
     * A listener that refuses stops the erase before anything is deleted.
     */
    #[DataProvider('databases')]
    public function testARefusingListenerStopsEverything(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $leaving = $this->userWithEverything('leaving');
        Event::listen(AccountErasure::EVENT, static fn (int $userId): bool => false);

        // Act
        $raised = false;
        try {
            (new AccountErasure($this->db))->erase($leaving);
        } catch (\RuntimeException) {
            $raised = true;
        }

        // Assert
        $this->assertTrue($raised, 'the refusal was not reported');
        $this->assertNotContains(0, $this->leftOf($leaving), 'something was deleted after the refusal');
    }
}
