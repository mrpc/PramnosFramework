<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Does the framework agree with the database about whether a refresh policy exists?
 *
 * WHAT: create a continuous aggregate, add a refresh policy through TimescaleDB
 *       itself, then ask the framework. It must say yes.
 * WHY:  `hasContinuousAggregatePolicy()` exists to make policy creation idempotent,
 *       and on TimescaleDB 2.26 it had never once returned true — so the repair
 *       re-added a policy that already existed, on every schedule cycle, producing
 *       three stack traces per cycle and an errors counter in every worker's lock
 *       file that could never read zero.
 *
 * **Nothing was broken, which is exactly why it would never have been fixed.** The
 * cost was that a real fault had to compete with it for attention — and the
 * deployment that reported it had just spent an afternoon chasing a restart loop that
 * turned out to be something else entirely.
 *
 * ## Why this asks the database rather than reading the query
 *
 * The bug was a join whose premise had expired. `timescaledb_information.jobs`
 * records the *materialization* hypertable on 2.19.3 and the aggregate's own *view*
 * name on 2.26.4 — measured, both — and the framework joined on the first and
 * asserted in a docblock that the second was impossible.
 *
 * A guard on the join's spelling would only have asserted that the code says what it
 * says. It would have passed on every version and caught nothing. So this asks
 * TimescaleDB for an aggregate that provably has a policy and fails the day the
 * framework disagrees, whichever pairing the running version happens to report.
 */
class ContinuousAggregatePolicyDetectionTest extends TestCase
{
    private Database $db;

    /** @var \Pramnos\Database\SchemaBuilder */
    private $schema;

    private const VIEW = 'polprobe.rollup';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->port     = 5432;
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('PostgreSQL/TimescaleDB not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('TimescaleDB not reachable: ' . $e->getMessage());
        }

        $this->schema = $this->db->schema();

        if (!$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('This asks a TimescaleDB-specific question.');
        }

        $this->drop();
    }

    protected function tearDown(): void
    {
        try {
            $this->drop();
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
        parent::tearDown();
    }

    private function drop(): void
    {
        $this->db->query('DROP MATERIALIZED VIEW IF EXISTS ' . self::VIEW . ' CASCADE');
        $this->db->query('DROP TABLE IF EXISTS polprobe.source CASCADE');
        $this->db->query('DROP SCHEMA IF EXISTS polprobe CASCADE');
    }

    /** Create the aggregate, without any refresh policy. */
    private function createAggregate(): void
    {
        $this->db->query('CREATE SCHEMA IF NOT EXISTS polprobe');
        $this->db->query('CREATE TABLE polprobe.source (t TIMESTAMPTZ NOT NULL, v INT)');
        $this->db->query("SELECT create_hypertable('polprobe.source', 't')");
        $this->db->query(
            'CREATE MATERIALIZED VIEW ' . self::VIEW
            . " WITH (timescaledb.continuous) AS SELECT time_bucket('1 hour', t) AS bucket,"
            . ' SUM(v) AS total FROM polprobe.source GROUP BY 1 WITH NO DATA'
        );
    }

    /** Add a policy through TimescaleDB itself, so the framework had no part in it. */
    private function addPolicyDirectly(): void
    {
        $this->db->query(
            "SELECT add_continuous_aggregate_policy('" . self::VIEW . "',"
            . " start_offset => INTERVAL '3 hours',"
            . " end_offset => INTERVAL '1 hour',"
            . " schedule_interval => INTERVAL '1 hour')"
        );
    }

    /**
     * The framework must see a policy that TimescaleDB created.
     *
     * The whole filing in one assertion. On 2.26 this returned false forever, so the
     * idempotence guard was never taken and the repair ran on every cycle.
     */
    public function testTheFrameworkSeesAPolicyTimescaleCreated(): void
    {
        // Arrange
        $this->createAggregate();
        $this->addPolicyDirectly();

        // Act
        $seen = $this->schema->hasContinuousAggregatePolicy(self::VIEW);

        // Assert
        $this->assertTrue(
            $seen,
            'TimescaleDB has a refresh policy for ' . self::VIEW . ' and the framework cannot see it'
        );
    }

    /**
     * And must not see one where there is none.
     *
     * Without this the previous assertion is satisfied by a check that always says
     * yes — the mirror of the bug rather than a fix for it.
     */
    public function testTheFrameworkSeesNoPolicyWhereThereIsNone(): void
    {
        // Arrange
        $this->createAggregate();

        // Act & Assert
        $this->assertFalse($this->schema->hasContinuousAggregatePolicy(self::VIEW));
    }

    /**
     * Whichever pairing this TimescaleDB reports, the framework's answer matches the
     * database's own.
     *
     * Compared against both joins rather than against the one this version happens to
     * use, so the test states the real invariant: the framework agrees with the
     * database. It keeps holding when a future version changes the columns again —
     * which is the thing that broke this in the first place.
     */
    public function testTheFrameworkAgreesWithWhicheverPairingThisVersionReports(): void
    {
        // Arrange
        $this->createAggregate();
        $this->addPolicyDirectly();

        [$viewSchema, $viewName] = explode('.', self::VIEW, 2);

        $count = static function (Database $db, string $on, string $s, string $n): int {
            $result = $db->query($db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                   FROM timescaledb_information.jobs j
                   JOIN timescaledb_information.continuous_aggregates c ON " . $on . "
                  WHERE j.proc_name = 'policy_refresh_continuous_aggregate'
                    AND c.view_schema = %s AND c.view_name = %s",
                $s,
                $n
            ));

            return $result ? (int) ($result->fields['cnt'] ?? 0) : 0;
        };

        $byMaterialization = $count(
            $this->db,
            'j.hypertable_schema = c.materialization_hypertable_schema
             AND j.hypertable_name = c.materialization_hypertable_name',
            $viewSchema,
            $viewName
        );

        $byViewName = $count(
            $this->db,
            'j.hypertable_schema = c.view_schema AND j.hypertable_name = c.view_name',
            $viewSchema,
            $viewName
        );

        // Assert — exactly one pairing matches on any given version, and the
        // framework's answer follows whichever it is.
        $this->assertSame(
            1,
            $byMaterialization + $byViewName,
            'expected exactly one pairing to identify the job; the view definitions may have '
            . 'changed again and hasContinuousAggregatePolicy() needs the new one'
        );
        $this->assertTrue($this->schema->hasContinuousAggregatePolicy(self::VIEW));
    }
}
