<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiCrudController;
use Pramnos\Auth\OrganizationScope;
use Pramnos\Auth\PermissionResolverInterface;
use Pramnos\Auth\Permissions;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\AuthServer;
use Pramnos\Framework\Testing\Schema;

/**
 * Permission checks made in one organisation, against the real tables.
 *
 * The case that was filed: a member who is a Client in organisation A and an Editor in
 * organisation B. Asked without an organisation, the store answers with the union of both
 * roles, so the Client could edit in A. `ApiCrudController` and `Permissions::isAllowed()` —
 * and through it `Gate`'s fallback — always asked that way.
 *
 * Run against MySQL and PostgreSQL, because the organisation-scoped read is one joined query
 * that each dialect builds differently.
 */
#[CoversClass(OrganizationScope::class)]
#[CoversClass(Permissions::class)]
#[CoversClass(ApiCrudController::class)]
class OrganizationScopedChecksTest extends TestCase
{
    private const PROBE = 'pramnos_org_scope_probe';

    private const ORG_A = 3;
    private const ORG_B = 5;

    private ?Database $admin = null;

    private ?Database $db = null;

    private ?Database $previous = null;

    private int $member = 0;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /**
     * A database of its own, the member, and their roles:
     *   everywhere — `list` on articles, a system-wide role
     *   client     — `read`, organisation A's
     *   editor     — `read` and `update`, organisation B's
     */
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
        Schema::ensure([
            CreateUsersTable::class,
            AuthServer\CreateAuthserverRolesTable::class,
            AuthServer\CreateAuthserverPermissionsTable::class,
            AuthServer\AddAudienceAndConditionsToPermissions::class,
            AuthServer\CreateAuthserverUserRolesTable::class,
            AuthServer\CreateOrganizationsTable::class,
            AuthServer\CreateAuthserverUserOrganizationsTable::class,
        ], $this->db);

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        // The member, a member of both organisations
        $this->db->queryBuilder()->table('users')->insert(['username' => 'member', 'email' => 'member@example.com']);
        $this->member = (int) $this->db->queryBuilder()->table('users')->orderBy('userid', 'desc')->first()->fields['userid'];
        foreach ([self::ORG_A, self::ORG_B] as $org) {
            $this->db->queryBuilder()->table('organizations')->insert(['organization_id' => $org, 'name' => 'Org ' . $org]);
            $this->db->queryBuilder()->table('authserver.user_organizations')
                ->insert(['userid' => $this->member, 'organization_id' => $org, 'is_active' => true]);
        }

        $this->give($this->role('everywhere', ['list'], null));
        $this->give($this->role('client', ['read'], self::ORG_A));
        $this->give($this->role('editor', ['read', 'update'], self::ORG_B));
    }

    protected function setUp(): void
    {
        OrganizationScope::reset();
    }

    protected function tearDown(): void
    {
        OrganizationScope::reset();
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->query('DROP DATABASE IF EXISTS ' . self::PROBE);
    }

    /**
     * A role allowing `$actions` on articles.
     *
     * @param list<string> $actions
     */
    private function role(string $name, array $actions, ?int $organization): int
    {
        $this->db->queryBuilder()->table('authserver.roles')
            ->insert(['role_name' => $name, 'is_active' => true, 'organization_id' => $organization]);
        $roleId = (int) $this->db->queryBuilder()->table('authserver.roles')->orderBy('roleid', 'desc')->first()->fields['roleid'];
        foreach ($actions as $action) {
            $this->db->queryBuilder()->table('authserver.permissions')->insert([
                'subject_type' => 'role', 'subject_id' => $roleId, 'object_type' => 'articles',
                'action' => $action, 'grant_type' => 'allow', 'is_active' => true,
            ]);
        }

        return $roleId;
    }

    /** Give the member a role. */
    private function give(int $roleId): void
    {
        $this->db->queryBuilder()->table('authserver.user_roles')
            ->insert(['userid' => $this->member, 'roleid' => $roleId, 'is_active' => true]);
    }

    /** May the member do $action on articles, as `Permissions::isAllowed()` answers? */
    private function allowed(string $action, ?Permissions $permissions = null): bool
    {
        return (bool) ($permissions ?? new Permissions())->isAllowed($this->member, 'articles', $action);
    }

    /**
     * In organisation A the member is a Client: they may read, not update — and the
     * system-wide role still counts.
     */
    #[DataProvider('databases')]
    public function testInAnOrganisationOnlyItsRolesAndTheSystemWideOnesCount(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        OrganizationScope::resolveWith(fn () => self::ORG_A);

        // Act & Assert — A: client and everywhere
        $this->assertFalse($this->allowed('update'), "organisation B's Editor role answered in organisation A");
        $this->assertTrue($this->allowed('read'));
        $this->assertTrue($this->allowed('list'), 'the system-wide role must count in every organisation');

        // And in B the Editor role is theirs
        OrganizationScope::resolveWith(fn () => self::ORG_B);
        $this->assertTrue($this->allowed('update'));
    }

    /**
     * Without a resolver the answer is what it always was: the union. Nothing changes for an
     * application with one organisation.
     */
    #[DataProvider('databases')]
    public function testWithoutAnOrganisationTheAnswerIsUnchanged(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act & Assert
        $this->assertTrue($this->allowed('update'));
        OrganizationScope::resolveWith(fn () => null);
        $this->assertTrue($this->allowed('update'), 'a request about no organisation is checked as before');
    }

    /**
     * One Permissions instance asked about A and then B gives each its own answer: the cache
     * is per organisation.
     */
    #[DataProvider('databases')]
    public function testTheCacheIsPerOrganisation(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $permissions = new Permissions();

        // Act
        $inB = $permissions->isAllowedInOrganization($this->member, self::ORG_B, 'articles', 'update');
        $inA = $permissions->isAllowedInOrganization($this->member, self::ORG_A, 'articles', 'update');

        // Assert
        $this->assertTrue($inB);
        $this->assertFalse($inA, "B's answer was served for A from the cache");
        // The explicit organisation does not linger: the next unscoped check is the union again.
        $this->assertTrue($permissions->isAllowed($this->member, 'articles', 'update'));
    }

    /**
     * A resolver that fails refuses, even what the system-wide role allows.
     *
     * Falling back to the unscoped check would hand out the union of every organisation's
     * roles — the thing scoping exists to prevent.
     */
    #[DataProvider('databases')]
    public function testAFailingResolverRefuses(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        OrganizationScope::resolveWith(function (): int {
            throw new \RuntimeException('no tenant on this request');
        });

        // Act & Assert
        $this->assertFalse($this->allowed('list'));
        $this->assertFalse($this->allowed('update'));
        // The public _isAllowed() path refuses too
        $this->assertFalse((new Permissions())->_isAllowed($this->member, 'articles', 'list', '', 'module', 'user', false));
    }

    /**
     * The generated CRUD endpoints authorise in the request's organisation.
     */
    #[DataProvider('databases')]
    public function testApiCrudAuthorisesInTheOrganisation(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $controller = new ArticlesProbeController($this->member);

        // Act & Assert — A: no update; B: update
        OrganizationScope::resolveWith(fn () => self::ORG_A);
        $this->assertFalse($controller->may('update'), "organisation B's Editor role answered in organisation A");
        $this->assertTrue($controller->may('read'));
        $this->assertFalse($controller->mayOn('update', '42'), 'the record-level check is scoped too');

        OrganizationScope::resolveWith(fn () => self::ORG_B);
        $this->assertTrue($controller->may('update'));

        // A controller can name its own organisation
        $controller->organization = self::ORG_A;
        $this->assertFalse($controller->may('update'));
    }

    /**
     * An endpoint whose organisation cannot be resolved is refused, not opened.
     *
     * For these endpoints "no opinion" means allowed, so the failure has to be a refusal.
     */
    #[DataProvider('databases')]
    public function testApiCrudRefusesWhenTheOrganisationCannotBeFound(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $controller = new ArticlesProbeController($this->member);
        OrganizationScope::resolveWith(fn () => 'not an id');

        // Act & Assert
        $this->assertFalse($controller->may('list'));
    }

    /**
     * A resolver an application supplies that cannot answer about one organisation is
     * refused in one, rather than asked the unscoped question.
     */
    #[DataProvider('databases')]
    public function testAResolverWithoutOrganisationSupportIsRefusedInOne(string $type, string $host, int $port, string $user): void
    {
        // Arrange — a resolver that only knows the unscoped question, and allows everything
        $this->boot($type, $host, $port, $user);
        $controller = new ArticlesProbeController($this->member);
        $controller->resolver = new class implements PermissionResolverInterface {
            /** Everything, everywhere. */
            public function resolve(int $userId, ?int $appId): array
            {
                return ['permissions' => [['object_type' => 'articles', 'action' => '*', 'grant' => 'allow']]];
            }
        };

        // Act & Assert — fine with no organisation, refused in one
        $this->assertTrue($controller->may('update'));
        OrganizationScope::resolveWith(fn () => self::ORG_A);
        $this->assertFalse($controller->may('update'));
    }
}

/**
 * A CRUD controller over `articles`, with the user and the resolver the test chooses.
 */
class ArticlesProbeController extends ApiCrudController
{
    /** @var int|null An organisation this controller names itself, overriding the scope */
    public ?int $organization = null;

    /** @var PermissionResolverInterface|null A resolver instead of the framework's */
    public ?PermissionResolverInterface $resolver = null;

    protected string $resource = 'articles';

    /** @param int $userId The signed-in user */
    public function __construct(private int $userId)
    {
    }

    /** May the user do $action on articles? */
    public function may(string $action): bool
    {
        return $this->authorize($action);
    }

    /** May the user do $action on one article? */
    public function mayOn(string $action, string $id): bool
    {
        return $this->permissionForObject($action, $id) !== false;
    }

    /** The test's user. */
    protected function requestUser(): ?object
    {
        return (object) ['userid' => $this->userId];
    }

    /** The controller's own organisation when set, the scope's otherwise. */
    protected function permissionOrganizationId(): ?int
    {
        return $this->organization ?? parent::permissionOrganizationId();
    }

    /** The test's resolver when set. */
    protected function permissionResolver(): PermissionResolverInterface
    {
        return $this->resolver ?? parent::permissionResolver();
    }
}
