<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\ContinuousAggregateRegistry;
use Pramnos\Database\Database;

/**
 * Integration tests for the refresh policy of rolled-up views, on both backends.
 *
 * WHAT: does a view that nothing refreshes end up with a policy, and does asking
 *       twice stay harmless?
 * WHY:  four migrations create a TimescaleDB continuous aggregate where the
 *       extension is present and a plain materialized view where it is not — and
 *       registered the refresh only in the first branch. PostgreSQL never
 *       refreshes a materialized view on its own, so on every other backend those
 *       views were frozen at the moment they were created: present, queryable,
 *       and answering with the data of the day the migration ran.
 *
 * The two backends store the answer in completely different places — a
 * background job for TimescaleDB, a row in `pramnos.framework_policies` for
 * everyone else — and the TimescaleDB one cannot even be found by the view's
 * name. Only a real database proves either.
 *
 * The plain-PostgreSQL case runs against a database on the same server with the
 * extension dropped, which is exactly what an installation without TimescaleDB
 * looks like.
 */
class ContinuousAggregateRefreshTest extends TestCase
{
    /** @var Database Connection under test */
    private Database $db;

    /** @var \Pramnos\Database\SchemaBuilder */
    private $schema;

    /** @var bool Whether this connection has the extension */
    private bool $hasTimescale = false;

    /** The view this test creates and repairs. */
    private const VIEW = 'aggtest.rollup';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->connect('pramnos_test');
        $this->hasTimescale = $this->db->capabilities()->hasTimescaleDB();

        ContinuousAggregateRegistry::register(self::VIEW, [
            'start_offset'      => '3 hours',
            'end_offset'        => '1 hour',
            'schedule_interval' => '1 hour',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->dropProbe();
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
        ContinuousAggregateRegistry::reset();
        parent::tearDown();
    }

    /**
     * Point this test at one database on the PostgreSQL/TimescaleDB server.
     */
    private function connect(string $database): void
    {
        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->port     = 5432;
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = $database;

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('PostgreSQL/TimescaleDB not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB not reachable: ' . $e->getMessage());
        }

        $this->schema = $this->db->schema();
    }

    /** Remove everything this test creates, in either database. */
    private function dropProbe(): void
    {
        $this->db->query('DROP MATERIALIZED VIEW IF EXISTS ' . self::VIEW . ' CASCADE');
        $this->db->query('DROP TABLE IF EXISTS aggtest.source CASCADE');
        $this->db->query('DROP SCHEMA IF EXISTS aggtest CASCADE');

        if (!$this->hasTimescale) {
            try {
                $this->db->queryBuilder()
                    ->table('pramnos.framework_policies')
                    ->where('target', self::VIEW)
                    ->delete();
            } catch (\Throwable) {
                // The policies table may not exist here.
            }
        }
    }

    /**
     * Build the rolled-up view the way the migrations build it on this backend.
     */
    private function createRollup(): void
    {
        $this->dropProbe();
        $this->db->query('CREATE SCHEMA IF NOT EXISTS aggtest');
        $this->db->query(
            'CREATE TABLE aggtest.source (t TIMESTAMPTZ NOT NULL, v INT)'
        );

        if ($this->hasTimescale) {
            $this->db->query("SELECT create_hypertable('aggtest.source', 't')");
            $this->db->query(
                'CREATE MATERIALIZED VIEW ' . self::VIEW
                . " WITH (timescaledb.continuous) AS SELECT time_bucket('1 hour', t) AS bucket,"
                . ' SUM(v) AS total FROM aggtest.source GROUP BY 1 WITH NO DATA'
            );
        } else {
            $this->db->query(
                'CREATE MATERIALIZED VIEW ' . self::VIEW
                . " AS SELECT date_trunc('hour', t) AS bucket, SUM(v) AS total"
                . ' FROM aggtest.source GROUP BY 1'
            );
        }
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * A freshly created view is not refreshing yet — the state the bug leaves.
     */
    public function testAFreshViewHasNoRefreshPolicy(): void
    {
        // Arrange
        $this->createRollup();

        // Act + Assert
        $this->assertTrue($this->schema->hasView(self::VIEW), 'the view is there');
        $this->assertFalse(
            $this->schema->hasContinuousAggregatePolicy(self::VIEW),
            'and nothing is refreshing it'
        );
    }

    /**
     * Applying the declaration gives it one.
     */
    public function testApplyingTheDeclarationRegistersTheRefresh(): void
    {
        // Arrange
        $this->createRollup();

        // Act
        $done = ContinuousAggregateRegistry::apply($this->schema, self::VIEW);

        // Assert
        $this->assertNotEmpty($done);
        $this->assertTrue($this->schema->hasContinuousAggregatePolicy(self::VIEW));
    }

    /**
     * Doing it again changes nothing and does not raise.
     *
     * `add_continuous_aggregate_policy()` errors on a duplicate rather than
     * no-opping, so a repair without this guard would work exactly once — and
     * fail every time an operator ran it afterwards, which is precisely when
     * they would be told to.
     */
    public function testASecondApplyIsANoOp(): void
    {
        // Arrange
        $this->createRollup();
        ContinuousAggregateRegistry::apply($this->schema, self::VIEW);

        // Act
        $second = ContinuousAggregateRegistry::apply($this->schema, self::VIEW);
        $third  = ContinuousAggregateRegistry::apply($this->schema, self::VIEW);

        // Assert
        $this->assertSame([], $second);
        $this->assertSame([], $third);
    }

    /**
     * A view this installation does not have is skipped, not created.
     */
    public function testAnAbsentViewIsLeftAlone(): void
    {
        // Arrange — nothing created
        $this->dropProbe();

        // Act
        $done = ContinuousAggregateRegistry::apply($this->schema, self::VIEW);

        // Assert
        $this->assertSame([], $done);
        $this->assertFalse($this->schema->hasView(self::VIEW));
    }

    /**
     * On TimescaleDB the policy is a real background job.
     *
     * And it cannot be found by the view's name: `timescaledb_information.jobs`
     * records the *materialization* hypertable
     * (`_timescaledb_internal._materialized_hypertable_N`), so the lookup has to
     * go through `continuous_aggregates`. A check written the obvious way
     * answers "no policy" for every aggregate that has one — and a repair built
     * on it would try to add a second every time it ran.
     */
    public function testOnTimescaleDbThePolicyIsABackgroundJob(): void
    {
        if (!$this->hasTimescale) {
            $this->markTestSkipped('This assertion is about the TimescaleDB path');
        }

        // Arrange
        $this->createRollup();
        ContinuousAggregateRegistry::apply($this->schema, self::VIEW);

        // Act
        $jobs = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM timescaledb_information.jobs j
               JOIN timescaledb_information.continuous_aggregates c
                 ON j.hypertable_schema = c.materialization_hypertable_schema
                AND j.hypertable_name   = c.materialization_hypertable_name
              WHERE j.proc_name = 'policy_refresh_continuous_aggregate'
                AND c.view_schema = 'aggtest' AND c.view_name = 'rollup'"
        )->fields['cnt'];

        // Assert
        $this->assertSame(1, $jobs);
    }

    /**
     * On plain PostgreSQL the policy is a row the policy engine executes.
     *
     * This is the case the bug was about. The database used here is one on the
     * same server with the TimescaleDB extension dropped — which is exactly what
     * an installation without it looks like, rather than a simulation of one.
     */
    public function testOnPlainPostgresThePolicyIsASoftwareRow(): void
    {
        // Arrange — a genuinely extension-less database
        // WITH (FORCE): this test's own earlier connection may still be open,
        // and PostgreSQL refuses to drop a database anyone is attached to.
        $this->db->query('DROP DATABASE IF EXISTS pramnos_aggplain WITH (FORCE)');
        $this->db->query('CREATE DATABASE pramnos_aggplain');

        $this->connect('pramnos_aggplain');
        $this->db->query('DROP EXTENSION IF EXISTS timescaledb CASCADE');
        $this->hasTimescale = false;

        $this->db->query('CREATE SCHEMA IF NOT EXISTS pramnos');
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS pramnos.framework_policies ('
            . 'policyid SERIAL PRIMARY KEY, policy_type VARCHAR(50), target VARCHAR(255), '
            . 'config TEXT, enabled SMALLINT DEFAULT 1, created_at TIMESTAMPTZ DEFAULT NOW())'
        );

        try {
            $this->createRollup();

            $this->assertFalse(
                $this->schema->hasContinuousAggregatePolicy(self::VIEW),
                'precondition: a materialized view PostgreSQL will never refresh'
            );

            // Act
            ContinuousAggregateRegistry::apply($this->schema, self::VIEW);

            // Assert
            $this->assertTrue($this->schema->hasContinuousAggregatePolicy(self::VIEW));

            $row = $this->db->queryBuilder()
                ->table('pramnos.framework_policies')
                ->where('target', self::VIEW)
                ->first();

            $this->assertSame('aggregate_refresh', $row->fields['policy_type']);
            $config = json_decode((string) $row->fields['config'], true);
            $this->assertSame('1 hour', $config['schedule_interval']);

            // ...and a second run still changes nothing
            $this->assertSame([], ContinuousAggregateRegistry::apply($this->schema, self::VIEW));
        } finally {
            $this->connect('pramnos_test');
            $this->hasTimescale = $this->db->capabilities()->hasTimescaleDB();
            $this->db->query('DROP DATABASE IF EXISTS pramnos_aggplain WITH (FORCE)');
        }
    }
}
