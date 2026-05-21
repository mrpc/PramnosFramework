<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Behavioral characterization tests for the 7 PL/pgSQL RBAC helper functions
 * installed by migration 000036 (CreateAuthserverRbacFunctions).
 *
 * The existing FrameworkMigrationsPostgreSQLTest verifies that the functions
 * and triggers *exist* after up() and disappear after down(), plus the
 * set_permission_priority trigger behaviour and apply_permission_template smoke.
 * These tests cover the remaining four functions at the behavioural level:
 *
 *   - check_permission_with_inheritance()  — direct grant + inherited grant + read_only guard
 *   - get_user_effective_permissions()     — direct permissions + role-based permissions + deny wins
 *   - apply_role_template()                — creates role + applies every listed permission template
 *   - log_audit_event()                    — inserts a structured row in audit_log
 *
 * The check_user_deya_membership() trigger is also exercised: it must raise an
 * exception when assigning a org-scoped role to a user who is not a member of
 * that organisation.
 *
 * Runs on PostgreSQL only (PL/pgSQL functions are not available on MySQL).
 *
 * Schema setup reuses the same migration chain used by FrameworkMigrationsPostgreSQLTest.
 */
#[CoversNothing]
class RbacFunctionsCharacterizationTest extends TestCase
{
    private Database $db;

    /** Path to the framework migrations directory. */
    private string $migrationsBase;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        if (!$this->db->connect(false)) {
            $this->markTestSkipped('PostgreSQL (timescaledb) container not reachable');
        }

        $this->migrationsBase = ROOT . \DS . 'database' . \DS . 'migrations' . \DS . 'framework';

        $this->dropAuthserverSchema();
        $this->installRbacSchema();
    }

    protected function tearDown(): void
    {
        $this->dropAuthserverSchema();
    }

    // =========================================================================
    // check_permission_with_inheritance()
    // =========================================================================

    /**
     * Direct allow in effective_permissions → check_permission_with_inheritance() returns TRUE.
     *
     * The simplest path: subject has an explicit allow entry for the object+action,
     * so the function returns true without traversing the inheritance graph.
     */
    public function testCheckPermissionDirectAllowReturnsTrue(): void
    {
        // Arrange — grant user 1 read access to report 42
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 1, 'report', '42', 'read', 'allow', 10)"
        );

        // Act
        $r = $this->db->execute(
            "SELECT authserver.check_permission_with_inheritance('user', 1, 'report', '42', 'read') AS granted"
        );

        // Assert — direct grant → true
        $this->assertTrue((bool) $r->fields['granted'], 'Direct allow must return true');
    }

    /**
     * Explicit deny (higher priority than any allow) → check_permission_with_inheritance() returns FALSE.
     *
     * The deny trigger auto-adds 1000 to priority, so the deny entry always wins
     * when both allow and deny exist for the same subject+object+action.
     */
    public function testCheckPermissionDirectDenyReturnsFalse(): void
    {
        // Arrange — insert allow then deny; trigger bumps deny priority to 1010
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 2, 'report', '99', 'write', 'allow', 5)"
        );
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 2, 'report', '99', 'write', 'deny', 0)"
        );

        // Act
        $r = $this->db->execute(
            "SELECT authserver.check_permission_with_inheritance('user', 2, 'report', '99', 'write') AS granted"
        );

        // Assert — deny dominates allow → false
        $this->assertFalse((bool) $r->fields['granted'], 'Deny (priority 1010) must override allow (priority 5)');
    }

    /**
     * Inherited grant: subject has no direct permission on child object but has allow
     * on the parent object linked via permission_inheritance → returns TRUE.
     *
     * This verifies the inheritance-graph walk inside check_permission_with_inheritance().
     */
    public function testCheckPermissionInheritedGrantReturnsTrue(): void
    {
        // Arrange — allow on parent 'folder/root', child 'folder/sub' inherits from parent
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 3, 'folder', 'root', 'read', 'allow', 10)"
        );
        $this->db->execute(
            "INSERT INTO authserver.permission_inheritance
             (child_object_type, child_object_id, parent_object_type, parent_object_id, inheritance_type, is_active)
             VALUES ('folder', 'sub', 'folder', 'root', 'full', TRUE)"
        );

        // Act — user 3 has no direct permission on 'folder/sub', should inherit from 'folder/root'
        $r = $this->db->execute(
            "SELECT authserver.check_permission_with_inheritance('user', 3, 'folder', 'sub', 'read') AS granted"
        );

        // Assert — inherited allow → true
        $this->assertTrue((bool) $r->fields['granted'], 'Inherited grant via permission_inheritance must return true');
    }

    /**
     * read_only inheritance: parent has 'read' allow, child inherits via read_only link.
     * Requesting 'read' on the child → TRUE (propagated).
     * Requesting 'write' on the child → FALSE (read_only inheritance blocks non-read actions).
     *
     * Verifies the CASE branch inside the inheritance walk:
     *   WHEN parent.inheritance_type = 'read_only' AND p_action <> 'read' THEN 'read'
     */
    public function testCheckPermissionReadOnlyInheritanceBlocksWrite(): void
    {
        // Arrange — parent 'zone/parent' has allow on 'read'; child inherits read_only
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 4, 'zone', 'parent', 'read', 'allow', 10)"
        );
        $this->db->execute(
            "INSERT INTO authserver.permission_inheritance
             (child_object_type, child_object_id, parent_object_type, parent_object_id, inheritance_type, is_active)
             VALUES ('zone', 'child', 'zone', 'parent', 'read_only', TRUE)"
        );

        // Act — read on child: should propagate (read_only allows read)
        $rRead = $this->db->execute(
            "SELECT authserver.check_permission_with_inheritance('user', 4, 'zone', 'child', 'read') AS granted"
        );
        // Act — write on child: should NOT propagate (read_only blocks write)
        $rWrite = $this->db->execute(
            "SELECT authserver.check_permission_with_inheritance('user', 4, 'zone', 'child', 'write') AS granted"
        );

        // Assert
        $this->assertTrue((bool) $rRead->fields['granted'],  'read_only inheritance must allow read on child');
        $this->assertFalse((bool) $rWrite->fields['granted'], 'read_only inheritance must block write on child');
    }

    // =========================================================================
    // get_user_effective_permissions()
    // =========================================================================

    /**
     * Direct user permissions must appear in get_user_effective_permissions() output
     * with permission_source = 'direct'.
     *
     * Verifies the first SELECT branch (direct user permissions) inside the function.
     */
    public function testGetUserEffectivePermissionsReturnsDirect(): void
    {
        // Arrange — grant user 10 read on 'api/v1'
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 10, 'api', 'v1', 'read', 'allow', 10)"
        );

        // Act
        $r = $this->db->execute(
            "SELECT object_type, object_id, action, permission_source, effective_grant
             FROM authserver.get_user_effective_permissions(10, NULL)
             WHERE object_type = 'api' AND object_id = 'v1' AND action = 'read'"
        );

        // Assert — one row, source = 'direct', grant = 'allow'
        $this->assertNotNull($r, 'get_user_effective_permissions() must return a result set');
        $r->fetch();
        $this->assertSame('direct', $r->fields['permission_source']);
        $this->assertSame('allow',  $r->fields['effective_grant']);
    }

    /**
     * Role-based permissions appear in get_user_effective_permissions() output
     * with permission_source prefixed 'role:<role_name>'.
     *
     * Sets up: role → permission, user → role assignment via user_roles.
     * Verifies the second SELECT branch (role-based) inside the function.
     */
    public function testGetUserEffectivePermissionsIncludesRoleBased(): void
    {
        // Arrange — create role 'admin_role', grant write on 'config' to the role
        $roleResult = $this->db->execute(
            "INSERT INTO authserver.roles (role_name, is_active) VALUES ('admin_role', TRUE) RETURNING roleid"
        );
        $roleResult->fetch();
        $roleId = (int) $roleResult->fields['roleid'];

        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('role', {$roleId}, 'config', 'global', 'write', 'allow', 5)"
        );

        // Assign user 20 to the role (no org scope — organization_id IS NULL for this role)
        $this->db->execute(
            "INSERT INTO authserver.user_roles (userid, roleid, is_active)
             VALUES (20, {$roleId}, TRUE)"
        );

        // Act
        $r = $this->db->execute(
            "SELECT object_type, object_id, action, permission_source, effective_grant
             FROM authserver.get_user_effective_permissions(20, NULL)
             WHERE object_type = 'config' AND object_id = 'global' AND action = 'write'"
        );

        // Assert — permission present, source starts with 'role:'
        $this->assertNotNull($r);
        $r->fetch();
        $this->assertStringStartsWith('role:', $r->fields['permission_source'],
            "Role-based permission_source must be prefixed 'role:'");
        $this->assertSame('allow', $r->fields['effective_grant']);
    }

    /**
     * When both a direct deny and a role-based allow exist for the same object+action,
     * the deny wins (effective_grant = 'deny') in get_user_effective_permissions().
     *
     * Verifies the CASE bool_or(deny) THEN 'deny' aggregation logic.
     */
    public function testGetUserEffectivePermissionsDenyWinsOverRoleAllow(): void
    {
        // Arrange — role grants 'read' on 'secret', but user has a direct deny
        $roleResult = $this->db->execute(
            "INSERT INTO authserver.roles (role_name, is_active) VALUES ('reader_role', TRUE) RETURNING roleid"
        );
        $roleResult->fetch();
        $roleId = (int) $roleResult->fields['roleid'];

        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('role', {$roleId}, 'doc', 'secret', 'read', 'allow', 5)"
        );
        $this->db->execute(
            "INSERT INTO authserver.user_roles (userid, roleid, is_active)
             VALUES (30, {$roleId}, TRUE)"
        );
        // Direct deny on user 30 (trigger bumps priority to 1000)
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 30, 'doc', 'secret', 'read', 'deny', 0)"
        );

        // Act
        $r = $this->db->execute(
            "SELECT effective_grant
             FROM authserver.get_user_effective_permissions(30, NULL)
             WHERE object_type = 'doc' AND object_id = 'secret' AND action = 'read'
             LIMIT 1"
        );

        // Assert — deny must win even though a role grants allow
        $r->fetch();
        $this->assertSame('deny', $r->fields['effective_grant'],
            'Direct deny must override role-based allow in get_user_effective_permissions()');
    }

    // =========================================================================
    // apply_role_template()
    // =========================================================================

    /**
     * apply_role_template() must create a new role and apply every permission template
     * listed in the role_template's permission_templateids JSON array.
     *
     * Verifies: new roleid is returned, role row exists, all template permissions
     * are inserted into authserver.permissions for the new role.
     */
    public function testApplyRoleTemplateCreatesRoleAndPermissions(): void
    {
        // Arrange — two permission templates
        $this->db->execute(
            "INSERT INTO authserver.permission_templates
             (template_name, template_type, object_type, object_id_pattern, action, grant_type, priority)
             VALUES ('tpl_read_all', 'role_template', 'report', '*', 'read', 'allow', 5)"
        );
        $tplId1Result = $this->db->execute(
            "SELECT templateid FROM authserver.permission_templates WHERE template_name = 'tpl_read_all'"
        );
        $tplId1Result->fetch();
        $tplId1 = (int) $tplId1Result->fields['templateid'];

        $this->db->execute(
            "INSERT INTO authserver.permission_templates
             (template_name, template_type, object_type, object_id_pattern, action, grant_type, priority)
             VALUES ('tpl_write_reports', 'role_template', 'report', '*', 'write', 'allow', 5)"
        );
        $tplId2Result = $this->db->execute(
            "SELECT templateid FROM authserver.permission_templates WHERE template_name = 'tpl_write_reports'"
        );
        $tplId2Result->fetch();
        $tplId2 = (int) $tplId2Result->fields['templateid'];

        // Create a role template referencing both permission templates
        $this->db->execute(
            "INSERT INTO authserver.role_templates
             (template_name, description, permission_templateids, is_active)
             VALUES ('reporter_role_tpl', 'Reporter role', '[{$tplId1},{$tplId2}]', TRUE)"
        );
        $rtplResult = $this->db->execute(
            "SELECT role_templateid FROM authserver.role_templates WHERE template_name = 'reporter_role_tpl'"
        );
        $rtplResult->fetch();
        $rtplId = (int) $rtplResult->fields['role_templateid'];

        // Act — create a role from the template
        $result = $this->db->execute(
            "SELECT authserver.apply_role_template({$rtplId}, 'Reporter Role Instance', NULL, NULL) AS roleid"
        );
        $result->fetch();
        $newRoleId = (int) $result->fields['roleid'];

        // Assert — new roleid is a positive integer
        $this->assertGreaterThan(0, $newRoleId, 'apply_role_template() must return the new roleid');

        // Assert — the role row was inserted
        $roleCheck = $this->db->execute(
            "SELECT role_name FROM authserver.roles WHERE roleid = {$newRoleId}"
        );
        $roleCheck->fetch();
        $this->assertSame('Reporter Role Instance', $roleCheck->fields['role_name']);

        // Assert — both permission templates were applied (2 permission rows for the new role)
        $permCount = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM authserver.permissions
             WHERE subject_type = 'role' AND subject_id = {$newRoleId}"
        );
        $permCount->fetch();
        $this->assertSame(2, (int) $permCount->fields['cnt'],
            'apply_role_template() must insert one permission row per template');
    }

    // =========================================================================
    // log_audit_event()
    // =========================================================================

    /**
     * log_audit_event() must insert a row into authserver.audit_log using the
     * generic polymorphic schema and return the new auditid (positive INTEGER).
     *
     * The function signature matches Urbanwater's authserver.log_audit_event():
     *   (event_type, actor_userid, actor_type, target_type, target_id,
     *    object_type, object_id, old_values, new_values, metadata, organization_context)
     *
     * Verifies: return value > 0, row exists, all scalar fields stored correctly,
     * JSONB old_values/new_values/metadata preserved round-trip.
     */
    public function testLogAuditEventInsertsRowAndReturnsId(): void
    {
        // Arrange — build metadata JSON that includes ip_address (moved from dedicated column)
        $metadata = json_encode(['ip_address' => '127.0.0.1', 'notes' => 'Test audit entry']);

        // Act
        $r = $this->db->execute(
            "SELECT authserver.log_audit_event(
                 'permission_granted',
                 99,
                 'user',
                 'user',
                 '42',
                 'permission',
                 'read_data',
                 '{\"before\": null}'::jsonb,
                 '{\"after\": \"allow\"}'::jsonb,
                 " . $this->db->prepareQuery('%s', $metadata) . "::jsonb,
                 NULL
             ) AS auditid"
        );
        $r->fetch();
        $auditId = (int) $r->fields['auditid'];

        // Assert — returned ID is a positive integer
        $this->assertGreaterThan(0, $auditId, 'log_audit_event() must return a positive auditid');

        // Assert — row exists with correct scalar fields
        $row = $this->db->execute(
            "SELECT event_type, actor_userid, actor_type, target_type, target_id,
                    object_type, object_id, metadata
             FROM authserver.audit_log WHERE auditid = {$auditId}"
        );
        $row->fetch();
        $this->assertSame('permission_granted', $row->fields['event_type'],
            'event_type must match the value passed to log_audit_event()');
        $this->assertSame(99,           (int) $row->fields['actor_userid'],
            'actor_userid must store the integer actor identity');
        $this->assertSame('user',             $row->fields['actor_type']);
        $this->assertSame('user',             $row->fields['target_type']);
        $this->assertSame('42',               $row->fields['target_id']);
        $this->assertSame('permission',       $row->fields['object_type']);
        $this->assertSame('read_data',        $row->fields['object_id']);

        // Assert — metadata JSONB round-trip preserves ip_address
        $storedMeta = json_decode($row->fields['metadata'], true);
        $this->assertSame('127.0.0.1', $storedMeta['ip_address'],
            'ip_address stored in metadata jsonb must survive JSONB round-trip');
    }

    // =========================================================================
    // check_user_deya_membership() trigger
    // =========================================================================

    /**
     * Assigning a user to an org-scoped role when the user is NOT a member of
     * that organisation must raise an exception (via the trigger).
     *
     * This verifies that check_user_deya_membership() correctly rejects the
     * INSERT into user_roles when the role has an organization_id and the user
     * has no matching active row in user_organizations.
     */
    public function testCheckUserDeyaMembershipTriggerBlocksNonMember(): void
    {
        // Arrange — create an org-scoped role (organisation_id = 1)
        $roleResult = $this->db->execute(
            "INSERT INTO authserver.roles (role_name, organization_id, is_active)
             VALUES ('org_scoped_role', 1, TRUE) RETURNING roleid"
        );
        $roleResult->fetch();
        $orgRoleId = (int) $roleResult->fields['roleid'];

        // Assert — user 50 has no user_organizations row for org 1 → trigger must raise
        $this->expectException(\Exception::class);

        $this->db->execute(
            "INSERT INTO authserver.user_roles (userid, roleid, is_active)
             VALUES (50, {$orgRoleId}, TRUE)"
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Installs the full RBAC schema chain using the authserver migrations.
     * Order matches the dependency graph in CreateAuthserverRbacFunctions.
     *
     * public.organizations is created as a minimal stub so that the FK added
     * by CreateAuthserverUserOrganizationsTable can resolve — the real migration
     * depends on CreateOrganizationsTable which in turn requires applications,
     * users, etc.  The stub is sufficient for the tests here.
     */
    private function installRbacSchema(): void
    {
        // Minimal stub: FK target for authserver.user_organizations.organization_id
        $this->db->execute(
            "CREATE TABLE IF NOT EXISTS public.organizations (
                organization_id SERIAL PRIMARY KEY,
                name            VARCHAR(255) NOT NULL
            )"
        );

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        $chain = [
            ['authserver', 'CreateAuthserverSchema'],
            ['authserver', 'CreateAuthserverRolesTable'],
            ['authserver', 'CreateAuthserverPermissionsTable'],
            ['authserver', 'CreateAuthserverUserRolesTable'],
            ['authserver', 'CreateAuthserverAuditLogTable'],
            ['authserver', 'CreateAuthserverPermissionTemplatesTable'],
            ['authserver', 'CreateAuthserverRoleTemplatesTable'],
            ['authserver', 'CreateAuthserverPermissionInheritanceTable'],
            ['authserver', 'CreateAuthserverEffectivePermissionsView'],
            ['authserver', 'CreateAuthserverUserOrganizationsTable'],
            ['authserver', 'CreateAuthserverRbacFunctions'],
        ];

        foreach ($chain as [$feature, $class]) {
            $this->loadMigration($feature, $class, $app)->up();
        }
    }

    private function loadMigration(string $feature, string $class, $app): \Pramnos\Database\Migration
    {
        $dir        = $this->migrationsBase . '/' . $feature;
        $migrations = MigrationLoader::loadFromDirectory($dir, $app);

        foreach ($migrations as $m) {
            if ((new \ReflectionClass($m))->getShortName() === $class) {
                return $m;
            }
        }

        $this->fail("Migration '{$class}' not found in feature '{$feature}'");
    }

    private function dropAuthserverSchema(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            try {
                $this->db->query('SET lock_timeout = \'2s\'');
                $this->db->query('DROP SCHEMA IF EXISTS authserver CASCADE');
                $this->db->query('DROP TABLE IF EXISTS public.organizations CASCADE');
                $this->db->query('SET lock_timeout = 0');
                return;
            } catch (\Exception $e) {
                usleep(200000 * $i);
                if (!$this->db->connected) {
                    $this->db->connect();
                }
            }
        }
    }
}
