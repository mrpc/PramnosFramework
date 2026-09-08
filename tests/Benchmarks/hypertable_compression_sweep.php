<?php

/**
 * Does compression actually make each declared hypertable smaller?
 *
 * Not a test — it asserts nothing and must never run in the suite. It answers one question
 * across every table in `HypertableRegistry`, because the question turned out to have a
 * different answer than anyone assumed for several of them.
 *
 * ## Why it exists
 *
 * `authserver.data_processing_records` declared a compression policy and **no
 * `segmentby`**. TimescaleDB then picks none — it warns that it cannot find a suitable
 * indexed column — and compression *grew* the table on a quiet installation. The reference
 * application had independently found the same thing on its own `alerthistory` and removed
 * the policy outright.
 *
 * Four of the framework's ten declared hypertables were in that state. So this measures all
 * of them, because the reported one is never the only one.
 *
 * ## Why it runs the real migrations
 *
 * A first version of this script wrote the DDL out by hand, and every one of the shapes was
 * wrong: `action` is not `activity`, `consent_type` is not `appid`, `ip_address` is not
 * `ip`. The numbers it produced were about tables that do not exist — including one that
 * contradicted `tokenactions_compression.php` and would have justified changing a layout
 * that measurement had already settled.
 *
 * So the tables here are created by the framework's own migrations and their columns are
 * read back from `information_schema`. The indexes come with them, which matters: those are
 * what TimescaleDB's default segment-by picker reads.
 *
 * ## What it measures, and how to read it
 *
 * `hypertable_size()` before compression divided by after, for the declared layout and for
 * each column that heads a btree index — the plausible `segmentby` candidates, since a
 * segment of one row is what "no segmentby" already amounts to.
 *
 * **A ratio below 1.0 means compression made the table bigger.** That is what this exists
 * to catch, and it is invisible from outside: nothing errors, the policy runs, the disk
 * grows.
 *
 * Both volumes have to be defensible. A segment that packs well at half a million rows can
 * be a handful of rows per chunk on a quiet installation, where a compressed chunk's own
 * overhead exceeds what it saves.
 *
 * **Size is not the only axis.** A `segmentby` column is stored per segment and can be
 * filtered without decompressing, so it also decides query cost — which is why
 * `tokenactions` keeps `urlid` in its key despite a slightly worse ratio, and why this
 * script does not recommend anything for a table whose queries have been measured
 * elsewhere. See `tokenactions_compression.php`.
 *
 * ## Running it
 *
 *     docker exec pramnos_php php tests/Benchmarks/hypertable_compression_sweep.php
 *     docker exec pramnos_php php tests/Benchmarks/hypertable_compression_sweep.php 5000 500000
 *
 * Needs TimescaleDB and about a gigabyte of scratch space. Drops what it made.
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

$volumes = [
    'quiet' => (int) ($argv[1] ?? 5_000),
    'busy'  => (int) ($argv[2] ?? 500_000),
];

/**
 * How many distinct values a column gets.
 *
 * The one thing that cannot be introspected and the one thing the answer turns on: a
 * segment key with thousands of values produces segments of a few rows, which is
 * compression that does not compress. These are the cardinalities the real data has —
 * `action` is a vocabulary, `userid` is the user table.
 */
const CARDINALITY = [
    'userid'           => 20_000,
    'processed_by'     => 20,
    'tokenid'          => 20_000,
    'urlid'            => 60,
    'action'           => 8,
    'operation'        => 5,
    'data_category'    => 4,
    'legal_basis'      => 4,
    'consent_type'     => 6,
    'request_type'     => 4,
    'status'           => 4,
    'method'           => 4,
    'code_used'        => 3,
    'client_id'        => 40,
    'processor'        => 3,
    'entity'           => 12,
    'itemid'           => 20_000,
    'event'            => 4,
    'appid'            => 40,
    'granted'          => 2,
    'return_status'    => 4,
    'servertime'       => 1_000,
    'retention_period' => 3,
];

const DEFAULT_CARDINALITY = 1_000;

/**
 * Which registry tables this builds, and which migration builds each.
 *
 * `tokenactions` is deliberately absent. It has its own benchmark, which measures the
 * query axis as well as the ratio and settled its layout — and it carries a trigger that
 * derives `action_time` from `servertime`, so generic filling puts every row in 1970 and
 * the chunk constraint refuses it. A table whose layout was chosen on measurements this
 * script does not take should not be re-argued from a subset of them.
 *
 * `pramnos.changelog*` and `applications.application_stats` already declare a `segmentby`
 * chosen by measurement (see `changelog_compression.php`) and are left to it for the same
 * reason.
 */
const BUILDERS = [
    'authserver.twofactor_attempts'      => ['auth', 'CreateTwofactorAttemptsTable'],
    'authserver.user_activity_log'       => ['auth', 'CreateUserActivityLogTable'],
    'authserver.user_consents'           => ['auth', 'CreateUserConsentsTable'],
    'authserver.data_processing_records' => ['auth', 'CreateDataProcessingRecordsTable'],
    'authserver.gdpr_requests'           => ['auth', 'CreateGdprRequestsTable'],
];

/** Migrations these depend on. Run once, up front. */
const PREREQUISITES = [
    ['auth', 'CreateUsersTable'],
    ['auth', 'CreateUserTwofactorTable'],
    ['auth', 'CreateTwofactorSetupTable'],
];

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

$db->execute('CREATE SCHEMA IF NOT EXISTS authserver');

/** An application shell: the migrations want one, and only for its database. */
$app = (new ReflectionClass(\Pramnos\Application\Application::class))
    ->newInstanceWithoutConstructor();
$app->database = $db;

/** Find one migration by class name, wherever it lives. */
$migration = static function (string $class) use ($app): \Pramnos\Database\Migration {
    $base = ROOT . '/database/migrations/framework';

    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory($dir, $app) as $m) {
            if ((new ReflectionClass($m))->getShortName() === $class) {
                return $m;
            }
        }
    }

    fwrite(STDERR, "Migration not found: {$class}\n");
    exit(1);
};

/** Split `schema.table` into its two halves. */
$split = static function (string $name): array {
    return str_contains($name, '.')
        ? explode('.', $name, 2)
        : ['public', $name];
};

/**
 * A value expression for one column, from its type and declared cardinality.
 *
 * `g` is the row number from `generate_series`, so `g % n` gives exactly n distinct values
 * rather than approximately n — a segment count that varies per run is a number nobody can
 * reproduce.
 */
$expression = static function (string $column, string $type, int $length): string {
    $n = CARDINALITY[$column] ?? DEFAULT_CARDINALITY;

    // A boolean has two values whatever the map says, and `g % 1000 = 0` would make one
    // of them 0.1% of the rows — a segment key that is effectively constant, which is a
    // different measurement from the one intended.
    if ($type === 'boolean') {
        return '((g % 2) = 0)';
    }

    // The varying part goes **first** so that fitting the value into the column's width
    // cannot collapse distinct values into one. Padding to the declared width matters:
    // the compressible bulk of these tables is repeated text, and a two-character stand-in
    // for a 255-character column measures a table nobody has.
    if ($length > 0) {
        return "left((g % " . $n . ")::text"
            . " || '-value-with-descriptive-padding-text-repeated-for-width', " . $length . ')';
    }

    return match (true) {
        $type === 'jsonb'   => "('{\"k\":' || (g % " . $n . ") || '}')::jsonb",
        $type === 'numeric' => '((g % ' . $n . ')::numeric / 7)',
        in_array($type, ['bigint', 'integer', 'smallint'], true) => '(g % ' . $n . ')',
        $type === 'timestamp with time zone' => "now() - ((g % " . $n . ") * INTERVAL '1 hour')",
        default => "((g % " . $n . ")::text"
            . " || ' — repeated descriptive text for this row, long enough to compress')",
    };
};

printf(
    "%-38s %-24s %9s %9s %7s %s\n",
    'table', 'segmentby', 'before', 'after', 'ratio', ''
);
echo str_repeat('-', 100), "\n";

foreach (PREREQUISITES as [$feature, $class]) {
    try {
        $migration($class)->up();
    } catch (\Throwable $e) {
        // A prerequisite already there is not a problem.
    }
}

$declared = \Pramnos\Database\HypertableRegistry::all();

foreach (BUILDERS as $name => [$feature, $class]) {
    [$schema, $table] = $split($name);
    $spec = $declared[$name] ?? [];
    $timeColumn = (string) ($spec['time_column'] ?? '');
    $declaredSegmentby = $spec['segmentby'] ?? null;

    foreach ($volumes as $label => $rows) {
        // Rebuilt per volume and per layout: `compress_segmentby` cannot be changed
        // meaningfully once chunks are packed, and a half-compressed table would report a
        // ratio for a mixture of layouts.
        $db->execute('DROP TABLE IF EXISTS "' . $schema . '"."' . $table . '" CASCADE');
        $migration($class)->up();

        $columns = $db->query($db->prepareQuery(
            'SELECT column_name, data_type, column_default,
                    COALESCE(character_maximum_length, 0) AS len
               FROM information_schema.columns
              WHERE table_schema = %s AND table_name = %s
              ORDER BY ordinal_position',
            $schema,
            $table
        ))->fetchAll();

        // Candidates: whatever heads a btree index, minus the time column. Those are the
        // columns TimescaleDB's own picker considers, and the ones a query filters on.
        $indexed = $db->query($db->prepareQuery(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = %s AND tablename = %s',
            $schema,
            $table
        ))->fetchAll();

        // A surrogate key cannot be a segment key — TimescaleDB puts it in `orderby` and
        // refuses to have it in both — and it would be a segment per row anyway, which is
        // the thing being measured against.
        $generated = [$timeColumn];
        foreach ($columns as $column) {
            if (str_contains((string) ($column['column_default'] ?? ''), 'nextval')
                || in_array((string) $column['column_name'], ['id', 'actionid', 'attemptid'], true)
            ) {
                $generated[] = (string) $column['column_name'];
            }
        }

        $candidates = [];
        foreach ($indexed as $index) {
            if (preg_match('/\(([a-z0-9_]+)/i', (string) $index['indexdef'], $m) === 1) {
                $head = strtolower($m[1]);
                if (!in_array($head, $generated, true) && !in_array($head, $candidates, true)) {
                    $candidates[] = $head;
                }
            }
        }

        $layouts = [];
        foreach (array_merge([$declaredSegmentby], $candidates) as $layout) {
            if (!in_array($layout, $layouts, true)) {
                $layouts[] = $layout;
            }
        }

        $insertable = [];
        $values     = [];
        foreach ($columns as $column) {
            $columnName = (string) $column['column_name'];
            if ($columnName === $timeColumn) {
                continue;
            }
            // The surrogate key is generated; anything else is filled.
            if (str_contains((string) ($column['column_default'] ?? ''), 'nextval')) {
                continue;
            }
            if (in_array($columnName, ['id', 'actionid', 'attemptid'], true)) {
                continue;
            }
            $insertable[] = $columnName;
            $values[] = $expression(
                $columnName,
                (string) $column['data_type'],
                (int) $column['len']
            );
        }

        $interval = (string) ($spec['chunk_interval'] ?? '7 days');

        foreach ($layouts as $segmentby) {
            try {
            $db->execute('DROP TABLE IF EXISTS "' . $schema . '"."' . $table . '" CASCADE');
            $migration($class)->up();

            // The migration installs the real compression **policy**, and most of the
            // history inserted below is already past it — so TimescaleDB's background
            // worker starts compressing chunks while this is still filling the table. That
            // makes `before` a size that is partly compressed already, and the layout
            // being measured a mixture of two. The policy goes, so the only compression
            // here is the one this script asks for.
            $db->execute(
                "SELECT remove_compression_policy('" . $schema . '.' . $table
                . "', if_exists => true)"
            );
            $db->execute(
                "SELECT decompress_chunk(c, if_compressed => true)
                   FROM show_chunks('" . $schema . '.' . $table . "') c"
            );

            $db->execute(
                'INSERT INTO "' . $schema . '"."' . $table . '" ("'
                . implode('", "', $insertable) . '", "' . $timeColumn . '") '
                . 'SELECT ' . implode(', ', $values) . ', '
                . "now() - INTERVAL '" . $interval . "' * 26 "
                . "+ (g * (INTERVAL '" . $interval . "' * 26) / " . max($rows, 1) . ') '
                . 'FROM generate_series(1, ' . max($rows, 1) . ') g'
            );

            $before = (int) $db->query(
                "SELECT hypertable_size('" . $schema . '.' . $table . "') AS b"
            )->fields['b'];

            // `compress_orderby` explicitly, and it is not optional. Left to the default,
            // TimescaleDB picks an ordering that includes the very column being tried as a
            // segment key and then refuses to have it in both — so every candidate came
            // back «cannot use column X for both ordering and segmenting», which reads as
            // "this layout is impossible" and is really "the sweep did not say enough".
            // Time descending is what a real declaration uses.
            $db->execute(
                'ALTER TABLE "' . $schema . '"."' . $table . '" SET (timescaledb.compress'
                . ($segmentby === null
                    ? ''
                    : ", timescaledb.compress_segmentby = '" . $segmentby . "'")
                . ", timescaledb.compress_orderby = '" . $timeColumn . " DESC')"
            );
            $db->execute(
                "SELECT compress_chunk(c, if_not_compressed => true)
                   FROM show_chunks('" . $schema . '.' . $table . "') c"
            );

            $after = (int) $db->query(
                "SELECT hypertable_size('" . $schema . '.' . $table . "') AS a"
            )->fields['a'];

            if ($after === 0) {
                continue;
            }

            $ratio = $before / $after;
            $flag  = $segmentby === $declaredSegmentby ? ' <- declared' : '';
            $flag .= $ratio < 1.0 ? '  ** BIGGER **' : '';

            printf(
                "%-38s %-24s %9.2f %9.2f %7.2f%s\n",
                $name . ' ' . $label,
                $segmentby ?? '(none)',
                $before / 1048576,
                $after / 1048576,
                $ratio,
                $flag
            );
            } catch (\Throwable $exception) {
                // A layout the engine refuses is an answer too — reported rather than
                // fatal, so one impossible candidate does not end the sweep.
                printf(
                    "%-38s %-24s %s\n",
                    $name . ' ' . $label,
                    $segmentby ?? '(none)',
                    'refused: ' . trim(explode('::: ', $exception->getMessage())[0])
                );
            }
        }
    }

    echo str_repeat('-', 100), "\n";
    $db->execute('DROP TABLE IF EXISTS "' . $schema . '"."' . $table . '" CASCADE');
}

echo "\nA ratio below 1.00 means compression made the table larger.\n";
echo "Size is not the only axis — a segmentby column is also cheap to filter on.\n";
