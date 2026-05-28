<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * Integration tests for MigrationRunner against PostgreSQL 14 (TimescaleDB
 * container, which is a strict superset of standard PostgreSQL).
 *
 * Tests mirror MigrationRunnerMySQLTest but exercise PostgreSQL-specific
 * behaviour:
 *   - history table uses SERIAL / TIMESTAMPTZ column types
 *   - catalog queries go to pg_tables / information_schema (same as MySQL)
 *   - table existence checked via information_schema.TABLES
 *
 * Schema used (all table names carry the "mr_pg_" prefix):
 *   mr_pg_framework_migrations  — history table managed by MigrationRunner
 *   mr_pg_roles                 — created by CreateMrPgRoles migration
 *   mr_pg_users                 — created by CreateMrPgUsers migration
 *
 * Requires the Docker TimescaleDB/PostgreSQL container (host: timescaledb, port: 5432).
 */
class MigrationRunnerPostgreSQLTest extends TestCase
{
    /** @var Database Live PostgreSQL connection. */
    protected Database $db;

    /** @var Application Mock application injected into Migration constructors. */
    protected Application $app;

    /** @var string History table name (isolated per suite). */
    protected string $historyTable = 'mr_pg_framework_migrations';

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Arrange – Logger::log() inside executeQueries() requires LOG_PATH to exist.
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        // Arrange – connect to Docker PostgreSQL / TimescaleDB
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->connect(true);

        // Drop all test tables so each test starts from a clean slate.
        $this->db->query("DROP TABLE IF EXISTS \"mr_pg_users\"");
        $this->db->query("DROP TABLE IF EXISTS \"mr_pg_roles\"");
        $this->db->query("DROP TABLE IF EXISTS \"{$this->historyTable}\"");

        $this->app = $this->makeApp();
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS \"mr_pg_users\"");
        $this->db->query("DROP TABLE IF EXISTS \"mr_pg_roles\"");
        $this->db->query("DROP TABLE IF EXISTS \"{$this->historyTable}\"");
    }

    // -------------------------------------------------------------------------
    // ensureHistoryTable
    // -------------------------------------------------------------------------

    /**
     * ensureHistoryTable() must create the schemaversion table with the
     * urbanwater base columns (when, key, extra) plus logging columns
     * (scope, feature, batch, execution_time, result, error_message).
     */
    public function testEnsureHistoryTableCreatesTableWithAllColumns(): void
    {
        // Arrange
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $runner->ensureHistoryTable();

        // Assert – table exists
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = %s",
                $this->historyTable
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'], 'History table must exist after ensureHistoryTable()');

        // Assert – all required columns present
        $required = ['when', 'key', 'extra', 'scope', 'feature', 'batch', 'execution_time', 'result', 'error_message'];
        $colsResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = %s",
                $this->historyTable
            )
        );

        $cols = [];
        $cols[] = $colsResult->fields['column_name'];
        while ($colsResult->fetch()) {
            $cols[] = $colsResult->fields['column_name'];
        }

        foreach ($required as $col) {
            $this->assertContains($col, $cols, "Column '{$col}' must exist in PostgreSQL history table");
        }
    }

    /**
     * Calling ensureHistoryTable() twice must be idempotent on PostgreSQL.
     */
    public function testEnsureHistoryTableIsIdempotent(): void
    {
        // Arrange
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act – call twice; must not throw "table already exists"
        $runner->ensureHistoryTable();
        $runner->ensureHistoryTable();

        // Assert
        $this->assertTrue(true, 'ensureHistoryTable() must be idempotent on PostgreSQL');
    }

    /**
     * ensureHistoryTable() must add missing columns to a pre-existing legacy table
     * that has only the original three columns (when, key, extra).
     *
     * PostgreSQL uses ADD COLUMN IF NOT EXISTS so the operation is a no-op for
     * columns that already exist; for legacy tables the new columns are added
     * without touching existing rows.
     */
    public function testEnsureHistoryTableUpgradesLegacyTable(): void
    {
        // Arrange – create a minimal legacy table mimicking the old urbanwater schema
        $this->db->query("CREATE TABLE \"{$this->historyTable}\" (
            \"when\" TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            \"key\"  VARCHAR(255) NOT NULL,
            \"extra\" VARCHAR(255) NULL,
            PRIMARY KEY (\"key\")
        )");

        // Insert a legacy row to prove existing data survives
        $this->db->query(
            "INSERT INTO \"{$this->historyTable}\" (\"when\", \"key\")
             VALUES (NOW(), 'LegacyMigration0010')"
        );

        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $runner->ensureHistoryTable();

        // Assert – all new columns were added
        $colsResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = %s",
                $this->historyTable
            )
        );
        $cols = [];
        while ($colsResult->fetch()) {
            $cols[] = $colsResult->fields['column_name'];
        }
        foreach (['scope', 'feature', 'batch', 'execution_time', 'result', 'error_message'] as $col) {
            $this->assertContains($col, $cols, "Column '{$col}' must be added to legacy table");
        }

        // Assert – the pre-existing legacy row is intact
        $row = $this->db->query(
            "SELECT \"key\" FROM \"{$this->historyTable}\" WHERE \"key\" = 'LegacyMigration0010'"
        );
        $row->fetch();
        $this->assertSame('LegacyMigration0010', $row->fields['key'], 'Legacy rows must survive the schema upgrade');
    }

    // -------------------------------------------------------------------------
    // run() — happy path
    // -------------------------------------------------------------------------

    /**
     * run() must execute up() and record the migration with correct metadata
     * on PostgreSQL.
     */
    public function testRunExecutesMigrationsAndRecordsHistory(): void
    {
        // Arrange
        $migration = new CreateMrPgRoles($this->app);
        $runner    = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $result = $runner->run([$migration]);

        // Assert – mr_pg_roles table was created by up()
        $tableCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = %s",
                'mr_pg_roles'
            )
        );
        $this->assertSame('1', (string) $tableCheck->fields['cnt'], 'Migration up() must have created mr_pg_roles');

        // Assert – history row recorded with correct metadata
        $histRow = $this->db->query(
            $this->db->prepareQuery(
                "SELECT * FROM \"{$this->historyTable}\" WHERE \"key\" = %s",
                'create_mr_pg_roles'
            )
        );
        $this->assertNotNull($histRow->fields);
        $this->assertSame('framework', $histRow->fields['scope']);
        $this->assertSame('core',      $histRow->fields['feature']);
        $this->assertSame('1',         (string) $histRow->fields['result']);
        $this->assertNotNull($histRow->fields['when']);

        // Assert – run() return lists ran migration
        $this->assertContains('create_mr_pg_roles', $result['ran']);
    }

    /**
     * Batch numbering increments across separate run() calls on PostgreSQL.
     */
    public function testSecondRunIncrementsBatchNumber(): void
    {
        // Arrange
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $runner->run([new CreateMrPgRoles($this->app)]);
        $runner->run([new CreateMrPgUsers($this->app)]);

        // Assert – two distinct batch numbers
        $result = $this->db->query(
            "SELECT DISTINCT batch FROM \"{$this->historyTable}\" ORDER BY batch"
        );
        $batches = [];
        while ($result->fetch()) {
            $batches[] = (int) $result->fields['batch'];
        }

        $this->assertCount(2, $batches);
        $this->assertSame($batches[0] + 1, $batches[1], 'Second run() must use batch = first+1');
    }

    // -------------------------------------------------------------------------
    // run() — failure handling
    // -------------------------------------------------------------------------

    /**
     * A migration that throws must record result=0 with error_message, and the
     * batch must continue on PostgreSQL.
     */
    public function testFailedMigrationRecordedAndBatchContinues(): void
    {
        // Arrange
        $broken = new BrokenMrPgMigration($this->app);
        $valid  = new CreateMrPgRoles($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $result = $runner->run([$broken, $valid]);

        // Assert – broken migration has result=0 in history
        $brokenRow = $this->db->query(
            $this->db->prepareQuery(
                "SELECT * FROM \"{$this->historyTable}\" WHERE \"key\" = %s",
                'broken_mr_pg_migration'
            )
        );
        $this->assertSame('0', (string) $brokenRow->fields['result']);
        $this->assertNotEmpty($brokenRow->fields['error_message']);

        // Assert – valid migration still ran
        $validRow = $this->db->query(
            $this->db->prepareQuery(
                "SELECT * FROM \"{$this->historyTable}\" WHERE \"key\" = %s",
                'create_mr_pg_roles'
            )
        );
        $this->assertSame('1', (string) $validRow->fields['result'], 'Subsequent migration must run even after a failure');

        // failed is now slug => errorMessage; assert the key exists and carries a message
        $this->assertArrayHasKey('broken_mr_pg_migration', $result['failed']);
        $this->assertNotEmpty($result['failed']['broken_mr_pg_migration'], 'failed map must carry the error message as value');
    }

    // -------------------------------------------------------------------------
    // getPending()
    // -------------------------------------------------------------------------

    /**
     * getPending() excludes already-ran migrations and includes failed ones
     * for retry on PostgreSQL.
     */
    public function testGetPendingExcludesAlreadyRanAndIncludesFailed(): void
    {
        // Arrange – run roles (succeeds), broken (fails)
        $roles  = new CreateMrPgRoles($this->app);
        $broken = new BrokenMrPgMigration($this->app);
        $users  = new CreateMrPgUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        $runner->run([$roles, $broken]);

        // Act
        $pending = $runner->getPending([$roles, $broken, $users]);

        // Assert – roles is done, broken is retryable, users is new
        $slugs = array_map(fn(Migration $m) => $m->getSlug(), $pending);
        $this->assertNotContains('create_mr_pg_roles',   $slugs, 'Successful migration must not be in pending');
        $this->assertContains('broken_mr_pg_migration',  $slugs, 'Failed migration must be retryable (pending)');
        $this->assertContains('create_mr_pg_users',      $slugs, 'New migration must be in pending');
    }

    // -------------------------------------------------------------------------
    // rollback()
    // -------------------------------------------------------------------------

    /**
     * rollback() must call down() and remove the last batch's rows from the
     * PostgreSQL history table.
     */
    public function testRollbackRemovesLastBatchFromHistory(): void
    {
        // Arrange – run and then roll back
        $roles  = new CreateMrPgRoles($this->app);
        $users  = new CreateMrPgUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);
        $runner->run([$roles, $users]);

        // Confirm tables exist before rollback
        $this->assertTableExists('mr_pg_roles');
        $this->assertTableExists('mr_pg_users');

        // Act
        $result = $runner->rollback([$roles, $users]);

        // Assert – history empty
        $count = $this->db->query("SELECT COUNT(*) as cnt FROM \"{$this->historyTable}\"");
        $this->assertSame('0', (string) $count->fields['cnt'], 'History must be empty after rollback of only batch');

        // Assert – down() dropped the tables
        $this->assertTableNotExists('mr_pg_roles',  "down() must have dropped mr_pg_roles");
        $this->assertTableNotExists('mr_pg_users', "down() must have dropped mr_pg_users");

        $this->assertContains('create_mr_pg_roles', $result['rolledBack']);
        $this->assertContains('create_mr_pg_users', $result['rolledBack']);
    }

    // -------------------------------------------------------------------------
    // rollbackAll()
    // -------------------------------------------------------------------------

    /**
     * rollbackAll() must roll back every batch, leaving the history table empty
     * and all affected tables dropped on PostgreSQL.
     */
    public function testRollbackAllRemovesAllBatches(): void
    {
        // Arrange – two separate batches
        $roles  = new CreateMrPgRoles($this->app);
        $users  = new CreateMrPgUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        $runner->run([$roles]);
        $runner->run([$users]);

        // Act
        $result = $runner->rollbackAll([$roles, $users]);

        // Assert – all slugs present in rolled-back list
        $this->assertContains('create_mr_pg_roles', $result['rolledBack']);
        $this->assertContains('create_mr_pg_users', $result['rolledBack']);

        // Assert – history is empty
        $count = $this->db->query("SELECT COUNT(*) as cnt FROM \"{$this->historyTable}\"");
        $this->assertSame('0', (string) $count->fields['cnt'], 'History must be empty after rollbackAll()');

        // Assert – tables were dropped
        $this->assertTableNotExists('mr_pg_roles');
        $this->assertTableNotExists('mr_pg_users');
    }

    // -------------------------------------------------------------------------
    // getHistory()
    // -------------------------------------------------------------------------

    /**
     * getHistory() must return all executed migrations with full metadata on
     * PostgreSQL.
     */
    public function testGetHistoryReturnsAllRows(): void
    {
        // Arrange – run both migrations in separate batches
        $roles  = new CreateMrPgRoles($this->app);
        $users  = new CreateMrPgUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        $runner->run([$roles]);
        $runner->run([$users]);

        // Act
        $history = $runner->getHistory();

        // Assert – two rows returned
        $this->assertCount(2, $history, 'getHistory() must return one row per executed migration');

        $slugs = array_column($history, 'key');
        $this->assertContains('create_mr_pg_roles', $slugs);
        $this->assertContains('create_mr_pg_users', $slugs);

        // Assert – rows contain all required metadata columns
        foreach ($history as $row) {
            $this->assertArrayHasKey('scope',          $row, 'History row must contain scope');
            $this->assertArrayHasKey('batch',          $row, 'History row must contain batch');
            $this->assertArrayHasKey('result',         $row, 'History row must contain result');
            $this->assertArrayHasKey('execution_time', $row, 'History row must contain execution_time');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assertTableExists(string $tableName, string $message = ''): void
    {
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = %s",
                $tableName
            )
        );
        $this->assertSame('1', (string) $r->fields['cnt'], $message ?: "Table '{$tableName}' must exist");
    }

    private function assertTableNotExists(string $tableName, string $message = ''): void
    {
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = %s",
                $tableName
            )
        );
        $this->assertSame('0', (string) $r->fields['cnt'], $message ?: "Table '{$tableName}' must not exist");
    }

    private function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        return $app;
    }
}

// =============================================================================
// Concrete migration fixtures
// =============================================================================

/**
 * Creates / drops mr_pg_roles.
 * Slug: create_mr_pg_roles | Feature: core | Scope: framework | Priority: 10
 */
class CreateMrPgRoles extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public $description = 'Creates the pg roles table for the test suite';

    public function up(): void
    {
        $this->addQuery(
            "CREATE TABLE IF NOT EXISTS \"mr_pg_roles\" (
                id   SERIAL PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            )"
        );
        $this->executeQueries();
    }

    public function down(): void
    {
        $this->addQuery("DROP TABLE IF EXISTS \"mr_pg_roles\"");
        $this->executeQueries();
    }
}

/**
 * Creates / drops mr_pg_users.
 * Slug: create_mr_pg_users | Feature: auth | Scope: framework | Priority: 20
 */
class CreateMrPgUsers extends Migration
{
    public string $feature      = 'auth';
    public string $scope        = 'framework';
    public int    $priority     = 20;
    public array  $dependencies = ['create_mr_pg_roles'];
    public $description  = 'Creates the pg users table for the test suite';

    public function up(): void
    {
        $this->addQuery(
            "CREATE TABLE IF NOT EXISTS \"mr_pg_users\" (
                id      SERIAL PRIMARY KEY,
                role_id INT NOT NULL,
                name    VARCHAR(100) NOT NULL
            )"
        );
        $this->executeQueries();
    }

    public function down(): void
    {
        $this->addQuery("DROP TABLE IF EXISTS \"mr_pg_users\"");
        $this->executeQueries();
    }
}

/**
 * Always throws from up() to verify failure recording on PostgreSQL.
 * Slug: broken_mr_pg_migration
 */
class BrokenMrPgMigration extends Migration
{
    public $description = 'Always throws to verify failure recording on PostgreSQL';

    public function up(): void
    {
        throw new \RuntimeException('Intentional PostgreSQL failure for MigrationRunner test');
    }

    public function down(): void {}
}
