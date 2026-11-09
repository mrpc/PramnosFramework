<?php

declare(strict_types=1);

/**
 * Phase 0, the distinct metrics (docs/Metrics-BI-Design.md §5.2): people counted once per period,
 * which do not add up from days to a month. Measured: counting them exactly from the events
 * against rolling up the Toolkit's `hyperloglog` sketches of each day — a continuous aggregate per
 * day, and one per month on top of it — for the time, the error, and the size of a sketch.
 *
 *   php hll.php <medium|large>
 *
 * medium: 20 M events of 400 k people over a year; large: 100 M events of 2 M people.
 * Results: results/hll-<size>.json.
 */

const SIZES = ['medium' => ['events' => 20_000_000, 'people' => 400_000], 'large' => ['events' => 100_000_000, 'people' => 2_000_000]];

$size = $argv[1] ?? '';
if (!isset(SIZES[$size])) {
    fwrite(STDERR, "usage: php hll.php <medium|large>\n");
    exit(2);
}
$spec   = SIZES[$size];
$schema = 'bench_hll_' . $size;
$pdo    = new PDO('pgsql:host=127.0.0.1;port=55432;dbname=bench', 'postgres', 'bench', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/** Print a line with the time. */
function say(string $line): void
{
    fwrite(STDOUT, date('H:i:s') . '  ' . $line . "\n");
}

/** A statement's time in ms, and its rows. @return array{0: float, 1: list<array<string, mixed>>} */
function run(PDO $pdo, string $sql): array
{
    $t    = hrtime(true);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    return [(hrtime(true) - $t) / 1e6, $rows];
}

/** The median of five runs, after one warm-up, in ms. */
function median(PDO $pdo, string $sql): float
{
    run($pdo, $sql);
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $times[] = run($pdo, $sql)[0];
    }
    sort($times);

    return $times[2];
}

$pdo->exec("DROP SCHEMA IF EXISTS $schema CASCADE; CREATE SCHEMA $schema");
$pdo->exec("CREATE TABLE $schema.events (at timestamptz NOT NULL, region text NOT NULL, person bigint NOT NULL)");
$pdo->exec("SELECT create_hypertable('$schema.events', by_range('at', INTERVAL '7 days'))");
// People drawn with a skew — a few come every day, most now and then — over 2025-11 … 2026-10.
$batch = 5_000_000;
for ($done = 0; $done < $spec['events']; $done += $batch) {
    $t = hrtime(true);
    $pdo->exec("INSERT INTO $schema.events
        SELECT TIMESTAMPTZ '2025-11-01' + random() * INTERVAL '365 days', 'r' || (floor(random() * 5))::int,
               floor(power(random(), 2) * {$spec['people']})::bigint
        FROM generate_series(1, $batch)");
    say(sprintf('events %s: %.1f s', number_format($done + $batch), (hrtime(true) - $t) / 1e9));
}
$pdo->exec("ANALYZE $schema.events");

$result = ['size' => $size, 'events' => $spec['events'], 'people' => $spec['people'], 'at' => date('c')];

// Exact: a month's distinct people, and per region, from the events.
$month = "at >= '2026-09-01' AND at < '2026-10-01'";
$result['exact_month_ms']           = median($pdo, "SELECT count(DISTINCT person) FROM $schema.events WHERE $month");
$result['exact_month_by_region_ms'] = median($pdo, "SELECT region, count(DISTINCT person) FROM $schema.events WHERE $month GROUP BY region");
$exact = (int) run($pdo, "SELECT count(DISTINCT person) AS n FROM $schema.events WHERE $month")[1][0]['n'];
$exactYear = (int) run($pdo, "SELECT count(DISTINCT person) AS n FROM $schema.events")[1][0]['n'];
$result['exact_year_ms'] = median($pdo, "SELECT count(DISTINCT person) FROM $schema.events");

foreach ([4096, 32768] as $buckets) {
    $daily   = "$schema.daily_$buckets";
    $monthly = "$schema.monthly_$buckets";
    [$build] = run($pdo, "CREATE MATERIALIZED VIEW $daily WITH (timescaledb.continuous) AS
        SELECT time_bucket(INTERVAL '1 day', at) AS day, region, hyperloglog($buckets, person) AS people
        FROM $schema.events GROUP BY 1, 2 WITH DATA");
    [$buildMonth] = run($pdo, "CREATE MATERIALIZED VIEW $monthly WITH (timescaledb.continuous) AS
        SELECT time_bucket(INTERVAL '1 month', day) AS month, region, rollup(people) AS people
        FROM $daily GROUP BY 1, 2 WITH DATA");
    $approx     = (int) run($pdo, "SELECT distinct_count(rollup(people)) AS n FROM $daily WHERE day >= '2026-09-01' AND day < '2026-10-01'")[1][0]['n'];
    $approxYear = (int) run($pdo, "SELECT distinct_count(rollup(people)) AS n FROM $monthly")[1][0]['n'];
    $result['hll'][$buckets] = [
        'build_daily_ms'          => $build,
        'build_monthly_ms'        => $buildMonth,
        'month_from_days_ms'      => median($pdo, "SELECT distinct_count(rollup(people)) FROM $daily WHERE day >= '2026-09-01' AND day < '2026-10-01'"),
        'month_by_region_ms'      => median($pdo, "SELECT region, distinct_count(rollup(people)) FROM $daily WHERE day >= '2026-09-01' AND day < '2026-10-01' GROUP BY region"),
        'month_from_monthly_ms'   => median($pdo, "SELECT distinct_count(rollup(people)) FROM $monthly WHERE month = '2026-09-01'"),
        'year_from_monthly_ms'    => median($pdo, "SELECT distinct_count(rollup(people)) FROM $monthly"),
        'month_error_pct'         => round(100 * ($approx - $exact) / $exact, 2),
        'year_error_pct'          => round(100 * ($approxYear - $exactYear) / $exactYear, 2),
        'sketch_bytes'            => (int) run($pdo, "SELECT avg(pg_column_size(people))::int AS b FROM $daily")[1][0]['b'],
        'refresh_one_day_ms'      => run($pdo, "CALL refresh_continuous_aggregate('$daily', '2026-10-30', '2026-10-31')")[0],
    ];
    say("hll $buckets: " . json_encode($result['hll'][$buckets]));
}
$result['exact_month'] = $exact;
$result['exact_year']  = $exactYear;
@mkdir(__DIR__ . '/results');
file_put_contents(__DIR__ . "/results/hll-$size.json", json_encode($result, JSON_PRETTY_PRINT) . "\n");
say('written results/hll-' . $size . '.json');
