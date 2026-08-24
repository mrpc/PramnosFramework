<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\HypertableRegistry;
use Pramnos\Framework\Factory;

/**
 * The change log's three tables, its view, and the policies that keep them affordable.
 *
 * Three tables because a TimescaleDB retention policy drops whole chunks by time and
 * takes no row predicate — so one table can only ever have one retention, and these three
 * populations have genuinely different answers to how long they are worth keeping. The
 * assertions that matter are the ones comparing them: three tables sharing an interval
 * would pass every "does it work" test and defeat the entire reason for the split.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class ChangelogMigrationTimescaleDBTest extends TestCase
{
    private Database $db;
    private $schema;
    private ?Database $previousSingleton = null;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('TimescaleDB not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('TimescaleDB not reachable: ' . $e->getMessage());
        }

        $this->schema = $this->db->schema();
        if (!$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('The TimescaleDB extension is not installed here');
        }

        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        $this->drop();
        $this->db->query('CREATE SCHEMA IF NOT EXISTS pramnos');
    }

    protected function tearDown(): void
    {
        $this->drop();
        HypertableRegistry::reset();

        $singleton = &Factory::getDatabase();
        $singleton = $this->previousSingleton;
    }

    private function drop(): void
    {
        $this->db->query('DROP VIEW IF EXISTS pramnos.changelog_history CASCADE');
        foreach (['changelog_trace', 'changelog_events', 'changelog'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS pramnos.' . $table . ' CASCADE');
        }
    }

    /**
     * Run the migration under test.
     */
    private function migrate(): void
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        // Through the loader the migration runner uses, rather than a hand-built
        // instance: it is what supplies the Application the constructor requires, and a
        // test that constructs migrations its own way stops testing the path that runs
        // in production.
        $migrations = \Pramnos\Database\MigrationLoader::loadFromDirectory(
            ROOT . \DS . 'database' . \DS . 'migrations' . \DS . 'framework' . \DS . 'changelog',
            $app
        );

        foreach ($migrations as $migration) {
            if ((new \ReflectionClass($migration))->getShortName() === 'CreateChangelogTables') {
                $migration->up();

                return;
            }
        }

        $this->fail('CreateChangelogTables was not found by the migration loader');
    }

    private function isHypertable(string $table): bool
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM timescaledb_information.hypertables
              WHERE hypertable_schema = 'pramnos' AND hypertable_name = '" . $table . "'"
        );

        return (int) $result->fields['cnt'] > 0;
    }

    // -------------------------------------------------------------------------
    // Shape
    // -------------------------------------------------------------------------

    /**
     * All three tables and the view are created, and all three are hypertables.
     */
    public function testTheThreeTablesAndTheViewAreCreated(): void
    {
        // Act
        $this->migrate();

        // Assert
        foreach (['changelog', 'changelog_events', 'changelog_trace'] as $table) {
            $this->assertTrue(
                $this->schema->hasTable('pramnos.' . $table),
                $table . ' must exist'
            );
            $this->assertTrue($this->isHypertable($table), $table . ' must be partitioned');
        }

        $this->assertTrue($this->schema->hasView('pramnos.changelog_history'));
    }

    /**
     * Every key is 64-bit.
     *
     * An append-only log with a 32-bit key is a cliff at 2.1 billion rows, and widening
     * one afterwards means decompressing every chunk and rebuilding the primary key. The
     * reference application wrote that migration; this assertion is why nobody has to
     * write it again.
     */
    public function testTheKeysAre64Bit(): void
    {
        // Act
        $this->migrate();

        // Assert
        foreach ([['changelog', 'logid'], ['changelog_events', 'eventid']] as [$table, $column]) {
            $result = $this->db->query(
                "SELECT data_type FROM information_schema.columns
                  WHERE table_schema = 'pramnos' AND table_name = '" . $table . "'
                    AND column_name = '" . $column . "'"
            );
            $this->assertSame('bigint', $result->fields['data_type'], $table . '.' . $column);
        }
    }

    /**
     * The trace table carries the feed row's natural key, not a surrogate.
     *
     * A surrogate would have to be generated before the row exists — the spool does not
     * insert until the drain — which means a database round trip per change, inside the
     * request, undoing the 0.003 ms append the whole design is built on.
     */
    public function testTheTraceTableJoinsOnTheNaturalKey(): void
    {
        // Act
        $this->migrate();

        // Assert
        $result = $this->db->query(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema = 'pramnos' AND table_name = 'changelog_trace'"
        );
        $columns = [];
        while ($result->fetch()) {
            $columns[] = $result->fields['column_name'];
        }

        $this->assertContains('entity', $columns);
        $this->assertContains('itemid', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertNotContains('logid', $columns,
            'a surrogate key here would cost a database round trip per change');
    }

    // -------------------------------------------------------------------------
    // The view
    // -------------------------------------------------------------------------

    /**
     * The view returns both populations, tagged, newest first.
     *
     * Read-only on purpose: writes go to the tables directly, which is what keeps it
     * portable — MySQL has no updatable views of the kind that would otherwise be needed.
     */
    public function testTheViewMergesBothPopulations(): void
    {
        // Arrange
        $this->migrate();
        $this->db->query(
            "INSERT INTO pramnos.changelog (entity, itemid, op, changes, source, created_at)
             VALUES ('device', '42', 'updated', '{\"status\":{\"old\":1,\"new\":3}}', 'web', NOW() - INTERVAL '1 hour')"
        );
        $this->db->query(
            "INSERT INTO pramnos.changelog_events (entity, itemid, event, source, created_at)
             VALUES ('device', '42', 'device.assigned', 'web', NOW())"
        );

        // Act
        $result = $this->db->query(
            "SELECT origin, event, op FROM pramnos.changelog_history
              WHERE entity = 'device' AND itemid = '42'
              ORDER BY created_at DESC"
        );
        $rows = [];
        while ($result->fetch()) {
            $rows[] = $result->fields;
        }

        // Assert
        $this->assertCount(2, $rows);
        $this->assertSame('events', $rows[0]['origin']);
        $this->assertSame('device.assigned', $rows[0]['event']);
        $this->assertSame('feed', $rows[1]['origin']);
        $this->assertSame('updated', $rows[1]['op']);
    }

    // -------------------------------------------------------------------------
    // Policies — the reason there are three tables
    // -------------------------------------------------------------------------

    /**
     * Each table gets its own retention, and no two are the same.
     *
     * **The assertion the split exists for.** Three tables sharing one interval would
     * pass every other test in this file while defeating the entire design — and the way
     * that happens is somebody tidying three declarations into one.
     */
    public function testEachTableKeepsItsRowsForADifferentLength(): void
    {
        // Arrange
        $this->migrate();

        // Act — the registry owns retention, so applying it is what installs the policies
        foreach (['changelog', 'changelog_events', 'changelog_trace'] as $table) {
            HypertableRegistry::apply($this->schema, 'pramnos.' . $table);
        }

        // Assert
        $intervals = [];
        foreach (['changelog', 'changelog_events', 'changelog_trace'] as $table) {
            $interval = $this->schema->policyInterval('pramnos.' . $table, 'retention');
            $this->assertNotNull($interval, $table . ' must have a retention policy');
            $intervals[$table] = $interval;
        }

        $this->assertCount(3, array_unique($intervals),
            'three tables sharing one retention is the design failing silently: '
            . json_encode($intervals));
    }

    /**
     * The trace table has no compression policy.
     *
     * At three days nothing lives long enough for compression to repay the CPU it costs.
     * Asserted because "add compression everywhere" is the obvious tidy-up.
     */
    public function testTheTraceTableIsNotCompressed(): void
    {
        // Arrange
        $this->migrate();

        // Act
        HypertableRegistry::apply($this->schema, 'pramnos.changelog_trace');

        // Assert
        $this->assertFalse(
            $this->schema->hasCompressionPolicy('pramnos.changelog_trace'),
            'a table dropped after three days must not pay to compress'
        );
    }

    /**
     * The two long-lived tables are compressed.
     */
    public function testTheLongLivedTablesAreCompressed(): void
    {
        // Arrange
        $this->migrate();

        // Act
        HypertableRegistry::apply($this->schema, 'pramnos.changelog');
        HypertableRegistry::apply($this->schema, 'pramnos.changelog_events');

        // Assert
        $this->assertTrue($this->schema->hasCompressionPolicy('pramnos.changelog'));
        $this->assertTrue($this->schema->hasCompressionPolicy('pramnos.changelog_events'));
    }

    /**
     * Running the migration twice changes nothing.
     *
     * Every step is guarded, and a migration that only works against a database which has
     * never seen it is not a migration.
     */
    public function testRunningItTwiceIsSafe(): void
    {
        // Arrange & Act
        $this->migrate();
        $this->migrate();

        // Assert
        $this->assertTrue($this->schema->hasTable('pramnos.changelog'));
        $this->assertTrue($this->schema->hasView('pramnos.changelog_history'));
    }
}
