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
    use \Pramnos\Tests\Integration\Audience\AdminScreen;

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
        require_once ROOT . '/database/migrations/framework/stats/2026_11_09_000004_create_goals_table.php';
        require_once ROOT . '/database/migrations/framework/stats/2026_11_09_000005_create_alert_rules_table.php';
        require_once ROOT . '/database/migrations/framework/stats/2026_11_09_000006_create_annotations_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([CreateMetricValuesTable::class, \Pramnos\Framework\Migrations\Stats\CreateGoalsTable::class,
            \Pramnos\Framework\Migrations\Stats\CreateAlertRulesTable::class, \Pramnos\Framework\Migrations\Stats\CreateAnnotationsTable::class], $this->db);
        $this->db->queryBuilder()->table(\Pramnos\Metrics\Annotations::TABLE)->delete();
        $this->db->queryBuilder()->table(\Pramnos\Metrics\Alerts::TABLE)->where('metric', 'like', 'test.%')->delete();
        $this->db->queryBuilder()->table(\Pramnos\Metrics\Goals::TABLE)->where('metric', 'like', 'test.%')->delete();
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
        // In the page's language: written as Greek writes numbers, then as English does.
        $language = strtolower((string) \Pramnos\Framework\Factory::getLanguage()->currentlang());
        Application::getInstance()->applicationInfo['numbers'] = [$language => \Pramnos\General\NumberFormat::CONVENTIONS['default']];
        $this->assertSame('1.234,50 €', \Pramnos\Dashboard\Widgets\MetricCard::format(1234.5, '€'));
        $this->assertSame('12,5%', \Pramnos\Dashboard\Widgets\MetricCard::format(12.5, '%'));
        $this->assertSame('1.234', \Pramnos\Dashboard\Widgets\MetricCard::format(1234.0, ''));
        $this->assertSame('2,50', \Pramnos\Dashboard\Widgets\MetricCard::format(2.5, ''));
        Application::getInstance()->applicationInfo['numbers'] = [$language => \Pramnos\General\NumberFormat::CONVENTIONS['en']];
        $this->assertSame('1,234.50 €', \Pramnos\Dashboard\Widgets\MetricCard::format(1234.5, '€'));
        unset(Application::getInstance()->applicationInfo['numbers']);
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
     * Money in two currencies is never added: an organisation in euros and one in pounds are each
     * read on their own, in their own currency — kept with each value, so a later change of an
     * organisation's currency does not relabel what it earned — and read together they are refused.
     * A report's cells are written in the reader's language: a comma in Greek, a dot and the symbol
     * first in English. The section's total over two currencies is not drawn.
     */
    public function testMoneyIsReadOneCurrencyAtATime(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_pay');
        $schema->createTable('metrics_test_pay', function ($table) {
            $table->integer('paid_at');
            $table->integer('org');
            $table->decimal('amount', 10, 2);
        });
        foreach ([[1, 1200.5], [1, 34], [2, 80]] as [$org, $amount]) {
            $this->db->queryBuilder()->table('metrics_test_pay')->insert(['paid_at' => self::at('2026-09-05 10:00'), 'org' => $org, 'amount' => $amount]);
        }
        $pounds = [2];
        Metrics::register(Metric::flow('test.paid', 'Paid')->tenant('org')->currency(static function (int $tenant) use (&$pounds): string {
            return in_array($tenant, $pounds, true) ? 'GBP' : 'EUR';
        })->source(static function (Window $w, array $by, Database $db): array {
            $query = $db->queryBuilder()->table('metrics_test_pay');

            return $query->select([...$by, $query->raw('SUM(amount) AS value')])
                ->where('paid_at', '>=', $w->startsAt())->where('paid_at', '<', $w->endsAt())->groupBy($by)->getAllOrFail();
        }));
        $boss = (object) ['userid' => 6, 'usertype' => 99];
        $q    = static fn (): \Pramnos\Metrics\Query => Metrics::query('test.paid')->grain('month')->at('2026-09-01')->for($boss);

        try {
            // Act
            Metrics::compute(['test.paid'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
            $pounds = [];   // organisation 2 moves to euros: what it earned in September stays in pounds
            $euros  = $q()->tenant(1);
            $one    = $euros->value();
            $gbp    = $q()->tenant(2);
            $two    = $gbp->value();
            $mixed  = null;
            try {
                $q()->value();
            } catch (\LogicException $e) {
                $mixed = $e->getMessage();
            }
            $report = \Pramnos\Metrics\Report::fromArray(['name' => 'Pay', 'grain' => 'month', 'sections' => [['total' => true, 'rows' => [['metric' => 'test.paid']]]]]);
            $table  = $report->forTenant(1)->build('2026-09-10', $boss);
            // The page's language written each way: as English writes numbers, and as Greek does.
            $language = strtolower((string) \Pramnos\Framework\Factory::getLanguage()->currentlang());
            Application::getInstance()->applicationInfo['numbers'] = [$language => \Pramnos\General\NumberFormat::CONVENTIONS['en']];
            $english = \Pramnos\Metrics\Report::html($table);
            Application::getInstance()->applicationInfo['numbers'] = [$language => \Pramnos\General\NumberFormat::CONVENTIONS['default']];
            $greek  = \Pramnos\Metrics\Report::html($table);
            $money  = Metric::flow('test.money', 'M')->format('currency')->display(5);
            unset(Application::getInstance()->applicationInfo['numbers']);
            $csv    = \Pramnos\Metrics\Report::csv($table);

            // Assert
            $this->assertSame([1234.5, 'EUR'], [$one, $euros->currency()]);
            $this->assertSame([80.0, 'GBP'], [$two, $gbp->currency()], 'kept in the currency it was counted in');
            $this->assertSame('Paid is in EUR, GBP: read one currency at a time.', $mixed);
            $this->assertStringContainsString('1.234,50 €', $greek);
            $this->assertStringContainsString('€1,234.50', $english);
            $this->assertStringContainsString('Paid (EUR)', $csv, 'the currency beside the label; the number plain');
            $this->assertStringContainsString(',1234.5', $csv);
            $this->assertSame('EUR', Metrics::get('test.paid')->getCurrency(1));
            $this->assertSame('5,00 €', $money, 'no currency named: metrics.currency, else euros');
        } finally {
            $schema->dropTableIfExists('metrics_test_pay');
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
     * A store that is not there — a deploy whose migration has not run yet — is made on its first
     * use in a process, and the computation and the reading go on; once found ready, it is not
     * asked about again.
     */
    public function testAStoreThatIsNotThereIsMadeOnFirstUse(): void
    {
        // Arrange
        \Pramnos\Metrics\Store::drop($this->db);
        $this->order('2026-09-01 09:00:00', 'north', 1);

        // Act
        Metrics::compute(['test.orders'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
        $value = Metrics::value('test.orders', 'month', '2026-09-01');

        // Assert
        $this->assertTrue($this->db->schema()->hasTable(\Pramnos\Metrics\Store::table('month')));
        $this->assertSame(1.0, $value);
    }

    /**
     * A zone per organisation: each organisation's day is its own local day. One moment — half
     * past midnight in Athens, half past ten the evening before in London — falls on the 2nd for
     * the Athens organisation and on the 1st for the London one; each is read in its own day, and
     * every organisation together adds each one's own day up.
     */
    public function testEachOrganisationsDayIsItsOwn(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_zoned');
        $schema->createTable('metrics_test_zoned', function ($table) {
            $table->integer('placed_at');
            $table->integer('org');
        });
        $moment = (new \DateTimeImmutable('2026-09-02 00:30', new \DateTimeZone('Europe/Athens')))->getTimestamp();
        foreach ([1, 2] as $org) {
            $this->db->queryBuilder()->table('metrics_test_zoned')->insert(['placed_at' => $moment, 'org' => $org]);
        }
        Metrics::register(Metric::flow('test.zoned', 'Orders, in each organisation\'s day')->tenant('org')->source(function (Window $w, array $by, Database $db): array {
            $query = $db->queryBuilder()->table('metrics_test_zoned');

            return $query->select([...$by, $query->raw('COUNT(*) AS value')])
                ->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->groupBy($by)->getAllOrFail();
        }));
        Application::getInstance()->applicationInfo['metrics'] = [
            'timezone'         => static fn (int $tenant): string => match ($tenant) { 2 => 'Europe/London', 3 => 'Nowhere/Here', default => 'Europe/Athens' },
            'tenants'          => static fn (): array => [1, 2],
            'default_timezone' => 'Europe/Athens',
        ];

        try {
            // Act
            Metrics::compute(['test.zoned'], ['day'], '2026-09-01', '2026-09-02', false, self::at('2026-11-06 12:00'));
            $read = static fn (?int $tenant, string $day): ?float => ($tenant === null ? Metrics::query('test.zoned') : Metrics::query('test.zoned')->tenant($tenant))->grain('day')->at($day)->value();
            $athens = [$read(1, '2026-09-01'), $read(1, '2026-09-02')];
            $london = [$read(2, '2026-09-01'), $read(2, '2026-09-02')];
            $both   = [$read(null, '2026-09-01'), $read(null, '2026-09-02')];
            $zones  = Metrics::zones();

            // Assert
            $this->assertSame([0.0, 1.0], $athens, 'Athens: the 2nd');
            $this->assertSame([1.0, 0.0], $london, 'London: still the 1st');
            $this->assertSame([1.0, 1.0], $both, 'each organisation\'s own day, added up');
            $this->assertSame(['Europe/Athens' => [1], 'Europe/London' => [2]], $zones);
            $this->assertSame('Europe/Athens', Window::zone()->getName(), 'the installation\'s zone, for everything else');
            $this->assertSame('Europe/Athens', Window::zoneOf(3)->getName(), 'a zone that is none: the installation\'s');
        } finally {
            $schema->dropTableIfExists('metrics_test_zoned');
        }
    }

    /**
     * A declared source: the framework makes the query from the table, its time column — unix
     * seconds here, read as they are — what is counted, its conditions and each dimension's
     * column or expression; a sum, a count and a distinct count, by breakdown and in total; a
     * declaration with no time column or nothing to count, and a column that is no column, refused.
     */
    public function testADeclaredSourceIsQueriedByTheFramework(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_invoices');
        $schema->createTable('metrics_test_invoices', function ($table) {
            $table->integer('date');
            $table->decimal('total', 10, 2);
            $table->smallInteger('status');
            $table->string('country', 4);
            $table->integer('userid');
            $table->timestampTz('created')->nullable();
        });
        foreach ([['gr', 10, 1, 1], ['gr', 20, 1, 1], ['uk', 5, 1, 2], ['gr', 99, 0, 3]] as [$country, $total, $status, $user]) {
            $this->db->queryBuilder()->table('metrics_test_invoices')->insert(['date' => self::at('2026-09-03 10:00'), 'total' => $total, 'status' => $status, 'country' => $country, 'userid' => $user,
                'created' => \Pramnos\Database\Instant::utc(self::at('2026-09-03 10:00'), $this->db)]);
        }
        Metrics::register(
            Metric::flow('test.revenue', 'Revenue')->from('metrics_test_invoices')->at('date', unix: true)->sum('total')->where('status', 1)->dimension('market', 'country'),
            Metric::flow('test.paid', 'Paid invoices')->from('metrics_test_invoices')->at('date', unix: true)->count()->where('status', [1]),
            Metric::flow('test.by_time', 'Revenue by a time column')->from('metrics_test_invoices')->at('created')->sum('total')->where('status', 1),
            Metric::flow('test.payers', 'Paying people')->from('metrics_test_invoices')->at('date', unix: true)->countDistinct('userid')->where('status', 1)->dimension('upper', 'UPPER(country)'),
        );
        $refused = [];
        foreach ([
            static fn () => Metric::flow('test.x')->at('date'),
            static fn () => Metric::flow('test.x')->from('t')->sum('total; DROP TABLE t'),
            static fn () => Metric::flow('test.x')->from('t')->sum('total')->measure(Window::of('day', '2026-09-03'), [], Factory::getDatabase()),
        ] as $attempt) {
            try {
                $attempt();
            } catch (\LogicException|\InvalidArgumentException $e) {
                $refused[] = $e::class;
            }
        }

        try {
            // Act
            Metrics::compute(['test.revenue', 'test.paid', 'test.payers', 'test.by_time'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));

            // Assert
            $this->assertSame(35.0, Metrics::value('test.revenue', 'month', '2026-09-01'), 'the paid ones added up');
            $this->assertSame(30.0, Metrics::value('test.revenue', 'month', '2026-09-01', ['market' => 'gr']));
            $this->assertSame(3.0, Metrics::value('test.paid', 'month', '2026-09-01'));
            $this->assertSame(35.0, Metrics::value('test.by_time', 'month', '2026-09-01'), 'a time column read as UTC');
            $this->assertSame(2.0, Metrics::value('test.payers', 'month', '2026-09-01'), 'people, once each');
            $this->assertSame(1.0, Metrics::value('test.payers', 'month', '2026-09-01', ['upper' => 'GR']), 'a dimension by an expression');
            $this->assertSame([\LogicException::class, \InvalidArgumentException::class, \LogicException::class], $refused);
        } finally {
            $schema->dropTableIfExists('metrics_test_invoices');
        }
    }

    /**
     * A declared query lists its own records (FW-457): the columns asked, by heading, in the
     * period, with its conditions and a filter by a dimension's column or expression, the newest
     * first; a filter on a dimension it has not is refused, so a viewer held to one region never
     * lists another's; and rows() with no column, or before from(), is refused.
     */
    public function testADeclaredQueryListsItsRecords(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_invoices');
        $schema->createTable('metrics_test_invoices', function ($table) {
            $table->integer('date');
            $table->decimal('total', 10, 2);
            $table->smallInteger('status');
            $table->string('country', 4);
            $table->integer('userid');
        });
        foreach ([['gr', 10, 1, 1, '2026-09-03 10:00'], ['gr', 20, 1, 2, '2026-09-04 10:00'], ['uk', 5, 1, 3, '2026-09-05 10:00'], ['gr', 99, 0, 4, '2026-09-06 10:00'],
            ['gr', 7, 1, 5, '2026-10-01 10:00']] as [$country, $total, $status, $user, $at]) {
            $this->db->queryBuilder()->table('metrics_test_invoices')->insert(['date' => self::at($at), 'total' => $total, 'status' => $status, 'country' => $country, 'userid' => $user]);
        }
        $metric = Metric::flow('test.revenue', 'Revenue')->from('metrics_test_invoices')->at('date', unix: true)->sum('total')->where('status', 1)
            ->dimension('market', 'country')->dimension('upper', 'UPPER(country)')->rows(['Person' => 'userid', 'total']);
        $window = Window::of('month', '2026-09-01');
        $refused = [];
        foreach ([
            static fn () => $metric->records($window, ['region' => 'north'], Factory::getDatabase()),
            static fn () => Metric::flow('test.x')->from('t')->rows([]),
            static fn () => Metric::flow('test.x')->rows(['a']),
        ] as $attempt) {
            try {
                $attempt();
            } catch (\DomainException|\InvalidArgumentException|\LogicException $e) {
                $refused[] = $e::class;
            }
        }

        try {
            // Act
            $all   = $metric->records($window, [], $this->db);
            $gr    = $metric->records($window, ['market' => 'gr'], $this->db);
            $upper = $metric->records($window, ['upper' => ['UK']], $this->db);

            // Assert
            $this->assertSame([3, 2, 1], array_map('intval', array_column($all, 'Person')), 'the paid ones of September, the newest first');
            $this->assertSame(['Person', 'total'], array_keys($all[0]), 'by heading, or by the column itself');
            $this->assertSame([2, 1], array_map('intval', array_column($gr, 'Person')));
            $this->assertSame([3], array_map('intval', array_column($upper, 'Person')), 'a dimension by an expression');
            $this->assertTrue($metric->hasRows());
            $this->assertSame([\DomainException::class, \InvalidArgumentException::class, \LogicException::class], $refused);
        } finally {
            $schema->dropTableIfExists('metrics_test_invoices');
        }
    }

    /**
     * Gaps: a period before the source's data begins (`since()`), or one the metric says it
     * cannot answer (`missing()`), is not computed — no nought written — and says why, to a query
     * and in a report's cell; `expect()` is kept, and a length that is none refused.
     */
    public function testAPeriodTheSourceCannotAnswerSaysWhy(): void
    {
        // Arrange
        $this->order('2026-09-10 10:00:00', 'north', 1);
        $this->order('2026-09-20 10:00:00', 'north', 2);
        Metrics::register(Metric::flow('test.since', 'Orders, counted since the 15th')->since('2026-09-15')
            ->missing(static fn (Window $w): ?string => $w->key() === '2026-09-20' ? 'The feed was down that day.' : null)
            ->expect('1 day')
            ->source(function (Window $w, array $by, Database $db): int {
                return (int) $db->queryBuilder()->table(self::ORDERS)->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->count();
            }));
        $refused = [];
        foreach ([static fn () => Metric::flow('test.x')->since('15/9'), static fn () => Metric::flow('test.x')->expect('soon')] as $attempt) {
            try {
                $attempt();
            } catch (\InvalidArgumentException $e) {
                $refused[] = $e->getMessage();
            }
        }

        // Act
        Metrics::compute(['test.since'], ['day'], '2026-09-09', '2026-09-21', false, self::at('2026-11-06 12:00'));
        $gaps   = Metrics::query('test.since')->grain('day')->between('2026-09-13', '2026-09-21')->gaps();
        $report = \Pramnos\Metrics\Report::fromArray(['name' => 'Gaps', 'grain' => 'day', 'columns' => [['label' => 'Before', 'offset' => 0], ['label' => 'After', 'offset' => 0]], 'sections' => [['rows' => [['metric' => 'test.since']]]]]);
        $cell   = $report->build('2026-09-10', (object) ['userid' => 1, 'usertype' => 99])['sections'][0]['rows'][0]['cells'][0];

        // Assert
        $this->assertNull(Metrics::value('test.since', 'day', '2026-09-10'), 'before its data: no nought written');
        $this->assertNull(Metrics::value('test.since', 'day', '2026-09-20'), 'a day it cannot answer');
        $this->assertSame(0.0, Metrics::value('test.since', 'day', '2026-09-16'));
        $this->assertSame(['2026-09-13' => 'No data before 2026-09-15.', '2026-09-14' => 'No data before 2026-09-15.', '2026-09-20' => 'The feed was down that day.'], $gaps);
        $this->assertSame(['value' => null, 'link' => null, 'error' => 'No data before 2026-09-15.'], $cell, 'a report\'s cell says why');
        $this->assertSame('1 day', Metrics::get('test.since')->getExpect());
        $this->assertCount(2, $refused);
    }

    /**
     * A mean, a least and a most, declared: each kept per organisation; a mean read over two
     * organisations is weighted by how many each is of (20, not the 25 of two means), the least
     * and the most are the least and the most of theirs; a direction and a format are kept, and
     * ones that are none refused.
     */
    public function testAMeanALeastAndAMost(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_deliveries');
        $schema->createTable('metrics_test_deliveries', function ($table) {
            $table->integer('at');
            $table->integer('org');
            $table->integer('minutes');
        });
        foreach ([[1, 10], [1, 10], [2, 40]] as [$org, $minutes]) {
            $this->db->queryBuilder()->table('metrics_test_deliveries')->insert(['at' => self::at('2026-09-05 12:00'), 'org' => $org, 'minutes' => $minutes]);
        }
        $declare = static fn (string $name): Metric => Metric::flow($name)->from('metrics_test_deliveries')->at('at', unix: true)->tenant('org');
        Metrics::register(
            $declare('test.mean')->avg('minutes')->direction(Metric::LOWER_IS_BETTER)->format('duration'),
            $declare('test.least')->min('minutes'),
            $declare('test.most')->max('minutes'),
        );
        $refused = 0;
        foreach ([static fn () => Metric::flow('test.x')->direction('up'), static fn () => Metric::flow('test.x')->format('emoji'), static fn () => Metric::flow('test.x')->aggregate('median')] as $attempt) {
            try {
                $attempt();
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }

        try {
            // Act
            Metrics::compute(['test.mean', 'test.least', 'test.most'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
            $read = static fn (string $metric, ?int $org = null): ?float => ($org === null ? Metrics::query($metric) : Metrics::query($metric)->tenant($org))->grain('month')->at('2026-09-01')->value();

            // Assert
            $this->assertSame([10.0, 40.0, 20.0], [$read('test.mean', 1), $read('test.mean', 2), $read('test.mean')], 'weighted by how many each is of');
            $this->assertSame([10.0, 40.0], [$read('test.least'), $read('test.most')]);
            $this->assertSame([Metric::LOWER_IS_BETTER, 'duration'], [Metrics::get('test.mean')->getDirection(), Metrics::get('test.mean')->getFormat()]);
            $this->assertSame('number', Metrics::get('test.least')->getFormat());
            $this->assertSame(3, $refused);
        } finally {
            $schema->dropTableIfExists('metrics_test_deliveries');
        }
    }

    /**
     * A corrected definition: version 2 is computed and read; the figures of version 1 — "as
     * before" — stay beside it, untouched by a recomputation, and are read with `version(1)`.
     */
    public function testACorrectedDefinitionKeepsTheOldFiguresBesideIt(): void
    {
        // Arrange
        Metrics::register(Metric::flow('test.fixed', 'Corrected')->version(2)->source(static fn (): int => 7));
        Metrics::import('test.fixed', 'month', '2026-08-01', [], 5);   // the figure of the first definition
        $refused = false;
        try {
            Metric::flow('test.x')->version(0);
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        // Act
        Metrics::compute(['test.fixed'], ['month'], '2026-08-01', '2026-08-31', true, self::at('2026-11-06 12:00'));
        Metrics::compute(['test.fixed'], ['month'], '2026-08-01', '2026-08-31', true, self::at('2026-11-06 13:00'));

        // Assert
        $this->assertSame(7.0, Metrics::value('test.fixed', 'month', '2026-08-01'), 'corrected');
        $this->assertSame(5.0, Metrics::query('test.fixed')->grain('month')->at('2026-08-01')->version(1)->value(), 'as before, kept');
        $this->assertSame(2, Metrics::get('test.fixed')->getVersion());
        $this->assertTrue($refused);
    }

    /**
     * Many periods of a declared unix-time source are read in one query on PostgreSQL — grouped by
     * the period in the metrics' zone — and give the same figures as one query each, which MySQL
     * keeps: an order at half past midnight in Athens is the next day's, by days and by weeks.
     */
    public function testManyPeriodsAreReadAtOnce(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_sales');
        $schema->createTable('metrics_test_sales', function ($table) {
            $table->integer('at');
            $table->string('region', 10);
            $table->integer('amount');
        });
        foreach ([['2026-09-07 10:00', 'north', 5], ['2026-09-08 00:30', 'north', 7], ['2026-09-13 23:30', 'south', 2], ['2026-09-14 00:10', 'south', 3]] as [$local, $region, $amount]) {
            $this->db->queryBuilder()->table('metrics_test_sales')->insert(['at' => self::at($local), 'region' => $region, 'amount' => $amount]);
        }
        $metric = Metric::flow('test.sales', 'Sales')->from('metrics_test_sales')->at('at', unix: true)->sum('amount')->dimension('region', 'region');
        Metrics::register($metric);

        try {
            // Act
            $ahead = $metric->prefetch(Window::range('day', '2026-09-07', '2026-09-08'), $this->db);
            $metric->forgetAhead();
            Metrics::compute(['test.sales'], ['day', 'week'], '2026-09-07', '2026-09-14', false, self::at('2026-11-06 12:00'));
            $days  = Metrics::series('test.sales', 'day', '2026-09-07', '2026-09-14')['values'][''];
            $weeks = Metrics::series('test.sales', 'week', '2026-09-07', '2026-09-14', [], 'region')['values'];

            // Assert
            $this->assertSame($this->db->type === 'postgresql', $ahead, 'read at once where it can be exactly');
            $this->assertSame([5.0, 7.0, 0.0, 0.0, 0.0, 0.0, 2.0, 3.0], $days, 'half past midnight is the next day');
            $this->assertSame(['north' => [12.0, 0.0], 'south' => [2.0, 3.0]], $weeks, 'Sunday night in the first week, Monday in the next');
        } finally {
            $schema->dropTableIfExists('metrics_test_sales');
        }
    }

    /**
     * Late data: a metric with a lateness keeps an ended period open, and computes it again at each
     * run, until the lateness has passed too; then it closes, and an order that comes later still is
     * left out unless recomputed.
     */
    public function testAPeriodStaysOpenForItsLateness(): void
    {
        // Arrange
        Metrics::register(Metric::flow('test.late', 'Orders, arriving late')->lateness('3 days')->source(function (Window $w, array $by, Database $db): int {
            return (int) $db->queryBuilder()->table(self::ORDERS)->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->count();
        }));
        $this->order('2026-09-30 10:00:00', 'north', 1);
        $refused = false;
        try {
            Metric::flow('test.x')->lateness('a while');
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        // Act — two days after September ends, again after an order arrives late, and after five days
        $first = Metrics::compute(['test.late'], ['month'], null, null, false, self::at('2026-10-02 12:00'));
        $this->order('2026-09-30 11:00:00', 'north', 2);
        Metrics::compute(['test.late'], ['month'], null, null, false, self::at('2026-10-03 12:00'));
        $openThen = $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('month'))->where('metric', 'test.late')->where('final', 1)->count();
        $late = Metrics::compute(['test.late'], ['month'], null, null, false, self::at('2026-10-05 12:00'));
        $this->order('2026-09-30 12:00:00', 'north', 3);
        Metrics::compute(['test.late'], ['month'], null, null, false, self::at('2026-10-06 12:00'));

        // Assert
        $this->assertSame(0, $first['closed'], 'still within its lateness');
        $this->assertSame(0, $openThen, 'open: not final');
        $this->assertSame(1, $late['closed'], 'closed once the lateness passed');
        $this->assertSame(2.0, Metrics::value('test.late', 'month', '2026-09-01'), 'the late order in; the one after it closed, not');
        $this->assertSame(3 * 86400, Metrics::get('test.late')->getLateness());
        $this->assertTrue($refused);
    }

    /**
     * A number sent to the application's own list: a report's cell links there, with the period
     * and the filters, instead of to the framework's records.
     */
    public function testANumberIsSentToTheApplicationsOwnList(): void
    {
        // Arrange
        Metrics::register(Metric::flow('test.drilled', 'Orders')->dimensions(['region'])
            ->drill(static fn (Window $w, array $filters): string => '/admin/Orders?from=' . $w->key() . '&region=' . ($filters['region'] ?? ''))
            ->source(static fn (Window $w, array $by): array => $by === [] ? [['value' => 2]] : [['region' => 'north', 'value' => 2]]));
        Metrics::compute(['test.drilled'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
        $report = \Pramnos\Metrics\Report::fromArray(['name' => 'Drill', 'grain' => 'month', 'sections' => [['rows' => [['metric' => 'test.drilled', 'filters' => ['region' => 'north']]]]]]);

        // Act
        $built = $report->build('2026-09-10', (object) ['userid' => 1, 'usertype' => 99]);
        $html  = \Pramnos\Metrics\Report::html($built, '/admin/MetricReports/rows');

        // Assert
        $this->assertSame('/admin/Orders?from=2026-09-01&region=north', $built['sections'][0]['rows'][0]['cells'][0]['link']['url']);
        $this->assertStringContainsString('href="/admin/Orders?from=2026-09-01&amp;region=north"', $html, 'the application\'s list, not the framework\'s');
        $this->assertNull(Metric::flow('test.none')->drillUrl(Window::of('day', '2026-09-01'), []));
    }

    /**
     * A meter and a latest reading: a counter's increase is each device's rises added up, a fall
     * a reset counted from nought, and the devices added together; the latest reading is the last
     * one in the period, whatever its size.
     */
    public function testAMeterAndALatestReading(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_meter');
        $schema->createTable('metrics_test_meter', function ($table) {
            $table->integer('at');
            $table->string('device', 4);
            $table->integer('reading');
        });
        foreach ([['a', '09-02 10:00', 100], ['a', '09-03 10:00', 150], ['a', '09-04 10:00', 30], ['a', '09-05 10:00', 60], ['b', '09-02 11:00', 10], ['b', '09-06 11:00', 20]] as [$device, $when, $reading]) {
            $this->db->queryBuilder()->table('metrics_test_meter')->insert(['at' => self::at('2026-' . $when), 'device' => $device, 'reading' => $reading]);
        }
        Metrics::register(
            Metric::flow('test.used', 'Used')->from('metrics_test_meter')->at('at', unix: true)->counter('reading', per: 'device')->dimension('device', 'device'),
            Metric::flow('test.latest', 'Latest reading')->from('metrics_test_meter')->at('at', unix: true)->last('reading')->where('device', 'a'),
        );

        try {
            // Act
            Metrics::compute(['test.used', 'test.latest'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));

            // Assert
            $this->assertSame(120.0, Metrics::value('test.used', 'month', '2026-09-01'), 'a: 50, a reset to 30, 30 — and b: 10');
            $this->assertSame(110.0, Metrics::value('test.used', 'month', '2026-09-01', ['device' => 'a']));
            $this->assertSame(60.0, Metrics::value('test.latest', 'month', '2026-09-01'), 'the last reading, not the largest');
        } finally {
            $schema->dropTableIfExists('metrics_test_meter');
        }
    }

    /**
     * A live metric is read from its source when asked and never kept: any breakdown of its
     * dimensions, filtered, by the periods asked — a period with nothing is nought — and a
     * computation writes nothing for it.
     */
    public function testALiveMetricIsReadFromItsSource(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_flow');
        $schema->createTable('metrics_test_flow', function ($table) {
            $table->integer('at');
            $table->string('device', 4);
            $table->string('zone', 4);
            $table->integer('litres');
        });
        foreach ([['d1', 'z1', '09-02 10:00', 3], ['d1', 'z1', '09-02 11:30', 4], ['d2', 'z2', '09-02 10:15', 5], ['d2', 'z2', '09-04 09:00', 1]] as [$device, $zone, $when, $litres]) {
            $this->db->queryBuilder()->table('metrics_test_flow')->insert(['at' => self::at('2026-' . $when), 'device' => $device, 'zone' => $zone, 'litres' => $litres]);
        }
        Metrics::register(Metric::flow('test.flow', 'Water')->from('metrics_test_flow')->at('at', unix: true)->sum('litres')
            ->dimension('device', 'device')->dimension('zone', 'zone')->live());

        try {
            // Act
            Metrics::compute(['test.flow'], ['day'], '2026-09-01', '2026-09-05', false, self::at('2026-11-06 12:00'));
            $kept   = $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('day'))->where('metric', 'test.flow')->count();
            $days   = Metrics::series('test.flow', 'day', '2026-09-02', '2026-09-04')['values'];
            $hours  = Metrics::query('test.flow')->grain('hour')->between('2026-09-02 10:00', '2026-09-02 11:00')->by('device')->series()['values'];
            $oneDev = Metrics::value('test.flow', 'day', '2026-09-02', ['device' => 'd1', 'zone' => 'z1']);

            // Assert
            $this->assertSame(0, $kept, 'never kept');
            $this->assertSame(['' => [12.0, 0.0, 1.0]], $days, 'a day with nothing is nought');
            $this->assertSame(['d1' => [3.0, 4.0], 'd2' => [5.0, 0.0]], $hours, 'by the hour, by device');
            $this->assertSame(7.0, $oneDev, 'any combination of its dimensions');
        } finally {
            $schema->dropTableIfExists('metrics_test_flow');
        }
    }

    /**
     * A cohort: of those who registered in September, how many paid within 7 and within 30 days
     * — read on its own for each, never added up — and the cohort's size as its total; the same
     * whether the event is kept as unix seconds or as a time.
     */
    public function testACohortCountsWhoReachedTheEventWithinEachSpan(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_people');
        $schema->createTable('metrics_test_people', function ($table) {
            $table->integer('regdate');
            $table->integer('firstpay')->nullable();
            $table->timestampTz('paid_at')->nullable();
        });
        foreach ([['09-01 10:00', '09-03 10:00'], ['09-05 10:00', '09-25 10:00'], ['09-10 10:00', null]] as [$registered, $paid]) {
            $this->db->queryBuilder()->table('metrics_test_people')->insert(['regdate' => self::at('2026-' . $registered),
                'firstpay' => $paid === null ? null : self::at('2026-' . $paid),
                'paid_at'  => $paid === null ? null : \Pramnos\Database\Instant::utc(self::at('2026-' . $paid), $this->db)]);
        }
        Metrics::register(
            Metric::cohort('test.paid_within', 'Paid within N days')->from('metrics_test_people')->of('regdate', unix: true)->event('firstpay', unix: true)->within([7, 30]),
            Metric::cohort('test.paid_within_t', 'The same, from a time')->from('metrics_test_people')->of('regdate', unix: true)->event('paid_at')->within([7, 30]),
        );
        $refused = false;
        try {
            Metric::cohort('test.x')->from('t')->within([]);
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        try {
            // Act
            Metrics::compute(['test.paid_within', 'test.paid_within_t'], ['month'], '2026-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
            $read = static fn (string $metric, ?string $within): ?float => Metrics::value($metric, 'month', '2026-09-01', $within === null ? [] : ['within' => $within]);

            // Assert
            $this->assertSame([3.0, 1.0, 2.0], [$read('test.paid_within', null), $read('test.paid_within', '7'), $read('test.paid_within', '30')], 'size, within 7 days, within 30');
            $this->assertSame([3.0, 1.0, 2.0], [$read('test.paid_within_t', null), $read('test.paid_within_t', '7'), $read('test.paid_within_t', '30')], 'an event kept as a time');
            $this->assertTrue($refused);
        } finally {
            $schema->dropTableIfExists('metrics_test_people');
        }
    }

    /**
     * A goal's progress within a day: the hours completed, against the share of the same weekday a
     * year earlier done by the same hour — its curve, not a straight line — scaled to the target,
     * with the forecast carried along it; against the mean of the last four same weekdays too; and
     * nothing at all where an hour of the reference was never computed.
     */
    public function testAGoalsProgressFollowsTheCurveOfItsReference(): void
    {
        // Arrange — last year's Monday: 10 at 09:00 and 30 at 14:00, nothing else, all hours computed
        Metrics::register(Metric::flow('test.sales_hourly', 'Sales')->grains(['hour', 'day'])->source(function (Window $w, array $by, Database $db): int {
            return (int) $db->queryBuilder()->table(self::ORDERS)->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->sum('customer');
        }));
        $this->order('2025-11-10 09:30:00', 'north', 10);
        $this->order('2025-11-10 14:30:00', 'north', 30);
        foreach ([1, 2, 3, 4] as $weeks) {
            $this->order(date('Y-m-d', strtotime('2026-11-09 -' . (7 * $weeks) . ' days')) . ' 09:30:00', 'north', 20);
        }
        $this->order('2026-11-09 09:15:00', 'north', 15);
        Metrics::compute(['test.sales_hourly'], ['hour'], '2025-11-10', '2025-11-10', false, self::at('2026-11-09 12:30'));
        Metrics::compute(['test.sales_hourly'], ['hour'], '2026-10-12', '2026-11-09', false, self::at('2026-11-09 12:30'));
        $goal     = \Pramnos\Metrics\Goals::set('test.sales_hourly', 'day', 400.0, '2026-11-09');
        $weekdays = \Pramnos\Metrics\Goals::set('test.sales_hourly', 'day', 400.0, '2026-11-09', reference: \Pramnos\Metrics\Goals::WEEKDAYS);

        // Act — at 12:30: hours to 11:00 completed
        $progress = \Pramnos\Metrics\Goals::progress($goal, self::at('2026-11-09 12:30'));
        $byWeeks  = \Pramnos\Metrics\Goals::progress($weekdays, self::at('2026-11-09 12:30'));
        $this->db->queryBuilder()->table(\Pramnos\Metrics\Store::table('hour'))->where('metric', 'test.sales_hourly')
            ->where('period_start', \Pramnos\Metrics\Store::instant($this->db, Window::of('hour', '2025-11-10 03:00')))->delete();
        $holed    = \Pramnos\Metrics\Goals::progress($goal, self::at('2026-11-09 12:30'));

        // Assert
        $this->assertSame(15.0, $progress['actual'], 'the hours completed today');
        $this->assertSame(0.25, $progress['share'], 'last year\'s Monday had done 10 of its 40 by noon');
        $this->assertSame(100.0, $progress['expected'], 'a quarter of the target');
        $this->assertSame(60.0, $progress['forecast'], 'carried along last year\'s curve');
        $this->assertSame('2026-11-09 11:00', $progress['through']);
        $this->assertSame(1.0, $byWeeks['share'], 'the last four Mondays had all theirs by 10:00');
        $this->assertNull($holed, 'an hour of the reference missing: nothing to judge by');
        $this->assertSame($goal, \Pramnos\Metrics\Goals::for('test.sales_hourly', 'day', '2026-11-09')['id']);
    }

    /**
     * A goal relative to its reference — the same day last year plus a growth — set once for every
     * day: its target worked out each day (40 last year, plus 10%), changed by `update()`; its way
     * there hour by hour for a chart — the actual so far, the curve expected, the forecast from the
     * last hour counted, the target; a goal with neither target nor growth refused; the growth
     * column added by itself where the table was made without it. The goal card reads the goal
     * kept for the metric when it has none of its own, and draws the way there; on the dashboard it
     * is offered to whoever may read a metric.
     */
    public function testAGoalRelativeToLastYearAndItsWayThere(): void
    {
        // Arrange — last year's Monday: 10 at 09:00 and 30 at 14:00; today 15 by 11:00
        Metrics::register(Metric::flow('test.sales_hourly', 'Sales')->grains(['hour', 'day'])->source(function (Window $w, array $by, Database $db): int {
            return (int) $db->queryBuilder()->table(self::ORDERS)->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->sum('customer');
        }));
        $this->order('2025-11-10 09:30:00', 'north', 10);
        $this->order('2025-11-10 14:30:00', 'north', 30);
        $this->order('2026-11-09 09:15:00', 'north', 15);
        Metrics::compute(['test.sales_hourly'], ['hour'], '2025-11-10', '2025-11-10', false, self::at('2026-11-09 12:30'));
        Metrics::compute(['test.sales_hourly'], ['hour'], '2026-11-09', '2026-11-09', false, self::at('2026-11-09 12:30'));
        $at   = self::at('2026-11-09 12:30');
        $goal = \Pramnos\Metrics\Goals::set('test.sales_hourly', 'day', 0, growth: 0.10);
        $refused = null;
        try {
            \Pramnos\Metrics\Goals::set('test.sales_hourly', 'day', 0);
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }

        // Act
        $kept     = \Pramnos\Metrics\Goals::for('test.sales_hourly', 'day', '2026-11-09');
        $trace    = \Pramnos\Metrics\Goals::trace(['period' => '2026-11-09'] + $kept, $at);
        \Pramnos\Metrics\Goals::update($goal, ['growth' => 0.0]);
        $flat     = \Pramnos\Metrics\Goals::progress(['period' => '2026-11-09'] + \Pramnos\Metrics\Goals::get($goal), $at);
        // A table made before growth existed: the column comes back by itself.
        $this->db->schema()->table('stats.goals', static fn ($t) => $t->dropColumn('growth'));
        (new \ReflectionProperty(\Pramnos\Metrics\Goals::class, 'ready'))->setValue(null, []);
        $again    = \Pramnos\Metrics\Goals::get($goal);
        \Pramnos\Metrics\Goals::update($goal, ['growth' => 0.10]);
        $entry    = ['id' => 1, 'name' => 'Day', 'report' => \Pramnos\Metrics\Report::fromArray(['name' => 'Day', 'grain' => 'day'])];
        \Pramnos\Metrics\ReportBoard::enter($entry, '2026-11-09', (object) ['userid' => 6, 'usertype' => 99]);
        try {
            $card = (new \Pramnos\Metrics\Widgets\GoalWidget())->configure(['metric' => 'test.sales_hourly', 'show' => 'chart'])->render();
            $own  = (new \Pramnos\Metrics\Widgets\GoalWidget())->configure(['metric' => 'test.sales_hourly', 'growth' => '50'])->render();
        } finally {
            \Pramnos\Metrics\ReportBoard::leave();
        }
        $dashboard = \Pramnos\Dashboard\WidgetRegistry::all()['metric.goal'] ?? null;

        // Assert
        $this->assertSame('A goal needs a target above nought, or a growth over its reference.', $refused);
        $this->assertSame(0.10, $kept['growth'], 'one goal for every day');
        $this->assertEqualsWithDelta(44.0, $trace['target'], 1e-9, 'last year\'s 40, plus 10%');
        $this->assertEqualsWithDelta(11.0, $trace['expected'], 1e-9, 'a quarter of it by noon');
        $this->assertSame(15.0, $trace['actual']);
        $this->assertCount(24, $trace['steps'], 'every hour of the day');
        $this->assertSame(['00:00', 0.0, null], [$trace['steps'][0]['label'], $trace['steps'][0]['actual'], $trace['steps'][0]['forecast']]);
        $this->assertSame([15.0, 15.0], [$trace['steps'][11]['actual'], $trace['steps'][11]['forecast']], 'the forecast starts from the last hour counted');
        $this->assertNull($trace['steps'][12]['actual'], 'hours to come');
        $this->assertEqualsWithDelta(60.0, $trace['steps'][23]['forecast'], 1e-9, 'carried along last year\'s curve to the day\'s end');
        $this->assertEqualsWithDelta(44.0, $trace['steps'][23]['expected'], 1e-9);
        $this->assertEqualsWithDelta(40.0, $flat['target'], 1e-9, 'growth changed to nought: last year\'s total');
        $this->assertNull($again['growth'], 'the column back, empty');
        $this->assertStringContainsString('data-pf-chart', $card, 'the way there, as a chart');
        $this->assertStringContainsString('44', $card, 'the kept goal\'s target');
        $this->assertStringContainsString('60', $own, 'the card\'s own growth: 40 plus 50%');
        $this->assertInstanceOf(\Pramnos\Metrics\Widgets\DashboardGoalWidget::class, $dashboard);
        $this->assertTrue($dashboard->allowed((object) ['userid' => 6, 'usertype' => 99]));
        $this->assertFalse($dashboard->allowed(null), 'nobody signed in reads no metric');
    }

    /**
     * Across a dimension (FW-360, design §16.1): three channels, the third a competitor's
     * (`Dimension::base()` leaves it out of every total that does not name it). The first is
     * measured by followers every day, the second only on even days, and a fourth by subscribers. Followers
     * across the channels carry the second's last reading over the days it was not read
     * (`acrossBy`), so the total does not drop on odd days, nor rise on the day it is first read; an
     * `audience` made of followers or subscribers, the first each channel has (`coalesce`), adds
     * them the same way; and a goal reads that series.
     */
    public function testATotalAcrossChannelsCarriesEachChannelsLastReading(): void
    {
        // Arrange
        $base = self::at('2026-09-01 00:00');
        $day  = static fn (Window $w): int => (int) (($w->startsAt() - $base) / 86400) + 1;
        Metrics::dimension(\Pramnos\Metrics\Dimension::of('channel', 'Channel')->base(static fn (): array => ['1', '2', '4']));
        Metrics::register(
            Metric::level('test.followers', 'Followers')->dimensions(['channel'])->knowsThePast()->acrossBy('channel')
                ->source(static function (Window $w, array $by) use ($day): array|int {
                    $rows = [['channel' => '1', 'value' => 100 + 10 * $day($w)], ['channel' => '3', 'value' => 9999]];
                    if ($day($w) >= 3 && $day($w) % 2 === 0) {
                        $rows[] = ['channel' => '2', 'value' => 500];
                    }

                    return $by === [] ? array_sum(array_column($rows, 'value')) : $rows;
                }),
            Metric::level('test.subscribers', 'Subscribers')->dimensions(['channel'])->knowsThePast()
                ->source(static fn (Window $w, array $by): array|int => $by === [] ? 7 : [['channel' => '4', 'value' => 7]]),
        );
        Metrics::register(Metric::coalesce('test.audience', 'Audience', ['test.followers', 'test.subscribers'], 'channel'));
        Metrics::compute(['test.followers', 'test.subscribers', 'test.audience'], ['day'], '2026-09-01', '2026-09-06', false, self::at('2026-09-07 12:00'));
        $series = static fn (string $metric, array $where = []): array => Metrics::query($metric)->grain('day')->between('2026-09-01', '2026-09-06')->where($where)->series()['values'][''];

        // Act
        $across   = $series('test.followers');
        $one      = $series('test.followers', ['channel' => '1']);
        $rival    = $series('test.followers', ['channel' => '3']);
        $audience = $series('test.audience');
        $goal     = \Pramnos\Metrics\Goals::judge(['id' => 0, 'metric' => 'test.followers', 'grain' => 'once', 'period' => '', 'tenant' => 0, 'filters' => [],
            'target' => 2000, 'growth' => null, 'reference' => 'first', 'kind' => 'reach', 'starts_at' => $base, 'due_at' => self::at('2026-09-30 23:59:59')],
            self::at('2026-09-06 18:00'));

        // Assert
        $this->assertSame([610.0, 620.0, 630.0, 640.0, 650.0, 660.0], $across, 'the second\'s 500 from its first reading on, back to the first day, and over odd days; no competitor');
        $this->assertSame([110.0, 120.0, 130.0, 140.0, 150.0, 160.0], $one);
        $this->assertSame([9999.0, 9999.0, 9999.0, 9999.0, 9999.0, 9999.0], $rival, 'a competitor named is read');
        $this->assertSame([617.0, 627.0, 637.0, 647.0, 657.0, 667.0], $audience, 'followers for the first two, carried; subscribers for the fourth, which has no followers');
        $this->assertSame(660.0, $goal['current'], 'a goal across the channels reads the carried total');
    }

    /**
     * Achievements (FW-360, 16c): a level declared `milestones()`, rising 100 a day from 150 on 1
     * September, passes 250, 500 and 1000 — each dated the day it did, never 100, which it began
     * above — for the total and for each channel of its base, not a competitor's; a flow declared
     * `bestWeeks()` keeps its best week, the current one left out; a one-off reach goal met is kept
     * with its goal, and stays when the goal is deleted; a second scan keeps nothing twice. A
     * channel connected later, its first reading 3058, passes nothing at or below it — the days
     * before it was read are not noughts — and passes 5000 the day it does.
     */
    public function testAchievementsAreKeptOnce(): void
    {
        // Arrange
        require_once ROOT . '/database/migrations/framework/stats/2026_11_09_000010_create_achievements_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Stats\CreateAchievementsTable::class], $this->db);
        $this->db->queryBuilder()->table(\Pramnos\Metrics\Achievements::TABLE)->delete();
        $base = self::at('2026-09-01 00:00');
        $day  = static fn (Window $w): int => (int) (($w->startsAt() - $base) / 86400);
        Metrics::dimension(\Pramnos\Metrics\Dimension::of('channel', 'Channel')->values(['1' => 'Shop', '2' => 'Later', '3' => 'Rival'])->base(static fn (): array => ['1', '2']));
        Metrics::register(
            Metric::level('test.fans', 'Fans')->dimensions(['channel'])->knowsThePast()->acrossBy('channel')->milestones()
                ->source(static function (Window $w, array $by) use ($day): array|int {
                    // Channel 2 connected on the 10th, its first reading 3058.
                    $later = $day($w) >= 9 ? 3058 + 1000 * ($day($w) - 9) : null;
                    $rows  = [['channel' => '1', 'value' => 150 + 100 * $day($w)], ['channel' => '3', 'value' => 99999]];
                    if ($later !== null) {
                        $rows[] = ['channel' => '2', 'value' => $later];
                    }

                    return $by === [] ? 150 + 100 * $day($w) + 99999 + (int) $later : $rows;
                }),
            Metric::flow('test.views', 'Views')->bestWeeks()->source(static fn (Window $w): int => $w->startsAt() >= self::at('2026-09-07 00:00') && $w->startsAt() < self::at('2026-09-14 00:00') ? 50 : 5),
        );
        $now = self::at('2026-09-20 12:00');
        Metrics::compute(['test.fans', 'test.views'], ['day'], '2026-09-01', '2026-09-20', false, $now);
        $goal = \Pramnos\Metrics\Goals::create(['metric' => 'test.fans', 'kind' => 'reach', 'target' => 800, 'starts' => '2026-09-01', 'due' => '2026-09-30', 'filters' => ['channel' => '1']]);

        // Act
        $first  = \Pramnos\Metrics\Achievements::scan($now);
        $second = \Pramnos\Metrics\Achievements::scan($now);
        \Pramnos\Metrics\Goals::delete($goal);
        $list   = \Pramnos\Metrics\Achievements::list(0);
        $kept   = array_map(static fn (array $a): string => $a['kind'] . ' ' . json_encode($a['dims']) . ' ' . $a['value'] . ' ' . $a['achieved_at'], $list);
        sort($kept);

        // Assert
        $this->assertSame(0, $second, 'nothing twice');
        $this->assertSame(count($kept), $first);
        $this->assertContains('milestone {"channel":"1"} 250 2026-09-02', $kept, 'the day it passed');
        $this->assertContains('milestone {"channel":"1"} 1000 2026-09-10', $kept);
        $this->assertNotContains('milestone {"channel":"1"} 100 2026-09-01', $kept, 'it began above 100');
        $this->assertEmpty(array_filter($kept, static fn (string $k): bool => str_contains($k, '"3"')), 'no competitor\'s channel');
        $this->assertContains('milestone {"channel":"2"} 5000 2026-09-12', $kept, 'the later channel: the day it passed 5000');
        $this->assertSame([], array_values(array_filter($kept, static fn (string $k): bool => preg_match('/^milestone \{"channel":"2"\} (\d+) /', $k, $m) === 1 && (int) $m[1] <= 3058)),
            'nothing at or below its first reading: the days before it was connected are not noughts');
        $this->assertContains('best [] 350 2026-09-07', $kept, 'the week of the 7th, 50 a day');
        $this->assertContains('goal {"channel":"1"} 800 2026-09-08', $kept, 'met the day it crossed 800, kept though its goal is gone');
        $this->assertStringContainsString('Fans on Shop', implode(' | ', array_column($list, 'says')));
    }

    /**
     * The kinds of goal on a total and on a level (FW-360, design §16):
     * - a monthly `count` on a total, judged on its current month, with each past month judged as
     *   it stood at its end, and a streak of the met ones;
     * - a one-off `growth` on a total against the same length before it;
     * - on a level rising 10 a day: `reach` met on the day it crosses, on track by the trend's
     *   crossing day, behind when that day is after the due date, unknown with too few readings;
     *   `stalled` on a level that does not move; `sustain` with its worst reading; `pace` and
     *   level `growth` from the first reading;
     * - a goal with neither target nor growth, a recurring one with a due date (ignored), and a
     *   change that forgets the state.
     */
    public function testEveryKindOfGoal(): void
    {
        // Arrange — sales: 10 a day in August, 5 a day in September, 10 a day in October so far
        foreach (['2026-08' => 31, '2026-09' => 30, '2026-10' => 20] as $month => $days) {
            for ($d = 1; $d <= $days; $d++) {
                $this->order(sprintf('%s-%02d 12:00:00', $month, $d), 'north', $month === '2026-09' ? 5 : 10);
            }
        }
        $base = self::at('2026-09-01 00:00');
        Metrics::register(
            Metric::flow('test.sold', 'Sold')->source(function (Window $w, array $by, Database $db): int {
                return (int) $db->queryBuilder()->table(self::ORDERS)->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->sum('customer');
            }),
            Metric::level('test.rising', 'Rising')->knowsThePast()->source(static fn (Window $w): int => max(0, (int) (($w->endsAt() - $base) / 86400)) * 10),
            Metric::level('test.flat', 'Flat')->knowsThePast()->source(static fn (): int => 50),
            Metric::level('test.falling', 'Falling')->knowsThePast()->source(static fn (Window $w): int => 1000 - max(0, (int) (($w->endsAt() - $base) / 86400)) * 10),
        );
        $now = self::at('2026-10-20 18:00');
        Metrics::compute(['test.sold', 'test.rising', 'test.flat', 'test.falling'], ['day'], '2026-07-25', '2026-10-20', false, $now);
        $goals = \Pramnos\Metrics\Goals::class;
        $refused = [];
        foreach ([['metric' => 'test.sold'], ['metric' => 'test.sold', 'target' => 5], ['metric' => 'test.sold', 'target' => 5, 'repeats' => 'fortnight'],
            ['metric' => 'test.sold', 'target' => 5, 'due' => '2026-10-01', 'starts' => '2026-10-05']] as $bad) {
            try {
                $goals::create($bad);
            } catch (\InvalidArgumentException $e) {
                $refused[] = $e->getMessage();
            }
        }

        // Act
        $monthly = $goals::create(['metric' => 'test.sold', 'target' => 250, 'repeats' => 'month', 'due' => '2026-10-31']);
        $judged  = $goals::judge($monthly, $now);
        $growth  = $goals::judge($goals::create(['metric' => 'test.sold', 'kind' => 'growth', 'target' => 50, 'starts' => '2026-10-01', 'due' => '2026-10-30']), $now);
        $reach   = static fn (float $target, string $due, string $starts = '2026-09-01') => $goals::judge($goals::create(['metric' => 'test.rising', 'target' => $target, 'starts' => $starts, 'due' => $due]), $now);
        $met     = $reach(300, '2026-12-31');
        $onTrack = $reach(800, '2026-12-31');
        $late    = $reach(800, '2026-10-25');
        $early   = $reach(800, '2026-12-31', '2026-10-15');
        $stalled = $goals::judge($goals::create(['metric' => 'test.flat', 'target' => 80, 'starts' => '2026-09-01', 'due' => '2026-12-31']), $now);
        $sustain = $goals::judge($goals::create(['metric' => 'test.falling', 'kind' => 'sustain', 'target' => 600, 'starts' => '2026-09-01', 'due' => '2026-12-31']), $now);
        $pace    = $goals::judge($goals::create(['metric' => 'test.rising', 'kind' => 'pace', 'target' => 500, 'starts' => '2026-10-01', 'due' => '2026-10-31']), $now);
        $levelUp = $goals::judge($goals::create(['metric' => 'test.rising', 'kind' => 'growth', 'target' => 100, 'starts' => '2026-10-01', 'due' => '2026-10-31']), $now);
        $this->db->queryBuilder()->table($goals::TABLE)->where('goalid', $monthly)->update(['last_state' => 'behind']);
        $goals::update($monthly, ['target' => 200]);
        $changed = $goals::get($monthly);

        // Assert
        $this->assertSame(['A goal needs a target above nought, or a growth over its reference.', 'A goal needs a date to be met by.',
            'A goal recurs every day, week, month or quarter.', 'A goal is met by a day after it starts.'], $refused);
        $this->assertSame(['count', 200.0, 'on_track'], [$judged['kind'], $judged['current'], $judged['state']], '200 of 250 by the 20th of 31');
        $this->assertStringStartsWith('This month: ', $judged['says']);
        $this->assertSame('2026-10-01', $judged['period']);
        $this->assertNull($changed['due_at'], 'a recurring goal has no due date');
        $months = array_column($judged['history'], 'met', 'from');
        $this->assertSame([true, false], [$months['2026-08-01'], $months['2026-09-01']], 'August 310, September 150');
        $this->assertNull($months['2026-06-01'], 'nothing that month: neither');
        $this->assertSame(0, $judged['streak'], 'September missed');
        $this->assertSame([225.0, 'on_track'], [$growth['target'], $growth['state']], 'the 30 days before: 150 in September, plus 50%');
        $this->assertSame(['met', '2026-09-30'], [$met['state'], $met['met_at']]);
        $this->assertSame('Reached on 30 September.', $met['says'], 'the sentence\'s day in words; the field keeps Y-m-d');
        $this->assertMatchesRegularExpression('/the goal is \d+ by 31 October, and about \d+ by today\.$/', $levelUp['says'],
            'a metric counting whole things: the figures worked out from it are whole');
        $this->assertStringContainsString('2027', $goals::judge($goals::create(['metric' => 'test.rising', 'target' => 99999, 'starts' => '2026-09-01', 'due' => '2027-03-31']), $now)['says'],
            'a day in another year names it');
        $this->assertSame('on_track', $onTrack['state']);
        $this->assertSame('2026-11-19', $onTrack['projected'], 'ten a day from 500 on 20 October: 30 days, across the clocks changing');
        $this->assertSame('behind', $late['state']);
        $this->assertSame('unknown', $early['state']);
        $this->assertStringContainsString('more reading(s) needed', $early['says']);
        $this->assertSame('stalled', $stalled['state']);
        $this->assertSame(['behind', 600.0], [$sustain['state'], $sustain['worst'] < 600 ? 600.0 : $sustain['worst']], 'it fell below 600');
        $this->assertSame('pace', $pace['kind']);
        $this->assertSame('growth', $levelUp['kind']);
        $this->assertNull($changed['last_state'], 'a changed goal is judged afresh');
        $this->assertSame(200.0, $changed['target']);
    }

    /**
     * A month's goal follows the days of the same month a year earlier; a goal for every period
     * gives way to one set for the period; a grain or a reference it does not know is refused; a
     * goal taken away is gone, and a goal that is not there has no progress.
     */
    public function testAMonthsGoalAndTheEdges(): void
    {
        // Arrange — last October: 30 on the 1st, 10 on the 2nd, every day computed
        $this->order('2025-10-01 10:00:00', 'north', 1);
        $this->order('2025-10-02 10:00:00', 'north', 2);
        $this->order('2026-10-01 10:00:00', 'north', 3);
        Metrics::compute(['test.orders'], ['day'], '2025-10-01', '2025-10-31', false, self::at('2026-10-02 12:00'));
        Metrics::compute(['test.orders'], ['day'], '2026-10-01', '2026-10-02', false, self::at('2026-10-02 12:00'));
        $every = \Pramnos\Metrics\Goals::set('test.orders', 'month', 10.0);
        $mine  = \Pramnos\Metrics\Goals::set('test.orders', 'month', 4.0, '2026-10-15');
        $refused = 0;
        foreach ([static fn () => \Pramnos\Metrics\Goals::set('test.orders', 'week', 1.0), static fn () => \Pramnos\Metrics\Goals::set('test.orders', 'day', 1.0, reference: 'luck')] as $attempt) {
            try {
                $attempt();
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }

        // Act — on the 2nd at noon: the 1st completed
        $progress = \Pramnos\Metrics\Goals::progress($mine, self::at('2026-10-02 12:00'));
        $found    = \Pramnos\Metrics\Goals::for('test.orders', 'month', '2026-10-20');
        $other    = \Pramnos\Metrics\Goals::for('test.orders', 'month', '2026-12-05');
        \Pramnos\Metrics\Goals::delete($mine);

        // Assert
        $this->assertSame([1.0, 0.5, 2.0], [$progress['actual'], $progress['share'], $progress['expected']], 'last October had done one of its two by the 2nd');
        $this->assertSame($mine, $found['id'], 'the period\'s own goal first');
        $this->assertSame($every, $other['id'], 'else the one for every month');
        $this->assertNull(\Pramnos\Metrics\Goals::get($mine));
        $this->assertNull(\Pramnos\Metrics\Goals::progress($mine));
        $this->assertSame(2, $refused);
    }

    /**
     * Alerts: a threshold crossed by the last completed period; a fall against a year earlier;
     * data that stopped — nought for the last two completed hours — only within its hours of the
     * day; a rule that fired waits before it may fire again; a kind, a direction or hours it does
     * not know are refused.
     */
    public function testAlertsFireOnWhatTheyWatch(): void
    {
        // Arrange — September: 3 orders; September a year before: 10; the morning of 6 November: one at 08:20
        foreach (['2026-09-05 10:00:00', '2026-09-06 10:00:00', '2026-09-07 10:00:00', '2026-11-06 08:20:00'] as $at) {
            $this->order($at, 'north', 1);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->order('2025-09-10 10:00:00', 'north', 2);
        }
        Metrics::register(Metric::flow('test.hourly_orders', 'Orders')->grains(['hour'])->source(function (Window $w, array $by, Database $db): int {
            return (int) $db->queryBuilder()->table(self::ORDERS)->where('placed_at', '>=', $w->startsAt())->where('placed_at', '<', $w->endsAt())->count();
        }));
        Metrics::compute(['test.orders'], ['month'], '2025-09-01', '2026-10-31', false, self::at('2026-11-06 12:00'));
        Metrics::compute(['test.hourly_orders'], ['hour'], '2026-11-06', '2026-11-06', false, self::at('2026-11-06 12:30'));
        $threshold = \Pramnos\Metrics\Alerts::create(['metric' => 'test.orders', 'grain' => 'month', 'kind' => 'threshold', 'op' => 'below', 'value' => 5]);
        $fall      = \Pramnos\Metrics\Alerts::create(['metric' => 'test.orders', 'grain' => 'month', 'kind' => 'change', 'op' => 'below', 'value' => -0.5, 'against' => Metrics::YEAR_EARLIER, 'name' => 'Orders']);
        $stopped   = \Pramnos\Metrics\Alerts::create(['metric' => 'test.hourly_orders', 'kind' => 'stopped', 'value' => 7200, 'hours' => '9-23']);
        $refused   = 0;
        foreach ([['kind' => 'mood'], ['kind' => 'threshold', 'op' => 'sideways'], ['kind' => 'threshold', 'hours' => 'mornings']] as $bad) {
            try {
                \Pramnos\Metrics\Alerts::create(['metric' => 'test.orders'] + $bad);
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }

        // Act — September's figures checked on 5 October; the hours at 12:30, then at 08:30 (outside 9-23)
        $september = \Pramnos\Metrics\Alerts::evaluate(self::at('2026-10-05 10:00'));
        $again     = \Pramnos\Metrics\Alerts::evaluate(self::at('2026-10-05 10:20'));
        $noon      = \Pramnos\Metrics\Alerts::evaluate(self::at('2026-11-06 12:30'));
        \Pramnos\Metrics\Alerts::enable($stopped);
        $this->db->queryBuilder()->table(\Pramnos\Metrics\Alerts::TABLE)->where('ruleid', $stopped)->update(['lastfired' => 0]);
        $morning   = \Pramnos\Metrics\Alerts::evaluate(self::at('2026-11-06 08:30'));
        \Pramnos\Metrics\Alerts::delete($threshold);

        // Assert
        $this->assertSame([$threshold, $fall, $stopped], array_keys($september), 'three is below five; three against ten is -70 %; and no orders those October hours');
        $this->assertStringContainsString('-70.0%', $september[$fall]);
        $this->assertSame([], $again, 'not again within the hour');
        $this->assertArrayHasKey($stopped, $noon, 'nothing at 10:00 and 11:00');
        $this->assertSame([], $morning, 'outside its hours');
        $this->assertSame(0, $this->db->queryBuilder()->table(\Pramnos\Metrics\Alerts::TABLE)->where('ruleid', $threshold)->count());
        $this->assertSame(3, $refused);
    }

    /**
     * Notes on the figures: a moment and a span, an organisation's and everybody's, read by range
     * — a span still running counts — and placed at the period each begins in for a chart, which
     * draws them and lists them under it; a deploy the orchestrator notices is noted; a note with
     * no words, or ending before it starts, is refused.
     */
    public function testNotesAreKeptAndDrawnOnTheCharts(): void
    {
        // Arrange
        $campaign = \Pramnos\Metrics\Annotations::add(self::at('2026-08-25 10:00'), 'Spring campaign', 'campaign', self::at('2026-09-10 00:00'), '/admin/Campaigns/3');
        $price    = \Pramnos\Metrics\Annotations::add(self::at('2026-09-15 09:00'), 'New prices', 'price', null, null, 7);
        $refused  = 0;
        foreach ([static fn () => \Pramnos\Metrics\Annotations::add(1, '  '), static fn () => \Pramnos\Metrics\Annotations::add(10, 'x', 'note', 5)] as $attempt) {
            try {
                $attempt();
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }
        $orchestrator = new class extends \Pramnos\Console\DaemonOrchestrator {
            protected function buildDesiredProcesses(): array { return []; }

            protected function getDashboardTitle(): string { return 'TEST'; }

            protected function getEntryPoint(): string { return '/dev/null'; }
        };

        // Act
        $september = \Pramnos\Metrics\Annotations::between(self::at('2026-09-01 00:00'), self::at('2026-10-01 00:00'));
        $others    = \Pramnos\Metrics\Annotations::between(self::at('2026-09-01 00:00'), self::at('2026-10-01 00:00'), 3);
        $placed    = \Pramnos\Metrics\Annotations::forPeriods(Window::range('month', '2026-09-01', '2026-10-01'));
        $chart     = \Pramnos\Html\Chart::render('line', ['2026-09', '2026-10'], [['label' => 'Orders', 'data' => [1, 2]]], ['annotations' => $placed]);
        (new \ReflectionMethod($orchestrator, 'annotateDeploy'))->invoke($orchestrator, 'abcdef1234567890');
        $deploys   = array_values(array_filter(\Pramnos\Metrics\Annotations::between(time() - 60, time() + 60), static fn (array $n): bool => $n['kind'] === 'deploy'));
        \Pramnos\Metrics\Annotations::delete($price);

        // Assert
        $this->assertSame([$campaign, $price], array_column($september, 'id'), 'a span running into September counts');
        $this->assertSame([$campaign], array_column($others, 'id'), 'another organisation sees only everybody\'s');
        $this->assertSame([['at' => 0, 'text' => 'Spring campaign', 'kind' => 'campaign'], ['at' => 0, 'text' => 'New prices', 'kind' => 'price']], $placed);
        $this->assertStringContainsString('"annotations":[{"at":0,"text":"Spring campaign","kind":"campaign"}', $chart, 'drawn by the page');
        $this->assertStringContainsString('<li data-kind="price">2026-09: New prices</li>', $chart, 'and listed under the chart');
        $this->assertSame('Deployed abcdef12', $deploys[0]['words'] ?? null);
        $this->assertSame([$campaign], array_column(\Pramnos\Metrics\Annotations::between(self::at('2026-09-01 00:00'), self::at('2026-10-01 00:00')), 'id'));
        $this->assertSame(2, $refused);
    }

    /**
     * The JSON API: the catalogue of what the person may read, a series with its labels and gaps,
     * a value against a year earlier, a goal's progress — as the person asking — and refusals in
     * the API's shape: nobody signed in (401), a metric or a goal that is not theirs (404), a grain
     * that is none (422). Its four routes are mounted under the path given.
     */
    public function testTheMetricsAsJson(): void
    {
        // Arrange
        $this->order('2026-09-05 10:00:00', 'north', 1);
        $this->order('2025-09-05 10:00:00', 'north', 2);
        $this->order('2025-09-06 10:00:00', 'north', 3);
        Metrics::compute(['test.orders'], ['month'], '2025-09-01', '2026-09-30', false, self::at('2026-11-06 12:00'));
        $goal = \Pramnos\Metrics\Goals::set('test.orders', 'month', 4.0, '2026-09-01');
        $api  = static function (?object $as): \Pramnos\Metrics\MetricsApi {
            return new class ($as) extends \Pramnos\Metrics\MetricsApi {
                public function __construct(private ?object $as)
                {
                }

                protected function user(): ?object
                {
                    return $this->as;
                }
            };
        };
        $boss   = (object) ['userid' => 5, 'usertype' => 99];
        $router = new class (null) extends \Pramnos\Routing\Router {
            /** @var list<string> The paths mounted */
            public array $mounted = [];

            public function get($uri, $action, $permissions = null): \Pramnos\Routing\Route|\Pramnos\Routing\LocalizedRoute
            {
                $this->mounted[] = $uri;

                return parent::get($uri, $action, $permissions);
            }
        };

        // Act
        $catalogue = $api($boss)->catalogue();
        $_GET      = ['metric' => 'test.orders', 'grain' => 'month', 'from' => '2026-08-01', 'to' => '2026-09-01', 'f' => ['region' => 'north']];
        $series    = $api($boss)->series();
        $_GET      = ['metric' => 'test.orders', 'grain' => 'month', 'at' => '2026-09-01', 'compare' => Metrics::YEAR_EARLIER];
        $compared  = $api($boss)->value();
        $_GET      = ['metric' => 'test.orders', 'grain' => 'month', 'at' => '2026-09-01'];
        $value     = $api($boss)->value();
        $_GET      = ['id' => (string) $goal];
        $progress  = $api($boss)->goal();
        $nobody    = $api(null)->catalogue();
        $_GET      = ['metric' => 'test.nothing'];
        $unknown   = $api($boss)->series();
        $_GET      = ['metric' => 'test.orders', 'grain' => 'fortnight'];
        $badGrain  = $api($boss)->series();
        $_GET      = ['id' => '999999'];
        $noGoal    = $api($boss)->goal();
        \Pramnos\Metrics\MetricsApi::routes($router, '/api/metrics');
        $_GET      = [];
        // The goals over JSON: added, listed, changed, refused, taken away; what was reached.
        $method    = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST     = ['metric' => 'test.orders', 'target' => 10, 'repeats' => 'month'];
        $added     = $api($boss)->addGoal();
        $_POST     = ['metric' => 'test.nothing', 'target' => 1, 'repeats' => 'month'];
        $refused   = $api($boss)->addGoal();
        $_POST     = ['metric' => 'test.orders', 'repeats' => 'month'];
        $bare      = $api($boss)->previewGoal();
        $_POST     = ['metric' => 'test.orders', 'target' => 10, 'repeats' => 'month'];
        $previewed = $api($boss)->previewGoal();
        $_POST     = ['metric' => 'test.nothing', 'target' => 1];
        $noMetric  = $api($boss)->previewGoal();
        $_POST     = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $listed    = $api($boss)->goals();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        \Pramnos\Http\Request::setRawInput((string) json_encode(['target' => 12]));
        $changed   = $api($boss)->changeGoal((int) ($added['data']['id'] ?? 0));
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $gone      = $api($boss)->deleteGoal((int) ($added['data']['id'] ?? 0));
        $notThere  = $api($boss)->deleteGoal((int) ($added['data']['id'] ?? 0));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $reached   = $api($boss)->achievements();
        \Pramnos\Http\Request::setRawInput('');
        if ($method === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $method;
        }

        // Assert
        $this->assertSame(200, $catalogue['status']);
        $this->assertContains('test.orders', array_column($catalogue['data'], 'name'));
        $orders = array_values(array_filter($catalogue['data'], static fn (array $m): bool => $m['name'] === 'test.orders'))[0];
        $this->assertSame(['label' => 'region', 'parent' => null, 'map' => false], ((array) $orders['hierarchy'])['region'], 'each dimension, what it is a part of, and whether it maps');
        $this->assertSame(['2026-08-01', '2026-09-01'], $series['data']['periods']);
        $this->assertSame(['2026-08', '2026-09'], $series['data']['labels']);
        $this->assertSame([0.0, 1.0], ((array) $series['data']['values'])['']);
        $this->assertSame([1.0, 2.0, '2025-09'], [$compared['data']['value'], $compared['data']['other'], $compared['data']['against']]);
        $this->assertSame(['value' => 1.0], $value['data']);
        $this->assertSame($goal, $progress['data']['goal']['id']);
        $this->assertSame([401, 404, 422, 404], [$nobody['status'], $unknown['status'], $badGrain['status'], $noGoal['status']]);
        $this->assertSame(['/api/metrics/series', '/api/metrics/value', '/api/metrics/goal', '/api/metrics', '/api/metrics/goals/achievements', '/api/metrics/goals'], $router->mounted, 'mounted under the path given');
        $this->assertSame([201, 'count'], [$added['status'], $added['data']['kind']]);
        $this->assertSame([200, ''], [$bare['status'], $bare['data']['says']], 'no target yet: no sentence');
        $this->assertArrayHasKey('last', $bare['data'], 'but the last period\'s figure');
        $this->assertNotSame('', $previewed['data']['says'], 'a target typed: its sentence, nothing kept');
        $this->assertSame(404, $noMetric['status']);
        $this->assertContains($added['data']['id'], array_column($listed['data']['goals'], 'id'));
        $this->assertSame(['count', 'growth', 'reach', 'sustain', 'pace'], $listed['data']['kinds']);
        $this->assertSame(12.0, $changed['data']['target'], 'changed: the target, the rest kept');
        $this->assertSame([200, 404, 200, 404], [$changed['status'], $refused['status'], $gone['status'], $notThere['status']]);
        $this->assertSame(200, $reached['status']);
    }

    /**
     * `/admin/Goals`: a goal added, refused for a metric that is not there, listed judged, opened to
     * change, changed, previewed as typed (the last period's figure without a target, the sentence
     * with one), shown by the dashboard card, and deleted — once; every theme's template drawn.
     */
    public function testTheGoalsScreen(): void
    {
        // Arrange
        $this->order('2026-09-05 10:00:00', 'north', 1);
        Metrics::compute(['test.orders'], ['day', 'month'], '2026-08-01', '2026-11-30', false, time());
        $this->clearRequest();
        $this->signIn(98, 2);
        $screen = $this->screen(\Pramnos\Application\Controllers\Goals::class);
        Metrics::dimension(\Pramnos\Metrics\Dimension::of('region', 'Region')->values(['north' => 'North', 'south' => 'South']));
        $form   = ['metric' => 'test.orders', 'kind' => '', 'target' => '10', 'repeats' => 'month', 'starts' => '', 'due' => '', 'note' => 'Ten a month',
            'filters' => ['test.orders' => ['region' => 'north'], 'test.customers' => ['region' => 'south']]];

        try {
            // Act — add, refuse, list, open to change, change
            $this->request('POST', null, [], $form);
            $screen->save();
            $this->request('POST', null, [], ['metric' => 'test.nothing'] + $form);
            $screen->save();
            $this->request('POST', null, [], ['target' => '5', 'filters' => ['test.orders' => ['region' => 'east']]] + $form);
            $bogus = json_decode((string) $screen->preview()->getBody(), true);
            $this->request('GET');
            $screen->display();
            $list = (array) $screen->view;
            $id   = (int) $list['goals'][0]['goal']['id'];
            $this->request('GET', null, ['goal' => (string) $id]);
            $screen->display();
            $open = (array) $screen->view;
            $this->request('POST', null, [], ['goalid' => (string) $id, 'target' => '12', 'filters' => ['test.orders' => ['region' => 'south']]] + $form);
            $screen->save();
            $south = \Pramnos\Metrics\Goals::get($id)['filters'];
            $this->request('POST', null, [], ['goalid' => (string) $id, 'target' => '12', 'filters' => ['test.orders' => ['region' => '']]] + $form);
            $screen->save();
            $every = \Pramnos\Metrics\Goals::get($id)['filters'];
            $this->request('POST', null, [], ['target' => ''] + $form);
            $bare = json_decode((string) $screen->preview()->getBody(), true);
            $this->request('POST', null, [], $form);
            $typed = json_decode((string) $screen->preview()->getBody(), true);
            $this->request('POST', null, [], ['target' => '-1'] + $form);
            $wrong = $screen->preview();
            $card    = (new \Pramnos\Metrics\Widgets\DashboardGoalWidget())->configure(['goal' => (string) $id])->render();
            $missing = (new \Pramnos\Metrics\Widgets\DashboardGoalWidget())->configure(['goal' => '999999'])->render();
            $this->request('POST', null, [], ['goalid' => (string) $id]);
            $screen->delete();
            $screen->delete();

            // Assert
            $this->assertSame([['message', 'Saved.'], ['error', 'There is no such metric.'], ['message', 'Saved.'], ['message', 'Saved.'], ['message', 'Deleted.'], ['error', 'There is no such goal.']], $screen->flash);
            $this->assertSame([['region' => 'south'], []], [$south, $every], 'changed: another region, then "All"');
            $this->assertCount(1, $list['goals'], 'the refused one was not kept');
            $this->assertSame('Orders', $list['goals'][0]['label']);
            $this->assertSame(['region' => 'north'], $list['goals'][0]['goal']['filters'], 'the chosen metric\'s dimension value; another metric\'s ignored');
            $this->assertSame('North', $list['goals'][0]['on'], 'named in the list');
            $this->assertSame(['North', 'South'], array_values(array_column($list['metrics'], 'dims', 'name')['test.orders']['region']['values']), 'a select per dimension');
            $this->assertArrayHasKey('history', $list['goals'][0]['judged'], 'a recurring goal, judged with its past periods');
            $this->assertNull($list['editing']);
            $this->assertSame($id, $open['editing']['id'], 'opened to change');
            $this->assertNull(\Pramnos\Metrics\Goals::get($id), 'deleted');
            $this->assertSame('', $bare['says'], 'no target typed: no sentence');
            $this->assertArrayHasKey('last', $bare, 'but the last period\'s figure');
            $this->assertNotSame('', $typed['says'], 'a target typed: its sentence');
            $this->assertSame(422, $wrong->getStatusCode(), 'a target below nought refused');
            $this->assertArrayNotHasKey('error', $bogus, 'a value the dimension does not have: left out, the goal for every region');
            $this->assertStringContainsString('Orders', $card);
            $this->assertStringContainsString('Choose a metric', $missing, 'a goal not theirs is not kept in the card\'s settings');
            foreach (['bootstrap', 'plain-css', 'tailwind'] as $theme) {
                foreach (['list' => $list, 'open' => $open] as $which => $vars) {
                    $html = $this->render($theme, 'goals/goals.tpl.php', $vars);
                    $this->assertStringContainsString('pf-ui', $html, $theme . '/' . $which);
                    $this->assertStringContainsString('data-pf-goal="' . $id . '"', $html, $theme . '/' . $which . ': the goal listed');
                    $this->assertStringNotContainsString('{{', $html, $theme . '/' . $which . ': compiled');
                }
                $this->assertStringContainsString('name="goalid" value="' . $id . '"', $this->render($theme, 'goals/goals.tpl.php', $open), $theme . ': the form changes that goal');
                $this->assertStringContainsString('name="filters[test.orders][region]"', $this->render($theme, 'goals/goals.tpl.php', $list), $theme . ': a select per dimension');
            }
        } finally {
            $this->clearRequest();
        }
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
     * An open period of a grain the metric asks to refresh less often is passed by while it was
     * computed lately — its days still computed every run — and computed again once the time is
     * up; a period that ended is closed whatever. `metrics.refresh` sets it for every metric, the
     * metric's own wins; a length that is none is refused.
     */
    public function testAnOpenPeriodIsComputedAsOftenAsTheMetricAsks(): void
    {
        // Arrange
        $calls = [];
        Metrics::register(Metric::flow('test.refresh', 'Costly')->grains(['day', 'month'])->refresh(['month' => '1 hour'])
            ->source(static function (Window $w) use (&$calls): int {
                $calls[] = $w->grain;

                return 1;
            }));
        $now   = (int) gmmktime(10, 0, 0, 10, 15, 2026);
        $count = static function () use (&$calls): array {
            $by    = array_count_values($calls);
            $calls = [];

            return [$by['day'] ?? 0, $by['month'] ?? 0];
        };

        // Act
        Metrics::compute(['test.refresh'], null, null, null, false, $now);
        $first = $count();
        Metrics::compute(['test.refresh'], null, null, null, false, $now + 60);
        $soon = $count();
        Metrics::compute(['test.refresh'], null, null, null, false, $now + 3700);
        $later = $count();
        Application::getInstance()->applicationInfo['metrics']['refresh'] = ['day' => '1 day'];
        $configured = Metrics::get('test.refresh')->refreshOf('day');
        $refused = null;
        try {
            Metric::flow('test.never', 'x')->refresh(['month' => 'whenever']);
        } catch (\InvalidArgumentException $e) {
            $refused = $e;
        }

        // Assert
        $this->assertSame([2, 2], $first, 'the day and month just ended, closed, and the open ones');
        $this->assertSame([1, 0], $soon, 'the open day at every run; the open month computed a minute ago: passed by');
        $this->assertSame([1, 1], $later, 'an hour on: the month again');
        $this->assertSame(86400, $configured, 'metrics.refresh, for a grain the metric says nothing of');
        $this->assertSame(3600, Metrics::get('test.refresh')->refreshOf('month'), 'the metric\'s own wins');
        $this->assertNotNull($refused);
    }
}
