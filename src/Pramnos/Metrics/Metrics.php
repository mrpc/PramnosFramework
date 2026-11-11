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
 *     'replica'   => true,                          // sources read the replica: the database settings' `replica` (or `read`) block
 *     // 'replica' => ['hostname' => getenv('PG_REPLICA_HOST'), 'port' => 5432, 'timeout' => 3],   // or this one
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
    /** The view over every grain's values, for SQL and exports; each grain is kept in its own table ({@see Store}). */
    public const TABLE = Store::VIEW;

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
     * Where a record's value opens, by its column — `'metrics' => ['links' => ['userid' =>
     * fn ($value, array $row): ?string => url('admin/users/' . $value)]]`: the address, or null for
     * none. A column without one, or an empty value, has none.
     *
     * @param array<string, mixed> $row
     */
    public static function link(string $column, mixed $value, array $row): ?string
    {
        $links = self::config()['links'] ?? [];
        $make  = is_array($links) ? ($links[$column] ?? null) : null;
        if (!is_callable($make) || $value === null || $value === '') {
            return null;
        }
        $url = $make($value, $row);

        return is_string($url) && $url !== '' ? $url : null;
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

        // A scoped viewer sees a metric only where their scope can narrow it, or it is nobody's.
        $scope = array_keys(self::scopeOf($user) ?? []);

        return array_filter(self::$metrics, static fn (Metric $m): bool => $m->allows($user)
            && ($m->isUnscoped() || array_diff($scope, $m->getDimensions()) === []));
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
        $grains = array_values(array_intersect(Window::GRAINS, (array) (self::config()['grains'] ?? Window::DEFAULT_GRAINS)));

        return $grains !== [] ? $grains : Window::DEFAULT_GRAINS;
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
     * Nothing is done while the store is being changed (a conversion or an import through {@see Store}, a migration) or another
     * computation runs: `busy` says so, and the next run computes what is due.
     *
     * @return array{computed: int, closed: int, skipped: int, failed: array<string, string>, busy: bool}
     */
    public static function compute(?array $only = null, ?array $grains = null, ?string $from = null, ?string $to = null, bool $recompute = false, ?int $now = null): array
    {
        $now    = $now ?? time();
        $report = ['computed' => 0, 'closed' => 0, 'skipped' => 0, 'failed' => [], 'busy' => false];
        if (!Store::lock(self::db(), 0)) {
            $report['busy'] = true;

            return $report;
        }
        try {
            return self::computeAll($report, $only, $grains, $from, $to, $recompute, $now);
        } finally {
            Store::unlock(self::db());
        }
    }

    /**
     * {@see compute()}, the store's lock held.
     *
     * @param array{computed: int, closed: int, skipped: int, failed: array<string, string>, busy: bool} $report
     * @param list<string>|null $only
     * @param list<string>|null $grains
     * @return array{computed: int, closed: int, skipped: int, failed: array<string, string>, busy: bool}
     */
    private static function computeAll(array $report, ?array $only, ?array $grains, ?string $from, ?string $to, bool $recompute, int $now): array
    {
        $source = self::sourceConnection();
        foreach (self::all() as $metric) {
            // A live metric is read from its source when asked: nothing to keep — at most its hourly
            // aggregate to make, once, where TimescaleDB can keep one.
            if ($metric->isLive()) {
                try {
                    Store::liveAggregate(self::db(), $metric);
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log('Live metric ' . $metric->name . ': no aggregate made: ' . $e->getMessage(), 'metrics');
                }
                continue;
            }
            if (!$metric->hasSource() || ($only !== null && !in_array($metric->name, $only, true))) {
                continue;
            }
            // The grains asked, of those the metric is kept at: an hour only where it asks for hours.
            $kept = $metric->getGrains() ?? self::grains();
            foreach (array_values(array_intersect($grains ?? $kept, $kept)) as $grain) {
                try {
                    $last    = $to ?? date('Y-m-d', $now);
                    // Days asked of hours: every hour of the last day too.
                    $last    = $grain === Window::HOUR && strlen($last) === 10 ? $last . ' 23:00' : $last;
                    $windows = $from !== null || $to !== null
                        ? Window::range($grain, $from ?? (string) $to, $last)
                        : self::due($metric, $grain, $now);
                    // Many periods of a declared source: read in one query where it can be, not one each.
                    if (count($windows) > 1 && self::zones() === null) {
                        $metric->prefetch($windows, $source);
                    }
                    try {
                        foreach ($windows as $window) {
                            $done = self::computeOne($metric, $window, $source, $recompute, $now);
                            $report[$done] = ($report[$done] ?? 0) + 1;
                        }
                    } finally {
                        $metric->forgetAhead();
                    }
                } catch (\Throwable $e) {
                    $report['failed'][$metric->name . '@' . $grain] = $e->getMessage();
                    \Pramnos\Logs\Logger::log('Metric ' . $metric->name . ' (' . $grain . ') not computed: ' . $e->getMessage(), 'metrics');
                }
            }
        }
        try {
            Store::prune(self::db(), $now);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Old metric values not pruned: ' . $e->getMessage(), 'metrics');
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
        $last   = self::db()->queryBuilder()->table(Store::table($grain))->where('metric', $metric->name)
            ->where('version', $metric->getVersion())->where('final', 1)->max('period_start');
        $window = $last ? Window::of($grain, Store::key((string) $last, $grain))->next() : $open->previous();
        $due    = [];
        while ($window->start < $open->start && count($due) < self::CATCH_UP) {
            $due[]  = $window;
            $window = $window->next();
        }
        // The open period, unless computed lately enough for how often the metric asks.
        $every = $metric->refreshOf($grain);
        if ($every > 0) {
            $at = self::db()->queryBuilder()->table(Store::table($grain))->where('metric', $metric->name)->where('version', $metric->getVersion())
                ->where('period_start', Store::instant(self::db(), $open))->max('computed_at');
            if ($at !== null && (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->getTimestamp() > $now - $every) {
                return $due;
            }
        }
        $due[] = $open;

        return $due;
    }

    /**
     * Compute one period: `computed` (open), `closed` (ended, now final) or `skipped`.
     */
    private static function computeOne(Metric $metric, Window $window, Database $source, bool $recompute, int $now): string
    {
        $zones = $metric->getTenant() !== null ? self::zones() : null;
        if ($zones === null) {
            return self::computeIn($metric, $window, $source, $recompute, $now, null);
        }
        // Each organisation's own day: one source call per zone, kept for that zone's organisations.
        $done = 'skipped';
        foreach ($zones as $zone => $tenants) {
            $local  = Window::of($window->grain, $window->key(), new \DateTimeZone($zone));
            $result = self::computeIn($metric, $local, $source, $recompute, $now, $tenants);
            $done   = $result !== 'skipped' ? $result : $done;
        }

        return $done;
    }

    /**
     * The organisations of each zone, `[zone => [tenant, …]]`, where `metrics.timezone` is a
     * function of the organisation and `metrics.tenants` lists them; null otherwise.
     *
     * @return array<string, list<int>>|null
     */
    public static function zones(): ?array
    {
        $config = self::config();
        if (!is_callable($config['timezone'] ?? null) || !is_callable($config['tenants'] ?? null)) {
            return null;
        }
        $out = [];
        foreach (($config['tenants'])() as $tenant) {
            $out[Window::zoneOf((int) $tenant)->getName()][] = (int) $tenant;
        }

        return $out;
    }

    /**
     * Compute one period, `$tenants` the organisations it is computed for (null: all of them).
     */
    private static function computeIn(Metric $metric, Window $window, Database $source, bool $recompute, int $now, ?array $tenants): string
    {
        // Not begun, or a period the source cannot answer: nothing written, so it reads as no figure.
        if (!$window->begun($now) || $metric->gapOf($window) !== null) {
            return 'skipped';
        }
        // A metric whose data arrive late keeps a period open, computed again at each run, until its
        // lateness has passed too. A level is how things stood when it closed: at its end, always.
        $ended = $window->ended($now - ($metric->kind === Metric::LEVEL ? 0 : $metric->getLateness()));
        $closed = self::db()->queryBuilder()->table(Store::table($window->grain))->where('metric', $metric->name)
            ->where('period_start', Store::instant(self::db(), $window))->where('version', $metric->getVersion())->where('final', 1);
        if ($tenants !== null) {
            $closed->whereIn('tenant', $tenants);
        }
        $final = $closed->count() > 0;
        // A closed period is kept; a level that has ended was either kept when it closed or never can be.
        if (($final && !$recompute) || (!$metric->hasThePast() && $ended && ($final || !self::closesNow($window, $now)))) {
            return 'skipped';
        }

        $rows = [];
        $sets = array_values(array_filter($metric->storedSets(), static fn (array $set): bool =>
            // a sum's total is its full breakdown added up, below
            !($set === [] && $metric->getAggregate() === Metric::SUM && $metric->getDimensions() !== [])));
        // A distinct count from the days' HyperLogLog sketches where they are (Store::sketched());
        // else every breakdown of the period in one read, where it can be (Metric::prefetchSets()).
        if ($window->grain !== Window::HOUR && $tenants === null && Store::sketched(self::db(), $metric)) {
            foreach ($sets as $set) {
                $metric->readAhead($window, $set, Store::sketchRows($source, $metric, $window, $set));
            }
        } else {
            $metric->prefetchSets($window, $sets, $source);
        }
        foreach ($sets as $set) {
            foreach ($metric->measure($window, $set, $source) as $row) {
                if ($tenants === null || in_array((int) ($row['tenant'] ?? 0), $tenants, true)) {
                    $rows[] = $row;
                }
            }
        }
        if ($metric->getAggregate() === Metric::SUM && $metric->getDimensions() !== []) {
            // Each organisation's total, its own breakdown added up.
            $totals = [];
            foreach ($rows as $row) {
                $totals[$row['tenant'] ?? 0] = ($totals[$row['tenant'] ?? 0] ?? 0.0) + $row['value'];
            }
            // An organisation with nothing in the period has a total all the same: nought.
            $none = $tenants === null ? [0 => 0.0] : array_fill_keys($tenants, 0.0);
            foreach ($totals + ($totals === [] || $tenants !== null ? $none : []) as $tenant => $total) {
                $rows[] = ['dims' => [], 'value' => $total, 'tenant' => $tenant];
            }
        }

        if ($tenants !== null) {
            // Each organisation of the zone has a total for the period, nought where it had nothing,
            // so its period reads as computed.
            $have = [];
            foreach ($rows as $row) {
                if ($row['dims'] === []) {
                    $have[(int) ($row['tenant'] ?? 0)] = true;
                }
            }
            foreach (array_diff($tenants, array_keys($have)) as $tenant) {
                $rows[] = ['dims' => [], 'value' => 0.0, 'tenant' => $tenant];
            }
        }
        $kind = $metric->kind === Metric::LEVEL ? Store::SNAPSHOT : Store::COMPUTED;
        self::write($metric->name, $window, $rows, $kind, $ended, $now, $recompute, $tenants, $metric->getVersion());

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
    private static function write(string $metric, Window $window, array $rows, string $source, bool $final, int $now, bool $replaceFinal, ?array $tenants = null, int $version = Store::VERSION): void
    {
        $db    = self::db();
        $table = Store::table($window->grain);
        $db->startTransaction();
        try {
            if ($replaceFinal && $db->type === 'postgresql' && $db->capabilities()->hasTimescaleDB()) {
                // A closed month of hours rewritten in one statement decompresses more than the 100 000
                // tuples a DML may by default (302 400 measured): lifted for this transaction alone.
                $db->query('SET LOCAL timescaledb.max_tuples_decompressed_per_dml_transaction = 0');
            }
            $delete = $db->queryBuilder()->table($table)->where('metric', $metric)->where('version', $version)->where('period_start', Store::instant($db, $window));
            if (!$replaceFinal) {
                $delete->where('final', 0);
            }
            if ($tenants !== null) {
                // Another zone's organisations keep theirs: their period starts elsewhere.
                $delete->whereIn('tenant', $tenants);
            }
            $delete->delete();
            $insert = [];
            $money  = self::get($metric);
            foreach ($rows as $row) {
                // The currency it was counted in, kept with it: an organisation that changes
                // currency later does not relabel what it earned before.
                $extra    = array_filter(['count' => isset($row['count']) ? (int) $row['count'] : null, 'currency' => $money->getCurrency((int) ($row['tenant'] ?? 0))], static fn ($v): bool => $v !== null);
                $insert[] = Store::row($db, $metric, $window, $row['dims'], (float) $row['value'], $source, $final, $now, $extra === [] ? null : $extra, (int) ($row['tenant'] ?? 0), $version);
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                $db->queryBuilder()->table($table)->insertMany($chunk);
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
        $currency = self::get($metric)->getCurrency();
        $row      = Store::row(self::db(), $metric, $window, $dims, (float) $value, Store::IMPORTED, true, time(), $currency !== null ? ['currency' => $currency] : null);
        self::db()->queryBuilder()->table(Store::table($grain))->upsert($row, Store::KEY, ['value', 'extra', 'source', 'final', 'computed_at']);
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
        return self::query($metric)->grain($grain)->between($from, $to)->where($filters)->by($breakdown)->series();
    }

    /**
     * One metric in one period, or null when nothing was kept for it.
     *
     * @param array<string, string|list<string>> $filters
     */
    public static function value(string $metric, string $grain, string $at, array $filters = []): ?float
    {
        return self::query($metric)->grain($grain)->at($at)->where($filters)->value();
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
        return self::query($metric)->grain($grain)->at($at)->where($filters)->compare($against)->compared();
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

    /** A metric read through the one query every reader uses ({@see Query}). */
    public static function query(string $metric): Query
    {
        return new Query($metric);
    }

    /**
     * The values of a metric over periods, for {@see Query}: under `''` without a breakdown, under
     * each of the breakdown's values with one; null where a period was never computed.
     *
     * @param list<Window>                        $periods
     * @param array<string, string|list<string>> $filters
     * @param list<int>|null                      $tenants The organisations read, null for every one
     * @return array<string, list<float|null>>
     */
    public static function read(string $metric, array $periods, array $filters = [], ?string $breakdown = null, ?array $tenants = null, ?int $version = null): array
    {
        return self::readIn($metric, $periods, $filters, $breakdown, $tenants, $version)['values'];
    }

    /**
     * {@see read()}, and the currency its amounts are in — null for a metric that is not money.
     * Amounts in two currencies are never added: such a read throws a `LogicException`, and the
     * reader reads one organisation, or one currency, at a time. `$unreadAsNull`: a breakdown's value
     * not read in a computed period is null, not nought — for carrying a reading over it.
     *
     * @param list<Window>                        $periods
     * @param array<string, string|list<string>> $filters
     * @param list<int>|null                      $tenants
     * @return array{values: array<string, list<float|null>>, currency: ?string}
     */
    public static function readIn(string $metric, array $periods, array $filters = [], ?string $breakdown = null, ?array $tenants = null, ?int $version = null, bool $unreadAsNull = false): array
    {
        $model    = self::get($metric);
        $currency = $model->getCurrency($tenants !== null && count($tenants) === 1 ? (int) $tenants[0] : 0);
        $values   = self::valuesFor($model, $periods, $filters, $breakdown, [], $tenants, $version, $currency, $unreadAsNull);

        return ['values' => $values, 'currency' => $currency];
    }

    /**
     * Which organisations a viewer may read, from `metrics.tenant` in app.php — a list of ids,
     * or null for every one (an application without organisations, or a viewer who reads them all).
     *
     * @return list<int>|null
     */
    public static function tenantsOf(?object $user): ?array
    {
        $scope = self::config()['tenant'] ?? null;
        if (!is_callable($scope)) {
            return null;
        }
        $ids = $scope($user);

        return $ids === null ? null : array_values(array_map('intval', (array) $ids));
    }

    /**
     * The values of each dimension a viewer may read, from `metrics.scope` in app.php — `fn (?object
     * $user): ?array`, dimension => values — or null for all of them. A regional manager's is
     * `['region' => ['north']]`: every figure they read is north's, its totals north's total.
     *
     * @return array<string, list<string>>|null
     */
    public static function scopeOf(?object $user): ?array
    {
        $scope = self::config()['scope'] ?? null;
        $given = is_callable($scope) ? $scope($user) : null;
        if (!is_array($given)) {
            return null;
        }
        $out = [];
        foreach ($given as $dimension => $values) {
            $out[(string) $dimension] = array_values(array_map('strval', (array) $values));
        }

        return $out;
    }

    /**
     * The filters a viewer reads a metric with: theirs, narrowed to their scope ({@see
     * scopeOf()}), checked against the dimensions they may use ({@see Dimension::ability()}).
     * A dimension they did not filter is filtered to the values of their scope, so a total is the
     * total of what they may read. `$single` is for the records behind a number, which an
     * application's `rows()` reads one value at a time.
     *
     * @param array<string, string|list<string>> $filters
     * @return array<string, string|list<string>>
     * @throws \DomainException For a value, or a dimension, the viewer may not read
     */
    public static function scoped(string $metric, array $filters, ?object $user, ?string $breakdown = null, bool $single = false): array
    {
        foreach (array_filter([...array_keys($filters), $breakdown]) as $dimension) {
            if (!self::dimensionOf((string) $dimension)->allows($user)) {
                throw new \DomainException(sprintf(t('You may not break down or filter by %s.'), t(self::dimensionOf((string) $dimension)->label)));
            }
        }
        $scope = self::get($metric)->isUnscoped() ? null : self::scopeOf($user);
        foreach ($scope ?? [] as $dimension => $allowed) {
            $asked = isset($filters[$dimension]) ? array_map('strval', (array) $filters[$dimension]) : $allowed;
            if (array_diff($asked, $allowed) !== [] || $asked === []) {
                throw new \DomainException(t('Those figures are not yours to read.'));
            }
            if ($single && count($asked) !== 1) {
                throw new \DomainException(sprintf(t('Choose one %s to list its records.'), t(self::dimensionOf($dimension)->label)));
            }
            $filters[$dimension] = count($asked) === 1 && !is_array($filters[$dimension] ?? null) ? $asked[0] : $asked;
        }

        return $filters;
    }

    /**
     * Whether a figure read with these filters is wholly within what the viewer may read — a goal
     * or an alert set for every region is not a north manager's, though its metric is.
     *
     * @param array<string, string|list<string>> $filters
     */
    public static function withinScope(string $metric, array $filters, ?object $user): bool
    {
        try {
            self::scoped($metric, $filters, $user);
        } catch (\DomainException) {
            return false;
        }
        $scope = self::get($metric)->isUnscoped() ? [] : (self::scopeOf($user) ?? []);

        return array_diff_key($scope, $filters) === [];
    }

    /**
     * @param list<Window>                        $periods
     * @param array<string, string|list<string>> $filters
     * @param list<string>                        $seen    Derived metrics on the way here, against a loop
     * @return array<string, list<float|null>>
     */
    private static function valuesFor(Metric $metric, array $periods, array $filters, ?string $breakdown, array $seen, ?array $tenants = null, ?int $version = null, ?string &$currency = null, bool $unreadAsNull = false): array
    {
        if ($metric->kind === Metric::DERIVED) {
            return self::derive($metric, $periods, $filters, $breakdown, $seen, $tenants);
        }
        if ($metric->isLive()) {
            return self::live($metric, $periods, $filters, $breakdown, $tenants);
        }
        $set  = self::setFor($metric, $filters, $breakdown);
        $keys = array_map(static fn (Window $w): string => $w->key(), $periods);
        if ($keys === []) {
            return [];
        }
        $db      = self::db();
        // Each organisation's periods start at its own midnight: the starts of every zone read.
        $zones   = $metric->getTenant() !== null ? self::zones() : null;
        $starts  = [Window::zone()->getName() => true];
        if ($zones !== null) {
            $starts = [];
            foreach ($zones as $zone => $members) {
                if ($tenants === null || array_intersect($members, $tenants) !== []) {
                    $starts[$zone] = true;
                }
            }
        }
        $instants = [];
        foreach (array_keys($starts) as $zone) {
            foreach ($periods as $w) {
                $instants[] = Store::instant($db, $zones === null ? $w : Window::of($w->grain, $w->key(), new \DateTimeZone($zone)));
            }
        }
        $instants = array_values(array_unique($instants));
        $query = $db->queryBuilder()->table(Store::table($periods[0]->grain))->select(['period_start', 'tenant', 'dimset', 'dims', 'value', 'extra'])
            ->where('metric', $metric->name)->where('version', $version ?? $metric->getVersion())->whereIn('dimset', array_values(array_unique([implode(',', $set), ''])))->whereIn('period_start', $instants);
        if ($tenants !== null) {
            // The reader's organisations: none at all reads nothing.
            if ($tenants === []) {
                return $breakdown === null ? ['' => array_fill(0, count($keys), null)] : [];
            }
            $query->whereIn('tenant', array_values(array_unique(array_map('intval', $tenants))));
        }
        $result    = $query->get();
        $from      = [];
        $weights   = [];
        $aggregate = $metric->getAggregate();
        $known = [];
        $sums  = [];
        $money = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $period         = Store::key((string) $row['period_start'], $periods[0]->grain, $zones === null ? null : Window::zoneOf((int) $row['tenant']));
            // The total marks a period as computed; only the breakdown asked for is added up.
            $known[$period] = true;
            $from[(int) $row['tenant']] = true;
            if ((string) $row['dimset'] !== implode(',', $set)) {
                continue;
            }
            $dims = (array) json_decode((string) $row['dims'], true);
            foreach ($filters as $name => $wanted) {
                if (!in_array((string) ($dims[$name] ?? ''), array_map('strval', (array) $wanted), true)) {
                    continue 2;
                }
            }
            $key   = $breakdown === null ? '' : (string) ($dims[$breakdown] ?? '');
            $value = (float) $row['value'];
            if ($metric->getFormat() === 'currency') {
                // Each row in the currency it was counted in: a row from before currencies were kept, in today's.
                $extra = is_string($row['extra'] ?? null) ? json_decode($row['extra'], true) : null;
                $money[(string) ($extra['currency'] ?? $metric->getCurrency((int) $row['tenant']))] = true;
            }
            if (!isset($sums[$key][$period])) {
                $sums[$key][$period]    = $value;
                $weights[$key][$period] = $weight = self::weight($row);
                continue;
            }
            // Another organisation's value for the same period: added, or combined as its kind says.
            $sums[$key][$period] = match ($aggregate) {
                Metric::MIN => min($sums[$key][$period], $value),
                Metric::MAX => max($sums[$key][$period], $value),
                Metric::AVG => ($sums[$key][$period] * $weights[$key][$period] + $value * ($weight = self::weight($row))) / max(1, $weights[$key][$period] + $weight),
                default     => $sums[$key][$period] + $value,
            };
            if ($aggregate === Metric::AVG) {
                $weights[$key][$period] += $weight;
            }
        }
        if (count($money) > 1) {
            throw new \LogicException(sprintf(t('%s is in %s: read one currency at a time.'), $metric->label(), implode(', ', array_keys($money))));
        }
        if ($money !== []) {
            $currency = (string) array_key_first($money);
        }
        if (count($from) > 1 && $metric->getAggregate() === Metric::DISTINCT) {
            // Somebody counted in two organisations is one person: their counts do not add up.
            throw new \LogicException(sprintf(t('%s is counted once per organisation: read one organisation at a time.'), $metric->label()));
        }
        if ($sums === [] && $breakdown === null) {
            $sums[''] = [];
        }
        $out = [];
        foreach ($sums as $key => $byPeriod) {
            $out[(string) $key] = array_map(static fn (string $p): ?float => isset($known[$p]) && (!$unreadAsNull || isset($byPeriod[$p])) ? (float) ($byPeriod[$p] ?? 0.0) : null, $keys);
        }
        ksort($out);

        return $out;
    }

    /**
     * A live metric's values: its source asked for each period, grouped by the dimensions the read
     * needs, the rows that match the filters added up per breakdown value and organisation read.
     *
     * @param list<Window>                        $periods
     * @param array<string, string|list<string>> $filters
     * @param list<int>|null                      $tenants
     * @return array<string, list<float|null>>
     */
    private static function live(Metric $metric, array $periods, array $filters, ?string $breakdown, ?array $tenants): array
    {
        $by = array_values(array_unique(array_merge(array_keys($filters), $breakdown !== null ? [$breakdown] : [])));
        $unknown = array_diff($by, $metric->getDimensions());
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Metric ' . $metric->name . ' is not broken down by ' . implode(', ', $unknown) . '.');
        }
        sort($by);
        $source = self::sourceConnection();
        $view   = self::liveViewOf($metric);
        if ($view === null && count($periods) > 1) {
            $metric->prefetch($periods, $source);
        }
        $out = [];
        try {
            foreach ($periods as $i => $window) {
                if ($metric->gapOf($window) !== null || !$window->begun()) {
                    continue;
                }
                foreach ($view !== null ? self::fromView($metric, $view, $window, $by) : $metric->measure($window, $by, $source) as $row) {
                    if ($tenants !== null && !in_array((int) ($row['tenant'] ?? 0), $tenants, true)) {
                        continue;
                    }
                    foreach ($filters as $name => $wanted) {
                        if (!in_array((string) ($row['dims'][$name] ?? ''), array_map('strval', (array) $wanted), true)) {
                            continue 2;
                        }
                    }
                    $key = $breakdown === null ? '' : (string) ($row['dims'][$breakdown] ?? '');
                    $out[$key][$i] = ($out[$key][$i] ?? 0.0) + (float) $row['value'];
                }
                // Asked and answered: a period with nothing is nought, not unknown.
                foreach ($out as $key => $values) {
                    $out[$key][$i] ??= 0.0;
                }
                $out[''] ??= [];
                if ($breakdown === null) {
                    $out[''][$i] ??= 0.0;
                }
            }
        } finally {
            $metric->forgetAhead();
        }
        if ($breakdown !== null) {
            unset($out['']);
        }
        $series = [];
        foreach ($out as $key => $values) {
            $series[(string) $key] = array_map(static fn (int $i): ?float => $values[$i] ?? null, array_keys($periods));
        }
        if ($series === [] && $breakdown === null) {
            $series[''] = array_fill(0, count($periods), null);
        }
        ksort($series);

        return $series;
    }

    /** The live metric's hourly aggregate, where one has been made; null otherwise. */
    private static function liveViewOf(Metric $metric): ?string
    {
        $db = self::db();
        if ($db->type !== 'postgresql' || $metric->declared() === null) {
            return null;
        }
        $view = Store::liveView($metric);

        return $db->schema()->hasView($view) ? $view : null;
    }

    /**
     * A live metric's rows in a window, read from its hourly aggregate: its hours in the window,
     * added up — or the least, or the most — per breakdown and organisation.
     *
     * @param list<string> $by
     * @return list<array{dims: array<string, string>, value: float, tenant: int}>
     */
    private static function fromView(Metric $metric, string $view, Window $window, array $by): array
    {
        $db     = self::db();
        $q      = $metric->declared();
        $tenant = $metric->getTenant();
        $query  = $db->queryBuilder()->table($view);
        $group  = [...$by, ...($tenant !== null ? [$tenant] : [])];
        $fold   = match ($q['fn']) {
            'MIN'   => 'MIN',
            'MAX'   => 'MAX',
            default => 'SUM',
        };
        $query->select([...$group, $query->raw($fold . '(value) AS value')]);
        [$from, $to] = $q['unix'] ? [$window->startsAt(), $window->endsAt()] : [\Pramnos\Database\Instant::utc($window->startsAt(), $db), \Pramnos\Database\Instant::utc($window->endsAt(), $db)];
        $query->where('bucket', '>=', $from)->where('bucket', '<', $to);
        if ($group !== []) {
            $query->groupBy($group);
        }
        $rows = [];
        foreach ($query->getAllOrFail() as $row) {
            $dims = [];
            foreach ($by as $name) {
                $dims[$name] = (string) ($row[$name] ?? '');
            }
            $rows[] = ['dims' => $dims, 'value' => (float) ($row['value'] ?? 0), 'tenant' => $tenant !== null ? (int) ($row[$tenant] ?? 0) : 0];
        }

        return $rows;
    }

    /** How many a kept mean is of: its `extra.count`, or one. @param array<string, mixed> $row */
    private static function weight(array $row): int
    {
        $extra = is_string($row['extra'] ?? null) ? json_decode($row['extra'], true) : null;

        return max(1, (int) (is_array($extra) ? ($extra['count'] ?? 1) : 1));
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
    private static function derive(Metric $metric, array $periods, array $filters, ?string $breakdown, array $seen, ?array $tenants = null): array
    {
        if (in_array($metric->name, $seen, true)) {
            throw new \LogicException('Metric ' . $metric->name . ' is worked out from itself.');
        }
        $seen[]   = $metric->name;
        $operands = [];
        $keys     = [];
        foreach ($metric->operands() as $name) {
            $operands[$name] = self::valuesFor(self::get($name), $periods, $filters, $breakdown, $seen, $tenants);
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
        // The audience's own — segments and person events — where its tables are, unless app.php says not.
        if ((self::config()['audience'] ?? true) !== false) {
            try {
                foreach ((new \Pramnos\Audience\AudienceMetrics())->metrics() as $item) {
                    if ($item instanceof Metric) {
                        self::$metrics[$item->name] ??= $item;
                    } elseif ($item instanceof Dimension) {
                        self::$dimensions[$item->name] ??= $item;
                    }
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('The audience\'s metrics were left out: ' . $e->getMessage(), 'metrics');
            }
        }
        // The site's own traffic, where it is counted, unless app.php says not.
        if ((self::config()['visits'] ?? true) !== false) {
            try {
                foreach ((new \Pramnos\Visits\VisitsMetrics())->metrics() as $item) {
                    if ($item instanceof Metric) {
                        self::$metrics[$item->name] ??= $item;
                    } elseif ($item instanceof Dimension) {
                        self::$dimensions[$item->name] ??= $item;
                    }
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('The site traffic\'s metrics were left out: ' . $e->getMessage(), 'metrics');
            }
        }
        // Registered in code wins over app.php's, as with dashboard widgets.
        self::$metrics = array_replace(self::$metrics, $registered);
    }

    /** Where the values are kept. */
    /** The connection the metrics' values, goals and alerts are kept in, the store made ready. */
    public static function connection(): Database
    {
        return self::db();
    }

    private static function db(): Database
    {
        $db = Database::getInstance();
        // The store made, or converted, on its first use in a process — a deploy whose migration
        // has not run yet computes and reads all the same, instead of failing on a missing table.
        Store::ready($db);

        return $db;
    }

    /**
     * Where sources read: the replica with `'replica' => true` — the database settings' `replica`
     * block, which routes nothing else, or their `read` — or a replica of the metrics' own,
     * `'replica' => ['hostname' => …, 'port' => …, 'timeout' => …]`; else the database itself. A
     * replica that does not answer within its timeout is said in the log, and the database is read
     * instead: a late number is better than none, a wrong one is not at stake.
     */
    private static function sourceConnection(): Database
    {
        $replica = self::config()['replica'] ?? null;
        if (empty($replica)) {
            return self::db();
        }
        // `true`: the database settings' replica; a block: this one — `hostname`, `port`, `timeout`.
        $at = is_array($replica) ? $replica : [];
        $db = self::db();
        $waited = $db->connectTimeout;
        try {
            if (isset($at['timeout'])) {
                $db->connectTimeout = (int) $at['timeout'];
            }

            return $db->replicaConnection(isset($at['hostname']) && $at['hostname'] !== '' ? (string) $at['hostname'] : null,
                isset($at['port']) && $at['port'] !== '' ? (int) $at['port'] : null);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Metrics read the database itself: the replica is not available (' . $e->getMessage() . ').', 'metrics');

            return self::db();
        } finally {
            $db->connectTimeout = $waited;
        }
    }
}
