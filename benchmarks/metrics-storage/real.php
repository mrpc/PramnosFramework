<?php

declare(strict_types=1);

/**
 * Phase 0 on real data (docs/Metrics-BI-Design.md §5.2, §6.2): a marketplace's anonymised copy —
 * its paid invoices and its registrations, times kept as unix integers as the application keeps
 * them — copied (read only) into the benchmark database, then:
 *
 * - computing a year of days per breakdown with one query per day, as a closure source does
 *   today, against one `time_bucket` query over a unix-time column, as a declared source would;
 * - the same, the source made a hypertable on its integer column (`integer_now`), the schema
 *   unchanged, and a continuous aggregate of it by day.
 *
 *   SOURCE_CONTAINER=… SOURCE_USER=… SOURCE_DB=… php real.php
 *
 * The copy is made with `COPY … TO STDOUT` through `docker exec` on the source's container.
 * Results: results/real.json.
 */

$pdo = new PDO('pgsql:host=127.0.0.1;port=55432;dbname=bench', 'postgres', 'bench', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/** Print a line with the time. */
function say(string $line): void
{
    fwrite(STDOUT, date('H:i:s') . '  ' . $line . "\n");
}

/** A statement's time in ms. */
function timed(PDO $pdo, string $sql): float
{
    $t = hrtime(true);
    $pdo->query($sql)->fetchAll();

    return (hrtime(true) - $t) / 1e6;
}

/** The median of five, after a warm-up, in ms. */
function median(PDO $pdo, string $sql): float
{
    timed($pdo, $sql);
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $times[] = timed($pdo, $sql);
    }
    sort($times);

    return $times[2];
}

[$container, $user, $database] = [getenv('SOURCE_CONTAINER'), getenv('SOURCE_USER'), getenv('SOURCE_DB')];
if (!$container || !$user || !$database) {
    fwrite(STDERR, "usage: SOURCE_CONTAINER=… SOURCE_USER=… SOURCE_DB=… php real.php\n");
    exit(2);
}

$pdo->exec('DROP SCHEMA IF EXISTS bench_real CASCADE; CREATE SCHEMA bench_real');
$pdo->exec('CREATE TABLE bench_real.invoices (date bigint NOT NULL, total numeric, gateway text, isrenewal smallint, userid bigint, usertype smallint)');
$pdo->exec('CREATE TABLE bench_real.users (regdate integer NOT NULL, usertype smallint, active smallint)');
$copies = [
    'invoices' => "COPY (SELECT i.date, i.total, coalesce(i.gateway, ''), coalesce(i.isrenewal, 0), i.userid, coalesce(u.usertype, 0) FROM invoices i LEFT JOIN users u ON u.userid = i.userid WHERE i.status = 1 AND i.date > 0) TO STDOUT",
    'users'    => 'COPY (SELECT regdate, usertype, active FROM users WHERE regdate > 0) TO STDOUT',
];
foreach ($copies as $table => $copy) {
    $out = sprintf('docker exec %s psql -U %s -d %s -Atc %s | docker exec -i pf_bench_ts psql -U postgres -d bench -c %s',
        escapeshellarg($container), escapeshellarg($user), escapeshellarg($database), escapeshellarg($copy), escapeshellarg("COPY bench_real.$table FROM STDIN"));
    passthru($out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "copying $table failed\n");
        exit(1);
    }
}
$pdo->exec('CREATE INDEX ON bench_real.invoices (date); CREATE INDEX ON bench_real.users (regdate); ANALYZE bench_real.invoices; ANALYZE bench_real.users');
$result = ['at' => date('c'), 'rows' => [
    'invoices' => (int) $pdo->query('SELECT count(*) FROM bench_real.invoices')->fetchColumn(),
    'users'    => (int) $pdo->query('SELECT count(*) FROM bench_real.users')->fetchColumn(),
]];
say('copied: ' . json_encode($result['rows']));

// A year of days — revenue by gateway and renewal, and paying people — one query per day.
$perDay = static function () use ($pdo): float {
    $t   = hrtime(true);
    $day = new DateTimeImmutable('2025-11-01 00:00 Europe/Athens');
    for ($i = 0; $i < 365; $i++) {
        $next = $day->modify('+1 day');
        $pdo->query("SELECT gateway, isrenewal, sum(total), count(DISTINCT userid) FROM bench_real.invoices
            WHERE date >= {$day->getTimestamp()} AND date < {$next->getTimestamp()} GROUP BY ROLLUP (gateway, isrenewal)")->fetchAll();
        $day = $next;
    }

    return (hrtime(true) - $t) / 1e6;
};
$oneQuery = "SELECT time_bucket(INTERVAL '1 day', to_timestamp(date), 'Europe/Athens') AS day, gateway, isrenewal, sum(total), count(DISTINCT userid)
    FROM bench_real.invoices WHERE date >= extract(epoch FROM TIMESTAMPTZ '2025-11-01 00:00 Europe/Athens') AND date < extract(epoch FROM TIMESTAMPTZ '2026-11-01 00:00 Europe/Athens')
    GROUP BY 1, ROLLUP (gateway, isrenewal)";
$result['year_of_days_one_query_per_day_ms'] = $perDay();
$result['year_of_days_one_time_bucket_query_ms'] = median($pdo, $oneQuery);
$result['twelve_years_of_months_one_query_ms'] = median($pdo, "SELECT time_bucket(INTERVAL '1 month', to_timestamp(date), 'Europe/Athens'), gateway, sum(total), count(DISTINCT userid) FROM bench_real.invoices GROUP BY 1, ROLLUP (gateway)");
say('compute: ' . json_encode($result));

// The source made a hypertable on its unix column, the application's schema unchanged.
$pdo->exec("CREATE FUNCTION bench_real.unix_now() RETURNS bigint LANGUAGE sql STABLE AS \$\$ SELECT extract(epoch FROM now())::bigint \$\$");
$pdo->exec("SELECT create_hypertable('bench_real.invoices', by_range('date', 86400 * 30), migrate_data => true)");
$pdo->exec("SELECT set_integer_now_func('bench_real.invoices', 'bench_real.unix_now')");
$pdo->exec('ANALYZE bench_real.invoices');
$result['hypertable_year_of_days_one_query_ms'] = median($pdo, $oneQuery);
$result['hypertable_year_of_days_per_day_ms']   = $perDay();
// A continuous aggregate of the unix-time hypertable, by day, read for a year.
$pdo->exec("CREATE MATERIALIZED VIEW bench_real.daily WITH (timescaledb.continuous) AS
    SELECT time_bucket(86400, date) AS day, gateway, isrenewal, sum(total) AS revenue, count(*) AS invoices
    FROM bench_real.invoices GROUP BY 1, 2, 3 WITH DATA");
$result['cagg_year_of_days_read_ms'] = median($pdo, "SELECT day, gateway, sum(revenue) FROM bench_real.daily WHERE day >= extract(epoch FROM TIMESTAMPTZ '2025-11-01')::bigint GROUP BY 1, 2");
say('hypertable: ' . json_encode(array_slice($result, -3)));

@mkdir(__DIR__ . '/results');
file_put_contents(__DIR__ . '/results/real.json', json_encode($result, JSON_PRETTY_PRINT) . "\n");
say('written results/real.json');
