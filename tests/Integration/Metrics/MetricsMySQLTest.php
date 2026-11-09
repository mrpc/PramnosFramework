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
#[CoversClass(\Pramnos\Metrics\Store::class)]
#[CoversClass(\Pramnos\Metrics\Query::class)]
#[CoversClass(\Pramnos\Console\Commands\MetricsCompute::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\MetricCard::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\MetricsTool::class)]
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
        \Pramnos\Metrics\Store::forget($this->db, 'test.%');
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'Europe/Athens'];
        Metrics::reset();
        $this->declare();
    }

    protected function tearDown(): void
    {
        \Pramnos\Metrics\Store::forget($this->db, 'test.%');
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
        $this->assertSame(['computed' => 0, 'closed' => 31, 'skipped' => 0, 'failed' => [], 'busy' => false], $report, '30 days and the month, all ended');
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
        $badGrain  = $run(['--grain' => ['fortnight']]);
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
     * Each metric is a dashboard card, off a new dashboard, for those it allows: a tile with this
     * period's value and the comparison chosen, a chart of several periods broken down, a note
     * where a read is refused — and none at all with `'metrics' => false` in the dashboard's config.
     */
    public function testEachMetricIsADashboardCard(): void
    {
        // Arrange
        $month = date('Y-m-15');
        $this->order(date('Y-m-01 10:00:00'), 'north', 1);
        $this->order(date('Y-m-01 11:00:00'), 'south', 2);
        Metrics::register(Metric::flow('test.split', 'Split')->aggregate(Metric::DISTINCT)->dimensions(['region', 'plan'])->source(static fn (): int => 0));
        Metrics::compute(['test.orders'], ['month'], $month, $month);
        \Pramnos\Dashboard\WidgetRegistry::reset();
        $cards = \Pramnos\Dashboard\WidgetRegistry::all();
        $card  = static fn (string $id, array $settings) => (clone $cards[$id])->configure($settings);

        // Act
        $tile    = $card('metric.test.orders', ['compare' => Metrics::PREVIOUS])->render();
        $empty   = $card('metric.test.active', [])->render();
        $chart   = $card('metric.test.orders', ['periods' => '6', 'breakdown' => 'region', 'chart' => 'bar', 'filters' => 'region=north, plan=x, nonsense'])->render();
        $refused = $card('metric.test.split', ['periods' => '6', 'breakdown' => 'plan', 'filters' => 'region=north'])->render();

        // Assert
        $this->assertArrayHasKey('metric.test.per_customer', $cards, 'a derived metric is a card too');
        $this->assertFalse($cards['metric.test.orders']->byDefault());
        $this->assertFalse($cards['metric.test.orders']->allowed(null), 'the metric\'s allowed(): manager by default');
        $this->assertSame('Orders', $cards['metric.test.orders']->title());
        $this->assertSame('Mine', $card('metric.test.orders', ['title' => 'Mine'])->title());
        $this->assertSame(['grain', 'periods', 'chart', 'compare', 'breakdown', 'filters', 'title'], array_keys($cards['metric.test.orders']->options()));
        $this->assertStringContainsString('>2<', $tile);
        $this->assertStringContainsString(date('Y-m'), $tile);
        $this->assertStringContainsString('—', $empty, 'never computed: a dash, not 0');
        $this->assertStringContainsString('data-pf-chart-figure', $chart);
        $this->assertStringContainsString('"label":"north"', $chart, 'broken down by region, filtered to north');
        $this->assertStringNotContainsString('"label":"south"', $chart);
        $this->assertStringContainsString('keeps only', $refused, 'a breakdown it does not keep is said, not drawn');
        Application::getInstance()->applicationInfo['dashboard'] = ['metrics' => false];
        $this->assertArrayNotHasKey('metric.test.orders', \Pramnos\Dashboard\WidgetRegistry::all());
        unset(Application::getInstance()->applicationInfo['dashboard']);
        $this->assertSame('1.234,50 €', \Pramnos\Dashboard\Widgets\MetricCard::format(1234.5, '€'));
        $this->assertSame('12,5%', \Pramnos\Dashboard\Widgets\MetricCard::format(12.5, '%'));
        $this->assertSame('1.234', \Pramnos\Dashboard\Widgets\MetricCard::format(1234.0, ''));
        $this->assertSame('2,50', \Pramnos\Dashboard\Widgets\MetricCard::format(2.5, ''));
    }

    /**
     * The analyst's `metrics` tool: the catalogue of what the person asking may see — nothing for
     * nobody — a series by breakdown, a comparison, the records behind a number, and errors said
     * in words: a metric they may not see, a breakdown not kept, too many periods.
     */
    public function testTheAnalystsTool(): void
    {
        // Arrange — the account loads with its details
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class,
        ], $this->db);
        $admin = new \Pramnos\User\User();
        $admin->username = 'metrics_admin_' . bin2hex(random_bytes(3));
        $admin->email    = $admin->username . '@example.com';
        $admin->usertype = 99;
        $admin->save();
        Metrics::register(Metric::flow('test.listed', 'Listed')->rows(static fn (Window $w, array $f): array => [['id' => 1, 'f' => $f], ['id' => 2]]));
        \Pramnos\Metrics\Metrics::dimension(\Pramnos\Metrics\Dimension::of('region', 'Region')->values(['north' => 'North']));
        $this->order('2026-09-01 09:00:00', 'north', 1);
        $this->order('2026-09-02 09:00:00', 'south', 1);
        Metrics::compute(['test.orders', 'test.customers'], ['month'], '2026-08-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
        $tool = new \Pramnos\Llm\Analyst\Tools\MetricsTool((int) $admin->userid);

        try {
            // Act
            $catalogue = $tool->execute([]);
            $nobody    = (new \Pramnos\Llm\Analyst\Tools\MetricsTool())->execute(['action' => 'catalogue']);
            $series    = $tool->execute(['action' => 'series', 'metric' => 'test.orders', 'from' => '2026-08-01', 'to' => '2026-09-30', 'breakdown' => 'region']);
            $compare   = $tool->execute(['action' => 'compare', 'metric' => 'test.orders', 'period' => '2026-09-10']);
            $rows      = $tool->execute(['action' => 'rows', 'metric' => 'test.listed', 'period' => '2026-09-10', 'filters' => ['region' => ['north']]]);
            $noRows    = $tool->execute(['action' => 'rows', 'metric' => 'test.orders']);
            $hidden    = (new \Pramnos\Llm\Analyst\Tools\MetricsTool())->execute(['action' => 'series', 'metric' => 'test.orders']);
            $notKept   = $tool->execute(['action' => 'series', 'metric' => 'test.customers', 'filters' => ['region' => ['north', 'south']]]);
            $tooMany   = $tool->execute(['action' => 'series', 'metric' => 'test.orders', 'grain' => 'day', 'from' => '2026-01-01', 'to' => '2026-12-31']);

            // Assert
            $byName = array_column($catalogue['metrics'], null, 'name');
            $this->assertSame(['sum', 'distinct'], [$byName['test.orders']['aggregate'], $byName['test.customers']['aggregate']]);
            $this->assertSame(['label' => 'Region', 'values' => ['north' => 'North']], $byName['test.orders']['dimensions']['region']);
            $this->assertSame(['(total)', 'region'], $byName['test.customers']['breakdowns']);
            $this->assertArrayNotHasKey('aggregate', $byName['test.per_customer'], 'derived: no aggregate of its own');
            $this->assertSame(['metrics' => []], $nobody);
            $this->assertSame(['2026-08' => 0.0, '2026-09' => 1.0], $series['values']['north'] ?? null);
            $this->assertSame(2.0, $compare['value']);
            $this->assertSame(2, $rows['count']);
            $this->assertSame(['region' => 'north'], $rows['rows'][0]['f'], 'one value of each filter');
            $this->assertArrayHasKey('says', $noRows);
            $this->assertStringStartsWith('Error: there is no metric', $hidden);
            $this->assertStringStartsWith('Error: Metric test.customers counts each one once', $notKept);
            $this->assertStringContainsString('at most', $tooMany);
            $this->assertSame('metrics', $tool->name());
            $this->assertArrayHasKey('breakdown', $tool->inputSchema()['properties']);
            $this->assertStringContainsString('distinct', $tool->description());
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', (int) $admin->userid)->delete();
            \Pramnos\User\User::clearUserCache();
        }
    }

    /**
     * Each grain has its own table, and the view shows them all with their grain. On TimescaleDB
     * each is a hypertable, compressed; a closed month rewritten after its chunk is compressed is
     * replaced in place (direct DML), and an import replaces rather than duplicates.
     */
    public function testEachGrainHasItsTableAndTheViewShowsThemAll(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $this->order('2026-09-01 09:00:00', 'north', 1);
        $timescale = $this->db->type === 'postgresql' && $this->db->capabilities()->hasTimescaleDB();

        // Act
        Metrics::compute(['test.orders'], ['day', 'month'], '2026-09-01', '2026-09-01', false, self::at('2026-11-06 12:00'));
        if ($timescale) {
            // Raw: compressing a chunk is TimescaleDB's own function.
            $this->db->query("SELECT compress_chunk(c, true) FROM show_chunks('" . $schema->quoteTable(\Pramnos\Metrics\Store::table('month')) . "') c");
        }
        $this->order('2026-09-02 09:00:00', 'south', 2);
        Metrics::compute(['test.orders'], ['month'], '2026-09-01', '2026-09-30', true, self::at('2026-11-06 13:00'));
        Metrics::import('test.active', 'month', '2026-08-01', [], 31);
        Metrics::import('test.active', 'month', '2026-08-01', [], 32);
        $view = $this->db->queryBuilder()->table(Metrics::TABLE)->where('metric', 'test.orders')->where('dimset', '')->orderBy('grain')->get()->fetchAll();

        // Assert
        foreach (\Pramnos\Metrics\Window::GRAINS as $grain) {
            $this->assertTrue($schema->hasTable(\Pramnos\Metrics\Store::table($grain)), $grain);
            if ($timescale) {
                $this->assertTrue($schema->hasHypertable(\Pramnos\Metrics\Store::table($grain)), $grain . ' is a hypertable');
            }
        }
        $this->assertSame(['day', 'month'], array_column($view, 'grain'), 'the view: each grain\'s rows, with their grain');
        $this->assertSame(2.0, Metrics::value('test.orders', 'month', '2026-09-01'), 'recomputed over its own rows, compressed or not');
        $this->assertSame(1.0, Metrics::value('test.orders', 'day', '2026-09-01'));
        $this->assertSame(32.0, Metrics::value('test.active', 'month', '2026-08-01'), 'an import replaces, not duplicates');
    }

    /**
     * An installation that ran the single-table migration: its table is converted to the grains'
     * tables on the next ensure, keeping every value — its periods, breakdowns and closed state.
     */
    public function testTheSingleTableFromBeforeIsConverted(): void
    {
        // Arrange — the table as the first version of the migration made it
        $schema = $this->db->schema();
        \Pramnos\Metrics\Store::drop($this->db);
        $schema->createTable(Metrics::TABLE, function ($table) {
            $table->string('metric', 100);
            $table->string('grain', 8);
            $table->date('period_start');
            $table->string('dimset', 200)->default('');
            $table->text('dims');
            $table->char('dims_hash', 32);
            $table->decimal('value', 20, 4)->default(0);
            $table->string('source', 10)->default('computed');
            $table->smallInteger('final')->default(0);
            $table->integer('computed_at')->default(0);
            $table->primary(['metric', 'grain', 'period_start', 'dims_hash']);
        });
        $old = fn (string $grain, string $period, string $dims, float $value, int $final): mixed => $this->db->queryBuilder()->table(Metrics::TABLE)->insert([
            'metric' => 'test.orders', 'grain' => $grain, 'period_start' => $period, 'dimset' => $dims === '{}' ? '' : 'region', 'dims' => $dims,
            'dims_hash' => md5($dims), 'value' => $value, 'source' => 'computed', 'final' => $final, 'computed_at' => 1,
        ]);
        $old('month', '2026-08-01', '{}', 5, 1);
        $old('month', '2026-08-01', '{"region":"north"}', 3, 1);
        $old('day', '2026-08-02', '{}', 2, 0);
        $old('era', '2026-08-02', '{}', 9, 0);

        try {
            // Act
            $done = \Pramnos\Metrics\Store::ensure($this->db);
            $again = \Pramnos\Metrics\Store::ensure($this->db);

            // Assert
            $this->assertContains('moved the single values table aside', $done);
            $this->assertContains('copied 3 values from the single table', $done, 'a grain no table has is left out');
            $this->assertNotContains('moved the single values table aside', $again, 'once');
            $this->assertSame(5.0, Metrics::value('test.orders', 'month', '2026-08-01'));
            $this->assertSame(3.0, Metrics::value('test.orders', 'month', '2026-08-01', ['region' => 'north']));
            $this->assertSame(2.0, Metrics::value('test.orders', 'day', '2026-08-02'));
            $this->assertSame(1, $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('month'))->where('metric', 'test.orders')->where('final', 1)->where('dimset', '')->count(), 'closed stays closed');
            $this->assertFalse($schema->hasTable('stats.metric_values_before'), 'the old table is gone');
        } finally {
            \Pramnos\Metrics\Store::drop($this->db);
            $schema->dropTableIfExists(Metrics::TABLE);
            \Pramnos\Framework\Testing\Schema::ensure([CreateMetricValuesTable::class], $this->db);
        }
    }

    /**
     * An application's own store is imported once: its columns mapped to metric, grain, period,
     * value, a dimension (empty for the total) and a kept text; every value final and imported,
     * a second run replacing rather than adding, and rows that cannot be placed counted aside.
     */
    public function testAnApplicationsStoreIsAdopted(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_store');
        $schema->createTable('metrics_test_store', function ($table) {
            $table->string('grain', 8);
            $table->timestampTz('period_start');
            $table->string('metric', 64);
            $table->string('market', 8)->default('');
            $table->decimal('value', 20, 4)->nullable();
            $table->text('text')->nullable();
        });
        $at = fn (string $day): string => \Pramnos\Database\Instant::utc((new \DateTimeImmutable($day, new \DateTimeZone('Europe/Athens')))->getTimestamp(), $this->db);
        foreach ([
            ['month', $at('2025-09-01'), 'orders', '', 40, null],
            ['month', $at('2025-09-01'), 'orders', 'gr', 30, null],
            ['month', $at('2025-09-01'), 'income', '', null, '1.234,50'],
            ['era', $at('2025-09-01'), 'orders', '', 1, null],
        ] as [$grain, $period, $metric, $market, $value, $text]) {
            $this->db->queryBuilder()->table('metrics_test_store')->insert(['grain' => $grain, 'period_start' => $period, 'metric' => $metric, 'market' => $market, 'value' => $value, 'text' => $text]);
        }
        $map = ['metric' => 'metric', 'grain' => 'grain', 'period' => 'period_start', 'value' => 'value', 'dims' => ['market'], 'extra' => ['text'], 'prefix' => 'test.'];

        try {
            // Act
            $first  = \Pramnos\Metrics\Store::adopt($this->db, $this->db, 'metrics_test_store', $map);
            $second = \Pramnos\Metrics\Store::adopt($this->db, $this->db, 'metrics_test_store', $map);
            $refused = [];
            foreach ([['metric' => 'metric', 'period' => 'period_start', 'value' => ''], ['metric' => 'metric', 'period' => 'period_start', 'value' => 'value']] as $bad) {
                try {
                    \Pramnos\Metrics\Store::adopt($this->db, $this->db, 'metrics_test_store', $bad);
                } catch (\InvalidArgumentException $e) {
                    $refused[] = $e->getMessage();
                }
            }
            $kept = $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('month'))->where('metric', 'like', 'test.%')->orderBy('metric')->orderBy('dimset')->get()->fetchAll();

            // Assert
            $this->assertSame(['rows' => 3, 'skipped' => 1], $first, 'a grain that is none of ours is skipped');
            $this->assertSame($first, $second);
            $this->assertCount(3, $kept, 'the second run replaced');
            $this->assertSame(['test.income', 'test.orders', 'test.orders'], array_column($kept, 'metric'));
            $this->assertSame('2025-09-01', \Pramnos\Metrics\Store::key((string) $kept[1]['period_start']), 'the period, in the metrics\' zone');
            $this->assertSame(['', 'market'], [$kept[1]['dimset'], $kept[2]['dimset']], 'an empty market is the total');
            $this->assertNull($kept[0]['value']);
            $this->assertSame(['text' => '1.234,50'], json_decode((string) $kept[0]['extra'], true), 'the kept text, beside the number');
            $this->assertSame(['imported', 1], [$kept[1]['source'], (int) $kept[1]['final']]);
            $this->assertCount(2, $refused, 'a column missing, and neither a grain column nor one grain');
            // A corrected version of the same figures, kept beside them and not read in their place.
            $fixed = \Pramnos\Metrics\Store::adopt($this->db, $this->db, 'metrics_test_store', ['version' => 2] + $map);
            Metrics::compute(['test.orders'], ['month'], '2025-09-01', '2025-09-30', true, self::at('2026-11-06 12:00'));
            $this->assertSame(3, $fixed['rows']);
            $this->assertSame(2, $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('month'))->where('metric', 'test.orders')->where('version', 2)->count(), 'version 2 kept, also through a recomputation of version 1');
            $this->assertSame(0.0, Metrics::value('test.orders', 'month', '2025-09-01'), 'the computed version 1 is read (no orders), never added to version 2');
        } finally {
            \Pramnos\Metrics\Store::forget($this->db, 'test.%');
            $schema->dropTableIfExists('metrics_test_store');
        }
    }

    /**
     * More than a batch of rows in one period — the store's order is not unique there — each
     * imported exactly once; a key the source gives twice kept once, its last row as read; and
     * `upsertMany()` refuses rows of different columns, or nothing to update.
     */
    public function testALargeImportTakesEveryRowOnce(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_big');
        $schema->createTable('metrics_test_big', function ($table) {
            $table->string('metric', 64);
            $table->date('month');
            $table->string('market', 8)->default('');
            $table->decimal('value', 20, 4);
        });
        $rows = [];
        for ($i = 0; $i < 1250; $i++) {
            $rows[] = ['metric' => 'big' . intdiv($i, 2), 'month' => '2025-03-01', 'market' => $i % 2 === 0 ? '' : 'gr', 'value' => $i];
        }
        $rows[] = ['metric' => 'big0', 'month' => '2025-03-01', 'market' => '', 'value' => 999];   // the same key again
        $this->db->queryBuilder()->table('metrics_test_big')->insertMany($rows);
        $refused = [];
        foreach ([[[['a' => 1], ['b' => 2]], ['a'], ['a']], [[['a' => 1]], ['a'], []]] as [$many, $key, $update]) {
            try {
                $this->db->queryBuilder()->table('metrics_test_big')->upsertMany($many, $key, $update);
            } catch (\InvalidArgumentException $e) {
                $refused[] = $e->getMessage();
            }
        }

        try {
            // Act
            $done = \Pramnos\Metrics\Store::adopt($this->db, $this->db, 'metrics_test_big', ['metric' => 'metric', 'grainIs' => 'month', 'period' => 'month', 'value' => 'value', 'dims' => ['market'], 'prefix' => 'test.']);
            $kept = $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('month'))->where('metric', 'like', 'test.big%')->count();
            $distinct = $this->db->query('SELECT COUNT(*) AS n FROM (SELECT DISTINCT metric, dims_hash FROM ' . $schema->quoteTable(\Pramnos\Metrics\Store::table('month')) . " WHERE metric LIKE 'test.big%') d")->fetch();

            // Assert
            // `rows` counts writes: the repeated key, read after the first thousand, is written again over itself.
            $this->assertContains($done['rows'], [1250, 1251]);
            $this->assertSame(0, $done['skipped']);
            $this->assertSame(1250, $kept);
            $this->assertSame(1250, (int) $distinct['n'], 'each metric and breakdown exactly once');
            $this->assertSame(999.0, (float) $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('month'))->where('metric', 'test.big0')->where('dimset', '')->first()->fetch()['value'], 'the last row of a repeated key');
            $this->assertCount(2, $refused);
        } finally {
            \Pramnos\Metrics\Store::forget($this->db, 'test.%');
            $schema->dropTableIfExists('metrics_test_big');
        }
    }

    /**
     * Each organisation's values are kept apart (`Metric::tenant()`), its total its own breakdown
     * added up; a reader reads only the organisations `metrics.tenant` gives them — all of them
     * when it gives null — through the one query every reader uses; one organisation of theirs
     * may be picked, another's is refused; a distinct does not add up across organisations; a
     * metric the reader may not read is refused; and the answers come as a series, a value, a
     * comparison, rows and a ranking.
     */
    public function testEachOrganisationIsKeptAndReadApart(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_orgs');
        $schema->createTable('metrics_test_orgs', function ($table) {
            $table->integer('placed_at');
            $table->integer('org');
            $table->string('region', 20);
            $table->integer('person');
        });
        foreach ([[1, 'north', 1], [1, 'north', 2], [1, 'south', 1], [2, 'north', 1], [2, 'south', 3]] as [$org, $region, $person]) {
            $this->db->queryBuilder()->table('metrics_test_orgs')->insert(['placed_at' => self::at('2026-09-05 10:00'), 'org' => $org, 'region' => $region, 'person' => $person]);
        }
        $count = function (string $what): \Closure {
            return function (Window $w, array $by, Database $db) use ($what): array {
                $query = $db->queryBuilder()->table('metrics_test_orgs');

                return $query->select([...$by, $query->raw($what . ' AS value')])
                    ->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->groupBy($by)->getAllOrFail();
            };
        };
        Metrics::register(
            Metric::flow('test.org_orders', 'Orders')->dimensions(['region'])->tenant('org')->source($count('COUNT(*)')),
            Metric::flow('test.org_people', 'People ordering')->aggregate(Metric::DISTINCT)->tenant('org')->source($count('COUNT(DISTINCT person)')),
            Metric::flow('test.secret', 'Secret')->allowed(static fn (?object $user): bool => false)->source(static fn (): int => 1),
        );
        Application::getInstance()->applicationInfo['metrics']['tenant'] = static fn (?object $user): ?array => $user?->orgs ?? null;
        $mine  = (object) ['userid' => 5, 'usertype' => 99, 'orgs' => [1]];
        $boss  = (object) ['userid' => 6, 'usertype' => 99];
        $nobody = (object) ['userid' => 7, 'usertype' => 99, 'orgs' => []];

        try {
            // Act
            Metrics::compute(['test.org_orders', 'test.org_people'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
            $q        = static fn (string $metric): \Pramnos\Metrics\Query => Metrics::query($metric)->grain('month')->at('2026-09-01');
            $hers     = $q('test.org_orders')->for($mine)->value();
            $all      = $q('test.org_orders')->for($boss)->value();
            $none     = $q('test.org_orders')->for($nobody)->value();
            $oneOfAll = $q('test.org_orders')->for($boss)->tenant(2)->value();
            $byRegion = $q('test.org_orders')->for($mine)->by('region')->series()['values'];
            $people   = $q('test.org_people')->for($mine)->value();
            $rows     = $q('test.org_orders')->for($boss)->by('region')->table();
            $top      = $q('test.org_orders')->for($boss)->by('region')->top(1);
            $compared = $q('test.org_orders')->for($mine)->compare(Metrics::YEAR_EARLIER)->compared();
            // A reader on the query: a report built for her shows her organisation alone.
            $report   = \Pramnos\Metrics\Report::fromArray(['name' => 'Orgs', 'grain' => 'month', 'sections' => [['rows' => [['metric' => 'test.org_orders']]]]]);
            $cellHers = $report->build('2026-09-10', $mine)['sections'][0]['rows'][0]['cells'][0]['value'];
            $cellAll  = $report->build('2026-09-10', $boss)['sections'][0]['rows'][0]['cells'][0]['value'];
            $refused  = [];
            foreach ([
                static fn () => $q('test.org_orders')->for($mine)->tenant(2),
                static fn () => $q('test.secret')->for($mine),
                static fn () => $q('test.org_people')->value(),
                static fn () => $q('test.org_orders')->top(3),
            ] as $attempt) {
                try {
                    $attempt();
                } catch (\DomainException|\LogicException $e) {
                    $refused[] = $e::class;
                }
            }

            // Assert
            $this->assertSame(3.0, $hers, 'organisation 1 alone');
            $this->assertSame(5.0, $all, 'every organisation, added up');
            $this->assertNull($none, 'no organisation: nothing');
            $this->assertSame(2.0, $oneOfAll);
            $this->assertSame(['north' => [2.0], 'south' => [1.0]], $byRegion);
            $this->assertSame(2.0, $people, 'distinct, in one organisation');
            $this->assertSame([['period' => '2026-09-01', 'key' => 'north', 'value' => 3.0], ['period' => '2026-09-01', 'key' => 'south', 'value' => 2.0]], $rows);
            $this->assertSame(['north' => 3.0], $top);
            $this->assertSame([3.0, 5.0], [$cellHers, $cellAll], 'a report reads as its viewer');
            $this->assertSame(['2026-09', 3.0, '2025-09', null], [$compared['period'], $compared['value'], $compared['against'], $compared['other']]);
            $this->assertSame([\DomainException::class, \DomainException::class, \LogicException::class, \LogicException::class], $refused,
                'another organisation; a metric not theirs; a distinct over two organisations; a ranking with no dimension');
        } finally {
            unset(Application::getInstance()->applicationInfo['metrics']['tenant']);
            $schema->dropTableIfExists('metrics_test_orgs');
        }
    }

    /**
     * A metric that asks for hours is kept by the hour, beside its days; one that does not is not.
     * The hours are read like any grain, and `metrics.retention` drops those older than it keeps.
     */
    public function testAMetricKeptByTheHour(): void
    {
        // Arrange
        Metrics::register(Metric::flow('test.hourly', 'Orders by the hour')->grains(['hour', 'day'])->source(function (Window $w, array $by, Database $db): int {
            return (int) $db->queryBuilder()->table(self::ORDERS)->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->count();
        }));
        $this->order('2026-09-01 09:10:00', 'north', 1);
        $this->order('2026-09-01 09:50:00', 'north', 2);
        $this->order('2026-09-01 11:05:00', 'south', 3);

        // Act
        Metrics::compute(['test.hourly', 'test.orders'], null, '2026-09-01', '2026-09-01', false, self::at('2026-11-06 12:00'));
        $hours = Metrics::query('test.hourly')->grain('hour')->between('2026-09-01 08:00', '2026-09-01 11:00')->series()['values'][''];
        $day   = Metrics::value('test.hourly', 'day', '2026-09-01');
        $orderHours = $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('hour'))->where('metric', 'test.orders')->count();
        Application::getInstance()->applicationInfo['metrics']['retention'] = ['hour' => '30 days', 'week' => 'never'];
        $pruned = \Pramnos\Metrics\Store::prune($this->db, self::at('2026-11-06 12:00'));
        $left   = $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('hour'))->where('metric', 'test.hourly')->count();
        unset(Application::getInstance()->applicationInfo['metrics']['retention']);

        // Assert
        $this->assertSame([0.0, 2.0, 0.0, 1.0], $hours, '08:00, 09:00, 10:00, 11:00');
        $this->assertSame(3.0, $day, 'and its days');
        $this->assertSame(0, $orderHours, 'a metric that did not ask has no hours');
        $this->assertSame(['hour' => '30 days'], $pruned, 'a retention that is no length is let be');
        $this->assertSame(0, $left, 'September\'s hours are older than thirty days');
    }

    /**
     * The store's lock: while another connection holds it — a conversion, an import, another
     * computation — a computation does nothing and says it was busy (the command too, without
     * failing), and once it is let go the computation runs.
     */
    public function testAComputationWaitsItsTurnForTheStore(): void
    {
        // Arrange
        $other = new Database();
        foreach (['type', 'server', 'port', 'user', 'password', 'database', 'prefix', 'schema'] as $setting) {
            if (property_exists($this->db, $setting)) {
                $other->{$setting} = $this->db->{$setting};
            }
        }
        $other->connect(false);
        $this->order('2026-09-01 09:00:00', 'north', 1);
        $held = \Pramnos\Metrics\Store::lock($other, 0);

        try {
            // Act
            $busy   = Metrics::compute(['test.orders'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
            $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\MetricsCompute());
            $code   = $tester->execute([]);
            \Pramnos\Metrics\Store::unlock($other);
            $done   = Metrics::compute(['test.orders'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));

            // Assert
            $this->assertTrue($held, 'the other connection took it');
            $this->assertTrue($busy['busy']);
            $this->assertSame(0, $busy['computed'] + $busy['closed'], 'nothing written meanwhile');
            $this->assertSame(0, $code, 'busy is not a failure');
            $this->assertStringContainsString('busy', $tester->getDisplay());
            $this->assertFalse($done['busy']);
            $this->assertSame(1.0, Metrics::value('test.orders', 'month', '2026-09-01'), 'computed once it was free');
        } finally {
            \Pramnos\Metrics\Store::unlock($other);
            $other->close();
        }
    }

    /**
     * A conversion cut short — the single table moved aside, not yet copied — is finished by the
     * next ensure: its rows copied, it dropped.
     */
    public function testAConversionCutShortIsFinished(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('stats.metric_values_before');
        $schema->createTable('stats.metric_values_before', function ($table) {
            $table->string('metric', 100);
            $table->string('grain', 8);
            $table->date('period_start');
            $table->string('dimset', 200)->default('');
            $table->text('dims');
            $table->char('dims_hash', 32);
            $table->decimal('value', 20, 4)->default(0);
            $table->string('source', 10)->default('computed');
            $table->smallInteger('final')->default(0);
            $table->integer('computed_at')->default(0);
        });
        $this->db->queryBuilder()->table('stats.metric_values_before')->insert([
            'metric' => 'test.orders', 'grain' => 'month', 'period_start' => '2026-06-01', 'dimset' => '', 'dims' => '{}',
            'dims_hash' => md5('{}'), 'value' => 8, 'source' => 'computed', 'final' => 1, 'computed_at' => 1,
        ]);

        // Act
        $done = \Pramnos\Metrics\Store::ensure($this->db);

        // Assert
        $this->assertSame(['copied 1 values from the single table'], $done);
        $this->assertSame(8.0, Metrics::value('test.orders', 'month', '2026-06-01'));
        $this->assertFalse($schema->hasTable('stats.metric_values_before'));
    }
}
