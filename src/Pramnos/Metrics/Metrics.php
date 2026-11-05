<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Database\Database;

/**
 * The application's metrics: the catalogue, the values kept for each period, computing and
 * closing periods, importing history, and reading series back.
 *
 * ```php
 * // app/app.php
 * 'metrics' => [
 *     'catalogue' => [\App\Metrics\Sales::class],   // each a Catalogue: metrics() yields Metric and Dimension
 *     'timezone'  => 'Europe/Athens',               // periods are counted in it (PHP's zone otherwise)
 *     'grains'    => ['day', 'week', 'month', 'quarter', 'year'],   // what metrics:compute keeps
 *     'replica'   => true,                          // sources read the replica (the "read" database settings)
 * ],
 *
 * Metrics::series('orders.count', 'month', '2026-01-01', '2026-10-31', ['region' => 'north'], 'plan');
 * Metrics::value('orders.count', 'month', '2026-09-01');
 * Metrics::compare('orders.count', 'month', '2026-09-01', [], Metrics::YEAR_EARLIER);
 * Metrics::import('customers.active', 'month', '2025-03-01', [], 4210);
 * ```
 *
 * Values live in `stats.metric_values`, one row per metric, grain, period and breakdown. A
 * period is computed while it is open, as often as `metrics:compute` runs, and **closed** once
 * after it ends: its values are then final and are never computed again unless asked
 * (`--recompute`) — a closed report keeps its numbers, and a level keeps how things stood. Rows
 * are never written for a period that has not begun.
 */
final class Metrics
{
    /** The values' table. */
    public const TABLE = 'stats.metric_values';

    /** Compare with the period before. */
    public const PREVIOUS = 'previous';

    /** Compare with the same period a year earlier. */
    public const YEAR_EARLIER = 'year';

    /** How far back a missed close is caught up, in periods. */
    private const CATCH_UP = 400;

    /** @var array<string, Metric> Registered in code */
    private static array $metrics = [];

    /** @var array<string, Dimension> */
    private static array $dimensions = [];

    /** Whether app.php's catalogue has been read. */
    private static bool $loaded = false;

    /** Add metrics or replace those under their names. */
    public static function register(Metric ...$metrics): void
    {
        foreach ($metrics as $metric) {
            self::$metrics[$metric->name] = $metric;
        }
    }

    /** Declare a dimension, or replace the one under its name. */
    public static function dimension(Dimension $dimension): void
    {
        self::$dimensions[$dimension->name] = $dimension;
    }

    /** Forget every metric and dimension, so the next read takes app.php's again. */
    public static function reset(): void
    {
        self::$metrics    = [];
        self::$dimensions = [];
        self::$loaded     = false;
    }

    /** @return array<string, mixed> What app.php says under `metrics` */
    public static function config(): array
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) && is_array($app->applicationInfo['metrics'] ?? null) ? $app->applicationInfo['metrics'] : [];
    }

    /**
     * Every metric, by name — or those this person may see.
     *
     * @return array<string, Metric>
     */
    public static function all(?object $user = null, bool $everybody = true): array
    {
        self::load();
        if ($everybody) {
            return self::$metrics;
        }

        return array_filter(self::$metrics, static fn (Metric $m): bool => $m->allows($user));
    }

    /** One metric by name. */
    public static function get(string $name): Metric
    {
        self::load();
        if (!isset(self::$metrics[$name])) {
            throw new \InvalidArgumentException('There is no metric "' . $name . '".');
        }

        return self::$metrics[$name];
    }

    /** One dimension by name; one that was never declared is its name alone. */
    public static function dimensionOf(string $name): Dimension
    {
        self::load();

        return self::$dimensions[$name] ?? Dimension::of($name);
    }

    /** @return array<string, Dimension> */
    public static function dimensions(): array
    {
        self::load();

        return self::$dimensions;
    }

    /** @return list<string> The grains metrics:compute keeps */
    public static function grains(): array
    {
        $grains = array_values(array_intersect(Window::GRAINS, (array) (self::config()['grains'] ?? Window::GRAINS)));

        return $grains !== [] ? $grains : Window::GRAINS;
    }

    // ------------------------------------------------------------------ computing

    /**
     * Compute what is due: each metric's open period at each grain, and every period that ended
     * since it last closed one (the one just ended, the first time). With `$from`/`$to`, every
     * period in between instead — a closed one only with `$recompute`, and a level's never once
     * it has ended, since nothing can say how things stood then.
     *
     * @param list<string>|null $only   Metric names; all by default
     * @param list<string>|null $grains Grains; {@see grains()} by default
     * @return array{computed: int, closed: int, skipped: int, failed: array<string, string>}
     */
    public static function compute(?array $only = null, ?array $grains = null, ?string $from = null, ?string $to = null, bool $recompute = false, ?int $now = null): array
    {
        $now    = $now ?? time();
        $report = ['computed' => 0, 'closed' => 0, 'skipped' => 0, 'failed' => []];
        $source = self::sourceConnection();
        foreach (self::all() as $metric) {
            if (!$metric->hasSource() || ($only !== null && !in_array($metric->name, $only, true))) {
                continue;
            }
            foreach ($grains ?? self::grains() as $grain) {
                try {
                    $windows = $from !== null || $to !== null
                        ? Window::range($grain, $from ?? (string) $to, $to ?? date('Y-m-d', $now))
                        : self::due($metric, $grain, $now);
                    foreach ($windows as $window) {
                        $done = self::computeOne($metric, $window, $source, $recompute, $now);
                        $report[$done] = ($report[$done] ?? 0) + 1;
                    }
                } catch (\Throwable $e) {
                    $report['failed'][$metric->name . '@' . $grain] = $e->getMessage();
                    \Pramnos\Logs\Logger::log('Metric ' . $metric->name . ' (' . $grain . ') not computed: ' . $e->getMessage(), 'metrics');
                }
            }
        }

        return $report;
    }

    /**
     * The periods due at a grain: those that ended since the last one closed — the one just
     * ended when none has — then the open one.
     *
     * @return list<Window>
     */
    private static function due(Metric $metric, string $grain, int $now): array
    {
        $open   = Window::of($grain, $now);
        $last   = self::db()->queryBuilder()->table(self::TABLE)->where('metric', $metric->name)->where('grain', $grain)
            ->where('final', 1)->max('period_start');
        $window = $last ? Window::of($grain, (string) $last)->next() : $open->previous();
        $due    = [];
        while ($window->start < $open->start && count($due) < self::CATCH_UP) {
            $due[]  = $window;
            $window = $window->next();
        }
        $due[] = $open;

        return $due;
    }

    /**
     * Compute one period: `computed` (open), `closed` (ended, now final) or `skipped`.
     */
    private static function computeOne(Metric $metric, Window $window, Database $source, bool $recompute, int $now): string
    {
        if (!$window->begun($now)) {
            return 'skipped';
        }
        $ended = $window->ended($now);
        $final = self::db()->queryBuilder()->table(self::TABLE)->where('metric', $metric->name)->where('grain', $window->grain)
            ->where('period_start', $window->key())->where('final', 1)->count() > 0;
        // A closed period is kept; a level that has ended was either kept when it closed or never can be.
        if (($final && !$recompute) || ($metric->kind === Metric::LEVEL && $ended && ($final || !self::closesNow($window, $now)))) {
            return 'skipped';
        }

        $rows = [];
        foreach ($metric->storedSets() as $set) {
            if ($set === [] && $metric->getAggregate() === Metric::SUM && $metric->getDimensions() !== []) {
                continue;   // a sum's total is its full breakdown added up, below
            }
            foreach ($metric->measure($window, $set, $source) as $row) {
                $rows[] = $row;
            }
        }
        if ($metric->getAggregate() === Metric::SUM && $metric->getDimensions() !== []) {
            $rows[] = ['dims' => [], 'value' => array_sum(array_column($rows, 'value'))];
        }

        $kind = $metric->kind === Metric::LEVEL ? 'snapshot' : 'computed';
        self::write($metric->name, $window, $rows, $kind, $ended, $now, $recompute);

        return $ended ? 'closed' : 'computed';
    }

    /**
     * Whether a level that has ended may still be closed now: only the period just ended — how
     * things stand now is how they stood at its end, near enough — never an older one.
     */
    private static function closesNow(Window $window, int $now): bool
    {
        return Window::of($window->grain, $now)->previous()->key() === $window->key();
    }

    /**
     * Replace a period's rows of a metric.
     *
     * @param list<array{dims: array<string, string>, value: float}> $rows
     */
    private static function write(string $metric, Window $window, array $rows, string $source, bool $final, int $now, bool $replaceFinal): void
    {
        $db = self::db();
        $db->startTransaction();
        try {
            $delete = $db->queryBuilder()->table(self::TABLE)->where('metric', $metric)->where('grain', $window->grain)->where('period_start', $window->key());
            if (!$replaceFinal) {
                $delete->where('final', 0);
            }
            $delete->delete();
            $insert = [];
            foreach ($rows as $row) {
                $insert[] = self::row($metric, $window, $row['dims'], $row['value'], $source, $final, $now);
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                $db->queryBuilder()->table(self::TABLE)->insertMany($chunk);
            }
            $db->commitTransaction();
        } catch (\Throwable $e) {
            $db->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Keep a value that is not computed again — history from before the metric existed, or a
     * level from an old report. Final, so computing never replaces it; a period's total is its
     * own row (`$dims` empty), imported beside any breakdown.
     *
     * @param array<string, string> $dims
     */
    public static function import(string $metric, string $grain, string $period, array $dims, float|int $value): void
    {
        self::get($metric);
        $window = Window::of($grain, $period);
        $row    = self::row($metric, $window, $dims, (float) $value, 'imported', true, time());
        self::db()->queryBuilder()->table(self::TABLE)->upsert($row, ['metric', 'grain', 'period_start', 'dims_hash'], ['value', 'source', 'final', 'computed_at']);
    }

    /** @param array<string, string> $dims @return array<string, mixed> */
    private static function row(string $metric, Window $window, array $dims, float $value, string $source, bool $final, int $now): array
    {
        $dims = array_map('strval', $dims);
        ksort($dims);
        $json = (string) json_encode((object) $dims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'metric'       => $metric,
            'grain'        => $window->grain,
            'period_start' => $window->key(),
            'dimset'       => implode(',', array_keys($dims)),
            'dims'         => $json,
            'dims_hash'    => md5($json),
            'value'        => round($value, 4),
            'source'       => $source,
            'final'        => $final ? 1 : 0,
            'computed_at'  => $now,
        ];
    }

    // ------------------------------------------------------------------ reading

    /**
     * A metric over the periods of a grain from `$from` to `$to`: the periods, and the values
     * of each — under `''` without a breakdown, under each value of the dimension with one.
     * A period nothing was kept for is null.
     *
     * @param array<string, string|list<string>> $filters Dimension => value, or a list of values (a sum only)
     * @return array{periods: list<Window>, values: array<string, list<float|null>>}
     */
    public static function series(string $metric, string $grain, string $from, string $to, array $filters = [], ?string $breakdown = null): array
    {
        $periods = Window::range($grain, $from, $to);

        return ['periods' => $periods, 'values' => self::valuesFor(self::get($metric), $periods, $filters, $breakdown, [])];
    }

    /**
     * One metric in one period, or null when nothing was kept for it.
     *
     * @param array<string, string|list<string>> $filters
     */
    public static function value(string $metric, string $grain, string $at, array $filters = []): ?float
    {
        $values = self::valuesFor(self::get($metric), [Window::of($grain, $at)], $filters, null, []);

        return $values[''][0] ?? null;
    }

    /**
     * One period against the one before or the same one a year earlier: both values, the
     * difference, and the change as a fraction (null when there is nothing to compare with).
     *
     * @param array<string, string|list<string>> $filters
     * @return array{period: string, value: ?float, against: string, other: ?float, difference: ?float, change: ?float}
     */
    public static function compare(string $metric, string $grain, string $at, array $filters = [], string $against = self::PREVIOUS): array
    {
        $window = Window::of($grain, $at);
        $other  = $against === self::YEAR_EARLIER ? $window->yearEarlier() : $window->previous();
        $values = self::valuesFor(self::get($metric), [$window, $other], $filters, null, []);
        [$a, $b] = [$values[''][0] ?? null, $values[''][1] ?? null];

        return [
            'period'     => $window->label(),
            'value'      => $a,
            'against'    => $other->label(),
            'other'      => $b,
            'difference' => $a !== null && $b !== null ? $a - $b : null,
            'change'     => $a !== null && $b !== null && $b != 0.0 ? ($a - $b) / abs($b) : null,
        ];
    }

    /**
     * The records behind a number, from the metric's `rows()`.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public static function records(string $metric, string $grain, string $at, array $filters = []): array
    {
        return self::get($metric)->records(Window::of($grain, $at), $filters, self::sourceConnection());
    }

    /**
     * @param list<Window>                        $periods
     * @param array<string, string|list<string>> $filters
     * @param list<string>                        $seen    Derived metrics on the way here, against a loop
     * @return array<string, list<float|null>>
     */
    private static function valuesFor(Metric $metric, array $periods, array $filters, ?string $breakdown, array $seen): array
    {
        if ($metric->kind === Metric::DERIVED) {
            return self::derive($metric, $periods, $filters, $breakdown, $seen);
        }
        $set  = self::setFor($metric, $filters, $breakdown);
        $keys = array_map(static fn (Window $w): string => $w->key(), $periods);
        if ($keys === []) {
            return [];
        }
        $result = self::db()->queryBuilder()->table(self::TABLE)->select(['period_start', 'dimset', 'dims', 'value'])
            ->where('metric', $metric->name)->where('grain', $periods[0]->grain)->whereIn('period_start', array_values(array_unique($keys)))->get();
        $known = [];
        $sums  = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $period         = substr((string) $row['period_start'], 0, 10);
            $known[$period] = true;
            if ((string) $row['dimset'] !== implode(',', $set)) {
                continue;
            }
            $dims = (array) json_decode((string) $row['dims'], true);
            foreach ($filters as $name => $wanted) {
                if (!in_array((string) ($dims[$name] ?? ''), array_map('strval', (array) $wanted), true)) {
                    continue 2;
                }
            }
            $key                  = $breakdown === null ? '' : (string) ($dims[$breakdown] ?? '');
            $sums[$key][$period]  = ($sums[$key][$period] ?? 0.0) + (float) $row['value'];
        }
        if ($sums === [] && $breakdown === null) {
            $sums[''] = [];
        }
        $out = [];
        foreach ($sums as $key => $byPeriod) {
            $out[(string) $key] = array_map(static fn (string $p): ?float => isset($known[$p]) ? (float) ($byPeriod[$p] ?? 0.0) : null, $keys);
        }
        ksort($out);

        return $out;
    }

    /**
     * The stored breakdown a read is answered from.
     *
     * @param array<string, string|list<string>> $filters
     * @return list<string>
     */
    private static function setFor(Metric $metric, array $filters, ?string $breakdown): array
    {
        $needed = array_keys($filters);
        if ($breakdown !== null) {
            $needed[] = $breakdown;
        }
        $needed = array_values(array_unique($needed));
        sort($needed);
        if ($needed === []) {
            return [];
        }
        $unknown = array_diff($needed, $metric->getDimensions());
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Metric ' . $metric->name . ' is not broken down by ' . implode(', ', $unknown) . '.');
        }
        if ($metric->getAggregate() === Metric::SUM) {
            return $metric->storedSets()[1];
        }
        foreach ($filters as $name => $wanted) {
            if (is_array($wanted) && count($wanted) !== 1) {
                throw new \InvalidArgumentException('Metric ' . $metric->name . ' counts each one once, so it cannot add up several values of ' . $name . '.');
            }
        }
        if (!in_array($needed, $metric->storedSets(), true)) {
            $kept = array_map(static fn (array $s): string => $s === [] ? '(total)' : implode('+', $s), $metric->storedSets());
            throw new \InvalidArgumentException('Metric ' . $metric->name . ' counts each one once and keeps only: ' . implode(', ', $kept) . '.');
        }

        return $needed;
    }

    /**
     * @param list<Window>                        $periods
     * @param array<string, string|list<string>> $filters
     * @param list<string>                        $seen
     * @return array<string, list<float|null>>
     */
    private static function derive(Metric $metric, array $periods, array $filters, ?string $breakdown, array $seen): array
    {
        if (in_array($metric->name, $seen, true)) {
            throw new \LogicException('Metric ' . $metric->name . ' is worked out from itself.');
        }
        $seen[]   = $metric->name;
        $operands = [];
        $keys     = [];
        foreach ($metric->operands() as $name) {
            $operands[$name] = self::valuesFor(self::get($name), $periods, $filters, $breakdown, $seen);
            $keys            = array_merge($keys, array_keys($operands[$name]));
        }
        $keys = array_values(array_unique(array_map('strval', $keys ?: [''])));
        sort($keys);
        $out = [];
        foreach ($keys as $key) {
            foreach (array_keys($periods) as $i) {
                $values = [];
                foreach ($operands as $name => $series) {
                    $values[$name] = $series[$key][$i] ?? (isset($series['']) || $series === [] ? null : 0.0);
                }
                $out[$key][$i] = $metric->evaluate($values);
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ plumbing

    /** Read app.php's catalogue once. */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $registered   = self::$metrics;
        foreach ((array) (self::config()['catalogue'] ?? []) as $class) {
            $catalogue = is_string($class) && class_exists($class) ? new $class() : $class;
            if (!$catalogue instanceof Catalogue) {
                \Pramnos\Logs\Logger::log('Metrics catalogue ' . (is_string($class) ? $class : gettype($class)) . ' is not a ' . Catalogue::class . '; it was left out.', 'metrics');
                continue;
            }
            foreach ($catalogue->metrics() as $item) {
                if ($item instanceof Metric) {
                    self::$metrics[$item->name] = $item;
                } elseif ($item instanceof Dimension) {
                    self::$dimensions[$item->name] ??= $item;
                }
            }
        }
        // Registered in code wins over app.php's, as with dashboard widgets.
        self::$metrics = array_replace(self::$metrics, $registered);
    }

    /** Where the values are kept. */
    private static function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * Where sources read: the replica with `'replica' => true` — the "read" database settings —
     * or the database itself. A replica that cannot be reached is said in the log, and the
     * database is read instead: a late number is better than none, a wrong one is not at stake.
     */
    private static function sourceConnection(): Database
    {
        if (empty(self::config()['replica'])) {
            return self::db();
        }
        try {
            return self::db()->replicaConnection();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Metrics read the database itself: the replica is not available (' . $e->getMessage() . ').', 'metrics');

            return self::db();
        }
    }
}
