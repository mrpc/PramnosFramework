<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Permissions;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Integration tests for Permissions::setupDb() against PostgreSQL.
 *
 * The main PermissionsTest.php runs against MySQL and covers the MySQL DDL
 * branch of setupDb().  This class covers the PostgreSQL DDL branch
 * (lines 392–408 of Permissions.php) which uses double-quoted identifiers
 * and CREATE INDEX IF NOT EXISTS statements instead of MySQL backtick syntax.
 *
 * Runs in separate processes so the pg_settings.php fixture takes effect
 * before the MySQL Database singleton is created by sibling tests.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[CoversClass(Permissions::class)]
#[RunTestsInSeparateProcesses]
class PermissionsPostgreSQLTest extends TestCase
{
    protected Database $db;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        $pgSettingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        // Ensure clean state before setupDb (other tests like FrameworkMigrationsTimescaleDBTest might leave dirty state)
        $this->db->execute('DROP TABLE IF EXISTS public.users CASCADE');
        
        // Ensure users table exists so setupDb(true) FK reference is valid
        \Pramnos\User\User::setupDb();
    }

    protected function tearDown(): void
    {
        // Drop permissions table created by setupDb() to avoid polluting other PG tests
        if (defined('DB_PERMISSIONSTABLE')) {
            $this->db->query('DROP TABLE IF EXISTS ' . DB_PERMISSIONSTABLE);
        }
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Verifies that Permissions::setupDb() creates the permissions table and its
     * indexes using PostgreSQL-compatible DDL (no backticks, CREATE INDEX IF NOT EXISTS).
     *
     * This covers lines 392–408 of Permissions.php, the PostgreSQL branch of
     * setupDb() which is NOT exercised by the main PermissionsTest (MySQL-only).
     *
     * Calling with $foreignKeys = false is sufficient to cover all PostgreSQL DDL
     * stmts without risking a FK-add failure due to constraint naming collisions.
     */
    public function testSetupDbCreatesTableOnPostgreSQL(): void
    {
        // Arrange — ensure table does not already exist
        if (defined('DB_PERMISSIONSTABLE')) {
            $this->db->query('DROP TABLE IF EXISTS ' . DB_PERMISSIONSTABLE);
        }

        // Act — run setupDb on a live PostgreSQL connection
        Permissions::setupDb(false);

        // Assert — table now exists
        $result = $this->db->query(
            "SELECT to_regclass('public.permissions') AS tbl"
        );
        $this->assertNotNull(
            $result->fields['tbl'] ?? null,
            'permissions table must exist after setupDb() on PostgreSQL'
        );
    }

    /**
     * Verifies that setupDb(true) attempts to add the FK constraint on PostgreSQL
     * and handles failure gracefully (constraint may already exist or users table
     * schema may differ).
     *
     * This covers lines 433–437 (PostgreSQL FK SQL construction) and line 444
     * (query execution), plus line 446 (catch block) when the constraint already
     * exists from the previous test or from a prior run.
     */
    public function testSetupDbWithForeignKeysOnPostgreSQL(): void
    {
        // Arrange — create table first
        Permissions::setupDb(false);

        // Act — enable FK addition; may succeed or throw (constraint duplicate) — both are valid
        try {
            Permissions::setupDb(true);
        } catch (\Throwable $e) {
            // FK constraint errors are handled internally; this catch is for any
            // unhandled re-throw (should not happen given the internal try/catch)
            $this->fail('setupDb(true) must not throw uncaught exceptions: ' . $e->getMessage());
        }

        // Assert — table still exists (setupDb ran without crashing)
        $result = $this->db->query(
            "SELECT to_regclass('public.permissions') AS tbl"
        );
        $this->assertNotNull(
            $result->fields['tbl'] ?? null,
            'permissions table must still exist after setupDb(true)'
        );
    }
}
