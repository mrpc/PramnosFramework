<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * What a business reached, kept for good (Metrics-BI-Design §16.7):
 *
 *   - `milestone`: a level declared `->milestones()` passing a rung of the ladder (100, 250, 500,
 *     1k … 10M) above its first reading — dated the day it did, never one not seen;
 *   - `best`: a flow declared `->bestWeeks()` having its best week so far — weeks from Monday,
 *     the current one excluded; a new record is a new row;
 *   - `goal`: a one-off `reach` goal met, dated the day it was; its goal kept by id when deleted.
 *
 * Each for the total and for each value of the dimension the metric adds up across
 * (`acrossBy`, its `base`), per organisation; never written twice (a unique key). `metrics:achievements`
 * scans every night.
 *
 * ```php
 * Achievements::scan();                 // what is new
 * Achievements::list(7, 100);           // an organisation's, newest first, each with a sentence
 * ```
 */
final class Achievements
{
    public const TABLE = 'stats.achievements';

    /** The rungs a milestone is at. */
    public const LADDER = [100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000, 2500000, 5000000, 10000000];

    /** How far back a scan reads. */
    public const DAYS = 730;

    /**
     * Scan every metric that earns achievements, and every goal met, and keep what is new.
     *
     * @return int How many were new
     */
    public static function scan(?int $now = null): int
    {
        $now  = $now ?? time();
        $new  = 0;
        $from = date('Y-m-d', $now - self::DAYS * 86400);
        $to   = date('Y-m-d', $now);
        foreach (Metrics::all() as $metric) {
            $earns = $metric->earns();
            if (!$earns['milestones'] && !$earns['best']) {
                continue;
            }
            foreach (self::tenants($metric) as $tenant) {
                foreach (self::series($metric, $tenant, $from, $to) as $dims => $days) {
                    if ($earns['milestones'] && $metric->kind === Metric::LEVEL && $metric->getDirection() !== Metric::LOWER_IS_BETTER) {
                        $new += self::milestones($metric, $tenant, (string) $dims, $days);
                    }
                    if ($earns['best'] && $metric->kind !== Metric::LEVEL) {
                        $new += self::best($metric, $tenant, (string) $dims, $days, $now);
                    }
                }
            }
        }

        return $new + self::goals($now);
    }

    /**
     * An organisation's achievements, newest first, each with a sentence.
     *
     * @return list<array{id: int, kind: string, metric: string, dims: array<string, string>, value: float, achieved_at: string, goal: ?int, says: string}>
     */
    public static function list(?int $tenant = null, int $limit = 100): array
    {
        try {
            $query = Metrics::connection()->queryBuilder()->table(self::TABLE)->orderBy('achievedat', 'DESC')->limit(max(1, min(500, $limit)));
            if ($tenant !== null) {
                $query->where('tenant', $tenant);
            }
            $rows = $query->getAllOrFail();
        } catch (\Throwable) {
            return [];   // no table yet
        }

        return array_map(static function (array $row): array {
            $dims  = (array) json_decode((string) $row['dims'], true);
            $label = isset(Metrics::all()[$row['metric']]) ? Metrics::get($row['metric'])->label() : (string) $row['metric'];
            $on    = implode(', ', array_map(static fn ($d, $v) => Metrics::dimensionOf((string) $d)->labelOf((string) $v), array_keys($dims), $dims));
            $what  = $on === '' ? $label : sprintf(t('%s on %s'), $label, $on);
            $value = \Pramnos\General\NumberFormat::trimmed((float) $row['value'], 2);
            $says  = match ((string) $row['kind']) {
                'milestone' => sprintf(t('Passed %s: %s.'), $value, $what),
                'best'      => sprintf(t('Best week for %s: %s — week of %s.'), $what, $value, date('Y-m-d', (int) $row['achievedat'])),
                default     => sprintf(t('Goal met: %s %s.'), $value, $what),
            };

            return ['id' => (int) $row['achievementid'], 'kind' => (string) $row['kind'], 'metric' => (string) $row['metric'], 'dims' => $dims,
                'value' => (float) $row['value'], 'achieved_at' => date('Y-m-d', (int) $row['achievedat']), 'goal' => $row['goalid'] !== null ? (int) $row['goalid'] : null, 'says' => $says];
        }, $rows);
    }

    /** The rungs a level's readings passed above its first one, each the first day it did. */
    private static function milestones(Metric $metric, int $tenant, string $dims, array $days): int
    {
        $readings = array_filter($days, static fn ($v): bool => $v !== null);
        if ($readings === []) {
            return 0;
        }
        $first = (float) reset($readings);
        $new   = 0;
        foreach (self::LADDER as $rung) {
            if ($rung <= $first) {
                continue;
            }
            foreach ($readings as $day => $value) {
                if ($value >= $rung) {
                    $new += self::record($tenant, 'milestone', $metric->name, $dims, $rung, (int) strtotime($day . ' 00:00:00'));
                    break;
                }
            }
        }

        return $new;
    }

    /** A flow's best week so far, Monday to Sunday, the current week left out. */
    private static function best(Metric $metric, int $tenant, string $dims, array $days, int $now): int
    {
        $current = date('o-W', $now);
        $weeks   = [];
        foreach ($days as $day => $value) {
            $week = date('o-W', (int) strtotime($day));
            if ($value !== null && $week !== $current) {
                $weeks[$week] = ($weeks[$week] ?? 0.0) + (float) $value;
            }
        }
        if ($weeks === [] || max($weeks) <= 0) {
            return 0;
        }
        $best   = max($weeks);
        $monday = (int) strtotime(substr((string) array_search($best, $weeks, true), 0, 4) . 'W' . substr((string) array_search($best, $weeks, true), 5, 2));

        return self::record($tenant, 'best', $metric->name, $dims, $best, $monday);
    }

    /** One-off reach goals met: each once, dated the day it was. */
    private static function goals(int $now): int
    {
        $new = 0;
        foreach (Goals::all() as $goal) {
            if ($goal['grain'] !== 'once' || ($goal['kind'] ?? null) !== 'reach') {
                continue;
            }
            try {
                $judged = GoalJudge::judge($goal, $now);
            } catch (\Throwable) {
                continue;
            }
            if ($judged['state'] === 'met' && ($judged['met_at'] ?? null) !== null) {
                $dims = $goal['filters'] === [] ? '' : (string) json_encode($goal['filters'], JSON_UNESCAPED_UNICODE);
                $new += self::record((int) $goal['tenant'], 'goal', $goal['metric'], $dims, (float) $goal['target'], (int) strtotime($judged['met_at'] . ' 00:00:00'), $goal['id']);
            }
        }

        return $new;
    }

    /** Keep one, unless it is kept already: 1 when new. */
    private static function record(int $tenant, string $kind, string $metric, string $dims, float $value, int $at, ?int $goal = null): int
    {
        $table  = static fn () => Metrics::connection()->queryBuilder()->table(self::TABLE);
        // A best week is new only above every one kept; any other, unless the same is kept.
        $exists = $kind === 'best'
            ? $table()->where('tenant', $tenant)->where('kind', 'best')->where('metric', $metric)->where('dims', $dims)->where('value', '>=', $value)->count() > 0
            : $table()->where('tenant', $tenant)->where('kind', $kind)->where('metric', $metric)->where('dims', $dims)->where('value', $value)->count() > 0;
        if ($exists) {
            return 0;
        }
        try {
            $table()->insert(['tenant' => $tenant, 'kind' => $kind, 'metric' => $metric, 'dims' => $dims, 'value' => $value, 'achievedat' => $at,
                'goalid' => $goal, 'createdat' => time()]);
        } catch (\Throwable) {
            return 0;   // another scan kept it a moment ago: the unique key says so
        }

        return 1;
    }

    /** The organisations a metric is read for: its tenants', or none. @return list<int> */
    private static function tenants(Metric $metric): array
    {
        $listed = Metrics::config()['tenants'] ?? null;

        return $metric->getTenant() !== null && is_callable($listed) ? array_values(array_map('intval', (array) $listed())) : [0];
    }

    /**
     * A metric's daily values: the total, and each value of the dimension it adds up across.
     *
     * @return array<string, array<string, float|null>> Dimension values as JSON ('' for the total) => day => value
     */
    private static function series(Metric $metric, int $tenant, string $from, string $to): array
    {
        $query = static function (?string $by) use ($metric, $tenant, $from, $to): array {
            $q = Metrics::query($metric->name)->grain(Window::DAY)->between($from, $to)->by($by);
            if ($tenant > 0) {
                $q->tenant($tenant);
            }

            return $q->series();
        };
        $out    = [];
        $series = $query(null);
        $keys   = array_map(static fn (Window $w): string => $w->key(), $series['periods']);
        $out[''] = array_combine($keys, $series['values'][''] ?? array_fill(0, count($keys), null));
        $across = $metric->getAcross();
        if ($across !== null) {
            $base = Metrics::dimensionOf($across[0])->baseValues();
            foreach ($query($across[0])['values'] as $value => $values) {
                if ($base === null || in_array((string) $value, $base, true)) {
                    $out[(string) json_encode([$across[0] => (string) $value], JSON_UNESCAPED_UNICODE)] = array_combine($keys, $values);
                }
            }
        }

        return $out;
    }
}
