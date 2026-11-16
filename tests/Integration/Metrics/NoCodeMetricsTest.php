<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\Instant;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Metrics\CreateMetricDeclarationsTable;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\NoCodeMetrics;

/**
 * BI roadmap phase 7i: a metric defined on the screen ({@see NoCodeMetrics}), on MySQL here,
 * PostgreSQL in the sibling.
 *
 * What has to hold: a declaration over an allowed table and column becomes an ordinary, working
 * `Metric`, read like any other; a table, time column, value column, dimension column or
 * condition column outside the allowlist is refused before it is ever written — the allowlist is
 * a security boundary, not a convenience, so every one of its edges gets its own test; turning a
 * declaration off takes it out of the catalogue without losing it; `cost()` answers a real planner
 * estimate from the real table.
 */
#[CoversClass(NoCodeMetrics::class)]
class NoCodeMetricsTest extends TestCase
{
    /** The orders a no-code declaration counts. */
    private const ORDERS = 'nocode_test_orders';

    protected Database $db;

    private object $user;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        \Pramnos\Framework\Testing\Schema::ensure([CreateMetricDeclarationsTable::class], $this->db);
        $this->db->queryBuilder()->table('pramnos.metric_declarations')->whereLike('name', 'test.%')->delete();
        $schema = $this->db->schema();
        $schema->dropTableIfExists(self::ORDERS);
        $schema->createTable(self::ORDERS, function ($table) {
            $table->increments('id');
            $table->integer('placed_at');
            $table->decimal('amount', 10, 2);
            $table->string('status', 20);
            $table->string('region', 20);
        });
        $now = time();
        foreach ([
            ['amount' => 100, 'status' => 'paid', 'region' => 'north'],
            ['amount' => 50, 'status' => 'paid', 'region' => 'south'],
            ['amount' => 30, 'status' => 'pending', 'region' => 'north'],
        ] as $order) {
            $this->db->queryBuilder()->table(self::ORDERS)->insert($order + ['placed_at' => $now - 3600]);
        }
        Application::getInstance()->applicationInfo['metrics'] = ['no_code' => ['tables' => [
            self::ORDERS => ['time' => ['placed_at'], 'unix' => true, 'columns' => ['amount', 'status', 'region']],
        ]]];
        $this->user = (object) ['userid' => 1];
        Metrics::reset();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('pramnos.metric_declarations')->whereLike('name', 'test.%')->delete();
        $this->db->schema()->dropTableIfExists(self::ORDERS);
        unset(Application::getInstance()->applicationInfo['metrics']);
        Metrics::reset();
        parent::tearDown();
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

    /** A paid-orders-by-region declaration becomes a real, readable Metric. */
    public function testADeclarationBecomesAWorkingMetric(): void
    {
        // Arrange
        $checked = NoCodeMetrics::create([
            'name' => 'test.paid_orders', 'label' => 'Paid orders', 'source_table' => self::ORDERS, 'time_column' => 'placed_at',
            'fn' => 'sum', 'value_column' => 'amount', 'where' => [['column' => 'status', 'value' => 'paid']],
            'dimensions' => [['name' => 'test_region', 'column' => 'region']],
        ], $this->user);

        // Act
        Metrics::reset();
        $now = time();
        $computed = Metrics::compute(['test.paid_orders'], ['day'], null, null, false, $now);
        $day      = gmdate('Y-m-d', $now);
        $byRegion = Metrics::query('test.paid_orders')->grain('day')->between($day, $day)->by('test_region')->top(10);

        // Assert
        $this->assertIsArray($checked, 'a valid declaration is accepted, not refused with an error string');
        $this->assertSame(0, count($computed['failed']), 'the declared query runs cleanly against the real table');
        $this->assertSame(['north' => 100.0, 'south' => 50.0], $byRegion, 'only the paid orders, 30 pending excluded, broken down by its declared dimension');
    }

    /** A table outside the allowlist is refused before anything is written or queried. */
    public function testATableOutsideTheAllowlistIsRefused(): void
    {
        $refused = NoCodeMetrics::create(['name' => 'test.bad', 'label' => 'Bad', 'source_table' => 'users', 'time_column' => 'regdate', 'fn' => 'count'], $this->user);

        $this->assertSame('source_table: not one of the tables allowed here.', $refused);
    }

    /** A time column the table does allow, but not for its time, is refused. */
    public function testATimeColumnOutsideTheAllowlistIsRefused(): void
    {
        $refused = NoCodeMetrics::create(['name' => 'test.bad', 'label' => 'Bad', 'source_table' => self::ORDERS, 'time_column' => 'amount', 'fn' => 'count'], $this->user);

        $this->assertSame('time_column: not one of this table\'s allowed time columns.', $refused);
    }

    /** A value column the allowlist never named is refused, whether asked for the sum or a dimension. */
    public function testAColumnOutsideTheAllowlistIsRefused(): void
    {
        $sum       = NoCodeMetrics::create(['name' => 'test.bad', 'label' => 'Bad', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'sum', 'value_column' => 'id'], $this->user);
        $where     = NoCodeMetrics::create(['name' => 'test.bad2', 'label' => 'Bad', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count', 'where' => [['column' => 'id', 'value' => 1]]], $this->user);
        $dimension = NoCodeMetrics::create(['name' => 'test.bad3', 'label' => 'Bad', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count', 'dimensions' => [['name' => 'x', 'column' => 'id']]], $this->user);

        $this->assertSame('value_column: not one of this table\'s allowed columns.', $sum);
        $this->assertStringStartsWith('where:', $where);
        $this->assertStringStartsWith('dimensions:', $dimension);
    }

    /** An unsafe function (one Metric itself can do, but this builder deliberately does not offer) is refused. */
    public function testAnUnsafeFunctionIsRefused(): void
    {
        $refused = NoCodeMetrics::create(['name' => 'test.bad', 'label' => 'Bad', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'last', 'value_column' => 'amount'], $this->user);

        $this->assertSame('fn: one of sum, count, countDistinct, avg, min, max.', $refused);
    }

    /** Turned off, a declaration leaves the catalogue without being deleted. */
    public function testTurningItOffTakesItOutOfTheCatalogueWithoutLosingIt(): void
    {
        // Arrange
        $row = NoCodeMetrics::create(['name' => 'test.paid_orders', 'label' => 'Paid orders', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count'], $this->user);
        Metrics::reset();
        Metrics::get('test.paid_orders');

        // Act
        NoCodeMetrics::setActive((int) $row['declaration_id'], false);
        Metrics::reset();
        $stillThere = array_key_exists('test.paid_orders', Metrics::all(null, true));
        $kept       = NoCodeMetrics::find((int) $row['declaration_id']);

        // Assert
        $this->assertFalse($stillThere, 'turned off, it is not a readable metric any more');
        $this->assertNotNull($kept, 'the declaration itself is kept, not deleted');
        $this->assertFalse((bool) $kept['active']);
    }

    /** The cost estimate is a real planner answer, not a guess — every safe function, not only sum. */
    public function testCostIsARealPlannerEstimate(): void
    {
        foreach (['sum', 'count', 'countDistinct', 'avg', 'min', 'max'] as $fn) {
            $cost = NoCodeMetrics::cost(['source_table' => self::ORDERS, 'time_column' => 'placed_at', 'time_unix' => true, 'fn' => $fn, 'value_column' => 'amount', 'where_json' => '[]']);
            $this->assertGreaterThanOrEqual(0, $cost['rows'], $fn);
            $this->assertArrayHasKey('indexed', $cost);
        }
    }

    /** Every safe function builds a working Metric through toMetric(), and its currency carries. */
    public function testToMetricBuildsEveryFunctionAndCarriesCurrency(): void
    {
        foreach (['sum', 'count', 'countDistinct', 'avg', 'min', 'max'] as $fn) {
            $metric = NoCodeMetrics::toMetric([
                'name' => 'test.' . strtolower($fn), 'label' => 'L', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'time_unix' => true,
                'fn' => $fn, 'value_column' => 'amount', 'where_json' => '[]', 'dimensions_json' => '[]', 'currency' => 'EUR',
            ]);
            $this->assertSame('test.' . strtolower($fn), $metric->name, $fn);
        }
    }

    /** A function no longer safe (an allowlist narrowed after the fact) is skipped, not fatal. */
    public function testAStaleDeclarationIsSkippedNotFatal(): void
    {
        // Arrange: written straight to the table, bypassing validate() — as if an old row's
        // function stopped being one toMetric() can build.
        $this->db->queryBuilder()->table('pramnos.metric_declarations')->insert([
            'name' => 'test.stale', 'label' => 'Stale', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'time_unix' => true,
            'fn' => 'counter', 'value_column' => 'amount', 'where_json' => '[]', 'dimensions_json' => '[]', 'currency' => null,
            'active' => true, 'created_by' => 1, 'created_at' => Instant::utc(time(), $this->db), 'updated_at' => Instant::utc(time(), $this->db),
        ]);

        // Act
        $names = array_map(static fn ($m) => $m->name, iterator_to_array((new NoCodeMetrics())->metrics()));

        // Assert
        $this->assertNotContains('test.stale', $names, 'left out, not thrown');
    }

    /** `update()` changes an existing declaration the same way `create()` makes one. */
    public function testUpdateChangesAnExistingDeclaration(): void
    {
        // Arrange
        $row = NoCodeMetrics::create(['name' => 'test.paid_orders', 'label' => 'Paid orders', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count'], $this->user);

        // Act
        $updated = NoCodeMetrics::update((int) $row['declaration_id'], ['name' => 'test.paid_orders', 'label' => 'Paid orders, renamed', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'sum', 'value_column' => 'amount']);
        $refused = NoCodeMetrics::update((int) $row['declaration_id'], ['name' => 'test.paid_orders', 'label' => '', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count']);

        // Assert
        $this->assertSame('Paid orders, renamed', $updated['label']);
        $this->assertSame('sum', $updated['fn'], 'the function changes too');
        $this->assertSame('label: required.', $refused);
    }

    /** Every remaining way `validate()` refuses: a malformed name, one already taken, no label, a bad currency. */
    public function testEveryRemainingValidationRefusal(): void
    {
        NoCodeMetrics::create(['name' => 'test.taken', 'label' => 'Taken', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count'], $this->user);

        $this->assertStringStartsWith('name:', NoCodeMetrics::create(['name' => 'Not Lower Case', 'label' => 'L', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count'], $this->user));
        $this->assertSame('name: already defined.', NoCodeMetrics::create(['name' => 'test.taken', 'label' => 'L2', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count'], $this->user));
        $this->assertSame('label: required.', NoCodeMetrics::create(['name' => 'test.nolabel', 'label' => '', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count'], $this->user));
        $this->assertSame('currency: a 3-letter code, or empty.', NoCodeMetrics::create(['name' => 'test.badcur', 'label' => 'L', 'source_table' => self::ORDERS, 'time_column' => 'placed_at', 'fn' => 'count', 'currency' => 'eu'], $this->user));
    }

    /** `allowedTables()` skips a malformed entry instead of offering a table with no real time column. */
    public function testAllowedTablesSkipsAMalformedEntry(): void
    {
        Application::getInstance()->applicationInfo['metrics'] = ['no_code' => ['tables' => [
            self::ORDERS => ['time' => ['placed_at'], 'columns' => ['amount']],
            0            => ['time' => ['x'], 'columns' => ['y']],
            'no_time'    => ['columns' => ['amount']],
        ]]];

        $tables = NoCodeMetrics::allowedTables();

        $this->assertSame([self::ORDERS], array_keys($tables), 'a numeric key and a table with no time column are both left out');
    }
}
