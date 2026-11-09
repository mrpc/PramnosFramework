<?php

declare(strict_types=1);

/**
 * Phase 1's measurement on MySQL, through the framework's own code (docs/Metrics-BI-Design.md §5.4):
 * the store made by Store::ensure(), filled in the database, then read with Metrics::value() and
 * Metrics::series(), and a day's values written as a computation writes them.
 *
 * 150 metrics × 20 series (the total and 19 values of one dimension): a year of days, twelve years
 * of months — about 1.5 M rows, what the suite's in-memory MySQL holds.
 *
 *   docker-compose exec -T php-apache-environment php benchmarks/metrics-storage/mysql.php
 *
 * Results: results/mysql.json. The store's tables are dropped at the end.
 */

// The suite's bootstrap: an application without a site to serve, as the tests have it.
require dirname(__DIR__, 2) . '/tests/bootstrap.php';

use Pramnos\Database\Database;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Store;
use Pramnos\Metrics\Window;

const METRICS = 150;
const VALUES  = 19;

/** Print a line with the time. */
function say(string $line): void
{
    fwrite(STDOUT, date('H:i:s') . '  ' . $line . "\n");
}

/** The median of 15 runs of `$run`, after two warm-ups, in ms. */
function median(callable $run): float
{
    $run();
    $run();
    $times = [];
    for ($i = 0; $i < 15; $i++) {
        $t = hrtime(true);
        $run();
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);

    return $times[7];
}

$db           = new Database();
$db->type     = 'mysql';
$db->server   = getenv('BENCH_MYSQL_HOST') ?: 'db';
$db->port     = 3306;
$db->user     = 'root';
$db->password = 'secret';
$db->database = 'pramnos_test';
$db->connect(false);
$singleton = &\Pramnos\Framework\Factory::getDatabase();
$singleton = $db;
\Pramnos\Application\Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'Europe/Athens'];

Store::drop($db);
Store::ensure($db);
$schema = $db->schema();

// The series and the periods, written by PHP so their hashes and instants are the store's own.
$schema->dropTableIfExists('bench_series');
$schema->createTable('bench_series', function ($t) {
    $t->string('metric', 100);
    $t->string('dimset', 200);
    $t->text('dims');
    $t->char('dims_hash', 36);
});
$series = [];
for ($m = 0; $m < METRICS; $m++) {
    $name = sprintf('m%03d', $m);
    Metrics::register(Metric::flow($name, $name)->dimensions(['region'])->source(static fn (): int => 0));
    for ($v = 0; $v <= VALUES; $v++) {
        $dims     = $v === 0 ? [] : ['region' => 'r' . $v];
        $json     = (string) json_encode((object) $dims);
        $series[] = ['metric' => $name, 'dimset' => $v === 0 ? '' : 'region', 'dims' => $json, 'dims_hash' => Store::hash($json)];
    }
}
foreach (array_chunk($series, 500) as $chunk) {
    $db->queryBuilder()->table('bench_series')->insertMany($chunk);
}
Metrics::dimension(\Pramnos\Metrics\Dimension::of('region', 'Region')->values(array_combine(array_map(static fn ($i) => 'r' . $i, range(1, VALUES)), array_map(static fn ($i) => 'R' . $i, range(1, VALUES)))));
$schema->dropTableIfExists('bench_periods');
$schema->createTable('bench_periods', function ($t) {
    $t->string('grain', 8);
    $t->dateTime('p');
});
$periods = [];
foreach (['day' => ['2025-11-01', '2026-10-31'], 'month' => ['2014-11-01', '2026-10-31']] as $grain => [$from, $to]) {
    foreach (Window::range($grain, $from, $to) as $w) {
        $periods[] = ['grain' => $grain, 'p' => Store::instant($db, $w)];
    }
}
foreach (array_chunk($periods, 500) as $chunk) {
    $db->queryBuilder()->table('bench_periods')->insertMany($chunk);
}

$rows = [];
foreach (['day', 'month'] as $grain) {
    $t = hrtime(true);
    // Raw: a bulk INSERT … SELECT the builder does not express.
    $db->query('INSERT INTO ' . $schema->quoteTable(Store::table($grain)) . ' (metric, tenant, period_start, dimset, dims, dims_hash, version, value, source, final, computed_at)
        SELECT s.metric, 0, p.p, s.dimset, s.dims, s.dims_hash, 1, ROUND(RAND() * 1000, 2), \'computed\', 1, UTC_TIMESTAMP()
        FROM bench_series s CROSS JOIN bench_periods p WHERE p.grain = \'' . $grain . '\'');
    $rows[$grain] = (int) $db->queryBuilder()->table(Store::table($grain))->count();
    say(sprintf('%s: %s rows in %.1f s', $grain, number_format($rows[$grain]), (hrtime(true) - $t) / 1e9));
}
$db->query('ANALYZE TABLE ' . $schema->quoteTable(Store::table('day')) . ', ' . $schema->quoteTable(Store::table('month')));

$result = ['at' => date('c'), 'engine' => 'MySQL 8.0, the framework\'s Metrics API', 'rows' => $rows];
$result['card_ms']               = median(static fn () => Metrics::value('m000', 'month', '2026-10-01'));
$result['chart_24_months_ms']    = median(static fn () => Metrics::series('m000', 'month', '2024-11-01', '2026-10-31'));
$result['chart_by_region_ms']    = median(static fn () => Metrics::series('m000', 'month', '2025-11-01', '2026-10-31', [], 'region'));
$result['report_30x12_ms']       = median(static function (): void {
    for ($m = 0; $m < 30; $m++) {
        Metrics::series(sprintf('m%03d', $m), 'month', '2025-11-01', '2026-10-31');
    }
});
$result['chart_365_days_ms']     = median(static fn () => Metrics::series('m000', 'day', '2025-11-01', '2026-10-31'));
// A day's values written as a computation writes them: each metric's period deleted, then inserted.
$day = Window::of('day', '2026-10-31');
$result['write_open_day_ms'] = median(static function () use ($db, $day): void {
    for ($m = 0; $m < METRICS; $m++) {
        $name   = sprintf('m%03d', $m);
        $insert = [];
        for ($v = 0; $v <= VALUES; $v++) {
            $insert[] = Store::row($db, $name, $day, $v === 0 ? [] : ['region' => 'r' . $v], (float) mt_rand(0, 1000), Store::COMPUTED, false, time());
        }
        $db->queryBuilder()->table(Store::table('day'))->where('metric', $name)->where('version', 1)->where('period_start', Store::instant($db, $day))->delete();
        $db->queryBuilder()->table(Store::table('day'))->insertMany($insert);
    }
});
$size = $db->query("SELECT SUM(data_length + index_length) AS b FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%metric_values_%'")->fetch();
$result['bytes'] = (int) $size['b'];
say(json_encode($result));

Store::drop($db);
$schema->dropTableIfExists('bench_series');
$schema->dropTableIfExists('bench_periods');
@mkdir(__DIR__ . '/results');
file_put_contents(__DIR__ . '/results/mysql.json', json_encode($result, JSON_PRETTY_PRINT) . "\n");
say('written results/mysql.json');
