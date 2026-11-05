<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Stats\CreateMetricValuesTable;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * Metrics against the real database — MySQL here, PostgreSQL in the sibling: computing from a
 * table of orders, keeping values per period, closing periods, levels, history and reading back.
 *
 * What holds: a sum's breakdowns add up and are read in any combination; a distinct's month is
 * counted on its own, not as its days added; an open period is replaced at each computation and a
 * closed one is kept unless recomputed; a level is kept as it stood when its period closed and
 * never computed for an older one; imported history is final and read like the rest; nothing is
 * written for a period that has not begun; a derived metric is worked out when read.
 */
#[CoversClass(Metrics::class)]
#[CoversClass(CreateMetricValuesTable::class)]
#[CoversClass(\Pramnos\Console\Commands\MetricsCompute::class)]
class MetricsMySQLTest extends TestCase
{
    /** The orders a source counts. */
    protected const ORDERS = 'metrics_test_orders';

    protected Database $db;

    private array $info;

    /** How many customers the level says are active. */
    private int $active = 40;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        \Pramnos\Framework\Testing\Schema::ensure([CreateMetricValuesTable::class], $this->db);
        $schema = $this->db->schema();
        $schema->dropTableIfExists(self::ORDERS);
        $schema->createTable(self::ORDERS, function ($table) {
            $table->increments('id');
            $table->integer('placed_at');
            $table->string('region', 20);
            $table->integer('customer');
        });
        $this->db->queryBuilder()->table(Metrics::TABLE)->where('metric', 'like', 'test.%')->delete();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'Europe/Athens'];
        Metrics::reset();
        $this->declare();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table(Metrics::TABLE)->where('metric', 'like', 'test.%')->delete();
        $this->db->schema()->dropTableIfExists(self::ORDERS);
        Application::getInstance()->applicationInfo = $this->info;
        Metrics::reset();
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }

        return $db;
    }

    /** Orders, counted three ways, a level, and a ratio. */
    private function declare(): void
    {
        $orders = function (Window $w, array $by, Database $db): array {
            $query = $db->queryBuilder()->table(self::ORDERS);

            return $query->select([...$by, $query->raw('COUNT(*) AS value')])
                ->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())
                ->groupBy($by)->getAllOrFail();
        };
        $customers = function (Window $w, array $by, Database $db): array {
            $query = $db->queryBuilder()->table(self::ORDERS);

            return $query->select([...$by, $query->raw('COUNT(DISTINCT customer) AS value')])
                ->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())
                ->groupBy($by)->getAllOrFail();
        };
        Metrics::register(
            Metric::flow('test.orders', 'Orders')->dimensions(['region'])->source($orders),
            Metric::flow('test.customers', 'Customers ordering')->aggregate(Metric::DISTINCT)->dimensions(['region'])->source($customers),
            Metric::level('test.active', 'Active customers')->source(fn (): int => $this->active),
            Metric::derived('test.per_customer', 'test.orders / test.customers', 'Orders per customer'),
        );
    }

    /** An order at a local time in Athens. */
    private function order(string $at, string $region, int $customer): void
    {
        $this->db->queryBuilder()->table(self::ORDERS)->insert([
            'placed_at' => (new \DateTimeImmutable($at, new \DateTimeZone('Europe/Athens')))->getTimestamp(),
            'region'    => $region,
            'customer'  => $customer,
        ]);
    }

    /** A local moment in Athens, as Unix seconds. */
    private static function at(string $at): int
    {
        return (new \DateTimeImmutable($at, new \DateTimeZone('Europe/Athens')))->getTimestamp();
    }

    /**
     * A sum computed for September's days and the month: by region, the total, a filter of
     * several regions, and a breakdown with a filter — each the orders that fall in the period,
     * half-open, in Athens time (an order at 00:00 on 1 October is October's).
     */
    public function testASumAddsUpInAnyCombination(): void
    {
        // Arrange
        $this->order('2026-09-01 00:00:00', 'north', 1);
        $this->order('2026-09-01 10:00:00', 'north', 1);
        $this->order('2026-09-02 23:59:59', 'south', 2);
        $this->order('2026-09-30 12:00:00', 'west', 3);
        $this->order('2026-10-01 00:00:00', 'north', 4);

        // Act
        $report = Metrics::compute(['test.orders'], ['day', 'month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
        $month  = Metrics::series('test.orders', 'month', '2026-09-01', '2026-09-30', [], 'region');
        $days   = Metrics::series('test.orders', 'day', '2026-09-01', '2026-09-03');

        // Assert
        $this->assertSame(['computed' => 0, 'closed' => 31, 'skipped' => 0, 'failed' => []], $report, '30 days and the month, all ended');
        $this->assertSame(['north' => [2.0], 'south' => [1.0], 'west' => [1.0]], $month['values']);
        $this->assertSame(['' => [2.0, 1.0, 0.0]], $days['values'], 'a day with no orders is 0, not missing');
        $this->assertSame(4.0, Metrics::value('test.orders', 'month', '2026-09-15'));
        $this->assertSame(3.0, Metrics::value('test.orders', 'month', '2026-09-15', ['region' => ['north', 'south']]));
        $this->assertNull(Metrics::value('test.orders', 'month', '2026-10-15'), 'October was not computed');
        $this->assertSame(['north' => [2.0]], Metrics::series('test.orders', 'month', '2026-09-01', '2026-09-01', ['region' => 'north'], 'region')['values']);
    }

    /**
     * A distinct metric's month is counted on its own: one customer ordering on two days is two
     * across the days and one in the month; it is read by what it keeps, and refuses what it
     * does not — two values of a dimension added together, or a dimension it lacks.
     */
    public function testADistinctIsCountedAtEachGrain(): void
    {
        // Arrange
        $this->order('2026-09-01 09:00:00', 'north', 1);
        $this->order('2026-09-02 09:00:00', 'north', 1);
        $this->order('2026-09-02 10:00:00', 'south', 2);
        Metrics::compute(['test.customers'], ['day', 'month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));

        // Act
        $days  = Metrics::series('test.customers', 'day', '2026-09-01', '2026-09-30')['values'][''];
        $month = Metrics::value('test.customers', 'month', '2026-09-01');

        // Assert
        $this->assertSame(3.0, array_sum($days), 'the days added up count customer 1 twice');
        $this->assertSame(2.0, $month, 'the month counts each customer once');
        $this->assertSame(1.0, Metrics::value('test.customers', 'month', '2026-09-01', ['region' => 'south']));
        foreach ([['region' => ['north', 'south']], ['plan' => 'basic']] as $filters) {
            try {
                Metrics::value('test.customers', 'month', '2026-09-01', $filters);
                $this->fail('read by ' . json_encode($filters));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * What is due: the open period, replaced at each run; the period that ended, closed once and
     * then kept — an order arriving late does not change it — until recomputed; and a missed
     * close caught up from the last one kept. Nothing for a period that has not begun.
     */
    public function testOpenPeriodsAreReplacedAndClosedOnesKept(): void
    {
        // Arrange
        $this->order('2026-10-20 10:00:00', 'north', 1);
        $this->order('2026-11-02 10:00:00', 'north', 2);

        // Act — on 6 November: October closes, November is open
        $first = Metrics::compute(['test.orders'], ['month'], null, null, false, self::at('2026-11-06 12:00'));
        $this->order('2026-11-03 10:00:00', 'south', 3);
        $this->order('2026-10-21 10:00:00', 'south', 4);   // late, after October closed
        $second = Metrics::compute(['test.orders'], ['month'], null, null, false, self::at('2026-11-06 13:00'));

        // Assert
        $this->assertSame(1, $first['closed']);
        $this->assertSame(1, $first['computed']);
        $this->assertSame(0, $second['closed'], 'October is kept');
        $this->assertSame(1.0, Metrics::value('test.orders', 'month', '2026-10-01'), 'the late order does not reopen it');
        $this->assertSame(2.0, Metrics::value('test.orders', 'month', '2026-11-01'), 'the open month is replaced');

        // Act — recompute October; then three months on, every month since is closed
        Metrics::compute(['test.orders'], ['month'], '2026-10-01', '2026-10-31', true, self::at('2026-11-06 14:00'));
        $later = Metrics::compute(['test.orders'], ['month'], null, null, false, self::at('2027-02-10 12:00'));
        $future = Metrics::compute(['test.orders'], ['month'], '2027-03-01', '2027-04-30', false, self::at('2027-02-10 12:00'));

        // Assert
        $this->assertSame(2.0, Metrics::value('test.orders', 'month', '2026-10-01'), 'recomputed');
        $this->assertSame(3, $later['closed'], 'November, December and January');
        $this->assertSame(2, $future['skipped'], 'March and April have not begun');
        $this->assertSame(0, $this->db->queryBuilder()->table(Metrics::TABLE)->where('metric', 'test.orders')->where('period_start', '>=', '2027-03-01')->count());
    }

    /**
     * A level: replaced while its period is open, kept as it stood when the run after the end
     * closed it, never computed for an older period nor recomputed — and its history imported,
     * final, read like any value.
     */
    public function testALevelIsKeptAsItStood(): void
    {
        // Arrange
        Metrics::import('test.active', 'month', '2026-08-01', [], 31);

        // Act — September open, then closed at the start of October
        Metrics::compute(['test.active'], ['month'], null, null, false, self::at('2026-09-20 12:00'));
        $this->active = 45;
        Metrics::compute(['test.active'], ['month'], null, null, false, self::at('2026-10-01 00:07'));
        $this->active = 50;
        $past = Metrics::compute(['test.active'], ['month'], '2026-06-01', '2026-09-30', true, self::at('2026-10-02 12:00'));

        // Assert
        $this->assertSame(31.0, Metrics::value('test.active', 'month', '2026-08-01'), 'imported');
        $this->assertSame(45.0, Metrics::value('test.active', 'month', '2026-09-01'), 'as it stood when September closed');
        $this->assertSame(4, $past['skipped'], 'June to September: a level\'s past is never computed, not even recomputed');
        $this->assertNull(Metrics::value('test.active', 'month', '2026-07-01'));
        $this->assertSame(
            ['value' => 45.0, 'other' => 31.0, 'difference' => 14.0],
            array_intersect_key(Metrics::compare('test.active', 'month', '2026-09-01'), ['value' => 1, 'other' => 1, 'difference' => 1])
        );
    }

    /**
     * A derived metric is worked out when read, by breakdown too; a period with no values has
     * none; a division by nothing is none. Compared with a year earlier, a missing year is no
     * change rather than a made-up one.
     */
    public function testADerivedMetricIsWorkedOutWhenRead(): void
    {
        // Arrange
        $this->order('2026-09-01 09:00:00', 'north', 1);
        $this->order('2026-09-02 09:00:00', 'north', 1);
        $this->order('2026-09-03 09:00:00', 'south', 2);
        Metrics::compute(['test.orders', 'test.customers'], ['month'], '2026-08-01', '2026-09-30', false, self::at('2026-11-06 12:00'));

        // Act
        $ratio = Metrics::series('test.per_customer', 'month', '2026-08-01', '2026-09-30');
        $byRegion = Metrics::series('test.per_customer', 'month', '2026-09-01', '2026-09-30', [], 'region');
        $compare = Metrics::compare('test.orders', 'month', '2026-09-01', [], Metrics::YEAR_EARLIER);

        // Assert
        $this->assertSame([null, 1.5], $ratio['values'][''], 'August had no orders and no customers: nothing to divide');
        $this->assertSame(['north' => [2.0], 'south' => [1.0]], $byRegion['values']);
        $this->assertSame(3.0, $compare['value']);
        $this->assertNull($compare['other']);
        $this->assertNull($compare['change']);
        $this->assertSame('2025-09', $compare['against']);
    }

    /**
     * A source that throws costs its own metric only, said in the report; a metric that does
     * not exist is refused when read.
     */
    public function testAFailingSourceCostsItsOwnMetric(): void
    {
        // Arrange
        Metrics::register(Metric::flow('test.broken')->source(static fn (): never => throw new \RuntimeException('no table')));
        $this->order('2026-09-01 09:00:00', 'north', 1);

        // Act
        $report = Metrics::compute(['test.broken', 'test.orders'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));

        // Assert
        $this->assertSame(['test.broken@month' => 'no table'], $report['failed']);
        $this->assertSame(1.0, Metrics::value('test.orders', 'month', '2026-09-01'));
        $this->expectException(\InvalidArgumentException::class);
        Metrics::value('test.nothing', 'month', '2026-09-01');
    }
    /**
     * `metrics:compute`: what it was given checked first — a grain, a day, a metric that exist —
     * then the periods computed and counted; a failing metric makes it exit 1.
     */
    public function testTheCommand(): void
    {
        // Arrange
        $this->order('2026-09-01 09:00:00', 'north', 1);
        $run = static function (array $input): array {
            $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\MetricsCompute());
            $code   = $tester->execute($input);

            return [$code, $tester->getDisplay()];
        };

        // Act
        $badGrain  = $run(['--grain' => ['hour']]);
        $badDay    = $run(['--from' => '1/9/2026']);
        $badMetric = $run(['--metric' => ['test.nothing']]);
        $ok        = $run(['--metric' => ['test.orders'], '--grain' => ['month'], '--from' => '2026-09-01', '--to' => '2026-09-30']);
        Metrics::register(Metric::flow('test.broken')->source(static fn (): never => throw new \RuntimeException('no table')));
        $failed    = $run(['--metric' => ['test.broken'], '--grain' => ['month'], '--from' => '2026-09-01', '--to' => '2026-09-30']);
        Metrics::reset();
        Application::getInstance()->applicationInfo['metrics'] = [];
        $none      = $run([]);

        // Assert
        $this->assertSame(2, $badGrain[0]);
        $this->assertStringContainsString('not one of', $badGrain[1]);
        $this->assertSame(2, $badDay[0]);
        $this->assertSame(2, $badMetric[0]);
        $this->assertSame(0, $ok[0]);
        $this->assertStringContainsString('closed 1', $ok[1]);
        $this->assertSame(2, $this->db->queryBuilder()->table(Metrics::TABLE)->where('metric', 'test.orders')->where('final', 1)->count(), 'September kept, final: its total and north');
        $this->assertSame(1, $failed[0]);
        $this->assertStringContainsString('test.broken@month: no table', $failed[1]);
        $this->assertSame(0, $none[0], 'nothing declared: nothing to do');
    }
    /**
     * Where TimescaleDB is, an installation may make the values a hypertable on `period_start`
     * — declared in app.php's `hypertables`, done by `timescale:ensure` — since the primary key
     * holds the time column; computing, importing and reading go on as before.
     */
    public function testTheValuesMayBeAHypertable(): void
    {
        // Arrange
        if ($this->db->type !== 'postgresql' || !$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('TimescaleDB only.');
        }
        $schema = $this->db->schema();
        $schema->dropTableIfExists(Metrics::TABLE);
        \Pramnos\Framework\Testing\Schema::ensure([CreateMetricValuesTable::class], $this->db);
        \Pramnos\Database\HypertableRegistry::loadOverridesFromConfig([Metrics::TABLE => ['time_column' => 'period_start', 'chunk_interval' => '1 year']]);
        $this->order('2026-09-01 09:00:00', 'north', 1);

        try {
            // Act
            $done = \Pramnos\Database\HypertableRegistry::apply($schema, Metrics::TABLE);
            Metrics::compute(['test.orders'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
            Metrics::compute(['test.orders'], ['month'], '2026-09-01', '2026-09-30', true, self::at('2026-11-06 13:00'));
            Metrics::import('test.active', 'month', '2026-08-01', [], 31);
            Metrics::import('test.active', 'month', '2026-08-01', [], 32);

            // Assert
            $this->assertContains('converted to hypertable', $done);
            $this->assertTrue($schema->hasHypertable(Metrics::TABLE));
            $this->assertSame(1.0, Metrics::value('test.orders', 'month', '2026-09-01'), 'recomputed over its own rows');
            $this->assertSame(32.0, Metrics::value('test.active', 'month', '2026-08-01'), 'an import replaces, not duplicates');
        } finally {
            \Pramnos\Database\HypertableRegistry::reset();
            $schema->dropTableIfExists(Metrics::TABLE);
            \Pramnos\Framework\Testing\Schema::ensure([CreateMetricValuesTable::class], $this->db);
        }
    }
}
