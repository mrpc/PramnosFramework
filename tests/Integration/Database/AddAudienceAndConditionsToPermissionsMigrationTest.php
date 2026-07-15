<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\AddAudienceAndConditionsToPermissions;

/**
 * Integration test for the 2026_07_15_000003 permissions grain migration.
 *
 * WHAT: verifies app_id + conditions columns and the audience lookup index are
 *       actually created on / dropped from the real authserver.permissions table.
 * WHY: this is the DB half of the Hybrid RBAC + ABAC grain (feature 4) and must
 *      be strictly additive and idempotent, leaving the existing unique
 *      constraint and effective_permissions view untouched (§8, DB-safety §7).
 *
 * Runs against whichever engine DB_TYPE selects; the CI matrix covers all three.
 */
class AddAudienceAndConditionsToPermissionsMigrationTest extends TestCase
{
    private Database $db;
    private Application $app;
    private bool $isPg;
    private bool $createdTable = false;

    /** Fully-qualified table reference used across the migration and assertions. */
    private const TBL = 'authserver.permissions';

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

        $this->app = new Application();
        $this->app->database = $this->db;

        $schema = $this->db->schema();

        // Ensure a permissions table exists (minimal stub with the columns the
        // audience index references). Only create it when absent, and remember,
        // so tearDown can fully drop the stub — never a real table.
        if (!$schema->hasTable(self::TBL)) {
            $this->createdTable = true;
            $pk = $this->isPg ? 'permissionid BIGSERIAL PRIMARY KEY' : 'permissionid BIGINT AUTO_INCREMENT PRIMARY KEY';
            $this->db->statement(
                'CREATE TABLE ' . $this->qualified() . " ($pk, "
                . 'subject_type VARCHAR(20), subject_id BIGINT, '
                . 'object_type VARCHAR(50), object_id VARCHAR(100), action VARCHAR(20))'
            );
        }

        // Clean slate — a prior auto-run may already have applied the columns.
        if ($schema->hasColumn(self::TBL, 'app_id')) {
            (new AddAudienceAndConditionsToPermissions($this->app))->down();
        }
    }

    protected function tearDown(): void
    {
        try {
            $schema = $this->db->schema();
            if ($this->createdTable) {
                $schema->dropTableIfExists(self::TBL);
            } elseif ($schema->hasColumn(self::TBL, 'app_id')) {
                (new AddAudienceAndConditionsToPermissions($this->app))->down();
            }
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    /** Schema-qualified table reference for raw SQL. */
    private function qualified(): string
    {
        return $this->isPg ? 'authserver.permissions' : 'authserver_permissions';
    }

    /** Whether the audience index exists (portable across engines). */
    private function indexExists(string $name): bool
    {
        if ($this->isPg) {
            $r = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT 1 FROM pg_indexes WHERE schemaname = 'authserver' AND indexname = %s",
                    $name
                )
            );
        } else {
            $r = $this->db->query(
                $this->db->prepareQuery(
                    'SELECT 1 FROM information_schema.statistics '
                    . 'WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
                    'authserver_permissions',
                    $name
                )
            );
        }
        return $r && $r->numRows > 0;
    }

    /**
     * up() adds both columns and the audience index; down() removes them.
     */
    public function testUpAddsColumnsAndIndexAndDownRemovesThem(): void
    {
        $m      = new AddAudienceAndConditionsToPermissions($this->app);
        $schema = $this->db->schema();

        // Pre-condition
        $this->assertFalse($schema->hasColumn(self::TBL, 'app_id'));
        $this->assertFalse($schema->hasColumn(self::TBL, 'conditions'));

        // Act
        $m->up();

        // Assert — columns + index present
        $this->assertTrue($schema->hasColumn(self::TBL, 'app_id'), 'app_id column must be created');
        $this->assertTrue($schema->hasColumn(self::TBL, 'conditions'), 'conditions column must be created');
        $this->assertTrue($this->indexExists('idx_authserver_perms_audience'), 'audience index must be created');

        // Act — rollback
        $m->down();

        // Assert — gone
        $this->assertFalse($schema->hasColumn(self::TBL, 'app_id'), 'app_id must be dropped');
        $this->assertFalse($schema->hasColumn(self::TBL, 'conditions'), 'conditions must be dropped');
        $this->assertFalse($this->indexExists('idx_authserver_perms_audience'), 'audience index must be dropped');
    }

    /**
     * up() is idempotent: a second run is a no-op (guarded by hasColumn), so it
     * is safe on installations that already have the columns.
     */
    public function testUpIsIdempotent(): void
    {
        $m      = new AddAudienceAndConditionsToPermissions($this->app);
        $schema = $this->db->schema();

        $m->up();
        $m->up(); // must not error

        $this->assertTrue($schema->hasColumn(self::TBL, 'app_id'));
        $this->assertTrue($schema->hasColumn(self::TBL, 'conditions'));
    }

    /**
     * A NULL app_id / conditions row can be inserted and read back — proving the
     * columns are nullable (existing rows keep today's global, unconditional
     * meaning) and that JSON round-trips.
     */
    public function testNullableAndJsonRoundTrip(): void
    {
        $m = new AddAudienceAndConditionsToPermissions($this->app);
        $m->up();

        // Insert a global unconditional row (both new columns NULL).
        $this->db->queryBuilder()->table(self::TBL)->insert([
            'subject_type' => 'user', 'subject_id' => 1,
            'object_type'  => 'invoice', 'object_id' => '*', 'action' => 'read',
            'app_id'       => null, 'conditions' => null,
        ]);

        // Insert an app-scoped, conditioned row.
        $this->db->queryBuilder()->table(self::TBL)->insert([
            'subject_type' => 'user', 'subject_id' => 2,
            'object_type'  => 'consumptions', 'object_id' => '*', 'action' => 'delete',
            'app_id'       => 77, 'conditions' => json_encode(['location_id' => [1, 2]]),
        ]);

        $globals = $this->db->queryBuilder()->table(self::TBL)->where('subject_id', 1)->first();
        $this->assertNull($globals->fields['app_id'], 'Global row keeps app_id NULL');

        $scoped = $this->db->queryBuilder()->table(self::TBL)->where('subject_id', 2)->first();
        $this->assertSame(77, (int) $scoped->fields['app_id']);
        $decoded = json_decode((string) $scoped->fields['conditions'], true);
        $this->assertSame(['location_id' => [1, 2]], $decoded, 'conditions JSON must round-trip');
    }
}
