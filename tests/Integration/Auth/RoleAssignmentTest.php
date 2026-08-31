<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Role;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Assigning and revoking roles, and the organisation rule that governs it.
 *
 * `authserver.roles` and `authserver.user_roles` shipped with the framework and had
 * no write path at all — `PermissionsController` could grant a permission to role 7,
 * but nothing could create role 7 or give it to anybody. Which is also why the rule
 * the `user_organizations` migration described had never been enforced: there was
 * nowhere to enforce it.
 *
 * The rule: a role belonging to an organisation may be given only to a member of
 * that organisation. A system-wide role (`organization_id` NULL) has no such
 * condition.
 *
 * And the deliberate non-rule, asserted because nothing in the code shows it:
 * removing somebody from an organisation does **not** touch their role assignments.
 *
 * Requires the Docker MySQL container.
 */
#[CoversClass(Role::class)]
class RoleAssignmentTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private ?\Pramnos\Database\Database $previousSingleton = null;

    private string $tRoles;
    private string $tUserRoles;
    private string $tMembers;

    private const UID = 4200;

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
        $this->tMembers   = $p . 'authserver_user_organizations';

        $this->buildTables();
    }

    protected function tearDown(): void
    {
        foreach ([$this->tUserRoles, $this->tMembers, $this->tRoles] as $t) {
            $this->db->query("DELETE FROM `{$t}` WHERE 1=1");
        }

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->previousSingleton;
    }

    private function buildTables(): void
    {
        $p = $this->db->prefix;

        // Every table here is built from its own migration: three of them are shared
        // with other suites, and two hand-rolled copies that disagreed about whether
        // `granted_by` exists is exactly the confusion that produces.
        foreach ([$this->tUserRoles, $this->tRoles, $this->tMembers] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
        ], $this->db);

        foreach ([3, 5, 99] as $orgId) {
            $this->db->query(
                "INSERT IGNORE INTO `{$p}organizations` (organization_id, name) "
                . "VALUES ({$orgId}, 'Org {$orgId}')"
            );
        }

        foreach ([$this->tUserRoles, $this->tMembers, $this->tRoles] as $t) {
            $this->db->query("DELETE FROM `{$t}` WHERE 1=1");
        }
    }

    /**
     * A controller for the model to hold.
     *
     * `Model::__construct()` requires one and this class never uses it, so a mock
     * with the database attached is all that is needed — building a real one would
     * boot an application for no reason.
     */
    private function controller(): \Pramnos\Application\Controller
    {
        static $controller = null;

        if ($controller === null) {
            $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)
                ->disableOriginalConstructor()
                ->getMock();

            $app = $this->getMockBuilder(Application::class)
                ->disableOriginalConstructor()
                ->getMock();
            $app->database = $this->db;
            $controller->application = $app;
        }

        return $controller;
    }

    /** A role row, and the model over it. */
    private function role(int $roleId, ?int $orgId): Role
    {
        $org = $orgId === null ? 'NULL' : (string) $orgId;
        $this->db->query(
            "INSERT INTO `{$this->tRoles}` (roleid, role_name, organization_id, is_active) "
            . "VALUES ({$roleId}, 'role-{$roleId}', {$org}, 1)"
        );

        $role = new Role($this->controller());
        $role->roleid          = $roleId;
        $role->organization_id = $orgId;

        return $role;
    }

    private function join(int $orgId, int $isActive = 1, ?string $expiresAt = null): void
    {
        $expires = $expiresAt === null ? 'NULL' : "'" . $expiresAt . "'";
        $this->db->query(
            "INSERT INTO `{$this->tMembers}` (userid, organization_id, is_active, expires_at) "
            . "VALUES (" . self::UID . ", {$orgId}, {$isActive}, {$expires})"
        );
    }

    /** The assignment row, or null. */
    private function assignmentRow(int $roleId): ?array
    {
        $r = $this->db->query(
            "SELECT * FROM `{$this->tUserRoles}` "
            . "WHERE userid = " . self::UID . " AND roleid = {$roleId}"
        );

        return ($r && $r->numRows > 0) ? $r->fields : null;
    }

    // ── System-wide roles ─────────────────────────────────────────────────────

    /** A system-wide role goes to anybody, no membership involved. */
    public function testASystemWideRoleCanBeAssignedToAnyone(): void
    {
        // Arrange
        $role = $this->role(1, null);

        // Act
        $assigned = $role->assignTo(self::UID);

        // Assert
        $this->assertTrue($assigned, $role->getLastError());
        $this->assertNotNull($this->assignmentRow(1));
    }

    // ── The organisation rule ─────────────────────────────────────────────────

    /**
     * THE rule: an organisation's role is refused for somebody outside it.
     *
     * Refused rather than written-and-ignored: the resolver would skip it anyway, so
     * accepting the write would leave an admin screen reporting success and a user
     * with a role that does nothing, which is worse than a clear refusal.
     */
    public function testAnOrganisationRoleIsRefusedForANonMember(): void
    {
        // Arrange
        $role = $this->role(2, 5);

        // Act
        $assigned = $role->assignTo(self::UID);

        // Assert
        $this->assertFalse($assigned);
        $this->assertStringContainsString('not a member', $role->getLastError());
        $this->assertNull($this->assignmentRow(2), 'Nothing must have been written.');
    }

    /** And accepted for a member. */
    public function testAnOrganisationRoleIsAcceptedForAMember(): void
    {
        // Arrange
        $this->join(5);
        $role = $this->role(2, 5);

        // Act + Assert
        $this->assertTrue($role->assignTo(self::UID), $role->getLastError());
        $this->assertNotNull($this->assignmentRow(2));
    }

    /** Membership of a different organisation does not qualify. */
    public function testMembershipOfAnotherOrganisationDoesNotQualify(): void
    {
        // Arrange
        $this->join(3);
        $role = $this->role(2, 5);

        // Act + Assert
        $this->assertFalse($role->assignTo(self::UID));
    }

    /**
     * A membership that was ended does not qualify.
     *
     * `is_active = 0` is how the admin screen removes somebody, and the write path
     * has to read it the same way the resolver does — or the screen would let an
     * administrator grant a role the resolver then refuses to honour.
     */
    public function testAnInactiveMembershipDoesNotQualify(): void
    {
        // Arrange
        $this->join(5, 0);
        $role = $this->role(2, 5);

        // Act + Assert
        $this->assertFalse($role->assignTo(self::UID));
    }

    /** An expired membership does not qualify; one still open does. */
    public function testMembershipExpiryIsHonoured(): void
    {
        // Arrange
        $role = $this->role(2, 5);

        // Act + Assert — expired yesterday.
        $this->join(5, 1, date('Y-m-d H:i:s', time() - 86400));
        $this->assertFalse($role->assignTo(self::UID));

        // Act + Assert — expires tomorrow.
        $this->db->query("DELETE FROM `{$this->tMembers}` WHERE 1=1");
        $this->join(5, 1, date('Y-m-d H:i:s', time() + 86400));
        $this->assertTrue($role->assignTo(self::UID), $role->getLastError());
    }

    // ── Idempotence, audit, revocation ────────────────────────────────────────

    /**
     * Assigning twice updates the row instead of failing on the primary key.
     *
     * `(userid, roleid)` is the primary key, so a plain insert would error the second
     * time — and "add" pressed twice on an admin screen is not an error.
     */
    public function testAssigningTwiceIsIdempotent(): void
    {
        // Arrange
        $role = $this->role(1, null);

        // Act
        $this->assertTrue($role->assignTo(self::UID, 99));
        $this->assertTrue($role->assignTo(self::UID, 100), $role->getLastError());

        // Assert — one row, carrying the later grantor.
        $r = $this->db->query(
            "SELECT COUNT(*) c FROM `{$this->tUserRoles}` WHERE userid = " . self::UID
        );
        $this->assertSame(1, (int) $r->fields['c']);
        $this->assertSame(100, (int) $this->assignmentRow(1)['granted_by']);
    }

    /** Re-assigning a revoked role brings it back rather than leaving it inactive. */
    public function testReassigningReactivatesARevokedRole(): void
    {
        // Arrange
        $role = $this->role(1, null);
        $role->assignTo(self::UID);
        $role->revokeFrom(self::UID);
        $this->assertSame(0, (int) $this->assignmentRow(1)['is_active']);

        // Act
        $this->assertTrue($role->assignTo(self::UID));

        // Assert
        $this->assertSame(1, (int) $this->assignmentRow(1)['is_active']);
    }

    /** The grantor and an expiry are recorded when given. */
    public function testTheGrantorAndExpiryAreRecorded(): void
    {
        // Arrange
        $role    = $this->role(1, null);
        $expires = date('Y-m-d H:i:s', time() + 3600);

        // Act
        $role->assignTo(self::UID, 77, $expires);

        // Assert
        $row = $this->assignmentRow(1);
        $this->assertSame(77, (int) $row['granted_by']);
        $this->assertSame($expires, (string) $row['expires_at']);
    }

    /**
     * Revoking deactivates rather than deletes.
     *
     * Same choice the membership screen makes: who held what is worth keeping, and
     * the resolver already ignores an inactive row.
     */
    public function testRevokingDeactivatesTheRowRatherThanDeletingIt(): void
    {
        // Arrange
        $role = $this->role(1, null);
        $role->assignTo(self::UID);

        // Act
        $this->assertTrue($role->revokeFrom(self::UID));

        // Assert
        $row = $this->assignmentRow(1);
        $this->assertNotNull($row, 'Revoking must not delete the row.');
        $this->assertSame(0, (int) $row['is_active']);
    }

    /**
     * Leaving an organisation leaves the role assignment alone.
     *
     * The deliberate non-behaviour. Nothing in `Role` reacts to a membership change,
     * and that is the design: the assignment stops counting while the person is out
     * and counts again when they return, with the set they had. A cascade added later
     * would be reversing a decision, not fixing an oversight.
     */
    public function testLeavingAnOrganisationDoesNotTouchTheAssignment(): void
    {
        // Arrange
        $this->join(5);
        $role = $this->role(2, 5);
        $this->assertTrue($role->assignTo(self::UID), $role->getLastError());

        // Act — the membership ends the way the admin screen ends it.
        $this->db->query(
            "UPDATE `{$this->tMembers}` SET is_active = 0 WHERE userid = " . self::UID
        );

        // Assert — the assignment is untouched and still active.
        $row = $this->assignmentRow(2);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['is_active']);
    }

    // ── holders() and the guards ──────────────────────────────────────────────

    /** holders() lists active assignments and leaves revoked ones out. */
    public function testHoldersListsActiveAssignmentsOnly(): void
    {
        // Arrange
        $role = $this->role(1, null);
        $role->assignTo(self::UID);
        $role->assignTo(self::UID + 1);
        $role->revokeFrom(self::UID + 1);

        // Act
        $holders = $role->holders();

        // Assert
        $this->assertArrayHasKey(self::UID, $holders);
        $this->assertArrayNotHasKey(self::UID + 1, $holders);
    }

    /** A role that has not been saved has no holders and cannot be assigned. */
    public function testAnUnsavedRoleIsRefused(): void
    {
        // Arrange
        $role = new Role($this->controller());

        // Act + Assert
        $this->assertFalse($role->assignTo(self::UID));
        $this->assertStringContainsString('required', $role->getLastError());
        $this->assertFalse($role->revokeFrom(self::UID));
        $this->assertSame([], $role->holders());
    }

    /** A missing user id is refused rather than written as user 0. */
    public function testAMissingUserIsRefused(): void
    {
        // Arrange
        $role = $this->role(1, null);

        // Act + Assert
        $this->assertFalse($role->assignTo(0));
        $this->assertFalse($role->revokeFrom(0));
    }
}
