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
        $this->db->execute('DROP MATERIALIZED VIEW IF EXISTS sb_cagg2_hourly CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS sb_cagg2_src CASCADE');
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

    /**
     * A real-time aggregate created over rows it already has keeps seeing the open bucket.
     *
     * glideday's finding: created `WITH DATA`, the aggregate materialised the open bucket too,
     * which moved its watermark to that bucket's end — so rows arriving later in the same
     * bucket were invisible until it closed. Four rollups over site statistics lost a day that
     * way. Now the closed buckets are materialised and the open one stays live.
     */
    public function testARealTimeAggregateStillSeesRowsArrivingInTheOpenBucket(): void
    {
        // Arrange — a closed hour and the current one already have rows
        $this->schema->createTable('sb_cagg_src', function ($t) {
            $t->timestampTz('recorded_at');
            $t->float('value')->nullable();
        });
        $this->schema->createHypertable('sb_cagg_src', 'recorded_at', ['chunk_time_interval' => '1 day']);
        $this->db->execute("INSERT INTO sb_cagg_src VALUES (date_trunc('hour', now()) - interval '90 minutes', 1)");
        $this->db->execute("INSERT INTO sb_cagg_src VALUES (date_trunc('hour', now()), 1)");

        $this->schema->createContinuousAggregate(
            'sb_cagg_hourly',
            "SELECT time_bucket('1 hour', recorded_at) AS bucket, COUNT(*) AS n
               FROM sb_cagg_src GROUP BY bucket",
            ['timescaledb.materialized_only' => false]
        );

        // Act — a row arrives in the open hour after the aggregate was made
        $this->db->execute("INSERT INTO sb_cagg_src VALUES (date_trunc('hour', now()) + interval '1 second', 1)");

        // Assert — the open hour counts both of its rows; the closed hour was materialised
        $open = $this->db->execute(
            "SELECT n FROM sb_cagg_hourly WHERE bucket = date_trunc('hour', now())"
        );
        $this->assertSame(2, (int) $open->fields['n'], 'the open bucket must stay live');

        $closed = $this->db->execute(
            "SELECT n FROM sb_cagg_hourly WHERE bucket = date_trunc('hour', now()) - interval '1 hour' * 2"
        );
        $this->assertSame(1, (int) $closed->fields['n'], 'a closed bucket is in the aggregate');
    }

    /**
     * Over an integer time column the aggregate is still created, and the empty history said.
     *
     * The initial refresh ends at `now()`, which an integer-time aggregate refuses — it takes
     * an integer window. That must not fail the migration: the aggregate exists and is correct,
     * and its refresh policy materialises the history as it runs.
     */
    public function testAnIntegerTimeAggregateIsCreatedAlthoughItsFirstRefreshIsRefused(): void
    {
        // Arrange — an integer-time hypertable with the integer_now function TimescaleDB needs
        $this->db->execute('DROP MATERIALIZED VIEW IF EXISTS sb_int_hourly CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS sb_int_src CASCADE');
        $this->db->execute('CREATE TABLE sb_int_src (ts bigint NOT NULL, value double precision)');
        $this->db->execute("SELECT create_hypertable('sb_int_src', 'ts', chunk_time_interval => 86400)");
        $this->db->execute(
            'CREATE OR REPLACE FUNCTION sb_int_now() RETURNS bigint LANGUAGE SQL STABLE '
            . 'AS $$ SELECT extract(epoch FROM now())::bigint $$'
        );
        $this->db->execute("SELECT set_integer_now_func('sb_int_src', 'sb_int_now')");

        try {
            // Act
            $this->schema->createContinuousAggregate(
                'sb_int_hourly',
                'SELECT time_bucket(3600, ts) AS bucket, COUNT(*) AS n FROM sb_int_src GROUP BY bucket'
            );

            // Assert — it exists as a continuous aggregate
            $rows = $this->db->execute(
                "SELECT COUNT(*) AS cnt FROM timescaledb_information.continuous_aggregates WHERE view_name = 'sb_int_hourly'"
            );
            $this->assertSame(1, (int) $rows->fields['cnt']);
        } finally {
            $this->db->execute('DROP MATERIALIZED VIEW IF EXISTS sb_int_hourly CASCADE');
            $this->db->execute('DROP TABLE IF EXISTS sb_int_src CASCADE');
            $this->db->execute('DROP FUNCTION IF EXISTS sb_int_now()');
        }
    }

    /**
     * A second createContinuousAggregate() on the same name keeps the first
     * definition instead of raising.
     *
     * WHAT: the same aggregate is asked for twice with a *different* SELECT; the
     *       call returns quietly and the aggregate that survives is the first one.
     * WHY:  `CREATE MATERIALIZED VIEW` is not idempotent, so the second call gave
     *
     *           continuous aggregate "…" already exists
     *
     *       and took the whole migration with it. The case in the field is not a
     *       re-run: an installation whose own migration had already built the same
     *       rollup, column for column, could never get past the framework
     *       migration that declares it — and the alternative from its side was
     *       dropping a continuous aggregate with materialised history off a
     *       hypertable and rebuilding it to arrive at the same definition.
     *
     * Asserted on the *columns*, not just on absence of an exception, because
     * "kept the old definition" and "silently replaced it" both avoid throwing and
     * only one of them is the contract.
     */
    public function testCreateContinuousAggregateKeepsAnExistingAggregate(): void
    {
        // Arrange — a hypertable and one aggregate over it
        $this->schema->createTable('sb_cagg2_src', function ($t) {
            $t->timestampTz('recorded_at');
            $t->float('value')->nullable();
        });
        $this->schema->createHypertable('sb_cagg2_src', 'recorded_at', [
            'chunk_time_interval' => '1 day',
        ]);
        $this->schema->createContinuousAggregate(
            'sb_cagg2_hourly',
            "SELECT time_bucket('1 hour', recorded_at) AS bucket, AVG(value) AS avg_value
               FROM sb_cagg2_src GROUP BY bucket"
        );

        // Act — the same name, a different definition
        $this->schema->createContinuousAggregate(
            'sb_cagg2_hourly',
            "SELECT time_bucket('1 hour', recorded_at) AS bucket, MAX(value) AS max_value
               FROM sb_cagg2_src GROUP BY bucket"
        );

        // Assert — still exactly one aggregate, and it is the original
        $viewName = 'sb_cagg2_hourly';
        $rows = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM timescaledb_information.continuous_aggregates
              WHERE view_name = \$1",
            $viewName
        );
        $this->assertSame(1, (int) $rows->fields['cnt']);

        $columns = $this->db->execute(
            "SELECT column_name FROM information_schema.columns
              WHERE table_name = \$1 ORDER BY ordinal_position",
            $viewName
        );
        $names = array_column($columns ? $columns->fetchAll() : [], 'column_name');
        // avg_value, not max_value: the definition that was already there won.
        $this->assertContains('avg_value', $names);
        $this->assertNotContains('max_value', $names);
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
