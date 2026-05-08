<?php

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\DatabaseCapabilities;

/**
 * SchemaBuilder integration tests — TimescaleDB dialect.
 *
 * Extends the PostgreSQL test class to run all PG SchemaBuilder tests against
 * the timescaledb container with TimescaleDB capabilities active
 * ($db->timescale = true).
 *
 * Two layers of verification:
 *
 * 1. All inherited PostgreSQL tests pass — TimescaleDB is a superset of
 *    PostgreSQL and every PG-dialect DDL statement must work identically.
 *
 * 2. TimescaleDB-specific tests added here:
 *    - createHypertable() registers the table as a TimescaleDB hypertable.
 *    - addRetentionPolicy() / addCompressionPolicy() add background jobs.
 *    - createContinuousAggregate() creates a continuous aggregate view.
 *    - ifCapable(TIMESCALEDB) executes its callback and skips its fallback.
 *
 * Note: The "no-op when TimescaleDB absent" path for createHypertable() etc.
 * is covered by SchemaBuilderMySQLTest and SchemaBuilderPostgreSQLTest (plain PG),
 * where hasTimescaleDB() always returns false. It cannot be exercised here because
 * the timescaledb extension is permanently installed in this container.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432)
 * with the timescaledb extension installed.
 */
class SchemaBuilderTimescaleDBTest extends SchemaBuilderPostgreSQLTest
{
    protected function setUp(): void
    {
        // Connect to the TimescaleDB container via the parent setUp.
        parent::setUp();

        // Enable TimescaleDB capabilities so createHypertable() etc. are active.
        $this->db->timescale = true;

        // Refresh the schema builder so it picks up TimescaleDB grammar/capabilities.
        $this->schema = $this->db->schema();

        // Drop TimescaleDB-specific test artefacts from any previous failed test.
        $this->dropTimescaleTables();
    }

    protected function tearDown(): void
    {
        $this->dropTimescaleTables();
        parent::tearDown();
    }

    /**
     * Drop all TimescaleDB-specific test tables/views created by this suite.
     * Must run in setUp() to clean up state left by previously failed tests.
     */
    private function dropTimescaleTables(): void
    {
        // Continuous aggregates must be dropped before their source tables
        $view = 'sb_cagg_hourly';
        $src  = 'sb_cagg_src';
        $comp = 'sb_compress';
        $ret  = 'sb_retention';
        $hyp  = 'sb_hyper';
        $this->db->execute("DROP MATERIALIZED VIEW IF EXISTS {$view} CASCADE");
        $this->db->execute("DROP TABLE IF EXISTS {$src} CASCADE");
        $this->db->execute("DROP TABLE IF EXISTS {$comp} CASCADE");
        $this->db->execute("DROP TABLE IF EXISTS {$ret} CASCADE");
        $this->db->execute("DROP TABLE IF EXISTS {$hyp} CASCADE");
    }

    // -------------------------------------------------------------------------
    // createHypertable()
    // -------------------------------------------------------------------------

    /**
     * createHypertable() must register the table in the TimescaleDB catalog.
     *
     * After the call, timescaledb_information.hypertables must contain a row
     * with the table name. This proves TimescaleDB accepted the DDL and the
     * table is no longer a plain PostgreSQL table.
     *
     * TimescaleDB requires the time column to be part of (or be) the primary
     * key. We use a table without a surrogate SERIAL PK to avoid the constraint.
     */
    public function testCreateHypertableRegistersTableInTimescaleDB(): void
    {
        // Arrange — table without surrogate PK so recorded_at can be the time dimension
        $this->schema->createTable('sb_hyper', function ($t) {
            $t->timestampTz('recorded_at');
            $t->float('value')->nullable();
        });

        // Act
        $result = $this->schema->createHypertable('sb_hyper', 'recorded_at', [
            'chunk_time_interval' => '1 day',
        ]);

        // Assert – call returned true
        $this->assertTrue($result, 'createHypertable() must return true on success');

        // Assert – appears in TimescaleDB's hypertable catalog
        // execute() takes params by reference — must use variables, not literals
        $tableName = 'sb_hyper';
        $rows = $this->db->execute(
            "SELECT hypertable_name FROM timescaledb_information.hypertables
              WHERE hypertable_schema = 'public' AND hypertable_name = \$1",
            $tableName
        );
        $this->assertSame(1, $rows->numRows, 'hypertable must appear in timescaledb_information.hypertables');
        $this->assertSame('sb_hyper', $rows->fields['hypertable_name']);
    }

    // -------------------------------------------------------------------------
    // addRetentionPolicy()
    // -------------------------------------------------------------------------

    /**
     * addRetentionPolicy() must register a drop-chunks job in the TimescaleDB
     * job scheduler (timescaledb_information.jobs with proc_name='policy_retention').
     *
     * A 365-day window avoids triggering actual drops during the test run.
     * The policy is registered even when the table is empty.
     */
    public function testAddRetentionPolicyRegistersJob(): void
    {
        // Arrange — hypertable is required before adding a policy
        $this->schema->createTable('sb_retention', function ($t) {
            $t->timestampTz('recorded_at');
            $t->float('value')->nullable();
        });
        $this->schema->createHypertable('sb_retention', 'recorded_at', [
            'chunk_time_interval' => '1 day',
        ]);

        // Act
        $result = $this->schema->addRetentionPolicy('sb_retention', '365 days');

        // Assert – returned true
        $this->assertTrue($result, 'addRetentionPolicy() must return true on success');

        // Assert – retention job registered in scheduler
        $tableName = 'sb_retention';
        $rows = $this->db->execute(
            "SELECT COUNT(*) AS cnt
               FROM timescaledb_information.jobs
              WHERE hypertable_name = \$1
                AND proc_name = 'policy_retention'",
            $tableName
        );
        $this->assertGreaterThan(0, (int) $rows->fields['cnt'],
            'addRetentionPolicy() must register a retention job in the scheduler');
    }

    // -------------------------------------------------------------------------
    // addCompressionPolicy()
    // -------------------------------------------------------------------------

    /**
     * addCompressionPolicy() must register a compression job after enabling
     * compression on the hypertable (ALTER TABLE SET timescaledb.compress).
     *
     * The policy compresses chunks older than 30 days. The job must appear
     * in timescaledb_information.jobs with proc_name='policy_compression'.
     */
    public function testAddCompressionPolicyRegistersJob(): void
    {
        // Arrange — create hypertable and enable compression
        $this->schema->createTable('sb_compress', function ($t) {
            $t->timestampTz('recorded_at');
            $t->float('value')->nullable();
        });
        $this->schema->createHypertable('sb_compress', 'recorded_at', [
            'chunk_time_interval' => '1 day',
        ]);
        $this->schema->enableCompression('sb_compress');

        // Act
        $result = $this->schema->addCompressionPolicy('sb_compress', '30 days');

        // Assert – returned true
        $this->assertTrue($result, 'addCompressionPolicy() must return true on success');

        // Assert – compression job registered
        $tableName = 'sb_compress';
        $rows = $this->db->execute(
            "SELECT COUNT(*) AS cnt
               FROM timescaledb_information.jobs
              WHERE hypertable_name = \$1
                AND proc_name = 'policy_compression'",
            $tableName
        );
        $this->assertGreaterThan(0, (int) $rows->fields['cnt'],
            'addCompressionPolicy() must register a compression job in the scheduler');
    }

    // -------------------------------------------------------------------------
    // createContinuousAggregate()
    // -------------------------------------------------------------------------

    /**
     * createContinuousAggregate() on TimescaleDB must produce a MATERIALIZED VIEW
     * with the timescaledb.continuous option — verifiable via
     * timescaledb_information.continuous_aggregates (which only lists continuous
     * aggregates, not plain materialized views).
     */
    public function testCreateContinuousAggregateOnTimescaleDB(): void
    {
        // Arrange — hypertable with time column (no surrogate PK)
        $this->schema->createTable('sb_cagg_src', function ($t) {
            $t->timestampTz('recorded_at');
            $t->float('value')->nullable();
        });
        $this->schema->createHypertable('sb_cagg_src', 'recorded_at', [
            'chunk_time_interval' => '1 day',
        ]);

        // Act — hourly average continuous aggregate
        $this->schema->createContinuousAggregate(
            'sb_cagg_hourly',
            "SELECT time_bucket('1 hour', recorded_at) AS bucket, AVG(value) AS avg_value
               FROM sb_cagg_src GROUP BY bucket"
        );

        // Assert – appears in continuous aggregates catalog (not just mat.views)
        $viewName = 'sb_cagg_hourly';
        $rows = $this->db->execute(
            "SELECT COUNT(*) AS cnt
               FROM timescaledb_information.continuous_aggregates
              WHERE view_name = \$1",
            $viewName
        );
        $this->assertGreaterThan(0, (int) $rows->fields['cnt'],
            'createContinuousAggregate() must create a timescaledb.continuous materialized view');
    }

    // -------------------------------------------------------------------------
    // ifCapable()
    // -------------------------------------------------------------------------

    /**
     * ifCapable(TIMESCALEDB) must execute the callback on a TimescaleDB backend
     * and must NOT execute the fallback.
     *
     * This is the "happy path" for capability-conditional DDL — migrations that
     * guard native TimescaleDB DDL with ifCapable() must receive the callback
     * on this backend. The fallback path is covered by MySQL + plain PG tests.
     */
    public function testIfCapableExecutesCallbackOnTimescaleDB(): void
    {
        // Arrange
        $callbackCalled = false;
        $fallbackCalled = false;

        // Act
        $this->schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use (&$callbackCalled) { $callbackCalled = true; },
            function () use (&$fallbackCalled) { $fallbackCalled = true; }
        );

        // Assert – callback ran, fallback did not
        $this->assertTrue($callbackCalled, 'ifCapable() must execute the callback on TimescaleDB');
        $this->assertFalse($fallbackCalled, 'ifCapable() must not execute the fallback on TimescaleDB');
    }
}
