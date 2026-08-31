<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\PermissionResolver;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Organisation scoping in {@see PermissionResolver::resolveForOrganization()}.
 *
 * The schema has always had `authserver.roles.organization_id`, with NULL meaning
 * "system-wide". Nothing read it. `resolve()` returned the permissions of every
 * active role a user held, whatever organisation that role belonged to — so a role
 * defined for organisation 5 decided questions about organisation 3's data, and a
 * multi-tenant application built on the framework's RBAC had no isolation at all.
 *
 * Two conditions now decide whether a role counts, and these tests are the two
 * conditions:
 *
 *   - the role is system-wide, or belongs to the organisation being asked about;
 *   - and for an organisation-scoped role, the user is a member of it.
 *
 * The second is the rule the `user_organizations` migration described from the
 * start and nothing enforced.
 *
 * **Leaving an organisation deletes nothing.** The assignment row stays and stops
 * counting; rejoining restores the same set. That is asserted here rather than
 * assumed, because "revoke access" and "forget what this person was" are different
 * operations and only the first was wanted.
 *
 * Requires the Docker MySQL container.
 */
#[CoversClass(PermissionResolver::class)]
class PermissionResolverOrganizationTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private PermissionResolver $resolver;

    /** Physical table names (authserver.* maps to a prefix on MySQL). */
    private string $tRoles;
    private string $tUserRoles;
    private string $tPerms;
    private string $tMembers;

    private const UID = 4100;

    /** The Database singleton as it was before this test replaced it. */
    private ?\Pramnos\Database\Database $previousSingleton = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->previousSingleton = $dbRef;
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL; the QueryBuilder abstracts the dialect.');
        }

        $p = $this->db->prefix;
        $this->tRoles     = $p . 'authserver_roles';
        $this->tUserRoles = $p . 'authserver_user_roles';
        $this->tPerms     = $p . 'authserver_permissions';
        $this->tMembers   = $p . 'authserver_user_organizations';

        $this->buildTables();
        $this->resolver = new PermissionResolver($this->db);
    }

    protected function tearDown(): void
    {
        foreach ([$this->tUserRoles, $this->tMembers, $this->tPerms, $this->tRoles] as $t) {
            $this->db->query("DELETE FROM `{$t}` WHERE 1=1");
        }

        // Hand the singleton back as it was found. Several suites in this repo
        // depend on whatever the previous test left in it — a fragility of their
        // own, but not one to make worse by replacing it and walking away.
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->previousSingleton;
    }

    /**
     * The four tables the scoped read touches.
     *
     * Built here with exactly the columns under test rather than through the
     * migrations: three of these tables are shared with other suites, and this test
     * needs to own the rows in them without owning their schema.
     */
    private function buildTables(): void
    {
        $p = $this->db->prefix;

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$this->tRoles}` (
            `roleid` INT NOT NULL PRIMARY KEY,
            `role_name` VARCHAR(100) NOT NULL DEFAULT '',
            `organization_id` INT NULL,
            `is_active` TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB");
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$this->tUserRoles}` (
            `userid` BIGINT NOT NULL,
            `roleid` INT NOT NULL,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `expires_at` TIMESTAMP NULL,
            PRIMARY KEY (`userid`,`roleid`)
        ) ENGINE=InnoDB");
        // Built from its migration rather than by hand, and dropped first: the test
        // database carried a copy with a `user_id` column, which is not what this
        // framework writes anywhere — the migration and OrganizationsController both
        // say `userid`. A stale shape left in place would have this test asserting
        // against a table no installation actually has.
        // `organizations` first: the membership table takes a foreign key to it, and
        // a missing FK target fails the CREATE rather than being skipped.
        $this->db->query("DROP TABLE IF EXISTS `{$this->tMembers}`");
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
        ], $this->db);

        // The organisations the tests refer to have to exist, for the same FK.
        foreach ([3, 5, 99] as $orgId) {
            $this->db->query(
                "INSERT IGNORE INTO `{$p}organizations` (organization_id, name) "
                . "VALUES ({$orgId}, 'Org {$orgId}')"
            );
        }
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$this->tPerms}` (
            `permissionid` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `subject_type` VARCHAR(20) NOT NULL,
            `subject_id` BIGINT NOT NULL,
            `object_type` VARCHAR(50) NOT NULL,
            `object_id` VARCHAR(100) NULL,
            `action` VARCHAR(20) NOT NULL,
            `grant_type` VARCHAR(5) NOT NULL DEFAULT 'allow',
            `priority` INT NOT NULL DEFAULT 100,
            `app_id` BIGINT NULL,
            `conditions` TEXT NULL,
            `expires_at` TIMESTAMP NULL,
            `is_active` TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB");

        foreach ([$this->tUserRoles, $this->tMembers, $this->tPerms, $this->tRoles] as $t) {
            $this->db->query("DELETE FROM `{$t}` WHERE 1=1");
        }
    }

    /** A role, optionally belonging to an organisation. */
    private function role(int $roleId, ?int $orgId, string $name): void
    {
        $org = $orgId === null ? 'NULL' : (string) $orgId;
        $this->db->query(
            "INSERT INTO `{$this->tRoles}` (roleid, role_name, organization_id, is_active) "
            . "VALUES ({$roleId}, '{$name}', {$org}, 1)"
        );
    }

    /** Give the user a role. */
    private function assign(int $roleId): void
    {
        $this->db->query(
            "INSERT INTO `{$this->tUserRoles}` (userid, roleid, is_active) "
            . "VALUES (" . self::UID . ", {$roleId}, 1)"
        );
    }

    /** Make the user a member of an organisation. */
    private function join(int $orgId, int $isActive = 1, ?string $expiresAt = null): void
    {
        $expires = $expiresAt === null ? 'NULL' : "'" . $expiresAt . "'";
        $this->db->query(
            "INSERT INTO `{$this->tMembers}` (userid, organization_id, is_active, expires_at) "
            . "VALUES (" . self::UID . ", {$orgId}, {$isActive}, {$expires})"
        );
    }

    /** Take the membership away, leaving the role assignment where it is. */
    private function leave(int $orgId): void
    {
        $this->db->query(
            "DELETE FROM `{$this->tMembers}` "
            . "WHERE userid = " . self::UID . " AND organization_id = {$orgId}"
        );
    }

    /** A permission granted to a role. */
    private function grantToRole(int $roleId, string $objectType, string $action): void
    {
        $this->db->query(
            "INSERT INTO `{$this->tPerms}` "
            . "(subject_type, subject_id, object_type, object_id, action, grant_type, is_active) "
            . "VALUES ('role', {$roleId}, '{$objectType}', NULL, '{$action}', 'allow', 1)"
        );
    }

    /**
     * The object types a resolution came back with, sorted.
     *
     * @param array<string, mixed> $result
     * @return string[]
     */
    private function objectsIn(array $result): array
    {
        $seen = [];
        foreach ($result['permissions'] ?? [] as $grant) {
            $seen[] = (string) $grant['object_type'];
        }
        sort($seen);

        return array_values(array_unique($seen));
    }

    // ── The regression ────────────────────────────────────────────────────────

    /**
     * THE regression: another organisation's role does not answer here.
     *
     * `resolve()` returns both, because it has no organisation to scope by. That is
     * asserted alongside, because the difference between the two methods is the
     * whole point and a reader should see it in one place.
     */
    public function testAnotherOrganisationsRoleDoesNotCount(): void
    {
        // Arrange
        $this->role(1, 5, 'operator-org5');
        $this->role(2, 3, 'operator-org3');
        $this->assign(1);
        $this->assign(2);
        $this->join(5);
        $this->join(3);
        $this->grantToRole(1, 'invoice-org5', 'read');
        $this->grantToRole(2, 'invoice-org3', 'read');

        // Act
        $scoped   = $this->resolver->resolveForOrganization(self::UID, null, 5);
        $unscoped = $this->resolver->resolve(self::UID, null);

        // Assert
        $this->assertSame(['invoice-org5'], $this->objectsIn($scoped));
        $this->assertSame(
            ['invoice-org3', 'invoice-org5'],
            $this->objectsIn($unscoped),
            'resolve() must keep its old, unscoped behaviour.'
        );
    }

    /** A system-wide role counts in every organisation. */
    public function testASystemWideRoleCountsEverywhere(): void
    {
        // Arrange
        $this->role(10, null, 'auditor');
        $this->assign(10);
        $this->grantToRole(10, 'report', 'read');

        // Act + Assert
        $this->assertSame(['report'], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
        $this->assertSame(['report'], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 99)
        ));
    }

    // ── Membership ────────────────────────────────────────────────────────────

    /**
     * An organisation's role does not count for somebody who is not a member.
     *
     * The rule `user_organizations` always described. Until now the assignment alone
     * was enough, so a role handed to the wrong person — or left behind by somebody
     * who moved on — kept working.
     */
    public function testAnOrganisationRoleWithoutMembershipDoesNotCount(): void
    {
        // Arrange — assigned the role, never joined the organisation.
        $this->role(1, 5, 'operator-org5');
        $this->assign(1);
        $this->grantToRole(1, 'invoice', 'read');

        // Act + Assert
        $this->assertSame([], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
    }

    /**
     * Leaving takes the access away and leaves the assignment alone; rejoining
     * restores exactly what was there.
     *
     * This is the behaviour that was asked for explicitly, and it is invisible in
     * the code — nothing *does* anything on leaving, which is precisely the point,
     * and precisely the kind of thing a later "tidy up orphaned roles" change would
     * undo without noticing.
     */
    public function testLeavingRemovesAccessButNotTheAssignment(): void
    {
        // Arrange
        $this->role(1, 5, 'operator-org5');
        $this->assign(1);
        $this->join(5);
        $this->grantToRole(1, 'invoice', 'read');

        $this->assertSame(['invoice'], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ), 'A member must have the access to begin with.');

        // Act
        $this->leave(5);

        // Assert — access gone…
        $this->assertSame([], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));

        // …assignment still on the books…
        $row = $this->db->query(
            "SELECT roleid FROM `{$this->tUserRoles}` WHERE userid = " . self::UID
        );
        $this->assertSame(1, $row->numRows, 'Leaving must not delete the role assignment.');

        // …and rejoining restores it.
        $this->join(5);
        $this->assertSame(['invoice'], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
    }

    /**
     * Membership in one organisation does not carry a role from another.
     *
     * The join matches the membership row to the *role's* organisation, not to any
     * membership the user happens to hold — an easy thing to get wrong in a query
     * with two joins, and it would grant a role to everyone who belongs to anything.
     */
    public function testMembershipOfAnotherOrganisationDoesNotCarry(): void
    {
        // Arrange — the role belongs to 5, the user belongs to 3.
        $this->role(1, 5, 'operator-org5');
        $this->assign(1);
        $this->join(3);
        $this->grantToRole(1, 'invoice', 'read');

        // Act + Assert
        $this->assertSame([], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
    }

    // ── The parts that must not have changed ──────────────────────────────────

    /**
     * A grant made directly to the user is unaffected by organisation scoping.
     *
     * Direct grants have no organisation — they are on the user, not on a role — so
     * scoping the *roles* must not filter them out. Getting this wrong would make
     * the scoped call refuse permissions the person holds personally.
     */
    public function testDirectUserGrantsSurviveScoping(): void
    {
        // Arrange
        $this->db->query(
            "INSERT INTO `{$this->tPerms}` "
            . "(subject_type, subject_id, object_type, object_id, action, grant_type, is_active) "
            . "VALUES ('user', " . self::UID . ", 'profile', NULL, 'read', 'allow', 1)"
        );

        // Act + Assert
        $this->assertSame(['profile'], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
    }

    /** An inactive assignment still does not count, scoped or not. */
    public function testAnInactiveAssignmentIsIgnored(): void
    {
        // Arrange
        $this->role(1, 5, 'operator-org5');
        $this->join(5);
        $this->db->query(
            "INSERT INTO `{$this->tUserRoles}` (userid, roleid, is_active) "
            . "VALUES (" . self::UID . ", 1, 0)"
        );
        $this->grantToRole(1, 'invoice', 'read');

        // Act + Assert
        $this->assertSame([], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
    }

    /** The envelope names the organisation it answered for. */
    public function testTheResultReportsTheOrganisation(): void
    {
        // Act
        $result = $this->resolver->resolveForOrganization(self::UID, 7, 5);

        // Assert
        $this->assertSame(self::UID, $result['user_id']);
        $this->assertSame(7, $result['app_id']);
        $this->assertSame(5, $result['organization_id']);
    }

    /**
     * A membership that was ended through the admin screen no longer counts.
     *
     * `OrganizationsController::removemember()` sets `is_active = 0` rather than
     * deleting the row, deliberately, to keep the audit trail. A join that only
     * asked whether the row existed would therefore leave every former member's
     * access exactly where it was — the screen would say "removed" and nothing
     * would have been.
     */
    public function testAnInactiveMembershipDoesNotCount(): void
    {
        // Arrange
        $this->role(1, 5, 'operator-org5');
        $this->assign(1);
        $this->join(5, 0);
        $this->grantToRole(1, 'invoice', 'read');

        // Act + Assert
        $this->assertSame([], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
    }

    /**
     * An expired membership does not count, and one with time left does.
     *
     * `expires_at` exists on the membership row for temporary access — a contractor
     * for the length of an engagement. It has to be honoured on the read, or the
     * expiry is decorative.
     */
    public function testMembershipExpiryIsHonoured(): void
    {
        // Arrange
        $this->role(1, 5, 'operator-org5');
        $this->assign(1);
        $this->grantToRole(1, 'invoice', 'read');

        // Act + Assert — expired yesterday.
        $this->join(5, 1, date('Y-m-d H:i:s', time() - 86400));
        $this->assertSame([], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));

        // Act + Assert — expires tomorrow.
        $this->leave(5);
        $this->join(5, 1, date('Y-m-d H:i:s', time() + 86400));
        $this->assertSame(['invoice'], $this->objectsIn(
            $this->resolver->resolveForOrganization(self::UID, null, 5)
        ));
    }
}
