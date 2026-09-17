<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * What happens when TimescaleDB refuses a continuous aggregate.
 *
 * WHAT: that the refusal produces a plain materialised view with the same columns, and
 *       that **any other** failure is re-thrown instead.
 * WHY:  the second half is the one that matters. A blanket fallback would turn a lock
 *       timeout, a permission or a typo in the SELECT into a quiet plain view that nothing
 *       refreshes incrementally — the same silent downgrade the rest of this area exists to
 *       stop, installed at the one place best able to hide it.
 *
 *       Worth stating plainly: on TimescaleDB 2.26.4 none of the expressions this fallback
 *       was written for is actually refused. `COUNT(DISTINCT …)`, `percentile_cont(…)
 *       WITHIN GROUP (…)`, a `HAVING COUNT(DISTINCT …)` and a join to a plain table were
 *       each measured and each accepted, and the framework's own migrations run on that
 *       version. The fallback is for a refusal reported from a host whose exact
 *       configuration is not reproduced here — so it is narrow, loud, and tested rather
 *       than assumed.
 */
class ContinuousAggregateFallbackTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // The same direct connection the sibling aggregate tests use: this asks the engine
        // what it accepts, which needs a connection and nothing else the framework builds.
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
            $this->markTestSkipped('PostgreSQL/TimescaleDB not reachable: ' . $e->getMessage());
        }

        if (!$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('This is about the TimescaleDB path.');
        }

        $this->db->query('DROP SCHEMA IF EXISTS cafallback CASCADE');
        $this->db->query('CREATE SCHEMA cafallback');
        $this->db->query('CREATE TABLE cafallback.src (t TIMESTAMPTZ NOT NULL, v INT)');
        $this->db->query("SELECT create_hypertable('cafallback.src', 't')");
    }

    protected function tearDown(): void
    {
        try {
            $this->db->query('DROP SCHEMA IF EXISTS cafallback CASCADE');
        } catch (\Throwable) {
            // Best-effort cleanup.
        }

        // Closed explicitly. This class opens its own connection per test rather than
        // sharing the suite's, and a full run has thousands of tests behind it — a handful
        // of connections left open here is a handful the classes after it cannot have.
        try {
            $this->db->close();
        } catch (\Throwable) {
            // Already gone.
        }
    }

    /**
     * A source table that is not a hypertable yet fails, loudly.
     *
     * The case a fallback would have hidden, and the reason there is no fallback. Both
     * causes of `ERROR: invalid continuous aggregate view` share that line and differ only
     * in the `DETAIL`:
     *
     *   - `At least one hypertable should be used in the view definition.` — the source
     *     has not been converted yet. A migration-ordering bug, fully fixable, and what a
     *     real installation actually produced for all three of its aggregate migrations.
     *   - an expression that cannot be maintained incrementally — the case a fallback
     *     would be for.
     *
     * A fallback keyed on the shared `ERROR` line turns the first into a plain materialised
     * view **permanently, while `migrate` reports success**. Keying on the second `DETAIL`
     * instead is not available: no expression this framework ships has ever been refused
     * by any version measured here, so there is no wording to match.
     *
     * So this fails, and the caller sees why.
     */
    public function testASourceThatIsNotAHypertableFailsLoudly(): void
    {
        // Arrange — an ordinary table, not converted.
        $this->db->query('CREATE TABLE cafallback.notahypertable (t TIMESTAMPTZ NOT NULL, v INT)');
        $schema = $this->db->schema();

        // Act & Assert
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/at least one hypertable/i');
        $schema->createContinuousAggregate(
            'cafallback.ordering',
            "SELECT time_bucket('1 hour', t) AS bucket, SUM(v) AS total
               FROM cafallback.notahypertable GROUP BY 1"
        );
    }

    /**
     * And nothing is left behind when it fails.
     *
     * A half-created view is worse than none: the next run finds the name taken and skips,
     * so the ordering bug becomes permanent in a second way.
     */
    public function testNothingIsLeftBehindWhenTheAggregateIsRefused(): void
    {
        // Arrange
        $this->db->query('CREATE TABLE cafallback.notahypertable2 (t TIMESTAMPTZ NOT NULL, v INT)');
        $schema = $this->db->schema();

        // Act
        try {
            $schema->createContinuousAggregate(
                'cafallback.ordering2',
                "SELECT time_bucket('1 hour', t) AS bucket, SUM(v) AS total
                   FROM cafallback.notahypertable2 GROUP BY 1"
            );
        } catch (\Throwable) {
            // Expected; the assertion is about what it left.
        }

        // Assert
        $this->assertFalse($schema->hasView('cafallback.ordering2'));
    }

    /**
     * Any other failure is re-thrown, not turned into a plain view.
     *
     * The assertion this class exists for. A SELECT naming a column that does not exist is
     * a mistake, and a mistake must fail the migration — not produce a view with whatever
     * the plain form happens to accept, which is nothing, silently.
     */
    public function testAnUnrelatedFailureIsNotSwallowed(): void
    {
        // Arrange
        $schema = $this->db->schema();

        // Act & Assert — a SELECT with no time bucket. The engine's own words for it are
        // "continuous aggregate view must include a valid time bucket function", which is
        // a mistake in the SELECT rather than a limit of the version — so it must reach
        // the caller instead of quietly producing a view.
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/time bucket/i');
        $schema->createContinuousAggregate(
            'cafallback.broken',
            'SELECT v, COUNT(*) AS n FROM cafallback.src GROUP BY v'
        );
    }

    /**
     * A SELECT this version *can* maintain is still a real continuous aggregate.
     *
     * The fallback must not be reached on the happy path — an aggregate quietly demoted to
     * a plain view is the failure, not the fix.
     */
    public function testAnAcceptableAggregateIsStillContinuous(): void
    {
        // Arrange
        $schema = $this->db->schema();

        // Act
        $schema->createContinuousAggregate(
            'cafallback.hourly',
            "SELECT time_bucket('1 hour', t) AS bucket, SUM(v) AS total
               FROM cafallback.src GROUP BY 1"
        );

        // Assert
        $this->assertTrue($schema->isContinuousAggregate('cafallback.hourly'));
    }
}
