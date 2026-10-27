<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiCrudController;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\AuthServer;

/**
 * `ApiCrudController::currentOrganizationId()` — the default membership-based resolver a
 * generated controller's `currentOrganizationId()` override calls (see
 * {@see \Pramnos\Console\Commands\MakeCommandBase} and the class's own docblock), against the
 * real `authserver.user_organizations` table.
 *
 * Run against MySQL and PostgreSQL: the membership read is one query each dialect's query
 * builder assembles differently, and the `expires_at` comparison happens in PHP afterward, not
 * in SQL, specifically because the two dialects disagree on it (see the method's own comment).
 */
#[CoversClass(ApiCrudController::class)]
class ApiCrudCurrentOrganizationIdTest extends TestCase
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    private const PROBE = 'pramnos_crud_current_org_probe';

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

        $this->admin = $make(TEST_DATABASE);
        try {
            if (!$this->admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->db = $this->openProbe($this->admin, self::PROBE, $make, [
            CreateUsersTable::class,
            AuthServer\CreateOrganizationsTable::class,
            AuthServer\CreateAuthserverUserOrganizationsTable::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db?->close();
        $this->admin?->close();
    }

    /** A user, with memberships in the given organisations (created if missing). */
    private function member(array $memberships): int
    {
        $this->db->queryBuilder()->table('users')->insert(['username' => 'org_' . bin2hex(random_bytes(3)), 'email' => bin2hex(random_bytes(4)) . '@example.com']);
        $userId = (int) $this->db->queryBuilder()->table('users')->orderBy('userid', 'desc')->first()->fields['userid'];
        foreach ($memberships as $membership) {
            $org = $membership['organization'];
            if (count($this->db->queryBuilder()->table('organizations')->where('organization_id', $org)->first()) === 0) {
                $this->db->queryBuilder()->table('organizations')->insert(['organization_id' => $org, 'name' => 'Org ' . $org]);
            }
            $this->db->queryBuilder()->table('authserver.user_organizations')->insert([
                'userid'          => $userId,
                'organization_id' => $org,
                'is_active'       => $membership['active'] ?? true,
                'expires_at'      => $membership['expires_at'] ?? null,
            ]);
        }

        return $userId;
    }

    private function probe(int $userId): OrganizationLookupProbeController
    {
        $controller     = new OrganizationLookupProbeController($userId);
        $controller->db = $this->db;

        return $controller;
    }

    /** The anonymous/system account (userid 1) never has a single answer. */
    #[DataProvider('databases')]
    public function testTheSystemAccountHasNoOrganisation(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act / Assert
        $this->assertNull($this->probe(1)->currentOrganizationId());
    }

    /** A member of exactly one active organisation resolves to it. */
    #[DataProvider('databases')]
    public function testASingleActiveMembershipResolves(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $userId = $this->member([['organization' => 11]]);

        // Act / Assert
        $this->assertSame(11, $this->probe($userId)->currentOrganizationId());
    }

    /** A member of two organisations has no single answer — the caller must say which one. */
    #[DataProvider('databases')]
    public function testTwoActiveMembershipsResolveToNull(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $userId = $this->member([['organization' => 11], ['organization' => 12]]);

        // Act / Assert
        $this->assertNull($this->probe($userId)->currentOrganizationId());
    }

    /** A user with no membership row at all has no organisation. */
    #[DataProvider('databases')]
    public function testNoMembershipResolvesToNull(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $userId = $this->member([]);

        // Act / Assert
        $this->assertNull($this->probe($userId)->currentOrganizationId());
    }

    /** An inactive membership does not count, so the one active one is the single answer. */
    #[DataProvider('databases')]
    public function testAnInactiveMembershipIsIgnored(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $userId = $this->member([
            ['organization' => 11],
            ['organization' => 12, 'active' => false],
        ]);

        // Act / Assert
        $this->assertSame(11, $this->probe($userId)->currentOrganizationId());
    }

    /**
     * Expiry is checked in PHP, not SQL (the method's own comment explains why): a membership
     * past its `expires_at` is excluded, so the still-open one is the single answer.
     */
    #[DataProvider('databases')]
    public function testAnExpiredMembershipIsExcluded(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $userId = $this->member([
            ['organization' => 11],
            ['organization' => 12, 'expires_at' => '2000-01-01 00:00:00'],
        ]);

        // Act / Assert
        $this->assertSame(11, $this->probe($userId)->currentOrganizationId());
    }

    /** No `authserver` tables on this installation: the question still gets an answer, not a fatal. */
    #[DataProvider('databases')]
    public function testWithNoAuthserverTablesItRefusesRatherThanFails(string $type, string $host, int $port, string $user): void
    {
        // Arrange — a database with only `users`, never migrated for authserver
        $this->boot($type, $host, $port, $user);
        $this->db->schema()->dropTableIfExists(\Pramnos\Auth\Role::membershipTable());
        $this->probeChanged($type);
        $this->db->queryBuilder()->table('users')->insert(['username' => 'no_org', 'email' => 'no_org@example.com']);
        $userId = (int) $this->db->queryBuilder()->table('users')->orderBy('userid', 'desc')->first()->fields['userid'];

        // Act / Assert
        $this->assertNull($this->probe($userId)->currentOrganizationId());
    }
}

/** A CRUD controller whose user and database connection the test chooses. */
class OrganizationLookupProbeController extends ApiCrudController
{
    public ?Database $db = null;

    public function __construct(private readonly int $userId)
    {
    }

    protected function requestUser(): ?object
    {
        return (object) ['userid' => $this->userId];
    }

    protected function db(): Database
    {
        return $this->db;
    }

    public function currentOrganizationId(): ?int
    {
        return parent::currentOrganizationId();
    }
}
