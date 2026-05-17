<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Policy\PolicyEngine;
use Pramnos\Policy\PolicyRecord;

/**
 * Characterization tests for PolicyEngine (QB migration + SchemaBuilder fallbacks).
 *
 * PolicyEngine methods (register, setEnabled, remove, loadPolicies, updateHistory)
 * were migrated from dialect-specific raw SQL ($1/$2 PG, ? MySQL) to QueryBuilder.
 * These tests verify the observable behaviour against a real MySQL database.
 *
 * The framework_policies table is created inline (pramnos_framework_policies on
 * MySQL, because schema.table → schema_table with empty prefix).
 * A mock Application carries the real Database connection — the same pattern used
 * by MigrationMySQLCharacterizationTest.
 *
 * Runs on MySQL only.
 */
#[CoversClass(PolicyEngine::class)]
class PolicyEngineCharacterizationTest extends TestCase
{
    private Database $db;
    private PolicyEngine $engine;

    /** Physical table name on MySQL with empty prefix. */
    private const TABLE = 'pramnos_framework_policies';

    /** Temp table used by retention tests. */
    private const RETENTION_TABLE = 'pe_test_retention_data';

    /** Temp source table used by aggregate_refresh / cache_rebuild tests. */
    private const AGG_SOURCE = 'pe_agg_source';

    /** Temp target/cache table used by aggregate_refresh / cache_rebuild tests. */
    private const AGG_TARGET = 'pe_agg_cache';

    protected function setUp(): void
    {
        // Arrange — connect directly to MySQL (same credentials as other char tests)
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;

        if (!$this->db->connect(true)) {
            $this->markTestSkipped('MySQL container not reachable (db:3306)');
        }

        // Build a minimal mock Application that carries the real DB connection.
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        $this->createPolicyTable();
        $this->engine = new PolicyEngine($app);
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM `' . self::TABLE . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . self::RETENTION_TABLE . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . self::AGG_SOURCE . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . self::AGG_TARGET . '`');
    }

    // =========================================================================
    // register() + getAllEnabled()
    // =========================================================================

    /**
     * register() must persist a new row in framework_policies and return the
     * auto-increment policyid.  getAllEnabled() must then surface the record
     * as a fully-hydrated PolicyRecord.
     *
     * Verifies: QB insert() binds policy_type, target, config (JSON), enabled,
     * created_at correctly on MySQL.
     */
    public function testRegisterPersistsRowAndReturnsId(): void
    {
        // Arrange
        $config = ['interval' => '30 days', 'time_column' => 'created_at'];

        // Act
        $id = $this->engine->register('retention', 'my_table', $config);

        // Assert — returned ID is a positive integer
        $this->assertGreaterThan(0, $id, 'register() must return the new policyid');

        // Assert — row is fetchable via getAllEnabled() as a correct PolicyRecord
        $all = $this->engine->getAllEnabled();
        $this->assertCount(1, $all);
        $record = $all[0];
        $this->assertInstanceOf(PolicyRecord::class, $record);
        $this->assertSame($id, $record->policyid);
        $this->assertSame('retention', $record->policyType);
        $this->assertSame('my_table', $record->target);
        $this->assertSame('30 days', $record->config['interval']);
        $this->assertTrue($record->enabled);
    }

    /**
     * Registering multiple policies must persist all of them with distinct IDs
     * and getAllEnabled() must return all in ascending policyid order.
     *
     * Verifies that repeated QB inserts each produce a fresh auto-increment ID.
     */
    public function testRegisterMultiplePoliciesAreAllReturned(): void
    {
        // Act
        $id1 = $this->engine->register('compression', 'table_a');
        $id2 = $this->engine->register('aggregate_refresh', 'view_b', ['source' => 'raw_b']);
        $id3 = $this->engine->register('cache_rebuild', 'cache_c', ['source' => 'raw_c']);

        // Assert — all three IDs are distinct positive integers
        $this->assertCount(3, array_unique([$id1, $id2, $id3]), 'Each register() call must produce a distinct ID');

        // Assert — getAllEnabled() returns all three in ascending policyid order
        $all = $this->engine->getAllEnabled();
        $this->assertCount(3, $all);
        $this->assertSame('compression', $all[0]->policyType);
        $this->assertSame('aggregate_refresh', $all[1]->policyType);
        $this->assertSame('cache_rebuild', $all[2]->policyType);
    }

    // =========================================================================
    // setEnabled()
    // =========================================================================

    /**
     * setEnabled(false) must mark the policy disabled so that getAllEnabled()
     * no longer returns it.  setEnabled(true) must restore it.
     *
     * Verifies: QB update() binding for the boolean enabled column; the
     * whereRaw('enabled = TRUE') filter in loadPolicies().
     */
    public function testSetEnabledTogglesVisibilityInGetAllEnabled(): void
    {
        // Arrange
        $id = $this->engine->register('compression', 'tbl');
        $this->assertCount(1, $this->engine->getAllEnabled(), 'Newly registered policy must appear in getAllEnabled()');

        // Act — disable
        $this->engine->setEnabled($id, false);

        // Assert — disabled policy is hidden from getAllEnabled()
        $this->assertCount(0, $this->engine->getAllEnabled(), 'Disabled policy must not appear in getAllEnabled()');

        // Act — re-enable
        $this->engine->setEnabled($id, true);

        // Assert — re-enabled policy is visible again
        $all = $this->engine->getAllEnabled();
        $this->assertCount(1, $all);
        $this->assertTrue($all[0]->enabled);
    }

    // =========================================================================
    // remove()
    // =========================================================================

    /**
     * remove() must delete the row from the database so that it never appears
     * again in getAllEnabled() or run().
     *
     * Verifies: QB delete() + WHERE policyid = ? binding.
     */
    public function testRemoveDeletesPolicyPermanently(): void
    {
        // Arrange
        $id = $this->engine->register('cache_rebuild', 'cache_tbl');
        $this->assertCount(1, $this->engine->getAllEnabled());

        // Act
        $this->engine->remove($id);

        // Assert — row is gone
        $this->assertCount(0, $this->engine->getAllEnabled(), 'Removed policy must not appear in getAllEnabled()');

        // Assert — the row is truly absent in the DB, not just disabled
        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM `' . self::TABLE . '` WHERE policyid = ' . $id);
        $row->fetch();
        $this->assertSame(0, (int) $row->fields['cnt']);
    }

    // =========================================================================
    // run() — no-op policy type updates history
    // =========================================================================

    /**
     * A 'compression' policy is always a no-op on non-TimescaleDB and returns
     * status='ok'.  After run(), last_run and last_result must be updated in
     * the database (verifying updateHistory()'s QB update path).
     *
     * Covers: getDuePolicies(), executePolicy(), updateHistory().
     */
    public function testRunNoOpCompressionPolicyUpdatesHistory(): void
    {
        // Arrange — next_run = NULL means immediately due
        $id = $this->engine->register('compression', 'some_hypertable');

        // Act
        $results = $this->engine->run();

        // Assert — one result, no error
        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]['status']);
        $this->assertNull($results[0]['error']);
        $this->assertSame($id, $results[0]['policyid']);

        // Assert — history columns were updated via QB
        $row = $this->db->query(
            'SELECT last_run, last_result, last_error FROM `' . self::TABLE . '` WHERE policyid = ' . $id
        );
        $row->fetch();
        $this->assertNotNull($row->fields['last_run'],   'last_run must be set by updateHistory()');
        $this->assertSame('ok', $row->fields['last_result']);
        $this->assertNull($row->fields['last_error']);
    }

    // =========================================================================
    // run() — due/not-due filtering
    // =========================================================================

    /**
     * Policies whose next_run is in the future must NOT be executed by run().
     * Only policies with next_run IS NULL or next_run <= NOW() are due.
     *
     * Verifies the whereRaw('(next_run IS NULL OR next_run <= NOW())') clause
     * in loadPolicies(onlyDue: true).
     */
    public function testRunSkipsFuturePolicies(): void
    {
        // Arrange — one due (next_run NULL), one not yet due (next_run = tomorrow)
        $dueId    = $this->engine->register('compression', 'tbl_due');
        $futureId = $this->engine->register('compression', 'tbl_future');

        $this->db->query(
            'UPDATE `' . self::TABLE . '` SET next_run = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE policyid = ' . $futureId
        );

        // Act
        $results = $this->engine->run();

        // Assert — only the due policy ran
        $this->assertCount(1, $results);
        $this->assertSame($dueId, $results[0]['policyid']);
    }

    // =========================================================================
    // run() — retention execution deletes old rows
    // =========================================================================

    /**
     * A 'retention' policy must delete rows older than the configured interval
     * from the target table and leave newer rows intact.
     *
     * Verifies executeRetention() builds the correct
     * DATE_SUB(NOW(), INTERVAL N UNIT) DELETE on MySQL.
     */
    public function testRunRetentionDeletesOldRows(): void
    {
        // Arrange — create a table with one old row (60 days ago) and one new row (today)
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . self::RETENTION_TABLE . '` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL
            )'
        );
        $this->db->query(
            'INSERT INTO `' . self::RETENTION_TABLE . '` (created_at)
             VALUES (DATE_SUB(NOW(), INTERVAL 60 DAY))'
        );
        $this->db->query(
            'INSERT INTO `' . self::RETENTION_TABLE . '` (created_at) VALUES (NOW())'
        );

        // Register a 30-day retention policy on the test table
        $this->engine->register('retention', self::RETENTION_TABLE, [
            'interval'    => '30 days',
            'time_column' => 'created_at',
        ]);

        // Act
        $results = $this->engine->run();

        // Assert — policy ran without error
        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]['status'], $results[0]['error'] ?? '');

        // Assert — old row deleted; new row survived
        $count = $this->db->query('SELECT COUNT(*) AS cnt FROM `' . self::RETENTION_TABLE . '`');
        $count->fetch();
        $this->assertSame(1, (int) $count->fields['cnt'], 'Only the new row (within 30-day window) must remain');
    }

    // =========================================================================
    // run() — unknown policy type returns error result
    // =========================================================================

    /**
     * An unrecognised policy_type must produce status='error' in the result
     * without throwing an uncaught exception from run().
     *
     * Verifies the catch(\Throwable) wrapper in executePolicy() converts
     * the RuntimeException to an error result entry.
     */
    public function testRunUnknownPolicyTypeReturnsError(): void
    {
        // Arrange — register a policy then directly override its type to something unknown
        $this->engine->register('compression', 'tbl_placeholder');
        $id = (int) $this->db->getInsertId();
        $this->db->query(
            "UPDATE `" . self::TABLE . "` SET policy_type = 'unknown_type' WHERE policyid = $id"
        );

        // Act
        $results = $this->engine->run();

        // Assert — error entry returned, no exception propagated
        $this->assertCount(1, $results);
        $this->assertSame('error', $results[0]['status']);
        $this->assertStringContainsString('unknown_type', (string) $results[0]['error']);
    }

    // =========================================================================
    // run() — aggregate_refresh execution
    // =========================================================================

    /**
     * An 'aggregate_refresh' policy on MySQL must TRUNCATE the target table
     * and reload it from the source table via INSERT INTO … SELECT.
     *
     * This covers PolicyEngine::executeAggregateRefresh() MySQL path (lines 252-254)
     * and the `'aggregate_refresh' => $this->executeAggregateRefresh($policy)` arm
     * of the match statement in executePolicy().
     */
    public function testRunAggregateRefreshCopiesFromSourceToTarget(): void
    {
        // Arrange — source table with 2 rows, target table with 1 stale row
        $this->createSimpleTable(self::AGG_SOURCE);
        $this->createSimpleTable(self::AGG_TARGET);
        $this->db->query("INSERT INTO `" . self::AGG_SOURCE . "` (val) VALUES ('alpha'), ('beta')");
        $this->db->query("INSERT INTO `" . self::AGG_TARGET . "` (val) VALUES ('stale')");

        $this->engine->register('aggregate_refresh', self::AGG_TARGET, [
            'source' => self::AGG_SOURCE,
        ]);

        // Act
        $results = $this->engine->run();

        // Assert — policy executed without error
        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]['status'], $results[0]['error'] ?? '');

        // Assert — target now matches source (stale row gone, two new rows)
        $cnt = $this->db->query("SELECT COUNT(*) AS c FROM `" . self::AGG_TARGET . "`");
        $cnt->fetch();
        $this->assertSame(2, (int) $cnt->fields['c'],
            'aggregate_refresh must TRUNCATE then INSERT SELECT from source');
    }

    /**
     * An 'aggregate_refresh' policy with no 'source' in config must be a no-op on
     * MySQL (nothing to reload without a source table).
     *
     * This covers the `if ($source !== null)` false branch inside executeAggregateRefresh().
     */
    public function testRunAggregateRefreshWithoutSourceIsNoOp(): void
    {
        // Arrange — target table with 1 row, no source config
        $this->createSimpleTable(self::AGG_TARGET);
        $this->db->query("INSERT INTO `" . self::AGG_TARGET . "` (val) VALUES ('keep')");

        $this->engine->register('aggregate_refresh', self::AGG_TARGET);

        // Act
        $results = $this->engine->run();

        // Assert — policy runs with 'ok' status (no-op doesn't throw)
        $this->assertSame('ok', $results[0]['status'], $results[0]['error'] ?? '');

        // Assert — target row untouched
        $cnt = $this->db->query("SELECT COUNT(*) AS c FROM `" . self::AGG_TARGET . "`");
        $cnt->fetch();
        $this->assertSame(1, (int) $cnt->fields['c'],
            'aggregate_refresh without source must leave target unchanged');
    }

    // =========================================================================
    // run() — cache_rebuild execution
    // =========================================================================

    /**
     * A 'cache_rebuild' policy must TRUNCATE the target and reload from source.
     *
     * This covers PolicyEngine::executeCacheRebuild() with source set (lines 268-269)
     * and the `'cache_rebuild' => $this->executeCacheRebuild($policy)` arm.
     */
    public function testRunCacheRebuildCopiesFromSourceToTarget(): void
    {
        // Arrange
        $this->createSimpleTable(self::AGG_SOURCE);
        $this->createSimpleTable(self::AGG_TARGET);
        $this->db->query("INSERT INTO `" . self::AGG_SOURCE . "` (val) VALUES ('x'), ('y'), ('z')");
        $this->db->query("INSERT INTO `" . self::AGG_TARGET . "` (val) VALUES ('old')");

        $this->engine->register('cache_rebuild', self::AGG_TARGET, [
            'source' => self::AGG_SOURCE,
        ]);

        // Act
        $results = $this->engine->run();

        // Assert — ran without error
        $this->assertSame('ok', $results[0]['status'], $results[0]['error'] ?? '');

        // Assert — target has 3 rows from source
        $cnt = $this->db->query("SELECT COUNT(*) AS c FROM `" . self::AGG_TARGET . "`");
        $cnt->fetch();
        $this->assertSame(3, (int) $cnt->fields['c'],
            'cache_rebuild must TRUNCATE then INSERT SELECT from source');
    }

    /**
     * A 'cache_rebuild' policy with no source config must be a no-op.
     *
     * Covers the `if ($source !== null)` false branch in executeCacheRebuild().
     */
    public function testRunCacheRebuildWithoutSourceIsNoOp(): void
    {
        // Arrange
        $this->createSimpleTable(self::AGG_TARGET);
        $this->db->query("INSERT INTO `" . self::AGG_TARGET . "` (val) VALUES ('keep_me')");
        $this->engine->register('cache_rebuild', self::AGG_TARGET);

        // Act
        $results = $this->engine->run();

        // Assert — no error, row untouched
        $this->assertSame('ok', $results[0]['status'], $results[0]['error'] ?? '');
        $cnt = $this->db->query("SELECT COUNT(*) AS c FROM `" . self::AGG_TARGET . "`");
        $cnt->fetch();
        $this->assertSame(1, (int) $cnt->fields['c']);
    }

    // =========================================================================
    // run() — quoteIdentifier invalid name → error result
    // =========================================================================

    /**
     * A policy whose target contains an invalid SQL identifier (e.g. a hyphen)
     * must produce status='error' without crashing run().
     *
     * This covers the `throw new InvalidArgumentException` guard in
     * PolicyEngine::quoteIdentifier() (lines 300-302).
     */
    public function testRunReturnsErrorForInvalidIdentifier(): void
    {
        // Arrange — register a retention policy with a target containing a hyphen
        $this->engine->register('retention', 'valid_target');
        $id = (int) $this->db->getInsertId();
        // Directly override target with an invalid identifier
        $this->db->query(
            "UPDATE `" . self::TABLE . "` SET target = 'my-invalid-table' WHERE policyid = $id"
        );

        // Act
        $results = $this->engine->run();

        // Assert — InvalidArgumentException from quoteIdentifier → error result
        $this->assertSame('error', $results[0]['status']);
        $this->assertStringContainsString('Invalid SQL identifier', (string) $results[0]['error']);
    }

    // =========================================================================
    // quoteIdentifier() — PostgreSQL double-quote path
    // =========================================================================

    /**
     * quoteIdentifier() must return a double-quoted identifier when the database
     * type is 'postgresql', and a back-tick-quoted identifier for MySQL.
     *
     * This covers the `return '"' . str_replace('"', '""', $name) . '"'` branch
     * in quoteIdentifier() (line 306).  We test the private method directly via
     * ReflectionMethod — no real DB query is issued, it is pure string logic.
     */
    public function testQuoteIdentifierReturnsDoubleQuotedForPostgres(): void
    {
        // Arrange — switch type flag only (no real PG connection needed)
        $originalType = $this->db->type;
        $this->db->type = 'postgresql';

        try {
            // Act — call private quoteIdentifier() via reflection
            $ref    = new \ReflectionMethod($this->engine, 'quoteIdentifier');
            $result = $ref->invoke($this->engine, 'my_schema.my_table');

            // Assert — PostgreSQL uses double-quotes
            $this->assertSame('"my_schema.my_table"', $result,
                'quoteIdentifier() must double-quote identifiers for PostgreSQL');
        } finally {
            $this->db->type = $originalType;
        }
    }

    // =========================================================================
    // run() — TimescaleDB fast path
    // =========================================================================

    /**
     * When the database type is 'timescaledb', run() must return an empty array
     * immediately without querying the policy table.
     *
     * This covers the `if ($this->isTimescaleDb()) { return []; }` guard (line 73)
     * in PolicyEngine::run().  PolicyEngine stores a reference to the Database
     * object, so mutating $this->db->type before calling run() is sufficient.
     */
    public function testRunReturnsEmptyArrayOnTimescaleDb(): void
    {
        // Arrange — switch the shared DB object's type to timescaledb
        $originalType = $this->db->type;
        $this->db->type = 'timescaledb';

        try {
            // Act
            $results = $this->engine->run();

            // Assert — fast-return with empty array; no policy was executed
            $this->assertSame([], $results, 'run() must be a no-op on TimescaleDB');
        } finally {
            // Restore db type so tearDown() can still clean up
            $this->db->type = $originalType;
        }
    }

    // =========================================================================
    // run() — toMySQLInterval week conversion
    // =========================================================================

    /**
     * A retention policy with interval '2 weeks' must convert to 14 DAY before
     * being passed to MySQL's DATE_SUB(), so rows older than 14 days are deleted.
     *
     * This covers the `if ($unit === 'WEEK') { return ($n * 7) . ' DAY'; }` branch
     * in PolicyEngine::toMySQLInterval().
     */
    public function testRetentionWithWeekIntervalConvertsTodays(): void
    {
        // Arrange — create data table with one row from 15 days ago and one from today
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . self::RETENTION_TABLE . "`
             (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL)"
        );
        $this->db->query(
            "INSERT INTO `" . self::RETENTION_TABLE . "` (created_at)
             VALUES (DATE_SUB(NOW(), INTERVAL 15 DAY))"
        );
        $this->db->query(
            "INSERT INTO `" . self::RETENTION_TABLE . "` (created_at) VALUES (NOW())"
        );

        // Register a 2-weeks retention policy ('2 weeks' → 14 DAY → 15-day row deleted)
        $this->engine->register('retention', self::RETENTION_TABLE, [
            'interval'    => '2 weeks',
            'time_column' => 'created_at',
        ]);

        // Act
        $results = $this->engine->run();

        // Assert — runs without error
        $this->assertSame('ok', $results[0]['status'], $results[0]['error'] ?? '');

        // Assert — the 15-day-old row was deleted (older than 2 weeks = 14 days)
        $cnt = $this->db->query("SELECT COUNT(*) AS c FROM `" . self::RETENTION_TABLE . "`");
        $cnt->fetch();
        $this->assertSame(1, (int) $cnt->fields['c'],
            '2 weeks must delete rows older than 14 days (week → 7 days conversion)');
    }

    /**
     * A retention policy with an unrecognised interval pattern must pass it
     * through unchanged to MySQL (fallback path in toMySQLInterval).
     *
     * This covers the `return $pgInterval` fallback (line 332).
     * The test verifies the policy produces an error result because MySQL
     * rejects the non-standard interval syntax, not that it succeeds.
     */
    public function testRetentionWithUnknownIntervalPatternFallsThrough(): void
    {
        // Arrange — table with one row
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . self::RETENTION_TABLE . "`
             (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL)"
        );
        $this->db->query(
            "INSERT INTO `" . self::RETENTION_TABLE . "` (created_at) VALUES (NOW())"
        );

        // Register with a non-parseable interval — toMySQLInterval() passes it through unchanged
        $this->engine->register('retention', self::RETENTION_TABLE, [
            'interval'    => 'P30D',   // ISO 8601 duration — not matched by the regex
            'time_column' => 'created_at',
        ]);

        // Act — MySQL will reject 'P30D' as an interval unit → DB error → error result
        $results = $this->engine->run();

        // Assert — error result (invalid SQL) confirms the fallback path was taken
        $this->assertSame('error', $results[0]['status'],
            'toMySQLInterval() fallback passes unknown patterns to MySQL which rejects them');
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function createPolicyTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $this->db->query(
            'CREATE TABLE `' . self::TABLE . '` (
                policyid    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                policy_type VARCHAR(50)  NOT NULL,
                target      VARCHAR(255) NOT NULL,
                config      JSON         NOT NULL,
                enabled     TINYINT(1)   NOT NULL DEFAULT 1,
                last_run    DATETIME     DEFAULT NULL,
                next_run    DATETIME     DEFAULT NULL,
                last_result TEXT         DEFAULT NULL,
                last_error  TEXT         DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Creates a minimal two-column table (id AUTO_INCREMENT + val VARCHAR(50))
     * used as source or target fixtures for aggregate_refresh / cache_rebuild tests.
     */
    private function createSimpleTable(string $name): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$name}` (
                id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                val VARCHAR(50) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
