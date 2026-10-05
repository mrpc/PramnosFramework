<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\PermissionResolver;
use Pramnos\Auth\Permissions;
use Pramnos\Auth\Role;
use Pramnos\Auth\WebhookService;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\AuthServer;
use Pramnos\Framework\Migrations\UserGroups;
use Pramnos\Framework\Testing\Schema;

/**
 * A role given to a user group counts for every member, by the rules a directly held role
 * follows — and a permission check about a group is answered about that group.
 *
 * Against a real database on MySQL and PostgreSQL, built in a throwaway database: the
 * question is what `authserver.permissions` rows resolve to, so the rows are real.
 */
#[CoversClass(PermissionResolver::class)]
#[CoversClass(Permissions::class)]
#[CoversClass(Role::class)]
#[CoversClass(WebhookService::class)]
class GroupRolesTest extends TestCase
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    private const PROBE = 'pramnos_group_roles_probe';

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
        // Built once per class and engine, emptied for each test; see ReusesProbeDatabase.
        $this->db = $this->openProbe($this->admin, self::PROBE, $make, [
            CreateUsersTable::class,
            AuthServer\CreateAuthserverRolesTable::class,
            AuthServer\CreateAuthserverPermissionsTable::class,
            AuthServer\AddAudienceAndConditionsToPermissions::class,
            AuthServer\CreateAuthserverUserRolesTable::class,
            AuthServer\CreateOrganizationsTable::class,
            AuthServer\CreateAuthserverUserOrganizationsTable::class,
            UserGroups\CreateUsergroupsTables::class,
            UserGroups\CreateAuthserverGroupRolesTable::class,
        ]);

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->close();
    }

    // ── Seeding ──────────────────────────────────────────────────────────────

    private function insertId(string $table, array $row, string $key): int
    {
        $this->db->queryBuilder()->table($table)->insert($row);

        return (int) $this->db->queryBuilder()->table($table)->orderBy($key, 'desc')->first()->fields[$key];
    }

    private function user(string $name): int
    {
        return $this->insertId('users', ['username' => $name, 'email' => $name . '@example.com'], 'userid');
    }

    private function group(string $name): int
    {
        return $this->insertId('usergroups', ['name' => $name], 'groupid');
    }

    /** A role granting `$action` on `articles`, optionally an organisation's. */
    private function role(string $name, string $action, ?int $organization = null): int
    {
        $roleId = $this->insertId('authserver.roles', [
            'role_name' => $name, 'is_active' => true, 'organization_id' => $organization,
        ], 'roleid');
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => 'role', 'subject_id' => $roleId, 'object_type' => 'articles',
            'action' => $action, 'grant_type' => 'allow', 'is_active' => true,
        ]);

        return $roleId;
    }

    private function join(int $userId, int $groupId): void
    {
        $this->db->queryBuilder()->table('userstogroups')->insert(['userid' => $userId, 'groupid' => $groupId]);
    }

    private function give(int $groupId, int $roleId, array $extra = []): void
    {
        $this->db->queryBuilder()->table('authserver.group_roles')
            // $extra first: `+` keeps the left-hand key, so it has to be the override.
            ->insert($extra + ['groupid' => $groupId, 'roleid' => $roleId, 'is_active' => true]);
    }

    /** @return list<string> The actions on `articles` this resolution allows. */
    private function allowed(array $resolution): array
    {
        $actions = [];
        foreach ($resolution['permissions'] as $grant) {
            if (($grant['object_type'] ?? '') === 'articles' && ($grant['grant'] ?? '') === 'allow') {
                $actions[] = (string) $grant['action'];
            }
        }
        sort($actions);

        return $actions;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * A member holds the group's role. A withdrawn assignment, an expired one and a role
     * switched off grant nothing, exactly as a directly held role would not.
     */
    #[DataProvider('databases')]
    public function testAMemberHoldsTheGroupsActiveRolesOnly(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $alice  = $this->user('alice');
        $outside = $this->user('outside');
        $group  = $this->group('Editors');
        $this->join($alice, $group);
        $this->give($group, $this->role('Editor', 'edit'));
        $this->give($group, $this->role('Withdrawn', 'delete'), ['is_active' => false]);
        $this->give($group, $this->role('Expired', 'publish'), ['expires_at' => '2001-01-01 00:00:00']);
        $off = $this->role('Off', 'archive');
        $this->db->queryBuilder()->table('authserver.roles')->where('roleid', $off)->update(['is_active' => false]);
        $this->give($group, $off);
        $resolver = new PermissionResolver($this->db);

        // Act
        $member    = $this->allowed($resolver->resolve($alice, null));
        $nonMember = $this->allowed($resolver->resolve($outside, null));

        // Assert
        $this->assertSame(['edit'], $member);
        $this->assertSame([], $nonMember, 'a role given to a group reached somebody outside it');
    }

    /**
     * A check about a group is answered about the group: its roles' grants, not the grants
     * of the user whose id the group happens to have.
     */
    #[DataProvider('databases')]
    public function testAGroupCheckIsAboutTheGroupNotAUserWithItsId(string $type, string $host, int $port, string $user): void
    {
        // Arrange — make a user and a group share an id, and give only the user a grant.
        $this->boot($type, $host, $port, $user);
        $group = $this->group('Readers');
        $userWithThatId = $this->user('same-id');
        $this->db->queryBuilder()->table('users')->where('userid', $userWithThatId)->update(['userid' => $group]);
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => 'user', 'subject_id' => $group, 'object_type' => 'articles',
            'action' => 'delete', 'grant_type' => 'allow', 'is_active' => true,
        ]);
        $this->give($group, $this->role('Reader', 'view'));
        $permissions = new Permissions();

        // Act
        $groupMayView   = $permissions->isAllowed($group, 'articles', 'view', '', 'module', 'group');
        $groupMayDelete = $permissions->isAllowed($group, 'articles', 'delete', '', 'module', 'group');
        $resolved       = $this->allowed((new PermissionResolver($this->db))->resolveForGroup($group, null));

        // Assert
        $this->assertTrue($groupMayView, 'the group\'s own role was not found');
        $this->assertFalse($groupMayDelete, 'the group was answered with a user\'s grant');
        $this->assertSame(['view'], $resolved);
    }

    /**
     * Within an organisation a group's role counts as a direct one would: system-wide
     * always; the organisation's own only while the member belongs to it.
     */
    #[DataProvider('databases')]
    public function testOrganisationRolesThroughAGroupFollowTheMembershipRule(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $member   = $this->user('member');
        $stranger = $this->user('stranger');
        $group    = $this->group('Mixed');
        $this->join($member, $group);
        $this->join($stranger, $group);
        $this->give($group, $this->role('Everywhere', 'view'));
        $this->give($group, $this->role('Org 5 editor', 'edit', 5));
        foreach ([3, 5] as $org) {
            $this->db->queryBuilder()->table('organizations')->insert(['organization_id' => $org, 'name' => 'Org ' . $org]);
        }
        $this->db->queryBuilder()->table('authserver.user_organizations')
            ->insert(['userid' => $member, 'organization_id' => 5, 'is_active' => true]);
        $resolver = new PermissionResolver($this->db);

        // Act
        $memberIn5   = $this->allowed($resolver->resolveForOrganization($member, null, 5));
        $memberIn3   = $this->allowed($resolver->resolveForOrganization($member, null, 3));
        $strangerIn5 = $this->allowed($resolver->resolveForOrganization($stranger, null, 5));

        // Assert
        $this->assertSame(['edit', 'view'], $memberIn5);
        $this->assertSame(['view'], $memberIn3, "org 5's role answered for org 3");
        $this->assertSame(['view'], $strangerIn5, 'a non-member of org 5 held its role through the group');
    }

    /**
     * Deleting a role deletes its grants, and only its grants.
     *
     * The rows named a role nobody can hold any more, so they granted nothing, but they
     * accumulated without limit: one project's test database held 326,492 for 26,330 deleted
     * roles. A user's grant with the same number as the role is somebody else's and stays.
     */
    #[DataProvider('databases')]
    public function testDeletingARoleDeletesItsGrants(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $roleId = $this->role('Doomed', 'edit');
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => 'user', 'subject_id' => $roleId, 'object_type' => 'articles',
            'action' => 'view', 'grant_type' => 'allow', 'is_active' => true,
        ]);
        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)
            ->disableOriginalConstructor()->getMock();
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $controller->application = $app;

        // Act
        (new Role($controller))->delete($roleId);

        // Assert
        $grants = fn (string $subjectType): int => $this->db->queryBuilder()->table('authserver.permissions')
            ->where('subject_type', $subjectType)->where('subject_id', $roleId)->count();
        $this->assertSame(0, $grants('role'), "the deleted role's grants stayed behind");
        $this->assertSame(1, $grants('user'), 'a user who shares the number lost a grant');
    }

    /**
     * Deleting a role removes its group assignments too, and the applications to tell are
     * found before the rows go — members of a group holding it among them.
     */
    #[DataProvider('databases')]
    public function testDeletingARoleReachesItsGroupHolders(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $direct = $this->user('direct');
        $viaGroup = $this->user('via-group');
        $group  = $this->group('Holders');
        $this->join($viaGroup, $group);
        $roleId = $this->role('Doomed', 'edit');
        $this->give($group, $roleId);
        $this->db->queryBuilder()->table('authserver.user_roles')
            ->insert(['userid' => $direct, 'roleid' => $roleId, 'is_active' => true]);
        $holdersOf = new \ReflectionMethod(WebhookService::class, 'holdersOf');
        $service   = new WebhookService($this->db);

        // Act
        $holders = $holdersOf->invoke($service, $roleId);
        sort($holders);
        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)
            ->disableOriginalConstructor()->getMock();
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $controller->application = $app;
        (new Role($controller))->delete($roleId);

        // Assert
        $expected = [$direct, $viaGroup];
        sort($expected);
        $this->assertSame($expected, $holders);
        $this->assertSame(0, (int) $this->db->queryBuilder()->table('authserver.group_roles')->where('roleid', $roleId)->count());
        $this->assertSame([], $this->allowed((new PermissionResolver($this->db))->resolve($viaGroup, null)));
    }

    /**
     * A role held directly and one held through a group add up, the same role twice counts
     * once, and the user check on this store sees both.
     */
    #[DataProvider('databases')]
    public function testDirectAndGroupRolesAddUp(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $alice  = $this->user('alice');
        $group  = $this->group('Writers');
        $direct = $this->role('Viewer', 'view');
        $shared = $this->role('Editor', 'edit');
        $this->join($alice, $group);
        $this->give($group, $shared);
        foreach ([$direct, $shared] as $roleId) {
            $this->db->queryBuilder()->table('authserver.user_roles')
                ->insert(['userid' => $alice, 'roleid' => $roleId, 'is_active' => true]);
        }

        // Act
        $actions = $this->allowed((new PermissionResolver($this->db))->resolve($alice, null));
        $mayEdit = (new Permissions())->isAllowed($alice, 'articles', 'edit');

        // Assert
        $this->assertSame(['edit', 'view'], $actions);
        $this->assertTrue($mayEdit, 'the user check did not see a role held through a group');
    }

    /**
     * Within an organisation, a user in no group is answered by their direct roles alone;
     * and an installation with no membership table counts a group's roles everywhere, as
     * it counts direct ones.
     */
    #[DataProvider('databases')]
    public function testOrganisationChecksWithNoGroupsOrNoOrganisations(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $loner  = $this->user('loner');
        $member = $this->user('member');
        $group  = $this->group('Org roles');
        $this->join($member, $group);
        $this->give($group, $this->role('Org 5 editor', 'edit', 5));
        $resolver = new PermissionResolver($this->db);
        $lonerIn5 = $this->allowed($resolver->resolveForOrganization($loner, null, 5));

        // Act — no organisations on this installation.
        $this->db->schema()->dropTableIfExists('authserver.user_organizations');
        $memberIn3 = $this->allowed($resolver->resolveForOrganization($member, null, 3));

        // Assert
        $this->assertSame([], $lonerIn5);
        $this->assertSame(['edit'], $memberIn3, 'without organisations a group role did not count');
    }
}
