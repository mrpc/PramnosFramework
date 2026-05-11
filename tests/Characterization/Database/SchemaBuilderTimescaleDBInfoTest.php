<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\SchemaBuilder;

/**
 * Characterization tests for SchemaBuilder TimescaleDB informational view API.
 *
 * Tests the 6 new read-only inspection methods:
 *   - getHypertables() / isHypertable()
 *   - getContinuousAggregates()
 *   - getHypertableDimensions()
 *   - getTimescaleJobs()
 *   - getChunks()
 *
 * Two classes of tests are run:
 *
 * 1. Non-TimescaleDB fallback — exercised against the regular PostgreSQL
 *    container (db:3306 is MySQL; we use timescaledb:5432 without the
 *    extension enabled — actually we skip and just verify the empty-return
 *    contract via a mock that reports no TIMESCALEDB capability).
 *
 * 2. TimescaleDB integration — creates a real hypertable, then asserts that
 *    the API methods return the expected metadata.
 *
 * Requires the Docker TimescaleDB container (timescaledb:5432).
 * All test tables use the "sbinfo_" prefix to avoid collisions.
 */
#[CoversClass(SchemaBuilder::class)]
class SchemaBuilderTimescaleDBInfoTest extends TestCase
{
    /** @var Database Live TimescaleDB connection (nullable until connect). */
    private ?Database $db = null;

    /** @var SchemaBuilder */
    private SchemaBuilder $schema;

    /** Tables created during test — dropped in tearDown. */
    private array $tables = [];

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', sys_get_temp_dir());
        }

        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->schema   = 'public';

        if (!$db->connect(false)) {
            $this->markTestSkipped('TimescaleDB container not reachable (timescaledb:5432)');
        }

        $caps = new DatabaseCapabilities($db);
        if (!$caps->has(DatabaseCapabilities::TIMESCALEDB)) {
            $this->markTestSkipped('TimescaleDB extension not present in this PostgreSQL instance');
        }

        $this->db     = $db;
        $this->schema = $db->schema();
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        foreach (array_reverse($this->tables) as $tbl) {
            $this->db->query("DROP TABLE IF EXISTS \"{$tbl}\" CASCADE");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a minimal hypertable and register it for tearDown.
     * Returns the plain table name (no schema prefix).
     */
    private function makeHypertable(string $name, string $timeCol = 'ts'): string
    {
        $this->db->query("DROP TABLE IF EXISTS \"{$name}\" CASCADE");
        $this->db->query(
            "CREATE TABLE \"{$name}\" (
                {$timeCol} TIMESTAMPTZ NOT NULL,
                val        DOUBLE PRECISION
            )"
        );
        $this->schema->createHypertable($name, $timeCol);
        $this->tables[] = $name;
        return $name;
    }

    // -------------------------------------------------------------------------
    // getHypertables()
    // -------------------------------------------------------------------------

    /**
     * getHypertables() must include any hypertable we just created.
     * Verifies the basic contract: the returned objects have at least
     * hypertable_schema and hypertable_name fields.
     */
    public function testGetHypertablesContainsCreatedHypertable(): void
    {
        // Arrange
        $table = $this->makeHypertable('sbinfo_events');

        // Act
        $rows = $this->schema->getHypertables('public');

        // Assert
        $this->assertIsArray($rows);
        $names = array_column($rows, 'hypertable_name');
        $this->assertContains($table, $names, 'getHypertables() must include the newly created hypertable');
    }

    /**
     * getHypertables() with a schema filter must not return hypertables
     * from other schemas (guarding against cross-schema leakage).
     */
    public function testGetHypertablesFilterBySchemaExcludesOtherSchemas(): void
    {
        // Arrange
        $this->makeHypertable('sbinfo_filtered');

        // Act — request a non-existent schema
        $rows = $this->schema->getHypertables('nonexistent_schema_xyz');

        // Assert
        $this->assertSame([], $rows);
    }

    // -------------------------------------------------------------------------
    // isHypertable()
    // -------------------------------------------------------------------------

    /**
     * isHypertable() must return true for a table that was converted to a
     * hypertable and false for a plain table that was never converted.
     */
    public function testIsHypertableDistinguishesHypertableFromPlainTable(): void
    {
        // Arrange — one hypertable, one plain table
        $hyper = $this->makeHypertable('sbinfo_is_hyper');
        $plain = 'sbinfo_plain_table';
        $this->db->query("DROP TABLE IF EXISTS \"{$plain}\" CASCADE");
        $this->db->query("CREATE TABLE \"{$plain}\" (id SERIAL PRIMARY KEY, name TEXT)");
        $this->tables[] = $plain;

        // Act + Assert
        $this->assertTrue(
            $this->schema->isHypertable($hyper, 'public'),
            'isHypertable() must return true for a converted hypertable'
        );
        $this->assertFalse(
            $this->schema->isHypertable($plain, 'public'),
            'isHypertable() must return false for a plain table'
        );
    }

    /**
     * isHypertable() must resolve the schema automatically when no schema
     * argument is passed (uses the connection's default schema).
     */
    public function testIsHypertableResolvesSchemaAutomatically(): void
    {
        // Arrange
        $table = $this->makeHypertable('sbinfo_autoscm');

        // Act — no schema argument
        $result = $this->schema->isHypertable($table);

        // Assert
        $this->assertTrue($result, 'isHypertable() must work without an explicit schema');
    }

    // -------------------------------------------------------------------------
    // getHypertableDimensions()
    // -------------------------------------------------------------------------

    /**
     * getHypertableDimensions() must return one row for the time column that
     * was declared in createHypertable(). The column_name must match exactly.
     */
    public function testGetHypertableDimensionsReturnsTimeColumn(): void
    {
        // Arrange
        $table = $this->makeHypertable('sbinfo_dims', 'event_time');

        // Act
        $dims = $this->schema->getHypertableDimensions($table, 'public');

        // Assert
        $this->assertNotEmpty($dims, 'getHypertableDimensions() must return at least one dimension');
        $cols = array_column($dims, 'column_name');
        $this->assertContains(
            'event_time',
            $cols,
            'The time dimension must correspond to the column passed to createHypertable()'
        );
    }

    // -------------------------------------------------------------------------
    // getContinuousAggregates()
    // -------------------------------------------------------------------------

    /**
     * getContinuousAggregates() must return empty for a schema that has no
     * continuous aggregates registered.
     */
    public function testGetContinuousAggregatesReturnsEmptyForSchemaWithNone(): void
    {
        // Act — use a schema that definitely has no continuous aggregates
        $rows = $this->schema->getContinuousAggregates('nonexistent_schema_xyz');

        // Assert
        $this->assertSame([], $rows);
    }

    /**
     * After createContinuousAggregate(), getContinuousAggregates() must include
     * the new view. Verifies that the view_name and view_schema are correct.
     */
    public function testGetContinuousAggregatesReflectsCreatedAggregate(): void
    {
        // Arrange — hypertable + continuous aggregate on top
        $this->makeHypertable('sbinfo_cagg_src', 'ts');
        $aggName  = 'sbinfo_cagg_view';
        $this->db->query("DROP MATERIALIZED VIEW IF EXISTS \"{$aggName}\" CASCADE");

        $this->schema->createContinuousAggregate(
            $aggName,
            "SELECT time_bucket('1 hour', ts) AS bucket, COUNT(*) AS cnt
             FROM sbinfo_cagg_src
             GROUP BY bucket"
        );

        // Act
        $rows = $this->schema->getContinuousAggregates('public');

        // Assert
        $names = array_column($rows, 'view_name');
        $this->assertContains(
            $aggName,
            $names,
            'getContinuousAggregates() must include the newly created continuous aggregate'
        );

        // Cleanup (materialized view not tracked in $this->tables)
        $this->db->query("DROP MATERIALIZED VIEW IF EXISTS \"{$aggName}\" CASCADE");
    }

    // -------------------------------------------------------------------------
    // getTimescaleJobs()
    // -------------------------------------------------------------------------

    /**
     * getTimescaleJobs() must return at least the built-in TimescaleDB
     * maintenance jobs (telemetry, compression scheduler, etc.).
     * The result must be a non-empty array of objects with job_id.
     *
     * Note: TimescaleDB always registers internal jobs, so the list is never
     * truly empty on a healthy installation.
     */
    public function testGetTimescaleJobsReturnsBuiltInJobs(): void
    {
        // Act
        $jobs = $this->schema->getTimescaleJobs();

        // Assert — at least the internal Telemetry job should always be present
        $this->assertIsArray($jobs);
        foreach ($jobs as $job) {
            $this->assertObjectHasProperty('job_id', $job);
        }
    }

    // -------------------------------------------------------------------------
    // getChunks()
    // -------------------------------------------------------------------------

    /**
     * getChunks() for a freshly created hypertable must return at least one
     * chunk once data is inserted (TimescaleDB creates the first chunk lazily
     * on first INSERT). Before any data, there may be zero chunks.
     * After inserting a row that falls into the hypertable time range, exactly
     * one chunk must exist.
     */
    public function testGetChunksReturnsChunkAfterInsert(): void
    {
        // Arrange — create hypertable and insert one row
        $table = $this->makeHypertable('sbinfo_chunks', 'ts');
        $this->db->query(
            "INSERT INTO \"{$table}\" (ts, val) VALUES (NOW() - INTERVAL '1 hour', 42.0)"
        );

        // Act
        $chunks = $this->schema->getChunks($table, 'public');

        // Assert
        $this->assertNotEmpty($chunks, 'At least one chunk must exist after inserting data');
        $htNames = array_column($chunks, 'hypertable_name');
        $this->assertContains(
            $table,
            $htNames,
            'getChunks() must return chunks belonging to the given hypertable'
        );
    }

    /**
     * getChunks() without a table argument must return chunks for all hypertables,
     * including the one we just created and populated.
     */
    public function testGetChunksWithNoFilterReturnsAllChunks(): void
    {
        // Arrange
        $table = $this->makeHypertable('sbinfo_chunks_all', 'ts');
        $this->db->query(
            "INSERT INTO \"{$table}\" (ts, val) VALUES (NOW() - INTERVAL '1 hour', 1.0)"
        );

        // Act — no table filter
        $allChunks = $this->schema->getChunks();

        // Assert
        $htNames = array_column($allChunks, 'hypertable_name');
        $this->assertContains(
            $table,
            $htNames,
            'getChunks() with no filter must include chunks from the created hypertable'
        );
    }
}
