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

    /**
     * Point a second connection at the same database as the first.
     *
     * The double above overrides `query()`, so it needs its own connection rather than a
     * reference to one — a `Database` is the connection.
     */
    private function copyConnection(Database $db): void
    {
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->connect(false);
    }

    protected function tearDown(): void
    {
        try {
            $this->db->query('DROP SCHEMA IF EXISTS cafallback CASCADE');
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
    }

    /**
     * The version refusal becomes a plain materialised view with the same columns.
     *
     * Injected rather than provoked, and that is worth saying out loud: **no SELECT this
     * framework ships is refused by TimescaleDB 2.26.4.** `COUNT(DISTINCT …)`,
     * `percentile_cont(…) WITHIN GROUP (…)`, `HAVING COUNT(DISTINCT …)` and a join to a
     * plain table were each measured on it and each accepted, and the framework's own
     * migrations run there. The refusal this branch handles was reported from a host whose
     * configuration is not reproduced here.
     *
     * So the branch is driven by making the engine's answer the reported one. A branch
     * that cannot be provoked is a branch that must still be exercised — untested
     * defensive code in a migration path is how a silent downgrade gets shipped.
     */
    public function testTheVersionRefusalBecomesAPlainMaterialisedView(): void
    {
        // Arrange — a connection that answers the reported error to the continuous form
        // and behaves normally otherwise.
        $db = new class () extends Database {
            public int $plainCreates = 0;

            public function query($sql, $cache = false, $cachetime = 60, $category = '',
                $dieOnFatalError = false, $skipDataFix = false
            ) {
                if (str_contains((string) $sql, 'timescaledb.continuous')) {
                    throw new \Exception('ERROR:  invalid continuous aggregate view');
                }
                if (str_starts_with((string) $sql, 'CREATE MATERIALIZED VIEW')) {
                    $this->plainCreates++;
                }

                return parent::query($sql, $cache, $cachetime, $category, $dieOnFatalError, $skipDataFix);
            }
        };
        $this->copyConnection($db);
        $schema = $db->schema();

        // Act
        $schema->createContinuousAggregate(
            'cafallback.rollup',
            "SELECT time_bucket('1 hour', t) AS bucket, SUM(v) AS total
               FROM cafallback.src GROUP BY 1"
        );

        // Assert — the plain form was created…
        $this->assertSame(1, $db->plainCreates);
        $this->assertTrue($this->db->schema()->hasView('cafallback.rollup'));

        // …with every column the SELECT named, which is the whole reason this is a
        // fallback rather than a rewrite.
        // Read from `pg_attribute`, not `information_schema.columns`: PostgreSQL leaves
        // materialised views out of information_schema entirely, so the obvious query
        // answers "no columns" for a view that has them.
        $columns = $this->db->query(
            "SELECT a.attname AS col
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0
              WHERE n.nspname = 'cafallback' AND c.relname = 'rollup'
              ORDER BY a.attnum"
        )->fetchAll();
        $this->assertSame(
            ['bucket', 'total'],
            array_map(static fn(array $r): string => (string) $r['col'], $columns)
        );

        // …and it is the plain form, not an aggregate.
        $this->assertFalse($this->db->schema()->isContinuousAggregate('cafallback.rollup'));
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
