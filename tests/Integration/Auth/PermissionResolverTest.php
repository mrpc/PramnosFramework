<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\PermissionResolver;
use Pramnos\Database\Database;

/**
 * Integration tests for PermissionResolver against a real database.
 *
 * WHAT: resolving a user's effective RBAC+ABAC permissions for one application —
 *       user + role grants, deny-over-allow, audience (app_id) scoping, expiry /
 *       active filtering, and ABAC condition pass-through.
 * WHY: this is the read side of the live-fetch model (feature 6); the resolution
 *       must match the effective_permissions semantics and correctly pass
 *       conditions through for runtime evaluation. Verified against real rows (§8).
 *
 * Uses isolated stub tables so the test is self-contained on any engine.
 */
class PermissionResolverTest extends TestCase
{
    private Database $db;
    private bool $isPg;
    private PermissionResolver $resolver;

    private const T_PERMS = 'authserver.permissions';
    private const T_ROLES = 'authserver.user_roles';

    /** Distinct test identifiers to avoid clashing with any real data. */
    private const USER = 970001;
    private const ROLE = 960001;
    private const APP  = 950001;

    private bool $createdPerms = false;
    private bool $createdRoles = false;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $driver = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $this->isPg = in_array($driver, ['postgresql', 'pgsql', 'timescaledb'], true);

        $this->db = new Database();
        $this->db->type     = $driver;
        $this->db->server   = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $this->db->port     = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: ($this->isPg ? 5432 : 3306)));
        $this->db->user     = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        $this->db->password  = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $this->db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('Database not reachable');
            }
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        if ($this->isPg) {
            $this->db->statement('CREATE SCHEMA IF NOT EXISTS authserver');
        }

        $this->ensureTables();
        $this->clearTestRows();

        $this->resolver = new PermissionResolver($this->db);
    }

    protected function tearDown(): void
    {
        try {
            $this->clearTestRows();
            $schema = $this->db->schema();
            if ($this->createdPerms) {
                $schema->dropTableIfExists(self::T_PERMS);
            }
            if ($this->createdRoles) {
                $schema->dropTableIfExists(self::T_ROLES);
            }
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function ensureTables(): void
    {
        $schema = $this->db->schema();
        $bool   = 'BOOLEAN';
        $pk     = $this->isPg ? 'BIGSERIAL PRIMARY KEY' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';

        if (!$schema->hasTable(self::T_PERMS)) {
            $this->createdPerms = true;
            $this->db->statement(
                'CREATE TABLE ' . $this->q(self::T_PERMS) . " (permissionid $pk, "
                . 'subject_type VARCHAR(20), subject_id BIGINT, object_type VARCHAR(50), '
                . 'object_id VARCHAR(100), action VARCHAR(20), grant_type VARCHAR(5), '
                . "priority INT DEFAULT 100, is_active $bool DEFAULT TRUE, "
                . 'expires_at TIMESTAMP NULL, app_id BIGINT NULL, conditions TEXT NULL)'
            );
        }
        if (!$schema->hasTable(self::T_ROLES)) {
            $this->createdRoles = true;
            $this->db->statement(
                'CREATE TABLE ' . $this->q(self::T_ROLES) . ' (userid BIGINT, roleid INT, '
                . "is_active $bool DEFAULT TRUE, expires_at TIMESTAMP NULL)"
            );
        }
    }

    private function q(string $logical): string
    {
        // authserver.x on PostgreSQL; authserver_x prefix on MySQL.
        return $this->isPg ? $logical : str_replace('authserver.', 'authserver_', $logical);
    }

    private function clearTestRows(): void
    {
        $this->db->queryBuilder()->table(self::T_PERMS)->whereIn('subject_id', [self::USER, self::ROLE])->delete();
        $this->db->queryBuilder()->table(self::T_ROLES)->where('userid', self::USER)->delete();
    }

    /** Insert a permission row with sensible defaults. */
    private function perm(array $over): void
    {
        $this->db->queryBuilder()->table(self::T_PERMS)->insert($over + [
            'subject_type' => 'user',
            'subject_id'   => self::USER,
            'object_type'  => 'invoice',
            'object_id'    => '*',
            'action'       => 'read',
            'grant_type'   => 'allow',
            'priority'     => 100,
            'is_active'    => true,
            'expires_at'   => null,
            'app_id'       => null,
            'conditions'   => null,
        ]);
    }

    private function assignRole(int $roleId, bool $active = true, ?string $expires = null): void
    {
        $this->db->queryBuilder()->table(self::T_ROLES)->insert([
            'userid' => self::USER, 'roleid' => $roleId, 'is_active' => $active, 'expires_at' => $expires,
        ]);
    }

    /** Find the resolved grant for a target, or null. */
    private function grantFor(array $result, string $objectType, string $action): ?array
    {
        foreach ($result['permissions'] as $p) {
            if ($p['object_type'] === $objectType && $p['action'] === $action) {
                return $p;
            }
        }
        return null;
    }

    // ── Tests ──────────────────────────────────────────────────────────────

    public function testUserAllowIsResolved(): void
    {
        $this->perm(['action' => 'read', 'grant_type' => 'allow']);

        $result = $this->resolver->resolve(self::USER, self::APP);

        $g = $this->grantFor($result, 'invoice', 'read');
        $this->assertNotNull($g);
        $this->assertSame('allow', $g['grant']);
        $this->assertNull($g['conditions'], 'Unconditional allow → null conditions');
    }

    public function testRoleInheritedPermissionIsResolved(): void
    {
        // User holds a role; the role (not the user) has the grant.
        $this->assignRole(self::ROLE);
        $this->perm(['subject_type' => 'role', 'subject_id' => self::ROLE, 'object_type' => 'report', 'action' => 'view']);

        $result = $this->resolver->resolve(self::USER, self::APP);

        $g = $this->grantFor($result, 'report', 'view');
        $this->assertNotNull($g, 'Role-inherited permission must be resolved');
        $this->assertSame('allow', $g['grant']);
    }

    public function testDenyOverridesAllowByPriority(): void
    {
        // A user allow and a role deny at higher priority on the same target.
        $this->assignRole(self::ROLE);
        $this->perm(['action' => 'delete', 'grant_type' => 'allow', 'priority' => 100]);
        $this->perm(['subject_type' => 'role', 'subject_id' => self::ROLE, 'action' => 'delete', 'grant_type' => 'deny', 'priority' => 1100]);

        $g = $this->grantFor($this->resolver->resolve(self::USER, self::APP), 'invoice', 'delete');

        $this->assertSame('deny', $g['grant'], 'Higher-priority deny must win');
    }

    public function testAllowWinsWhenPriorityExceedsDeny(): void
    {
        // Unusual but valid: an allow with priority above the deny wins.
        $this->perm(['action' => 'update', 'grant_type' => 'allow', 'priority' => 500]);
        $this->perm(['action' => 'update', 'grant_type' => 'deny', 'priority' => 100]);

        $g = $this->grantFor($this->resolver->resolve(self::USER, self::APP), 'invoice', 'update');

        $this->assertSame('allow', $g['grant']);
    }

    public function testGlobalPermissionAppliesRegardlessOfApp(): void
    {
        $this->perm(['action' => 'read', 'app_id' => null]); // global

        $g = $this->grantFor($this->resolver->resolve(self::USER, 12345), 'invoice', 'read');

        $this->assertNotNull($g, 'Global (app_id NULL) permission applies to any app');
    }

    public function testOtherAppPermissionIsExcluded(): void
    {
        $this->perm(['action' => 'read', 'app_id' => 88888]); // different app

        $result = $this->resolver->resolve(self::USER, self::APP);

        $this->assertNull($this->grantFor($result, 'invoice', 'read'),
            'A permission scoped to another app must not apply');
    }

    public function testMatchingAppPermissionApplies(): void
    {
        $this->perm(['action' => 'read', 'app_id' => self::APP]);

        $this->assertNotNull($this->grantFor($this->resolver->resolve(self::USER, self::APP), 'invoice', 'read'));
    }

    public function testExpiredPermissionIsExcluded(): void
    {
        $this->perm(['action' => 'read', 'expires_at' => '2000-01-01 00:00:00']); // long past

        $this->assertNull($this->grantFor($this->resolver->resolve(self::USER, self::APP), 'invoice', 'read'));
    }

    public function testInactivePermissionIsExcluded(): void
    {
        $this->perm(['action' => 'read', 'is_active' => false]);

        $this->assertNull($this->grantFor($this->resolver->resolve(self::USER, self::APP), 'invoice', 'read'));
    }

    public function testExpiredRoleAssignmentIsIgnored(): void
    {
        // Role grant exists, but the user's role assignment has expired.
        $this->assignRole(self::ROLE, true, '2000-01-01 00:00:00');
        $this->perm(['subject_type' => 'role', 'subject_id' => self::ROLE, 'object_type' => 'report', 'action' => 'view']);

        $this->assertNull(
            $this->grantFor($this->resolver->resolve(self::USER, self::APP), 'report', 'view'),
            'Permissions from an expired role assignment must be ignored'
        );
    }

    public function testConditionalAllowPassesConditionsThrough(): void
    {
        $this->perm(['action' => 'read', 'conditions' => json_encode(['location_id' => [1, 2]])]);

        $g = $this->grantFor($this->resolver->resolve(self::USER, self::APP), 'invoice', 'read');

        $this->assertSame('allow', $g['grant']);
        $this->assertSame([['location_id' => [1, 2]]], $g['conditions'],
            'A conditional allow passes its predicate through for runtime evaluation');
    }

    public function testUnconditionalRowMakesGrantUnconditional(): void
    {
        // One conditional and one unconditional allow on the same target →
        // the unconditional one broadens the grant to null (no condition).
        $this->perm(['action' => 'read', 'conditions' => json_encode(['location_id' => [1]])]);
        $this->perm(['action' => 'read', 'conditions' => null]);

        $g = $this->grantFor($this->resolver->resolve(self::USER, self::APP), 'invoice', 'read');

        $this->assertNull($g['conditions'], 'An unconditional winning row makes the grant unconditional');
    }
}
