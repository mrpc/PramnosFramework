<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migrations\CreateApplicationSettingsTable;
use Pramnos\Database\Migrations\CreateApplicationStatsTable;
use Pramnos\Database\Migrations\CreateUserAppAuthorizationsTable;
use Pramnos\Database\Migrations\AddMissingForeignKeysToExistingTables;

/**
 * Characterization tests for new UrbanWater schema backport migrations.
 *
 * These tests verify that the new migration classes correctly create tables,
 * hypertables, foreign keys, triggers, and indexes across MySQL, PostgreSQL,
 * and TimescaleDB databases.
 *
 * Dependent tables must exist before running these tests. The migration
 * framework runs migrations in dependency order, so this test suite assumes:
 * - All auth/, authserver/, core/ base migrations have been executed
 * - public.users table exists
 * - public.applications table exists
 * - authserver schema exists
 * - applications schema exists
 */
#[CoversClass(CreateApplicationSettingsTable::class)]
#[CoversClass(CreateApplicationStatsTable::class)]
#[CoversClass(CreateUserAppAuthorizationsTable::class)]
#[CoversClass(AddMissingForeignKeysToExistingTables::class)]
#[Group('migrations')]
class UrbanWaterBackportMigrationsCharacterizationTest extends TestCase
{
    /** @var Database Live database connection. */
    protected Database $db;

    /** @var Application Mock application. */
    protected Application $app;

    /** @var string Driver name (mysql, pgsql). */
    protected string $driver;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        // Create database connection from environment
        $this->db = $this->createDatabaseConnection();
        $this->driver = $this->db->getDriverName();
        $this->app = $this->makeApp();
    }

    protected function tearDown(): void
    {
        // Cleanup: migrations run down() in reverse order by framework
        // These tests rely on framework's automated cleanup
    }

    // =========================================================================
    // CreateApplicationSettingsTable Tests
    // =========================================================================

    /**
     * Verifies that CreateApplicationSettingsTable.up() creates the applications.application_settings
     * table with all expected columns and indexes.
     */
    public function testCreateApplicationSettingsTableCreatesTableAndIndexes(): void
    {
        // Arrange
        $migration = new CreateApplicationSettingsTable($this->app);

        // Assume: applications.application_settings does not exist
        $this->dropTableIfExists('applications', 'application_settings');

        // Act
        $migration->up();

        // Assert – table exists
        $this->assertTableExists('applications', 'application_settings');

        // Assert – columns exist
        $this->assertColumnExists('applications', 'application_settings', 'id');
        $this->assertColumnExists('applications', 'application_settings', 'appid');
        $this->assertColumnExists('applications', 'application_settings', 'rate_limit_requests');
        $this->assertColumnExists('applications', 'application_settings', 'cors_enabled');
        $this->assertColumnExists('applications', 'application_settings', 'cors_origins');
        $this->assertColumnExists('applications', 'application_settings', 'allowed_ips');
        $this->assertColumnExists('applications', 'application_settings', 'updated_at');

        // Assert – unique index on appid
        $this->assertIndexExists('applications', 'application_settings', 'idx_application_settings_appid');

        // Assert – index on updated_at
        $this->assertIndexExists('applications', 'application_settings', 'idx_application_settings_updated_at');

        // Cleanup
        $migration->down();
    }

    /**
     * Verifies that CreateApplicationSettingsTable creates an automatic update trigger
     * for updated_at timestamp on PostgreSQL.
     */
    public function testCreateApplicationSettingsTableCreatesUpdateTrigger(): void
    {
        if ($this->driver !== 'pgsql') {
            $this->markTestSkipped('Update trigger only on PostgreSQL');
        }

        // Arrange
        $migration = new CreateApplicationSettingsTable($this->app);
        $this->dropTableIfExists('applications', 'application_settings');

        // Act
        $migration->up();

        // Assert – trigger exists
        $triggerExists = (bool) $this->db->selectOne(
            "SELECT 1 FROM information_schema.triggers 
             WHERE event_object_schema = 'applications' 
             AND event_object_table = 'application_settings'
             AND trigger_name = 'trg_update_application_settings_timestamp'"
        );
        $this->assertTrue($triggerExists, 'Update trigger should exist on PostgreSQL');

        // Cleanup
        $migration->down();
    }

    // =========================================================================
    // CreateApplicationStatsTable Tests
    // =========================================================================

    /**
     * Verifies that CreateApplicationStatsTable.up() creates the applications.application_stats
     * table with all expected columns and indexes.
     */
    public function testCreateApplicationStatsTableCreatesTable(): void
    {
        // Arrange
        $migration = new CreateApplicationStatsTable($this->app);
        $this->dropTableIfExists('applications', 'application_stats');

        // Act
        $migration->up();

        // Assert – table exists
        $this->assertTableExists('applications', 'application_stats');

        // Assert – key columns exist
        $this->assertColumnExists('applications', 'application_stats', 'time');
        $this->assertColumnExists('applications', 'application_stats', 'appid');
        $this->assertColumnExists('applications', 'application_stats', 'total_requests');
        $this->assertColumnExists('applications', 'application_stats', 'avg_response_time');
        $this->assertColumnExists('applications', 'application_stats', 'status_2xx');

        // Assert – composite index exists
        $this->assertIndexExists('applications', 'application_stats', 'idx_application_stats_appid_time');

        // Cleanup
        $migration->down();
    }

    /**
     * Verifies that CreateApplicationStatsTable creates a TimescaleDB hypertable
     * on PostgreSQL when TimescaleDB extension is available.
     */
    public function testCreateApplicationStatsTableCreatesHypertableOnTimescaleDB(): void
    {
        if ($this->driver !== 'pgsql') {
            $this->markTestSkipped('Hypertable only on PostgreSQL with TimescaleDB');
        }

        // Check if TimescaleDB extension exists
        $hasTimescaleDB = (bool) $this->db->selectOne(
            "SELECT 1 FROM pg_extension WHERE extname = 'timescaledb'"
        );
        if (!$hasTimescaleDB) {
            $this->markTestSkipped('TimescaleDB extension not installed');
        }

        // Arrange
        $migration = new CreateApplicationStatsTable($this->app);
        $this->dropTableIfExists('applications', 'application_stats');

        // Act
        $migration->up();

        // Assert – hypertable was created
        $isHypertable = (bool) $this->db->selectOne(
            "SELECT 1 FROM timescaledb_information.hypertables 
             WHERE hypertable_schema = 'applications' 
             AND hypertable_name = 'application_stats'"
        );
        $this->assertTrue($isHypertable, 'Table should be a hypertable on TimescaleDB');

        // Cleanup
        $migration->down();
    }

    // =========================================================================
    // CreateUserAppAuthorizationsTable Tests
    // =========================================================================

    /**
     * Verifies that CreateUserAppAuthorizationsTable.up() creates the authserver.user_app_authorizations
     * table with all expected columns and constraints.
     */
    public function testCreateUserAppAuthorizationsTableCreatesTable(): void
    {
        // Arrange
        $migration = new CreateUserAppAuthorizationsTable($this->app);
        $this->dropTableIfExists('authserver', 'user_app_authorizations');

        // Act
        $migration->up();

        // Assert – table exists
        $this->assertTableExists('authserver', 'user_app_authorizations');

        // Assert – key columns exist
        $this->assertColumnExists('authserver', 'user_app_authorizations', 'id');
        $this->assertColumnExists('authserver', 'user_app_authorizations', 'userid');
        $this->assertColumnExists('authserver', 'user_app_authorizations', 'appid');
        $this->assertColumnExists('authserver', 'user_app_authorizations', 'scope');
        $this->assertColumnExists('authserver', 'user_app_authorizations', 'status');
        $this->assertColumnExists('authserver', 'user_app_authorizations', 'granted_at');
        $this->assertColumnExists('authserver', 'user_app_authorizations', 'revoked_at');

        // Assert – unique constraint on (userid, appid)
        $this->assertIndexExists('authserver', 'user_app_authorizations', 'idx_user_app_auth_unique');

        // Cleanup
        $migration->down();
    }

    /**
     * Verifies that CreateUserAppAuthorizationsTable creates proper foreign keys
     * to users and applications tables.
     */
    public function testCreateUserAppAuthorizationsTableCreatesForeignKeys(): void
    {
        // Arrange
        $migration = new CreateUserAppAuthorizationsTable($this->app);
        $this->dropTableIfExists('authserver', 'user_app_authorizations');

        // Act
        $migration->up();

        // Assert – FK to users table exists
        $this->assertForeignKeyExists(
            'authserver',
            'user_app_authorizations',
            'userid',
            'public',
            'users'
        );

        // Assert – FK to applications table exists
        $this->assertForeignKeyExists(
            'authserver',
            'user_app_authorizations',
            'appid',
            'public',
            'applications'
        );

        // Cleanup
        $migration->down();
    }

    // =========================================================================
    // AddMissingForeignKeysToExistingTables Tests
    // =========================================================================

    /**
     * Verifies that AddMissingForeignKeysToExistingTables.up() adds foreign keys
     * to usertokens table (parentToken and applicationid).
     */
    public function testAddMissingForeignKeysAddsUserTokensForeignKeys(): void
    {
        // Arrange
        $migration = new AddMissingForeignKeysToExistingTables($this->app);

        // Remove FKs if they exist (idempotence test)
        $this->dropForeignKeyIfExists('usertokens', 'fk_usertokens_parenttoken');
        $this->dropForeignKeyIfExists('usertokens', 'fk_usertokens_applicationid');

        // Act
        $migration->up();

        // Assert – FK parentToken → usertokens exists
        $this->assertForeignKeyExists(
            'public',
            'usertokens',
            'parentToken',
            'public',
            'usertokens'
        );

        // Assert – FK applicationid → applications exists
        $this->assertForeignKeyExists(
            'public',
            'usertokens',
            'applicationid',
            'public',
            'applications'
        );

        // Cleanup
        $migration->down();
    }

    /**
     * Verifies that AddMissingForeignKeysToExistingTables.up() adds foreign keys
     * to tokenactions table (tokenid and urlid).
     */
    public function testAddMissingForeignKeysAddsTokenActionsForeignKeys(): void
    {
        // Arrange
        $migration = new AddMissingForeignKeysToExistingTables($this->app);

        // Remove FKs if they exist
        $this->dropForeignKeyIfExists('tokenactions', 'fk_tokenactions_tokenid');
        $this->dropForeignKeyIfExists('tokenactions', 'fk_tokenactions_urlid');

        // Act
        $migration->up();

        // Assert – FK tokenid → usertokens exists
        $this->assertForeignKeyExists(
            'public',
            'tokenactions',
            'tokenid',
            'public',
            'usertokens'
        );

        // Assert – FK urlid → urls exists
        $this->assertForeignKeyExists(
            'public',
            'tokenactions',
            'urlid',
            'public',
            'urls'
        );

        // Cleanup
        $migration->down();
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a database connection from environment variables.
     */
    protected function createDatabaseConnection(): Database
    {
        $driver = $_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?? 'mysql';
        $server = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'db';
        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? 3306);
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'root';
        $password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?? 'secret';
        $database = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'pramnos_test';

        $db = new Database();
        $db->type = $driver;
        $db->server = $server;
        $db->port = $port;
        $db->user = $user;
        $db->password = $password;
        $db->database = $database;

        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped("Database not reachable ({$server}:{$port})");
            }
        } catch (\RuntimeException $e) {
            $this->markTestSkipped("Database not reachable ({$server}:{$port}): " . $e->getMessage());
        }

        return $db;
    }

    /**
     * Create mock application with database.
     */
    protected function makeApp(): Application
    {
        $app = new Application();
        $app->set('database', $this->db);
        return $app;
    }

    /**
     * Assert that a table exists in a given schema.
     */
    protected function assertTableExists(string $schema, string $table): void
    {
        $exists = match ($this->driver) {
            'mysql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                [$this->db->database, $table]
            ),
            'pgsql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name = ?",
                [$schema, $table]
            ),
            default => false,
        };

        $this->assertTrue($exists, "Table {$schema}.{$table} should exist");
    }

    /**
     * Assert that a column exists in a table.
     */
    protected function assertColumnExists(string $schema, string $table, string $column): void
    {
        $exists = match ($this->driver) {
            'mysql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$this->db->database, $table, $column]
            ),
            'pgsql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ? AND column_name = ?",
                [$schema, $table, $column]
            ),
            default => false,
        };

        $this->assertTrue($exists, "Column {$schema}.{$table}.{$column} should exist");
    }

    /**
     * Assert that an index exists on a table.
     */
    protected function assertIndexExists(string $schema, string $table, string $indexName): void
    {
        $exists = match ($this->driver) {
            'mysql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$this->db->database, $table, $indexName]
            ),
            'pgsql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM pg_indexes
                 WHERE schemaname = ? AND tablename = ? AND indexname = ?",
                [$schema, $table, $indexName]
            ),
            default => false,
        };

        $this->assertTrue($exists, "Index {$indexName} on {$schema}.{$table} should exist");
    }

    /**
     * Assert that a foreign key exists on a table column.
     */
    protected function assertForeignKeyExists(
        string $fromSchema,
        string $fromTable,
        string $fromColumn,
        string $toSchema,
        string $toTable
    ): void {
        $exists = match ($this->driver) {
            'mysql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
                 AND REFERENCED_TABLE_NAME = ?",
                [$this->db->database, $fromTable, $fromColumn, $toTable]
            ),
            'pgsql' => (bool) $this->db->selectOne(
                "SELECT 1 FROM information_schema.constraint_column_usage
                 WHERE table_schema = ? AND table_name = ?",
                [$toSchema, $toTable]
            ),
            default => false,
        };

        $this->assertTrue(
            $exists,
            "Foreign key from {$fromSchema}.{$fromTable}.{$fromColumn} to {$toSchema}.{$toTable} should exist"
        );
    }

    /**
     * Drop a table if it exists.
     */
    protected function dropTableIfExists(string $schema, string $table): void
    {
        $fullName = match ($this->driver) {
            'mysql' => "`{$table}`",
            'pgsql' => "\"{$schema}\".\"{$table}\"",
            default => $table,
        };

        $this->db->statement("DROP TABLE IF EXISTS {$fullName}");
    }

    /**
     * Drop a foreign key if it exists.
     */
    protected function dropForeignKeyIfExists(string $table, string $fkName): void
    {
        if ($this->driver === 'pgsql') {
            $this->db->statement(
                "ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$fkName}\""
            );
        } elseif ($this->driver === 'mysql') {
            $this->db->statement(
                "ALTER TABLE `{$table}` DROP FOREIGN KEY IF EXISTS `{$fkName}`"
            );
        }
    }
}
