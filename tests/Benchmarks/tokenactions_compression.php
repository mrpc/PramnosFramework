<?php

/**
 * Does `tokenactions` compress, or does its segment key defeat compression?
 *
 * Not a test — it asserts nothing and must never run in the suite.
 *
 * `HypertableRegistry` declares `segmentby tokenid, urlid, method` for the framework's
 * highest-volume table: one row per API request, kept three years. `tokenid` is high
 * cardinality — one per issued token — which is the pattern that measured a compression
 * ratio of **0.59** on the change log, meaning compression made the table larger.
 *
 * ## Why it might nevertheless be right here
 *
 * A change log is sparse per record by nature. An API log is not necessarily: it depends
 * entirely on traffic shape, and the two plausible shapes point opposite ways.
 *
 * - **Few long-lived tokens** — a handful of server-to-server clients hammering a handful
 *   of endpoints — give large `(tokenid, urlid, method)` segments. Compression works, and
 *   segmenting by token is exactly the right read optimisation.
 * - **Many short-lived tokens** — browser sessions, each making a few calls — give
 *   segments of a few rows each, and the same failure as the change log.
 *
 * So this measures both rather than guessing which one an installation has.
 *
 *     docker exec pramnos_php php tests/Benchmarks/tokenactions_compression.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__, 2));
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('LOG_PATH')) {
    define('LOG_PATH', ROOT . DS . 'var');
}

$rows = (int) ($argv[1] ?? 2_000_000);

/**
 * The two traffic shapes, as token counts over the same number of rows.
 *
 * 200 tokens against 2 M rows is 10 000 calls each: a server-to-server integration.
 * 200 000 tokens is 10 calls each: browser sessions. Everything else is held equal so the
 * only variable is how many rows land in a segment.
 */
const PROFILES = [
    'few long-lived'    => 200,
    'many short-lived'  => 200_000,
];

const URLS = 60;

$db = new \Pramnos\Database\Database();
$db->type     = 'postgresql';
$db->server   = 'timescaledb';
$db->user     = 'postgres';
$db->password = 'secret';
$db->database = 'pramnos_test';
$db->port     = 5432;
$db->schema   = 'public';
$db->connect(true);

$check = $db->query("SELECT COUNT(*) AS cnt FROM pg_extension WHERE extname = 'timescaledb'");
if ((int) $check->fields['cnt'] === 0) {
    fwrite(STDERR, "TimescaleDB is not installed here.\n");
    exit(1);
}

$layouts = [
    'declared'  => 'tokenid, urlid, method',
    'method'    => 'method',
    'urlid'     => 'urlid, method',
];

$time = static function (\Pramnos\Database\Database $db, string $sql, int $tries = 5): float {
    $best = INF;
    for ($i = 0; $i < $tries; $i++) {
        $start = microtime(true);
        $db->query($sql);
        $best = min($best, (microtime(true) - $start) * 1000);
    }

    return $best;
};

$results = [];

foreach (PROFILES as $profile => $tokens) {
    foreach ($layouts as $layoutName => $segmentBy) {
        $name  = $profile . ' / ' . $layoutName;
        $table = 'bench_tokenactions_' . abs(crc32($name));

        echo str_pad($name, 30), "building… ";
        $db->query('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
        $db->query(
            'CREATE TABLE ' . $table . ' ('
            . 'actionid BIGSERIAL, tokenid INTEGER NOT NULL, urlid INTEGER NOT NULL, '
            . 'method VARCHAR(10) NOT NULL, return_status INTEGER, '
            . 'execution_time_ms NUMERIC(10,3), '
            . 'action_time TIMESTAMPTZ NOT NULL, PRIMARY KEY (actionid, action_time))'
        );
        $db->query(
            "SELECT create_hypertable('" . $table . "', 'action_time', "
            . "chunk_time_interval => INTERVAL '14 days')"
        );

        $db->query(
            'INSERT INTO ' . $table
            . ' (tokenid, urlid, method, return_status, execution_time_ms, action_time) '
            . 'SELECT (g % ' . $tokens . '), '
            . '       (g % ' . URLS . '), '
            . "       (ARRAY['GET','POST','PUT','DELETE'])[1 + (g % 4)], "
            . '       200, '
            . '       (random() * 500)::numeric(10,3), '
            . "       NOW() - (random() * INTERVAL '90 days') "
            . 'FROM generate_series(1, ' . $rows . ') g'
        );

        echo 'compressing… ';
        $db->query(
            'ALTER TABLE ' . $table . ' SET ('
            . 'timescaledb.compress, '
            . "timescaledb.compress_segmentby = '" . $segmentBy . "', "
            . "timescaledb.compress_orderby = 'action_time DESC')"
        );
        $start = microtime(true);
        $db->query("SELECT compress_chunk(c) FROM show_chunks('" . $table . "') c");
        $compressSeconds = microtime(true) - $start;

        $stats = $db->query(
            'SELECT COALESCE(SUM(before_compression_total_bytes), 0) AS b,'
            . " COALESCE(SUM(after_compression_total_bytes), 0) AS a"
            . " FROM hypertable_compression_stats('" . $table . "')"
        );
        $before = (int) $stats->fields['b'];
        $after  = (int) $stats->fields['a'];

        echo "querying…\n";
        $byToken = $time(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' WHERE tokenid = 7'
            . " AND action_time > NOW() - INTERVAL '30 days'"
        );
        $byUrl = $time(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' WHERE urlid = 7'
            . " AND action_time > NOW() - INTERVAL '30 days'"
        );

        $results[$name] = [
            'ratio'    => $after > 0 ? $before / $after : 0.0,
            'stored'   => $after,
            'compress' => $compressSeconds,
            'byToken'  => $byToken,
            'byUrl'    => $byUrl,
        ];

        $db->query('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
    }
}

$mb = static fn(int $bytes): string => number_format($bytes / 1048576, 1) . ' MB';

echo "\n", number_format($rows), " rows · ", URLS, " endpoints · 90 days · 14-day chunks\n\n";
printf("%-30s %8s %10s %10s %11s %10s\n",
    'profile / segmentby', 'ratio', 'stored', 'compress', 'by-token ms', 'by-url ms');
foreach ($results as $name => $r) {
    printf("%-30s %8.2f %10s %9.1fs %11.3f %10.3f\n",
        $name, $r['ratio'], $mb($r['stored']), $r['compress'], $r['byToken'], $r['byUrl']);
}
echo "\n'declared' is what HypertableRegistry ships today.\n";
