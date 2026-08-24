<?php

/**
 * Which compression layout the changelog should declare.
 *
 * Not a test — it asserts nothing and must never run in the suite. It answers one
 * question with numbers, because the numbers currently in HypertableRegistry are
 * reasoning about TimescaleDB's mechanics rather than measurement on this shape of data,
 * and this repository's own standard is higher than that: WriteSpool carries a table of
 * measured ms/row, which is why its defaults are trustworthy.
 *
 * ## The question
 *
 * TimescaleDB compresses in batches of up to 1000 rows **per segment**. The framework
 * declares `segmentby entity`, keeping the high-cardinality `itemid` out of it and first
 * in `orderby` instead; the reference application uses `segmentby itemtype, itemid`.
 *
 * The claim behind the framework's choice is that a change log is sparse per row — one
 * record changes a handful of times a day — so putting `itemid` in `segmentby` produces
 * segments of a few rows each: compression that does not compress, paying CPU for it.
 *
 * That is a prediction. This measures it.
 *
 * ## What is measured, and why the third one matters
 *
 * | | |
 * |---|---|
 * | compression ratio | whether the segments are big enough to compress at all |
 * | per-row lookup | `WHERE entity = ? AND itemid = ?` — the query the table exists for |
 * | recent-across-entity | `WHERE entity = ? ORDER BY created_at DESC` |
 *
 * The third is the one the framework's layout is expected to *lose*, and the spec accepted
 * that without a number. If the loss is disproportionate, the balance changes.
 *
 * ## Running it
 *
 *     docker exec pramnos_php php tests/Benchmarks/changelog_compression.php
 *     docker exec pramnos_php php tests/Benchmarks/changelog_compression.php 5000000
 *
 * Needs TimescaleDB and a few hundred MB of scratch space. Drops everything it made.
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
 * How the data is shaped, and why these numbers.
 *
 * `entities` is small because it is a vocabulary — an application has a dozen kinds of
 * thing, not thousands. `items` is large because it is every row of every table. The
 * ratio between them is what decides segment size, so it is the parameter the whole
 * question turns on, and it is stated here rather than buried in a query.
 */
const ENTITIES = 12;
const ITEMS_PER_ENTITY = 20_000;
const DAYS = 30;

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

/** The four candidates. */
$layouts = [
    'framework/7d' => ['chunk' => '7 days', 'segmentby' => 'entity',         'orderby' => 'itemid, created_at DESC'],
    'framework/1d' => ['chunk' => '1 day',  'segmentby' => 'entity',         'orderby' => 'itemid, created_at DESC'],
    'reference/7d' => ['chunk' => '7 days', 'segmentby' => 'entity, itemid', 'orderby' => 'created_at DESC'],
    'reference/1d' => ['chunk' => '1 day',  'segmentby' => 'entity, itemid', 'orderby' => 'created_at DESC'],
];

/** Run a query and return the milliseconds it took, best of $tries. */
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

foreach ($layouts as $name => $layout) {
    $table = 'bench_changelog_' . preg_replace('/\W+/', '_', $name);

    echo str_pad($name, 16), "building… ";
    $db->query('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
    $db->query(
        'CREATE TABLE ' . $table . ' ('
        . 'logid BIGSERIAL, entity VARCHAR(64) NOT NULL, itemid VARCHAR(64) NOT NULL, '
        . 'op VARCHAR(8) NOT NULL, changes JSONB, userid BIGINT, source VARCHAR(8), '
        . 'created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY (logid, created_at))'
    );
    $db->query(
        "SELECT create_hypertable('" . $table . "', 'created_at', "
        . "chunk_time_interval => INTERVAL '" . $layout['chunk'] . "')"
    );

    // Generated in the database rather than in PHP: two million round trips would measure
    // the driver, and what is under test is storage.
    $db->query(
        'INSERT INTO ' . $table . ' (entity, itemid, op, changes, userid, source, created_at) '
        . "SELECT 'entity-' || (g % " . ENTITIES . "), "
        . "       ((g / " . ENTITIES . ") % " . ITEMS_PER_ENTITY . ")::text, "
        . "       'updated', "
        . '       jsonb_build_object(\'status\', jsonb_build_object(\'old\', g % 7, \'new\', (g + 1) % 7)), '
        . '       (g % 500), '
        . "       'web', "
        . "       NOW() - (random() * INTERVAL '" . DAYS . " days') "
        . 'FROM generate_series(1, ' . $rows . ') g'
    );
    $db->query('CREATE INDEX ON ' . $table . ' (entity, itemid, created_at DESC)');

    $before = $db->query(
        "SELECT pg_total_relation_size('" . $table . "') AS bytes"
    )->fields['bytes'];

    echo 'compressing… ';
    $db->query(
        'ALTER TABLE ' . $table . " SET ("
        . 'timescaledb.compress, '
        . "timescaledb.compress_segmentby = '" . $layout['segmentby'] . "', "
        . "timescaledb.compress_orderby = '" . $layout['orderby'] . "')"
    );
    $compressStart = microtime(true);
    $db->query("SELECT compress_chunk(c) FROM show_chunks('" . $table . "') c");
    $compressSeconds = microtime(true) - $compressStart;

    $stats = $db->query(
        "SELECT COALESCE(SUM(before_compression_total_bytes), 0) AS before_bytes,"
        . " COALESCE(SUM(after_compression_total_bytes), 0) AS after_bytes"
        . " FROM hypertable_compression_stats('" . $table . "')"
    );
    $beforeBytes = (int) $stats->fields['before_bytes'];
    $afterBytes  = (int) $stats->fields['after_bytes'];

    echo "querying…\n";
    $perRow = $time(
        $db,
        'SELECT * FROM ' . $table . " WHERE entity = 'entity-3' AND itemid = '512'"
        . ' ORDER BY created_at DESC LIMIT 50'
    );
    $recent = $time(
        $db,
        'SELECT * FROM ' . $table . " WHERE entity = 'entity-3'"
        . ' ORDER BY created_at DESC LIMIT 50'
    );

    $results[$name] = [
        'ratio'    => $afterBytes > 0 ? $beforeBytes / $afterBytes : 0.0,
        'stored'   => $afterBytes,
        'uncompr'  => (int) $before,
        'compress' => $compressSeconds,
        'perRow'   => $perRow,
        'recent'   => $recent,
    ];

    $db->query('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
}

$mb = static fn(int $bytes): string => number_format($bytes / 1048576, 1) . ' MB';

echo "\n", number_format($rows), " rows · ", ENTITIES, " entities · ",
     number_format(ENTITIES * ITEMS_PER_ENTITY), " records · ", DAYS, " days\n\n";
printf("%-16s %8s %10s %10s %12s %12s\n",
    'layout', 'ratio', 'stored', 'compress', 'per-row ms', 'recent ms');
foreach ($results as $name => $r) {
    printf("%-16s %8.2f %10s %9.1fs %12.3f %12.3f\n",
        $name, $r['ratio'], $mb($r['stored']), $r['compress'], $r['perRow'], $r['recent']);
}
echo "\nratio = uncompressed / compressed, from hypertable_compression_stats()\n";
echo "per-row = WHERE entity AND itemid; recent = WHERE entity, newest 50\n";
