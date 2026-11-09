<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Stats\CreateMetricValuesTable;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Store;
use Pramnos\Metrics\Window;

/**
 * The metrics' values on TimescaleDB: each grain's table a hypertable with its own chunk interval
 * and compression, and a closed period rewritten in place after its chunk is compressed — the
 * direct DML of 2.11+, with the decompression limit lifted for that transaction alone. In a
 * database of its own with the extension, as the suite's PostgreSQL database has none.
 */
#[CoversClass(Store::class)]
#[CoversClass(Metrics::class)]
#[CoversClass(CreateMetricValuesTable::class)]
final class MetricsTimescaleDBTest extends TestCase
{
    private const PROBE = 'pramnos_metrics_ts_probe';

    private ?Database $db = null;

    private ?Database $previous = null;

    private array $info = [];

    /** The day's count a source answers. */
    private int $count = 1;

    /** A connection to `$name` on the TimescaleDB container. */
    private static function connection(string $name): Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = $name;

        return $db;
    }

    protected function setUp(): void
    {
        $admin = self::connection(TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped('timescaledb not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('timescaledb not reachable: ' . $e->getMessage());
        }
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->query('CREATE DATABASE ' . self::PROBE);
        $admin->close();
        $this->db = self::connection(self::PROBE);
        $this->db->connect(false);
        $this->db->query('CREATE EXTENSION IF NOT EXISTS timescaledb');
        if (!$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('No TimescaleDB on this connection.');
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
        \Pramnos\Framework\Testing\Schema::ensure([CreateMetricValuesTable::class], $this->db);
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'Europe/Athens'];
        Metrics::reset();
        Metrics::register(Metric::flow('test.count', 'Count')->source(fn (): int => $this->count));
    }

    protected function tearDown(): void
    {
        Metrics::reset();
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
            Application::getInstance()->applicationInfo = $this->info;
        }
        if ($this->db !== null) {
            $this->db->close();
            $admin = self::connection(TEST_DATABASE);
            if ($admin->connect(false)) {
                $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
                $admin->close();
            }
        }
    }

    /**
     * Every grain is a hypertable; all but the year compress, by metric, tenant and breakdown. A
     * closed month, its chunk compressed, computed again: its value replaced in place, once.
     */
    public function testEachGrainIsAHypertableAndAClosedMonthIsRewrittenCompressed(): void
    {
        // Arrange
        $schema = $this->db->schema();
        Metrics::compute(['test.count'], ['month'], '2025-10-01', '2025-10-31', false, (int) strtotime('2026-11-06 12:00 UTC'));
        // Raw: compressing a chunk is TimescaleDB's own function.
        $this->db->query("SELECT compress_chunk(c, true) FROM show_chunks('stats.metric_values_month') c");
        $compressed = (int) $this->db->query("SELECT count(*) AS n FROM timescaledb_information.chunks WHERE hypertable_name = 'metric_values_month' AND is_compressed")->fetch()['n'];
        $this->count = 7;

        // Act
        Metrics::compute(['test.count'], ['month'], '2025-10-01', '2025-10-31', true, (int) strtotime('2026-11-06 13:00 UTC'));
        $done = Store::ensure($this->db);

        // Assert
        foreach (Window::GRAINS as $grain) {
            $this->assertTrue($schema->hasHypertable(Store::table($grain)), $grain . ' is a hypertable');
        }
        $settings = $this->db->query("SELECT count(*) AS n FROM timescaledb_information.hypertables WHERE hypertable_schema = 'stats' AND compression_enabled")->fetch()['n'];
        $this->assertSame(5, (int) $settings, 'every grain but the year compresses');
        $this->assertGreaterThan(0, $compressed, 'the month was compressed before it was rewritten');
        $this->assertSame(7.0, Metrics::value('test.count', 'month', '2025-10-01'), 'rewritten in the compressed chunk');
        $this->assertSame(1, $this->db->queryBuilder()->table(Store::table('month'))->where('metric', 'test.count')->count(), 'replaced, not added');
        $this->assertSame([], $done, 'a second ensure has nothing to do');
    }

    /**
     * A live metric over a hypertable gets an hourly continuous aggregate when metrics are
     * computed, and is read from it — the hours not refreshed yet too — with the same figures its
     * source gives, by any of its dimensions; a source that is not a hypertable gets none.
     */
    public function testALiveMetricIsReadFromItsHourlyAggregate(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->createTable('flows', function ($table) {
            $table->timestampTz('at');
            $table->string('device', 4);
            $table->integer('litres');
        });
        $schema->createHypertable('flows', 'at', ['chunk_time_interval' => '7 days']);
        $schema->createTable('plain_flows', function ($table) {
            $table->timestampTz('at');
            $table->integer('litres');
        });
        foreach ([['2026-09-02 10:10', 'd1', 3], ['2026-09-02 10:40', 'd2', 4], ['2026-09-02 13:00', 'd1', 5]] as [$at, $device, $litres]) {
            $this->db->queryBuilder()->table('flows')->insert(['at' => \Pramnos\Database\Instant::utc((int) strtotime($at . ' UTC'), $this->db), 'device' => $device, 'litres' => $litres]);
        }
        $live  = Metric::flow('test.flows', 'Water')->from('flows')->at('at')->sum('litres')->dimension('device', 'device')->live();
        $plain = Metric::flow('test.plain_flows')->from('plain_flows')->at('at')->sum('litres')->live();
        Metrics::register($live, $plain);
        Application::getInstance()->applicationInfo['metrics']['timezone'] = 'UTC';

        // Act
        Metrics::compute(null, ['day'], null, null, false, (int) strtotime('2026-11-06 12:00 UTC'));
        $byDevice = Metrics::query('test.flows')->grain('day')->at('2026-09-02')->by('device')->series()['values'];
        $hour     = Metrics::query('test.flows')->grain('hour')->at('2026-09-02 10:00')->value();
        // Materialised, then the source emptied: what is read now can only come from the aggregate.
        $this->db->query("CALL refresh_continuous_aggregate('" . $schema->quoteTable(Store::liveView($live)) . "', '2026-09-01', '2026-09-03')");
        $this->db->query('DELETE FROM flows');
        $kept     = Metrics::query('test.flows')->grain('day')->at('2026-09-02')->value();

        // Assert
        $this->assertTrue($schema->hasView(Store::liveView($live)), 'its hourly aggregate made');
        $this->assertFalse($schema->hasView(Store::liveView($plain)), 'not over a table that is no hypertable');
        $this->assertSame(['d1' => [8.0], 'd2' => [4.0]], $byDevice, 'read from the aggregate, not yet refreshed');
        $this->assertSame(7.0, $hour);
        $this->assertSame(12.0, $kept, 'from the aggregate, the source gone');
        $this->assertTrue(Store::liveAggregate($this->db, $live), 'made once, there after');
    }
}
