<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * Integration tests for MigrationRunner against MySQL 8.0.
 *
 * These tests exercise the full run/rollback lifecycle against a live database,
 * verifying that:
 *   - ensureHistoryTable() creates the framework_migrations table with all
 *     required Phase 4 columns.
 *   - run() executes pending migrations and records metadata in the history
 *     table (scope, feature, batch, execution_time, result, description, ran_at).
 *   - A failed migration records result=0 and an error_message, and does NOT
 *     stop the batch — subsequent migrations still run.
 *   - rollback() removes the last batch from the history table and calls
 *     down() on each rolled-back migration.
 *   - getPending() returns only migrations whose slugs are not yet in history.
 *   - Batch numbering increments with each run() call.
 *
 * Schema used (all table names carry the "mr_my_" prefix to avoid collisions):
 *   mr_my_framework_migrations  — history table managed by MigrationRunner
 *   mr_my_roles                 — created by a test migration's up()
 *   mr_my_users                 — created by another test migration's up()
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class MigrationRunnerMySQLTest extends TestCase
{
    /** @var Database Live MySQL connection shared by all tests in this class. */
    protected Database $db;

    /** @var Application Mock application injected into Migration constructors. */
    protected Application $app;

    /** @var string History table name (isolated per suite). */
    protected string $historyTable = 'mr_my_framework_migrations';

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

        // Arrange – connect to Docker MySQL
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        // Drop all test tables so each test starts from a clean slate.
        $this->db->query("DROP TABLE IF EXISTS `mr_my_users`");
        $this->db->query("DROP TABLE IF EXISTS `mr_my_roles`");
        $this->db->query("DROP TABLE IF EXISTS `{$this->historyTable}`");

        // Build a real Application that provides a DB connection to migrations.
        $this->app = $this->makeApp();
    }

    protected function tearDown(): void
    {
        // Arrange – drop test tables created by migrations during the test
        $this->db->query("DROP TABLE IF EXISTS `mr_my_users`");
        $this->db->query("DROP TABLE IF EXISTS `mr_my_roles`");
        $this->db->query("DROP TABLE IF EXISTS `{$this->historyTable}`");
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

        // Assert – table exists in information_schema
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                $this->historyTable
            )
        );
        $this->assertSame('1', (string) $result->fields['cnt'], 'History table must exist after ensureHistoryTable()');

        // Assert – all required columns are present
        $required = ['when', 'key', 'extra', 'scope', 'feature', 'batch', 'execution_time', 'result', 'error_message'];
        $colsResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                $this->historyTable
            )
        );

        $cols = [];
        while ($colsResult->fetch()) {
            $cols[] = $colsResult->fields['COLUMN_NAME'];
        }

        foreach ($required as $col) {
            $this->assertContains($col, $cols, "Column '{$col}' must exist in history table");
        }
    }

    /**
     * Calling ensureHistoryTable() twice must be idempotent — no error is
     * thrown when the table already exists.
     */
    public function testEnsureHistoryTableIsIdempotent(): void
    {
        // Arrange
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act – call twice
        $runner->ensureHistoryTable();
        $runner->ensureHistoryTable();

        // Assert – no exception was thrown (test passes if we reach this line)
        $this->assertTrue(true, 'ensureHistoryTable() must be safe to call multiple times');
    }

    /**
     * ensureHistoryTable() must add missing columns to a pre-existing legacy table
     * that has only the original three columns (when, key, extra) from the
     * old urbanwater schemaversion schema.
     *
     * This mirrors the real-world upgrade path: the production database already
     * has a schemaversion table populated by app-level migrations, but without
     * the framework logging columns.  ensureHistoryTable() must patch the table
     * in place rather than failing or silently leaving the columns absent.
     */
    public function testEnsureHistoryTableUpgradesLegacyTable(): void
    {
        // Arrange – create a legacy table with only the three original columns
        $this->db->query("CREATE TABLE `{$this->historyTable}` (
            `when` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `key`  VARCHAR(255) NOT NULL,
            `extra` VARCHAR(255) NULL,
            PRIMARY KEY (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        // Insert a legacy row to prove existing data survives the upgrade
        $this->db->query(
            "INSERT INTO `{$this->historyTable}` (`when`, `key`, `extra`)
             VALUES (NOW(), 'LegacyMigration0010', NULL)"
        );

        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $runner->ensureHistoryTable();

        // Assert – all new columns were added
        $colsResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                $this->historyTable
            )
        );
        $cols = [];
        while ($colsResult->fetch()) {
            $cols[] = $colsResult->fields['COLUMN_NAME'];
        }
        foreach (['scope', 'feature', 'batch', 'execution_time', 'result', 'error_message'] as $col) {
            $this->assertContains($col, $cols, "Column '{$col}' must be added to legacy table");
        }

        // Assert – the pre-existing legacy row is intact
        $row = $this->db->query(
            "SELECT `key` FROM `{$this->historyTable}` WHERE `key` = 'LegacyMigration0010'"
        );
        $row->fetch();
        $this->assertSame('LegacyMigration0010', $row->fields['key'], 'Legacy rows must survive the schema upgrade');
    }

    // -------------------------------------------------------------------------
    // run() — happy path
    // -------------------------------------------------------------------------

    /**
     * run() must execute pending migrations' up() methods and record a row
     * in the history table for each one. The recorded row must carry scope,
     * feature, extra, result=1 (success), and a non-null `when`.
     */
    public function testRunExecutesMigrationsAndRecordsHistory(): void
    {
        // Arrange – a single migration that creates mr_my_roles
        $migration = new CreateMrMyRoles($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $result = $runner->run([$migration]);

        // Assert – mr_my_roles table was actually created by up()
        $tableCheck = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'mr_my_roles'
            )
        );
        $this->assertSame('1', (string) $tableCheck->fields['cnt'], 'Migration up() must have created mr_my_roles');

        // Assert – history row exists with correct metadata
        $histRow = $this->db->query(
            $this->db->prepareQuery(
                "SELECT * FROM `{$this->historyTable}` WHERE `key` = %s",
                'create_mr_my_roles'
            )
        );
        $this->assertNotNull($histRow->fields, 'History row must exist for ran migration');
        $this->assertSame('framework', $histRow->fields['scope']); // CreateMrMyRoles declares scope='framework'
        $this->assertSame('core', $histRow->fields['feature']);
        $this->assertSame('1',    (string) $histRow->fields['result'], 'result must be 1 for successful migration');
        $this->assertNotNull($histRow->fields['when'], '`when` must be set after migration runs');
        $this->assertNotNull($histRow->fields['execution_time'], 'execution_time must be recorded');

        // Assert – run() return value reports success
        $this->assertArrayHasKey('ran', $result);
        $this->assertContains('create_mr_my_roles', $result['ran']);
    }

    /**
     * When two migrations are run together, they must be assigned the same
     * batch number so they can be rolled back as a unit.
     */
    public function testRunAssignsSameBatchToAllMigrationsInOneCall(): void
    {
        // Arrange
        $roles = new CreateMrMyRoles($this->app);
        $users = new CreateMrMyUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $runner->run([$roles, $users]);

        // Assert – both rows have the same batch number
        $result = $this->db->query(
            "SELECT DISTINCT batch FROM `{$this->historyTable}` ORDER BY batch"
        );
        $batches = [];
        while ($result->fetch()) {
            $batches[] = $result->fields['batch'];
        }
        $unique = array_unique($batches);

        $this->assertCount(1, $unique, 'All migrations in a single run() call must share the same batch number');
    }

    /**
     * A second call to run() must increment the batch number so the new
     * batch can be rolled back independently of the first one.
     */
    public function testSecondRunIncrementsBatchNumber(): void
    {
        // Arrange
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act – first batch
        $runner->run([new CreateMrMyRoles($this->app)]);

        // Act – second batch
        $runner->run([new CreateMrMyUsers($this->app)]);

        // Assert – two distinct batch numbers in history
        $result = $this->db->query(
            "SELECT DISTINCT batch FROM `{$this->historyTable}` ORDER BY batch"
        );
        $batches = [];
        while ($result->fetch()) {
            $batches[] = (int) $result->fields['batch'];
        }

        $this->assertCount(2, $batches, 'Each run() call must use a new batch number');
        $this->assertSame($batches[0] + 1, $batches[1], 'Second batch must be first+1');
    }

    // -------------------------------------------------------------------------
    // run() — failure handling
    // -------------------------------------------------------------------------

    /**
     * When a migration's up() throws an exception, the runner must:
     *   1. Record result=0 and the error_message in history.
     *   2. Continue to run subsequent migrations in the same batch.
     * The migration's table must NOT be created when the exception occurs
     * inside the SQL itself (since the migration class is designed to throw).
     */
    public function testFailedMigrationRecordedAndBatchContinues(): void
    {
        // Arrange – a migration that always throws, followed by a valid one
        $broken = new BrokenMrMyMigration($this->app);
        $valid  = new CreateMrMyRoles($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $result = $runner->run([$broken, $valid]);

        // Assert – broken migration recorded with result=0
        $brokenRow = $this->db->query(
            $this->db->prepareQuery(
                "SELECT * FROM `{$this->historyTable}` WHERE `key` = %s",
                'broken_mr_my_migration'
            )
        );
        $this->assertSame('0', (string) $brokenRow->fields['result'], 'Failed migration must record result=0');
        $this->assertNotEmpty($brokenRow->fields['error_message'], 'error_message must be populated for failures');

        // Assert – valid migration still ran (batch did not stop)
        $validRow = $this->db->query(
            $this->db->prepareQuery(
                "SELECT * FROM `{$this->historyTable}` WHERE `key` = %s",
                'create_mr_my_roles'
            )
        );
        $this->assertSame('1', (string) $validRow->fields['result'], 'Subsequent migration must still run after a failure');

        // Assert – run() return value reports failed as slug => errorMessage map
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('broken_mr_my_migration', $result['failed']);
        $this->assertNotEmpty($result['failed']['broken_mr_my_migration'], 'failed map must carry the error message as value');
    }

    // -------------------------------------------------------------------------
    // getPending()
    // -------------------------------------------------------------------------

    /**
     * getPending() must return only migrations whose slugs do not already
     * appear as result=1 (successful) rows in the history table.
     */
    public function testGetPendingExcludesAlreadyRanMigrations(): void
    {
        // Arrange – run roles, then ask for pending from [roles, users]
        $roles  = new CreateMrMyRoles($this->app);
        $users  = new CreateMrMyUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        $runner->run([$roles]);

        // Act
        $pending = $runner->getPending([$roles, $users]);

        // Assert – only users is pending
        $this->assertCount(1, $pending, 'Only the not-yet-ran migration must be pending');
        $this->assertSame('create_mr_my_users', $pending[0]->getSlug());
    }

    /**
     * A migration that previously failed (result=0) must be included in
     * pending so it can be retried on the next run().
     */
    public function testPendingIncludesFailedMigrations(): void
    {
        // Arrange – run a broken migration; it should appear in pending again
        $broken = new BrokenMrMyMigration($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);
        $runner->run([$broken]);

        // Act
        $pending = $runner->getPending([$broken]);

        // Assert – still pending because it failed
        $this->assertCount(1, $pending, 'Failed migrations must remain in the pending list for retry');
    }

    // -------------------------------------------------------------------------
    // rollback()
    // -------------------------------------------------------------------------

    /**
     * rollback() must call down() on each migration in the last batch and
     * remove those entries from the history table.
     */
    public function testRollbackRemovesLastBatchFromHistory(): void
    {
        // Arrange – run two migrations in one batch
        $roles  = new CreateMrMyRoles($this->app);
        $users  = new CreateMrMyUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);
        $runner->run([$roles, $users]);

        // Confirm both tables exist before rollback
        $this->assertTableExists('mr_my_roles');
        $this->assertTableExists('mr_my_users');

        // Act
        $result = $runner->rollback([$roles, $users]);

        // Assert – history rows removed
        $count = $this->db->query("SELECT COUNT(*) as cnt FROM `{$this->historyTable}`");
        $this->assertSame('0', (string) $count->fields['cnt'], 'History must be empty after rolling back the only batch');

        // Assert – down() methods were called (tables dropped by migrations' down())
        $this->assertTableNotExists('mr_my_roles',  'down() of CreateMrMyRoles must have dropped mr_my_roles');
        $this->assertTableNotExists('mr_my_users', 'down() of CreateMrMyUsers must have dropped mr_my_users');

        // Assert – rollback return value lists rolled-back slugs
        $this->assertArrayHasKey('rolledBack', $result);
        $this->assertContains('create_mr_my_roles', $result['rolledBack']);
        $this->assertContains('create_mr_my_users', $result['rolledBack']);
    }

    /**
     * rollback() without a prior run (empty history) must return an empty
     * rolledBack list and not throw.
     */
    public function testRollbackOnEmptyHistoryIsNoOp(): void
    {
        // Arrange
        $runner = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $result = $runner->rollback([]);

        // Assert – empty result, no exception
        $this->assertSame([], $result['rolledBack'] ?? [], 'Rollback on empty history must return empty list');
    }

    /**
     * rollback() with the batch option must roll back the specified batch
     * rather than the most recent one, leaving other batches intact.
     */
    public function testRollbackWithBatchOptionRollsBackSpecificBatch(): void
    {
        // Arrange – run two separate batches: batch 1 = roles, batch 2 = users
        $roles  = new CreateMrMyRoles($this->app);
        $users  = new CreateMrMyUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        $runner->run([$roles]);  // batch 1
        $runner->run([$users]);  // batch 2

        // Act – roll back batch 1 (not the most recent batch)
        $result = $runner->rollback([$roles, $users], ['batch' => 1]);

        // Assert – roles was rolled back
        $this->assertContains('create_mr_my_roles', $result['rolledBack']);
        $this->assertTableNotExists('mr_my_roles', 'Batch 1 migration must be rolled back');

        // Assert – users (batch 2) is still recorded in history
        $row = $this->db->query(
            $this->db->prepareQuery(
                "SELECT result FROM `{$this->historyTable}` WHERE `key` = %s",
                'create_mr_my_users'
            )
        );
        $this->assertSame('1', (string) $row->fields['result'], 'Batch 2 migration must remain in history');
    }

    // -------------------------------------------------------------------------
    // rollbackAll()
    // -------------------------------------------------------------------------

    /**
     * rollbackAll() must roll back every batch in reverse order, leaving the
     * history table completely empty and all affected tables dropped.
     */
    public function testRollbackAllRemovesAllBatches(): void
    {
        // Arrange – create two batches
        $roles  = new CreateMrMyRoles($this->app);
        $users  = new CreateMrMyUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        $runner->run([$roles]);
        $runner->run([$users]);

        // Act
        $result = $runner->rollbackAll([$roles, $users]);

        // Assert – all migrations are listed in the result
        $this->assertContains('create_mr_my_roles', $result['rolledBack']);
        $this->assertContains('create_mr_my_users', $result['rolledBack']);

        // Assert – history table is now empty
        $count = $this->db->query("SELECT COUNT(*) as cnt FROM `{$this->historyTable}`");
        $this->assertSame('0', (string) $count->fields['cnt'], 'History must be empty after rollbackAll()');

        // Assert – both tables were dropped
        $this->assertTableNotExists('mr_my_roles');
        $this->assertTableNotExists('mr_my_users');
    }

    // -------------------------------------------------------------------------
    // getHistory()
    // -------------------------------------------------------------------------

    /**
     * getHistory() must return an array with one row per executed migration,
     * including all metadata columns (scope, feature, batch, result, when).
     */
    public function testGetHistoryReturnsAllRows(): void
    {
        // Arrange – run both migrations across two batches
        $roles  = new CreateMrMyRoles($this->app);
        $users  = new CreateMrMyUsers($this->app);
        $runner = new MigrationRunner($this->db, $this->historyTable);

        $runner->run([$roles]);
        $runner->run([$users]);

        // Act
        $history = $runner->getHistory();

        // Assert – two rows, one per migration
        $this->assertCount(2, $history, 'getHistory() must return one row per executed migration');

        $slugs = array_column($history, 'key');
        $this->assertContains('create_mr_my_roles', $slugs);
        $this->assertContains('create_mr_my_users', $slugs);

        // Assert – rows contain expected metadata
        foreach ($history as $row) {
            $this->assertArrayHasKey('scope',          $row, 'History row must contain scope');
            $this->assertArrayHasKey('batch',          $row, 'History row must contain batch');
            $this->assertArrayHasKey('result',         $row, 'History row must contain result');
            $this->assertArrayHasKey('execution_time', $row, 'History row must contain execution_time');
        }
    }

    /**
     * getHistory() on an empty history table must return an empty array
     * without throwing.
     */
    public function testGetHistoryReturnsEmptyArrayWhenNoMigrationsRan(): void
    {
        // Arrange – ensure history table exists but is empty
        $runner = new MigrationRunner($this->db, $this->historyTable);
        $runner->ensureHistoryTable();

        // Act
        $history = $runner->getHistory();

        // Assert
        $this->assertSame([], $history, 'getHistory() must return [] when no migrations have run');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assertTableExists(string $tableName, string $message = ''): void
    {
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                $tableName
            )
        );
        $this->assertSame('1', (string) $r->fields['cnt'], $message ?: "Table '{$tableName}' must exist");
    }

    private function assertTableNotExists(string $tableName, string $message = ''): void
    {
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
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
// Concrete migration fixtures used by this test suite
// =============================================================================

/**
 * Creates and drops the mr_my_roles table.
 * Slug: create_mr_my_roles | Feature: core | Priority: 10
 */
class CreateMrMyRoles extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public $description = 'Creates the roles table for the test suite';

    public function up(): void
    {
        $this->addQuery(
            "CREATE TABLE IF NOT EXISTS `mr_my_roles` (
                id   INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            )"
        );
        $this->executeQueries();
    }

    public function down(): void
    {
        $this->addQuery("DROP TABLE IF EXISTS `mr_my_roles`");
        $this->executeQueries();
    }
}

/**
 * Creates and drops the mr_my_users table.
 * Depends on create_mr_my_roles so it runs after roles exist.
 * Slug: create_mr_my_users | Feature: auth | Priority: 20
 */
class CreateMrMyUsers extends Migration
{
    public string $feature      = 'auth';
    public string $scope        = 'framework';
    public int    $priority     = 20;
    public array  $dependencies = ['create_mr_my_roles'];
    public $description  = 'Creates the users table for the test suite';

    public function up(): void
    {
        $this->addQuery(
            "CREATE TABLE IF NOT EXISTS `mr_my_users` (
                id      INT AUTO_INCREMENT PRIMARY KEY,
                role_id INT NOT NULL,
                name    VARCHAR(100) NOT NULL
            )"
        );
        $this->executeQueries();
    }

    public function down(): void
    {
        $this->addQuery("DROP TABLE IF EXISTS `mr_my_users`");
        $this->executeQueries();
    }
}

/**
 * A migration whose up() always throws to test failure-recording behaviour.
 * Slug: broken_mr_my_migration
 */
class BrokenMrMyMigration extends Migration
{
    public $description = 'Always throws to verify failure recording';

    public function up(): void
    {
        // Intentionally broken — simulates a migration that encounters an error
        throw new \RuntimeException('Intentional failure for MigrationRunner test');
    }

    public function down(): void
    {
        // Nothing to undo since up() never succeeded
    }
}
