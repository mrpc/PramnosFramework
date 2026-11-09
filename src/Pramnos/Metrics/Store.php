<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Database\Database;
use Pramnos\Database\Instant;

/**
 * Where the metrics' values are kept: **a table per grain** — `stats.metric_values_day`, `_week`,
 * `_month`, `_quarter`, `_year` — and one view over them all, `stats.metric_values`, with a `grain`
 * column, for SQL, exports and the assistant.
 *
 * A table per grain because each grain wants its own chunk size, compression and retention, and
 * because a read of one grain then never pays for another's rows: measured on 100 M rows, a
 * 24-month chart reads in 0.5 ms here against 3.1 ms (10 ms compressed) from one table of all the
 * grains (docs/Metrics-BI-Design.md §5.4). The same split on MySQL and plain PostgreSQL, where it
 * costs nothing, so there is one layout and one query path.
 *
 * Where TimescaleDB is installed each table is a hypertable on `period_start`, with its grain's
 * chunk interval, compressed by `(metric, tenant, dims_hash)` once its periods are well past
 * correction. Rewriting a closed period inside a compressed chunk is direct DML on 2.11+.
 *
 * {@see ensure()} makes all of it, and is safe to run again; it also converts the single
 * `stats.metric_values` table an installation may have from before, keeping every row.
 */
final class Store
{
    /** The view over every grain. */
    public const VIEW = 'stats.metric_values';

    /** A grain's table: this, then the grain. */
    public const PREFIX = 'stats.metric_values_';

    /** A value's key: what it is of, and when. */
    public const KEY = ['metric', 'tenant', 'dims_hash', 'version', 'period_start'];

    /**
     * The version of a metric's definition that is computed and read. Another version — the
     * figures of an earlier definition, or corrected ones — is kept beside it, imported, and read
     * once versions are (design §4.6).
     */
    public const VERSION = 1;

    /** Where a value came from. */
    public const COMPUTED = 'computed';
    public const SNAPSHOT = 'snapshot';
    public const IMPORTED = 'imported';

    /** The lock that keeps a change of the store and a computation apart, across hosts. */
    public const LOCK = 'pramnos_metrics_store';

    /** Chunk interval per grain: a few hundred thousand rows a chunk at the sizes measured. */
    private const CHUNK = ['day' => '90 days', 'week' => '1 year', 'month' => '2 years', 'quarter' => '5 years', 'year' => '10 years'];

    /** Compressed once a period is this old; a year's rows are too few to bother. */
    private const COMPRESS_AFTER = ['day' => '90 days', 'week' => '1 year', 'month' => '2 years', 'quarter' => '5 years'];

    /** A grain's table. */
    public static function table(string $grain): string
    {
        if (!in_array($grain, Window::GRAINS, true)) {
            throw new \InvalidArgumentException('Grain "' . $grain . '" is not one of: ' . implode(', ', Window::GRAINS) . '.');
        }

        return self::PREFIX . $grain;
    }

    /**
     * Make the tables, the view and — with TimescaleDB — the hypertables and their compression;
     * convert a single values table from before. Safe to run again.
     *
     * @return list<string> What was done, a line each
     */
    public static function ensure(Database $db): array
    {
        if (!self::lock($db, 600)) {
            throw new \RuntimeException('The metrics\' store is busy: a computation or another change holds it. Try again.');
        }
        try {
            return self::make($db);
        } finally {
            self::unlock($db);
        }
    }

    /**
     * Take the store's lock — waiting up to `$wait` seconds, 0 to try once — for this connection.
     * Taken again by the connection that holds it, it is granted (both databases count it).
     */
    public static function lock(Database $db, int $wait): bool
    {
        // Raw: advisory locks are each database's own function.
        $deadline = time() + $wait;
        do {
            $result = $db->type === 'postgresql'
                ? $db->query("SELECT pg_try_advisory_lock(hashtext('" . self::LOCK . "')) AS got")
                : $db->query("SELECT GET_LOCK('" . self::LOCK . "', " . min($wait, 600) . ') AS got');
            $row = $result ? $result->fetch() : null;
            // A connection that cannot even ask (a stand-in in a test) has nobody to wait for.
            if (!is_array($row)) {
                return true;
            }
            if ($row['got'] === true || $row['got'] === 't' || (string) $row['got'] === '1') {
                return true;
            }
            if ($db->type === 'postgresql' && time() < $deadline) {
                sleep(1);
            }
        } while ($db->type === 'postgresql' && time() < $deadline);

        return false;
    }

    /** Let the store's lock go. */
    public static function unlock(Database $db): void
    {
        // Raw: as lock().
        $db->query($db->type === 'postgresql' ? "SELECT pg_advisory_unlock(hashtext('" . self::LOCK . "'))" : "SELECT RELEASE_LOCK('" . self::LOCK . "')");
    }

    /** {@see ensure()}, the lock held. @return list<string> */
    private static function make(Database $db): array
    {
        $schema = $db->schema();
        $schema->ensureSchema('stats');
        $done   = [];
        $legacy = 'stats.metric_values_before';
        // The single table from before is named like the view: moved aside, then copied over. A
        // conversion cut short left it aside: the copy starts again, and replaces what it had done.
        if (!$schema->hasTable($legacy) && $schema->hasTable(self::VIEW) && $schema->hasColumn(self::VIEW, 'dimset') && !$schema->hasColumn(self::VIEW, 'tenant')) {
            $schema->renameTable(self::VIEW, $legacy);
            $done[] = 'moved the single values table aside';
        }
        $legacy = $schema->hasTable($legacy) ? $legacy : null;
        $timescale = $db->type === 'postgresql' && $db->capabilities()->hasTimescaleDB();
        foreach (Window::GRAINS as $grain) {
            $table = self::table($grain);
            if ($schema->hasTable($table)) {
                continue;
            }
            $schema->createTable($table, static function ($t) use ($grain) {
                $t->comment('The values of the application\'s metrics by ' . $grain . ', per tenant, period and breakdown');
                $t->string('metric', 100)->comment('The metric\'s name, as its catalogue declares it');
                $t->bigInteger('tenant')->default(0)->comment('The organisation the value is of; 0 for the installation');
                $t->timestampTz('period_start')->comment('The period\'s first instant, in the metrics\' time zone');
                $t->string('dimset', 200)->default('')->comment('The dimensions of this breakdown, sorted and comma-joined; empty for the total');
                $t->jsonb('dims')->comment('The breakdown\'s values; {} for the total');
                $t->uuid('dims_hash')->comment('md5 of dims as a uuid, for the key');
                $t->smallInteger('version')->default(1)->comment('The definition of the metric the value was computed by');
                $t->double('value')->nullable();
                $t->jsonb('extra')->nullable()->comment('What a value carries beside its number: an average\'s sum and count, a kept text');
                $t->string('source', 10)->default(self::COMPUTED)->comment('computed, snapshot (a level) or imported');
                $t->smallInteger('final')->default(0)->comment('1 once the period is closed: never computed again unless asked');
                $t->timestampTz('computed_at')->nullable();
                $t->primary(self::KEY);
                // A report across dimensions: every breakdown of a metric in a period.
                $t->index(['metric', 'period_start'], 'idx_metric_values_' . $grain . '_period');
            });
            $done[] = 'created ' . $table;
            if ($timescale) {
                $schema->createHypertable($table, 'period_start', ['chunk_time_interval' => self::CHUNK[$grain], 'migrate_data' => true]);
                if (isset(self::COMPRESS_AFTER[$grain])) {
                    $schema->enableCompression($table, ['segmentby' => 'metric, tenant, dims_hash', 'orderby' => 'period_start DESC']);
                    $schema->addCompressionPolicy($table, self::COMPRESS_AFTER[$grain]);
                }
                $done[] = 'made ' . $table . ' a hypertable';
            }
        }
        $schema->createOrReplaceView(self::VIEW, self::viewSql($db));
        if ($legacy !== null) {
            $done[] = 'copied ' . self::copyLegacy($db, $legacy) . ' values from the single table';
            $schema->dropTableIfExists($legacy);
        }

        return $done;
    }

    /** Drop the view and every grain's table. */
    public static function drop(Database $db): void
    {
        $schema = $db->schema();
        $schema->dropView(self::VIEW);
        foreach (Window::GRAINS as $grain) {
            $schema->dropTableIfExists(self::table($grain));
        }
    }

    /** Delete the values of the metrics whose name is like `$like` (`test.%`), at every grain. */
    public static function forget(Database $db, string $like): void
    {
        foreach (Window::GRAINS as $grain) {
            $db->queryBuilder()->table(self::table($grain))->where('metric', 'like', $like)->delete();
        }
    }

    /**
     * One row as kept.
     *
     * @param array<string, string>     $dims
     * @param array<string, mixed>|null $extra
     * @return array<string, mixed>
     */
    public static function row(Database $db, string $metric, Window $window, array $dims, ?float $value, string $source, bool $final, int $now, ?array $extra = null, int $tenant = 0, int $version = self::VERSION): array
    {
        $dims = array_map('strval', $dims);
        ksort($dims);
        // A broken value of a dimension is substituted, never left to empty the JSON and its hash.
        $json = (string) json_encode((object) $dims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return [
            'metric'       => $metric,
            'tenant'       => $tenant,
            'period_start' => self::instant($db, $window),
            'dimset'       => implode(',', array_keys($dims)),
            'dims'         => $json,
            'dims_hash'    => self::hash($json),
            'version'      => $version,
            'value'        => $value,
            'extra'        => $extra === null ? null : json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'source'       => $source,
            'final'        => $final ? 1 : 0,
            'computed_at'  => Instant::utc($now, $db),
        ];
    }

    /** A period's start as the column takes it. */
    public static function instant(Database $db, Window $window): string
    {
        return Instant::utc($window->start->getTimestamp(), $db);
    }

    /** The period a kept `period_start` is the start of, as its key (YYYY-MM-DD in the metrics' zone). */
    public static function key(string $stored): string
    {
        // PostgreSQL answers with an offset, MySQL with the bare UTC it was given.
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->setTimezone(Window::zone())->format('Y-m-d');
    }

    /** md5 of the dimensions' JSON, written as a uuid: 16 bytes in PostgreSQL. */
    public static function hash(string $json): string
    {
        $md5 = md5($json);

        return substr($md5, 0, 8) . '-' . substr($md5, 8, 4) . '-' . substr($md5, 12, 4) . '-' . substr($md5, 16, 4) . '-' . substr($md5, 20);
    }

    /**
     * Copy another table's figures in, once: an application's own store, or its statistics
     * tables. `$map` names its columns — `metric`, `period` (its start: a date, a time, or a unix
     * time), `value`, and `grain` (a column) or `grainIs` (one grain for the whole table) — and
     * optionally `dims` (columns that are dimensions, an empty value meaning the total), `extra`
     * (columns kept beside the number), `prefix` (put before each metric's name) and `version`
     * (the definition the figures are of; {@see VERSION} by default). Every value is imported
     * final, as `imported`, and replaces one kept for the same period, breakdown and version.
     *
     * @param array{metric: string, period: string, value: string, grain?: string, grainIs?: string, dims?: list<string>, extra?: list<string>, prefix?: string, version?: int} $map
     * @return array{rows: int, skipped: int}
     */
    public static function adopt(Database $db, Database $from, string $table, array $map, ?callable $progress = null): array
    {
        foreach (['metric', 'period', 'value'] as $needed) {
            if (($map[$needed] ?? '') === '') {
                throw new \InvalidArgumentException('Name the column that holds the ' . $needed . '.');
            }
        }
        if (($map['grain'] ?? '') === '' && !in_array($map['grainIs'] ?? '', Window::GRAINS, true)) {
            throw new \InvalidArgumentException('Name the column that holds the grain, or the one grain of the whole table: ' . implode(', ', Window::GRAINS) . '.');
        }
        self::ensure($db);
        if (!self::lock($db, 600)) {
            throw new \RuntimeException('The metrics\' store is busy: a computation holds it. Try again.');
        }
        try {
            return self::import($db, $from, $table, $map, $progress);
        } finally {
            self::unlock($db);
        }
    }

    /**
     * {@see adopt()}, the lock held.
     *
     * @param array<string, mixed> $map
     * @return array{rows: int, skipped: int}
     */
    private static function import(Database $db, Database $from, string $table, array $map, ?callable $progress): array
    {
        $now = time();

        return self::pour($db, $from->queryBuilder()->table($table)->get(), static function (array $source) use ($db, $map, $now): ?array {
            $grain = ($map['grain'] ?? '') !== '' ? (string) ($source[$map['grain']] ?? '') : (string) $map['grainIs'];
            $at    = $source[$map['period']] ?? null;
            if (!in_array($grain, Window::GRAINS, true) || $at === null || $at === '' || (string) ($source[$map['metric']] ?? '') === '') {
                return null;
            }
            $dims = [];
            foreach ($map['dims'] ?? [] as $column) {
                if (($source[$column] ?? '') !== '' && $source[$column] !== null) {
                    $dims[$column] = (string) $source[$column];
                }
            }
            $extra = [];
            foreach ($map['extra'] ?? [] as $column) {
                if (($source[$column] ?? null) !== null) {
                    $extra[$column] = $source[$column];
                }
            }
            $value  = $source[$map['value']] ?? null;
            // A unix time, or a time or date read as UTC (an offset kept), then in the metrics' zone.
            $window = Window::of($grain, is_numeric($at) && strlen((string) $at) > 8 ? (int) $at : self::key((string) $at));

            return [$grain, self::row($db, ($map['prefix'] ?? '') . $source[$map['metric']], $window, $dims, $value === null ? null : (float) $value, self::IMPORTED, true, $now, $extra ?: null, 0, (int) ($map['version'] ?? self::VERSION))];
        }, $progress);
    }

    /**
     * Write the rows a query gives, each made by `$make` (`[grain, row]`, or null to leave it out),
     * a thousand at a time, one statement per grain. The query is read once, row by row — no pages,
     * so a table whose order is not unique loses and repeats nothing. A key given twice keeps the
     * last row read, as one upsert after another would; `rows` counts the writes, so such a key
     * read in two batches counts twice.
     *
     * @return array{rows: int, skipped: int}
     */
    private static function pour(Database $db, mixed $result, callable $make, ?callable $progress): array
    {
        $rows    = 0;
        $skipped = 0;
        $batch   = [];
        $held    = 0;
        $flush   = static function () use ($db, &$batch, &$held, &$rows, $progress, &$skipped): void {
            foreach ($batch as $grain => $byKey) {
                $db->queryBuilder()->table(self::table($grain))->upsertMany(array_values($byKey), self::KEY, ['dimset', 'dims', 'value', 'extra', 'source', 'final', 'computed_at']);
                $rows += count($byKey);
            }
            $batch = [];
            $held  = 0;
            if ($progress !== null) {
                $progress($rows, $skipped);
            }
        };
        while ($result && ($source = $result->fetch()) !== null && $source !== false) {
            $made = $make($source);
            if ($made === null) {
                $skipped++;
                continue;
            }
            [$grain, $row] = $made;
            $batch[$grain][$row['metric'] . '|' . $row['tenant'] . '|' . $row['dims_hash'] . '|' . $row['version'] . '|' . $row['period_start']] = $row;
            if (++$held >= 1000) {
                $flush();
            }
        }
        $flush();

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** The view: every grain's table, its grain as a column. */
    private static function viewSql(Database $db): string
    {
        $schema  = $db->schema();
        $columns = 'metric, tenant, period_start, dimset, dims, dims_hash, version, value, extra, source, final, computed_at';
        $parts   = [];
        foreach (Window::GRAINS as $grain) {
            // Raw: a view's body is SQL; the table names go through the schema's quoting (rule 15).
            $parts[] = "SELECT '" . $grain . "' AS grain, " . $columns . ' FROM ' . $schema->quoteTable(self::table($grain));
        }

        return implode(' UNION ALL ', $parts);
    }

    /** Copy the single table's rows into the grains' tables; how many. */
    private static function copyLegacy(Database $db, string $legacy): int
    {
        return self::pour($db, $db->queryBuilder()->table($legacy)->get(), static function (array $old) use ($db): ?array {
            if (!in_array((string) $old['grain'], Window::GRAINS, true)) {
                return null;
            }
            $window = Window::of((string) $old['grain'], substr((string) $old['period_start'], 0, 10));
            $dims   = json_decode((string) $old['dims'], true);

            return [(string) $old['grain'], self::row($db, (string) $old['metric'], $window, is_array($dims) ? $dims : [], $old['value'] === null ? null : (float) $old['value'], (string) $old['source'], (int) $old['final'] === 1, (int) $old['computed_at'])];
        }, null)['rows'];
    }
}
