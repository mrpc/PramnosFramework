<?php

declare(strict_types=1);

/**
 * Phase 0 of the metrics design (docs/Metrics-BI-Design.md §5.2): how the values are best laid out
 * — A, one table; B, a table per grain; C, the hour apart and the rest together — measured on
 * synthetic data in three sizes, on TimescaleDB and on plain PostgreSQL.
 *
 *   php bench.php generate <small|medium|large> <ts|plain>   build the three layouts in schema bench_<size>_<engine>
 *   php bench.php measure  <small|medium|large> <ts|plain>   reads, a computation, sizes; with ts also compressed
 *   php bench.php drop     <small|medium|large> <ts|plain>
 *
 * Connection: PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE (default 127.0.0.1:55432 postgres/bench/bench).
 * Results are appended to results/<size>-<engine>.json.
 */

const SIZES = [
    // metrics, tenants, combinations per metric (the total among them), grains, first period
    'small'  => ['metrics' => 20, 'tenants' => 1, 'combos' => 3, 'grains' => ['day', 'week', 'month', 'quarter', 'year'], 'from' => '2023-11-01'],
    'medium' => ['metrics' => 150, 'tenants' => 1, 'combos' => 20, 'grains' => ['day', 'week', 'month', 'quarter', 'year'], 'from' => '2014-11-01'],
    'large'  => ['metrics' => 12, 'tenants' => 30, 'combos' => 10, 'grains' => ['hour', 'day', 'week', 'month', 'quarter', 'year'], 'from' => '2023-11-01'],
];
const UNTIL = '2026-11-01';
const STEP  = ['hour' => '1 hour', 'day' => '1 day', 'week' => '1 week', 'month' => '1 month', 'quarter' => '3 months', 'year' => '1 year'];
// Chunk intervals: B and C's fine table by grain; A and C's coarse table one size each.
const CHUNK = ['hour' => '7 days', 'day' => '90 days', 'week' => '1 year', 'month' => '2 years', 'quarter' => '5 years', 'year' => '10 years'];
const COMPRESS_BEFORE = '2026-09-01';

$pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('PGHOST') ?: '127.0.0.1', getenv('PGPORT') ?: '55432', getenv('PGDATABASE') ?: 'bench'),
    getenv('PGUSER') ?: 'postgres', getenv('PGPASSWORD') ?: 'bench', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

[$cmd, $size, $engine] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (!isset(SIZES[$size]) || !in_array($engine, ['ts', 'plain'], true)) {
    fwrite(STDERR, "usage: php bench.php generate|measure|drop <small|medium|large> <ts|plain>\n");
    exit(2);
}
$schema = 'bench_' . $size . '_' . $engine;
$spec   = SIZES[$size];
$ts     = $engine === 'ts';

match ($cmd) {
    'generate' => generate($pdo, $schema, $spec, $ts),
    'measure'  => measure($pdo, $schema, $spec, $ts, $size, $engine),
    'drop'     => $pdo->exec("DROP SCHEMA IF EXISTS $schema CASCADE"),
    default    => exit(2),
};

/** Print a line with the time. */
function say(string $line): void
{
    fwrite(STDOUT, date('H:i:s') . '  ' . $line . "\n");
}

/** Run a statement, timed in milliseconds. */
function timed(PDO $pdo, string $sql): float
{
    $t = hrtime(true);
    $pdo->exec($sql);

    return (hrtime(true) - $t) / 1e6;
}

/** The value columns, without the grain. */
function columns(): string
{
    return 'tenant bigint NOT NULL, period_start timestamptz NOT NULL, dims jsonb NOT NULL, dims_hash uuid NOT NULL, '
        . 'version smallint NOT NULL DEFAULT 1, value double precision, extra jsonb, source smallint NOT NULL DEFAULT 1, '
        . 'final boolean NOT NULL DEFAULT false, computed_at timestamptz NOT NULL';
}

/**
 * The tables of each layout: name => [grains it holds, has a grain column, chunk interval].
 *
 * @return array<string, array{0: list<string>, 1: bool, 2: string}>
 */
function tables(array $spec): array
{
    $grains = $spec['grains'];
    $coarse = array_values(array_diff($grains, ['hour']));
    // A's one interval: the dominant grain's — the hour where there are hours, the day otherwise.
    $tables = ['a_values' => [$grains, true, in_array('hour', $grains, true) ? CHUNK['hour'] : '1 year']];
    foreach ($grains as $g) {
        $tables['b_' . $g] = [[$g], false, CHUNK[$g]];
    }
    if (in_array('hour', $grains, true)) {
        $tables['c_fine'] = [['hour'], false, CHUNK['hour']];
    }
    $tables['c_coarse'] = [$coarse, true, '1 year'];

    return $tables;
}

/** Build the series, then the three layouts, filled. */
function generate(PDO $pdo, string $schema, array $spec, bool $ts): void
{
    $pdo->exec("DROP SCHEMA IF EXISTS $schema CASCADE; CREATE SCHEMA $schema");
    // The series: each metric's combinations (the first the total) for each tenant.
    $pdo->exec("CREATE TABLE $schema.series AS
        SELECT 'm' || lpad(m::text, 3, '0') AS metric, t AS tenant,
               CASE WHEN c = 0 THEN '{}'::jsonb ELSE jsonb_build_object('region', 'r' || (c % 5), 'plan', 'p' || c) END AS dims
        FROM generate_series(0, {$spec['metrics']} - 1) m, generate_series(1, {$spec['tenants']}) t, generate_series(0, {$spec['combos']} - 1) c");
    $pdo->exec("ALTER TABLE $schema.series ADD COLUMN dims_hash uuid; UPDATE $schema.series SET dims_hash = md5(dims::text)::uuid");
    $series = (int) $pdo->query("SELECT count(*) FROM $schema.series")->fetchColumn();
    say("$schema: $series series");

    foreach (tables($spec) as $table => [$grains, $grainColumn, $chunk]) {
        $pk = 'metric, ' . ($grainColumn ? 'grain, ' : '') . 'tenant, dims_hash, version, period_start';
        $pdo->exec("CREATE TABLE $schema.$table (metric text NOT NULL, " . ($grainColumn ? 'grain text NOT NULL, ' : '') . columns() . ", PRIMARY KEY ($pk))");
        if ($ts) {
            $pdo->exec("SELECT create_hypertable('$schema.$table', by_range('period_start', INTERVAL '$chunk'))");
        }
        // A report across dimensions: the period first.
        $pdo->exec("CREATE INDEX ON $schema.$table (metric, " . ($grainColumn ? 'grain, ' : '') . "period_start)");
        foreach ($grains as $g) {
            fill($pdo, $schema, $table, $g, $grainColumn, $spec['from']);
        }
        $rows = (int) $pdo->query("SELECT count(*) FROM $schema.$table")->fetchColumn();
        say("$schema.$table: " . number_format($rows) . ' rows');
    }
    $pdo->exec("ANALYZE");
}

/** One grain's periods for every series, a month of periods at a time for the hour. */
function fill(PDO $pdo, string $schema, string $table, string $grain, bool $grainColumn, string $from): void
{
    $cols  = 'metric, ' . ($grainColumn ? 'grain, ' : '') . 'tenant, period_start, dims, dims_hash, value, final, computed_at';
    $slice = $grain === 'hour' ? '1 month' : '20 years';
    $start = new DateTimeImmutable($from . ' UTC');
    $until = new DateTimeImmutable(UNTIL . ' UTC');
    $total = 0.0;
    while ($start < $until) {
        $end = min($until, $start->modify('+' . $slice));
        $total += timed($pdo, "INSERT INTO $schema.$table ($cols)
            SELECT s.metric, " . ($grainColumn ? "'$grain', " : '') . "s.tenant, p, s.dims, s.dims_hash, round((random() * 1000)::numeric, 2)::float8,
                   p < TIMESTAMPTZ '" . COMPRESS_BEFORE . "', now()
            FROM $schema.series s,
                 generate_series(date_trunc('$grain', TIMESTAMPTZ '{$start->format('Y-m-d H:i:sP')}'), TIMESTAMPTZ '{$end->format('Y-m-d H:i:sP')}' - INTERVAL '1 second', INTERVAL '" . STEP[$grain] . "') p
            WHERE p >= TIMESTAMPTZ '{$start->format('Y-m-d H:i:sP')}'");
        $start = $end;
    }
    say(sprintf('  %s.%s %s: %.1f s', $schema, $table, $grain, $total / 1000));
}

/** Where a grain's values are read from in a layout, and the condition that picks it. @return array{0: string, 1: string} */
function source(string $schema, string $layout, string $grain): array
{
    return match ($layout) {
        'A'     => ["$schema.a_values", "grain = '$grain' AND "],
        'B'     => ["$schema.b_$grain", ''],
        default => $grain === 'hour' ? ["$schema.c_fine", ''] : ["$schema.c_coarse", "grain = '$grain' AND "],
    };
}

/**
 * The median time of a query, in milliseconds, after two warm-ups.
 */
function median(PDO $pdo, string $sql, int $runs = 15): float
{
    $pdo->query($sql)->fetchAll();
    $pdo->query($sql)->fetchAll();
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $pdo->query($sql)->fetchAll();
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);

    return $times[intdiv($runs, 2)];
}

/** The reads, a day's computation and a closed month's recomputation, per layout. @return array<string, mixed> */
function reads(PDO $pdo, string $schema, array $spec, string $layout, bool $hours): array
{
    $total = md5('{}');
    $total = substr($total, 0, 8) . '-' . substr($total, 8, 4) . '-' . substr($total, 12, 4) . '-' . substr($total, 16, 4) . '-' . substr($total, 20);
    [$month, $mWhere] = source($schema, $layout, 'month');
    $out = [];
    // A card: one value.
    $out['card'] = median($pdo, "SELECT value FROM $month WHERE {$mWhere}metric = 'm000' AND tenant = 1 AND dims_hash = '$total' AND version = 1 AND period_start = '2026-10-01'");
    // A chart: 24 months of one series.
    $out['chart_24_months'] = median($pdo, "SELECT period_start, value FROM $month WHERE {$mWhere}metric = 'm000' AND tenant = 1 AND dims_hash = '$total' AND version = 1 AND period_start >= '2024-11-01' AND period_start < '2026-11-01' ORDER BY period_start");
    // A report: 10 metrics × 3 combinations = 30 rows, 12 months, and the same months a year earlier.
    $out['report_30x12_compare'] = median($pdo, "SELECT metric, dims_hash, period_start, value FROM $month WHERE {$mWhere}metric IN ('m000','m001','m002','m003','m004','m005','m006','m007','m008','m009') AND tenant = 1
        AND ((dims->>'plan') IS NULL OR (dims->>'plan') IN ('p1','p2'))
        AND ((period_start >= '2025-11-01' AND period_start < '2026-11-01') OR (period_start >= '2024-11-01' AND period_start < '2025-11-01'))");
    // The same report, read by the hash of each row's combination, as the query API would.
    $out['report_30x12_by_hash'] = median($pdo, "SELECT v.metric, v.dims_hash, v.period_start, v.value FROM $month v
        JOIN (SELECT DISTINCT metric, dims_hash FROM $schema.series WHERE metric < 'm010' AND tenant = 1 AND ((dims->>'plan') IS NULL OR dims->>'plan' IN ('p1','p2'))) s USING (metric, dims_hash)
        WHERE " . ($mWhere !== '' ? "v.grain = 'month' AND " : '') . "v.tenant = 1 AND v.version = 1 AND v.period_start >= '2024-11-01' AND v.period_start < '2026-11-01'");
    if ($hours) {
        [$hour, $hWhere] = source($schema, $layout, 'hour');
        $out['chart_168_hours'] = median($pdo, "SELECT period_start, value FROM $hour WHERE {$hWhere}metric = 'm000' AND tenant = 1 AND dims_hash = '$total' AND version = 1 AND period_start >= '2026-10-24' AND period_start < '2026-10-31' ORDER BY period_start");
        $out['day_by_tenant_from_hours'] = median($pdo, "SELECT tenant, sum(value) FROM $hour WHERE {$hWhere}metric = 'm000' AND dims_hash = '$total' AND version = 1 AND period_start >= '2026-10-30' AND period_start < '2026-10-31' GROUP BY tenant");
    }

    return $out;
}

/** Write an open day's values again, every series, as a computation does; with hours, its 24 hours too. @return array<string, float> */
function compute(PDO $pdo, string $schema, string $layout, bool $hours): array
{
    $out = [];
    foreach (array_merge(['day'], $hours ? ['hour'] : []) as $grain) {
        [$table] = source($schema, $layout, $grain);
        $grainColumn = $layout === 'A' || ($layout === 'C' && $grain !== 'hour');
        $cols  = 'metric, ' . ($grainColumn ? 'grain, ' : '') . 'tenant, period_start, dims, dims_hash, value, final, computed_at';
        $key   = 'metric, ' . ($grainColumn ? 'grain, ' : '') . 'tenant, dims_hash, version, period_start';
        $periods = $grain === 'hour' ? "generate_series(TIMESTAMPTZ '2026-10-31', TIMESTAMPTZ '2026-10-31 23:00', INTERVAL '1 hour')" : "(VALUES (TIMESTAMPTZ '2026-10-31'))";
        $sql   = "INSERT INTO $table ($cols) SELECT s.metric, " . ($grainColumn ? "'$grain', " : '') . "s.tenant, p, s.dims, s.dims_hash, random() * 1000, false, now()
            FROM $schema.series s, $periods AS p(p) ON CONFLICT ($key) DO UPDATE SET value = EXCLUDED.value, computed_at = EXCLUDED.computed_at";
        timed($pdo, $sql);
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $times[] = timed($pdo, $sql);
        }
        sort($times);
        $out['compute_open_' . $grain] = $times[2];
    }

    return $out;
}

/** Recompute a closed month — inside compressed chunks where they are compressed. @return array<string, float> */
function recompute(PDO $pdo, string $schema, string $layout, bool $hours): array
{
    $out = [];
    foreach (array_merge(['day', 'month'], $hours ? ['hour'] : []) as $grain) {
        [$table, $where] = source($schema, $layout, $grain);
        $out['recompute_closed_' . $grain] = timed($pdo, "UPDATE $table SET value = value + 1, computed_at = now()
            WHERE {$where}metric = 'm000' AND period_start >= '2025-10-01' AND period_start < '2025-11-01'");
    }

    return $out;
}

/** Bytes on disk of each layout. @return array<string, int> */
function sizes(PDO $pdo, string $schema, array $spec, bool $ts): array
{
    $sizes = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach (array_keys(tables($spec)) as $table) {
        $bytes = (int) $pdo->query($ts ? "SELECT hypertable_size('$schema.$table')" : "SELECT pg_total_relation_size('$schema.$table')")->fetchColumn();
        $sizes[strtoupper($table[0])] += $bytes;
    }

    return $sizes;
}

/** Compress every chunk of every table that ends before the cut. */
function compressAll(PDO $pdo, string $schema, array $spec): float
{
    $ms = 0.0;
    foreach (tables($spec) as $table => [$grains, $grainColumn]) {
        $segment = 'metric, ' . ($grainColumn ? 'grain, ' : '') . 'tenant, dims_hash';
        $pdo->exec("ALTER TABLE $schema.$table SET (timescaledb.compress, timescaledb.compress_segmentby = '$segment', timescaledb.compress_orderby = 'period_start DESC')");
        $ms += timed($pdo, "SELECT count(compress_chunk(c, true)) FROM show_chunks('$schema.$table', older_than => TIMESTAMPTZ '" . COMPRESS_BEFORE . "') c");
    }

    return $ms;
}

/** Measure everything, and keep it. */
function measure(PDO $pdo, string $schema, array $spec, bool $ts, string $size, string $engine): void
{
    $hours  = in_array('hour', $spec['grains'], true);
    // Once compressed, a second run would read compressed data as if it were not: build it again.
    if ($ts && (int) $pdo->query("SELECT count(*) FROM timescaledb_information.chunks WHERE hypertable_schema = '$schema' AND is_compressed")->fetchColumn() > 0) {
        fwrite(STDERR, "$schema is already compressed: run generate again first.\n");
        exit(1);
    }
    $result = ['size' => $size, 'engine' => $engine, 'at' => date('c'), 'rows' => [], 'layouts' => []];
    foreach (array_keys(tables($spec)) as $table) {
        $result['rows'][$table] = (int) $pdo->query("SELECT count(*) FROM $schema.$table")->fetchColumn();
    }
    $result['bytes'] = sizes($pdo, $schema, $spec, $ts);
    foreach (['A', 'B', 'C'] as $layout) {
        say("$schema $layout: reads");
        $result['layouts'][$layout] = reads($pdo, $schema, $spec, $layout, $hours) + compute($pdo, $schema, $layout, $hours);
        if (!$ts) {
            $result['layouts'][$layout] += recompute($pdo, $schema, $layout, $hours);
        }
    }
    @mkdir(__DIR__ . '/results');
    file_put_contents(__DIR__ . "/results/$size-$engine.json", json_encode($result, JSON_PRETTY_PRINT) . "\n");
    if ($ts) {
        // A closed month of hours rewritten in one statement decompresses more than the default
        // 100 000 tuples a DML may (302 400 at the large size): the mechanism lifts it for its own
        // recomputation, as this does.
        $pdo->exec('SET timescaledb.max_tuples_decompressed_per_dml_transaction = 0');
        say("$schema: compressing");
        $result['compress_ms'] = compressAll($pdo, $schema, $spec);
        $pdo->exec('ANALYZE');
        $result['bytes_compressed'] = sizes($pdo, $schema, $spec, true);
        foreach (['A', 'B', 'C'] as $layout) {
            say("$schema $layout: reads, compressed");
            $result['layouts_compressed'][$layout] = reads($pdo, $schema, $spec, $layout, $hours) + compute($pdo, $schema, $layout, $hours) + recompute($pdo, $schema, $layout, $hours);
        }
    }
    file_put_contents(__DIR__ . "/results/$size-$engine.json", json_encode($result, JSON_PRETTY_PRINT) . "\n");
    say('written results/' . "$size-$engine.json");
}
