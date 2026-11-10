<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Store;

/**
 * A distinct metric from daily HyperLogLog sketches (`Store::sketched()`), on TimescaleDB with its
 * toolkit — `./dockertest --toolkit`: a continuous aggregate for each breakdown the metric keeps,
 * and a month that is the days' sketches rolled up, not their sum — the people of three days
 * counted once — with each breakdown alike; within the sketch's error of the exact count.
 */
#[Group('toolkit')]
#[CoversClass(Store::class)]
#[CoversClass(Metrics::class)]
class SketchToolkitTest extends TestCase
{
    /** The hits the metric counts. */
    private const TABLE = 'test_sketch_hits';

    private ?Database $db = null;

    private ?Database $previous = null;

    /** @var array<string, mixed> */
    private array $info = [];

    /** The toolkit's TimescaleDB, made the application's, with the extensions and a hypertable of hits. */
    protected function setUp(): void
    {
        $this->info   = Application::getInstance()->applicationInfo;
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb-toolkit';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('The toolkit\'s TimescaleDB is not reachable: ./dockertest --toolkit');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('The toolkit\'s TimescaleDB is not reachable: ./dockertest --toolkit (' . $e->getMessage() . ')');
        }
        $this->db = $db;
        // Raw: extensions, and a hypertable made for the test.
        $db->query('CREATE EXTENSION IF NOT EXISTS timescaledb');
        $db->query('CREATE EXTENSION IF NOT EXISTS timescaledb_toolkit');
        foreach ($db->schema()->getContinuousAggregates('stats') as $view) {
            $db->query('DROP MATERIALIZED VIEW IF EXISTS stats.' . $view->view_name . ' CASCADE');
        }
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE . ' CASCADE');
        $db->query('CREATE TABLE ' . self::TABLE . ' (at timestamptz NOT NULL, visitor bigint NOT NULL, device text, flags int NOT NULL DEFAULT 0)');
        $db->query("SELECT create_hypertable('" . self::TABLE . "', 'at', chunk_time_interval => INTERVAL '1 day')");
        $this->previous = Database::getInstance();
        $reference = &Database::getInstance();
        $reference = $db;
        Store::forgetReady();
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        Metrics::reset();
    }

    /** The application's database and settings given back. */
    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        Metrics::reset();
        Store::forgetReady();
        if ($this->previous !== null) {
            $reference = &Database::getInstance();
            $reference = $this->previous;
        }
    }

    /**
     * Three days, thirty people: 1–20, 11–30, 1–5. The month is the thirty, not the forty-five of
     * the days added, and by device fifteen and fifteen; each day as it was; a robot's row left
     * out by the metric's own condition. Read from the sketches' aggregates, which are there.
     */
    public function testAMonthIsTheDaysSketchesRolledUp(): void
    {
        // Arrange
        $days = ['2026-10-05' => range(1, 20), '2026-10-06' => range(11, 30), '2026-10-07' => range(1, 5)];
        foreach ($days as $day => $people) {
            foreach ($people as $person) {
                $this->db->queryBuilder()->table(self::TABLE)->insert(['at' => $day . ' 10:00:00+00', 'visitor' => $person,
                    'device' => $person % 2 === 1 ? 'mobile' : 'desktop', 'flags' => 0]);
            }
        }
        $this->db->queryBuilder()->table(self::TABLE)->insert(['at' => '2026-10-05 11:00:00+00', 'visitor' => 999, 'device' => 'mobile', 'flags' => 1]);
        Metrics::register(Metric::flow('test.sketch', 'People')->from(self::TABLE)->at('at')->countDistinct('visitor')
            ->dimension('device', 'device')->where('flags', [0])->grains(['day', 'month']));
        $within = static fn (float $exact, ?float $read): bool => $read !== null && abs($read - $exact) <= max(1.0, $exact * 0.02);

        // Act
        Metrics::compute(['test.sketch'], ['day', 'month'], '2026-10-05', '2026-10-07', true, (int) gmmktime(12, 0, 0, 11, 10, 2026));
        $month   = Metrics::value('test.sketch', 'month', '2026-10-01');
        $mobile  = Metrics::value('test.sketch', 'month', '2026-10-01', ['device' => 'mobile']);
        $desktop = Metrics::value('test.sketch', 'month', '2026-10-01', ['device' => 'desktop']);
        $daily   = array_map(static fn (string $day): ?float => Metrics::value('test.sketch', 'day', $day), array_keys($days));

        // Assert
        $this->assertTrue(Store::sketched($this->db, Metrics::get('test.sketch')), 'the sketches are made and read');
        $this->assertTrue($this->db->schema()->hasView(Store::sketchView(Metrics::get('test.sketch'), ['device'])));
        $this->assertTrue($within(30, $month), 'thirty people, not forty-five: ' . var_export($month, true));
        $this->assertTrue($within(15, $mobile) && $within(15, $desktop), 'by device alike: ' . var_export([$mobile, $desktop], true));
        foreach ([20, 20, 5] as $i => $exact) {
            $this->assertTrue($within($exact, $daily[$i]), 'day ' . $i . ': ' . var_export($daily[$i], true));
        }
    }
}
