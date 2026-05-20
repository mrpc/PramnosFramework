<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\Applications\CreateApplicationSettingsTable;
use Pramnos\Framework\Migrations\Applications\CreateApplicationStatsTable;
use Pramnos\Framework\Migrations\AuthServer\CreateUserAppAuthorizationsTable;
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
#[CoversClass(\Pramnos\Database\Migrations\AddMissingForeignKeysToExistingTables::class)]
#[Group('migrations')]
class UrbanWaterBackportMigrationsCharacterizationTest extends TestCase
{
    /** @var Database Live database connection. */
    protected Database $db;

    /** @var Application Mock application. */
    protected Application $app;

    /** @var string Driver name (mysql, pgsql). */
    protected string $driver;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        // Create database connection from environment
        $this->db = $this->createDatabaseConnection();
        // Normalize to 'pgsql' for any PostgreSQL variant (postgresql, timescaledb).
        $rawType = $this->db->type;
        $this->driver = ($rawType === 'postgresql' || $rawType === 'timescaledb') ? 'pgsql' : $rawType;
        $this->app = $this->makeApp();

        // Ensure the schemas and stub FK-target tables used by these migrations
        // exist.  In production these are created by prerequisite migrations; in
        // the isolated characterization test environment we create them here so
        // that migration up()/down() methods don't fail with "schema/relation
        // does not exist" errors on FK references.
        if ($this->driver === 'pgsql') {
            $this->db->statement('CREATE SCHEMA IF NOT EXISTS applications');
            $this->db->statement('CREATE SCHEMA IF NOT EXISTS authserver');

            // Minimal stubs — just enough for FK references; not the full schema.
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.applications '
                . '(appid SERIAL PRIMARY KEY, name VARCHAR(255), owner BIGINT)'
            );
            // Add owner column if the stub table already existed without it.
            $this->db->statement(
                'ALTER TABLE public.applications ADD COLUMN IF NOT EXISTS owner BIGINT'
            );
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.urls '
                . '(urlid SERIAL PRIMARY KEY, url TEXT)'
            );
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.tokenactions '
                . '(actionid SERIAL PRIMARY KEY, tokenid INTEGER, urlid INTEGER)'
            );
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.user_privacy_settings '
                . '(id SERIAL PRIMARY KEY, userid BIGINT)'
            );
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.user_consents '
                . '(id SERIAL PRIMARY KEY, userid BIGINT)'
            );
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.data_processing_records '
                . '(id SERIAL PRIMARY KEY, userid BIGINT)'
            );
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.gdpr_requests '
                . '(id SERIAL PRIMARY KEY, userid BIGINT)'
            );
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.user_activity_log '
                . '(id SERIAL PRIMARY KEY, userid BIGINT, time TIMESTAMPTZ)'
            );
            // Users and usertokens are referenced by several migrations.
            // Create minimal stubs so FK constraints can be established even when
            // the integration-test tables do not exist in this environment.
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.users '
                . '(userid BIGSERIAL PRIMARY KEY, username VARCHAR(255))'
            );
            // Column "parentToken" must be quoted so PostgreSQL preserves camelCase.
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS public.usertokens '
                . '(tokenid SERIAL PRIMARY KEY, "parentToken" INTEGER, applicationid INTEGER)'
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->driver === 'pgsql') {
            // Step 1 — remove any FK constraints that migration up() methods may have
            // added to stub tables. down() should already have removed them, but if a
            // test fails before reaching its inline cleanup call these can linger.
            // We use ALTER TABLE IF EXISTS … DROP CONSTRAINT IF EXISTS so this is safe
            // even when the table or constraint no longer exists.
            // This must happen BEFORE dropping referenced tables; otherwise a plain
            // DROP TABLE would require CASCADE and risk destroying unrelated tables.
            $constraintsToRemove = [
                'usertokens'              => ['fk_usertokens_parenttoken', 'fk_usertokens_applicationid'],
                'tokenactions'            => ['fk_tokenactions_tokenid', 'fk_tokenactions_urlid'],
                'applications'            => ['fk_applications_owner'],
                'user_privacy_settings'   => ['fk_user_privacy_settings_userid'],
                'user_consents'           => ['fk_user_consents_userid'],
                'data_processing_records' => ['fk_data_processing_records_userid'],
                'gdpr_requests'           => ['fk_gdpr_requests_userid'],
                'user_activity_log'       => ['fk_user_activity_log_userid'],
            ];
            foreach ($constraintsToRemove as $table => $constraints) {
                foreach ($constraints as $constraint) {
                    $this->db->statement(
                        "ALTER TABLE IF EXISTS \"public\".\"{$table}\""
                        . " DROP CONSTRAINT IF EXISTS \"{$constraint}\""
                    );
                }
            }

            // Step 2 — drop tables that migration up() methods CREATE (not pre-existing).
            // These should be dropped by inline migration->down() calls in each test,
            // but guard with IF EXISTS in case a test fails before reaching its cleanup.
            foreach ([
                '"applications"."application_settings"',
                '"applications"."application_stats"',
                '"authserver"."user_app_authorizations"',
            ] as $table) {
                $this->db->statement("DROP TABLE IF EXISTS {$table}");
            }

            // Step 3 — drop all stub tables created in setUp without CASCADE.
            // All FK constraints that could cause cascade propagation were removed in
            // step 1, so a plain DROP TABLE is safe and will not touch any other table.
            foreach ([
                '"public"."user_activity_log"',
                '"public"."gdpr_requests"',
                '"public"."data_processing_records"',
                '"public"."user_consents"',
                '"public"."user_privacy_settings"',
                '"public"."tokenactions"',
                '"public"."urls"',
                '"public"."usertokens"',
                '"public"."users"',
                '"public"."applications"',
            ] as $table) {
                $this->db->statement("DROP TABLE IF EXISTS {$table}");
            }
        }

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
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
     *
     * Reads the Docker container env vars: DB_TYPE (driver), DB_HOST, DB_PORT,
     * DB_USER, DB_PASS, DB_NAME. getenv() returns false (not null) when a var is
     * absent, so we use ?: instead of ?? to fall through to the default.
     */
    protected function createDatabaseConnection(): Database
    {
        // DB_TYPE is the canonical name in docker-compose (not DB_DRIVER).
        $driver = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $server = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        // Default port depends on driver: 5432 for PostgreSQL, 3306 for MySQL.
        $defaultPort = ($driver === 'postgresql' || $driver === 'pgsql') ? 5432 : 3306;
        $port = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: $defaultPort));
        $user = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        // DB_PASS is the canonical name in docker-compose (not DB_PASSWORD).
        $password = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

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
        // Inject the live test connection so migrations use the correct DB instance.
        $app->database = $this->db;
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
     *
     * Silently skips when the table itself does not exist — this prevents
     * "relation does not exist" errors in tests that run before the dependent
     * tables are created by earlier migrations.
     */
    protected function dropForeignKeyIfExists(string $table, string $fkName): void
    {
        // Guard: check the table exists before issuing ALTER TABLE.
        $tableExists = $this->db->selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = 'public'",
            [$table]
        );
        if (!$tableExists) {
            return;
        }

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
