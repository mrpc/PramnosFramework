<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * Targets set on metrics, and how near they are: "how close are we to today's target".
 *
 * ```php
 * $id = Goals::set('payments.revenue', 'day', 2000.0);                 // every day, against last year
 * Goals::set('registrations', 'month', 900.0, '2026-11-01', reference: Goals::WEEKDAYS);
 * Goals::progress($id);   // ['actual' => 620.0, 'expected' => 580.0, 'forecast' => 2140.0, 'share' => 0.29, 'through' => '2026-11-09 11:00', …] or null
 * ```
 *
 * **The expected curve** is not a straight line: a business is busier at some hours than others,
 * and on some days. The share of the target due by now is the share of a reference period done by
 * the same point — the same weekday a year earlier ({@see LAST_YEAR}), or the mean of the same
 * weekday over the last four weeks ({@see WEEKDAYS}) — scaled to the target. The **forecast**
 * carries the actual on along the same curve.
 *
 * Within a day it reads the metric by the hour, the hours completed so far; within a month, the
 * days completed. Where a single hour (or day) of the reference was never computed the progress is
 * null: a curve with a hole in it would say something false. The metric must be kept at the grain
 * it is read at — by the hour for a day's goal ({@see Metric::grains()}).
 *
 * State, not history (rule 14): a goal is what is aimed at now.
 */
final class Goals
{
    public const TABLE = 'stats.goals';

    /** The same weekday a year earlier: a business judged against the same time of year. */
    public const LAST_YEAR = 'lastyear';

    /** The mean of the same weekday over the last weeks. */
    public const WEEKDAYS = 'weekdays';

    /** How many weeks back {@see WEEKDAYS} reads. */
    public const WEEKS = 4;

    /**
     * Set a target: for one period (`$period`, a day of it), or for every period of its grain.
     *
     * @param array<string, string|list<string>> $filters
     */
    public static function set(string $metric, string $grain, float $target, ?string $period = null, array $filters = [], int $tenant = 0, string $reference = self::LAST_YEAR, ?int $by = null): int
    {
        Metrics::get($metric);
        if (!in_array($grain, [Window::DAY, Window::MONTH], true)) {
            throw new \InvalidArgumentException(t('A goal is for a day or a month.'));
        }
        if (!in_array($reference, [self::LAST_YEAR, self::WEEKDAYS], true)) {
            throw new \InvalidArgumentException(t('A goal follows last year or the same weekdays.'));
        }
        $db = Metrics::connection();
        $db->queryBuilder()->table(self::TABLE)->insert([
            'metric' => $metric, 'grain' => $grain, 'period' => $period === null ? '' : Window::of($grain, $period)->key(), 'tenant' => $tenant,
            'filters' => $filters === [] ? null : json_encode($filters, JSON_UNESCAPED_UNICODE), 'target' => $target, 'reference' => $reference,
            'createdby' => $by, 'createdat' => time(),
        ]);

        return (int) $db->getInsertId();
    }

    /** Take a goal away. */
    public static function delete(int $id): void
    {
        Metrics::connection()->queryBuilder()->table(self::TABLE)->where('goalid', $id)->delete();
    }

    /**
     * A goal, as kept, or null.
     *
     * @return array{id: int, metric: string, grain: string, period: string, tenant: int, filters: array<string, mixed>, target: float, reference: string}|null
     */
    public static function get(int $id): ?array
    {
        $row = Metrics::connection()->queryBuilder()->table(self::TABLE)->where('goalid', $id)->first()?->fetch();

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * The goal for a metric's period: the one set for that period, else the one for every period.
     *
     * @return array{id: int, metric: string, grain: string, period: string, tenant: int, filters: array<string, mixed>, target: float, reference: string}|null
     */
    public static function for(string $metric, string $grain, string $at, int $tenant = 0): ?array
    {
        $key  = Window::of($grain, $at)->key();
        $rows = Metrics::connection()->queryBuilder()->table(self::TABLE)->where('metric', $metric)->where('grain', $grain)
            ->where('tenant', $tenant)->whereIn('period', [$key, ''])->getAllOrFail();
        usort($rows, static fn (array $a, array $b): int => ($b['period'] !== '') <=> ($a['period'] !== ''));

        return $rows === [] ? null : self::shape($rows[0]);
    }

    /**
     * How near a goal is, now: the actual so far, what was expected by now, the forecast for the
     * whole period, the share of it the reference had done by now, and the last hour (or day)
     * counted — or null where the reference has a hole, or nothing to go by.
     *
     * @param int|array<string, mixed> $goal A goal's id, or a goal as {@see get()} gives it
     * @return array{target: float, actual: float, expected: float, forecast: ?float, share: float, through: ?string, period: string, reference: string}|null
     */
    public static function progress(int|array $goal, ?int $now = null): ?array
    {
        $goal = is_int($goal) ? self::get($goal) : $goal;
        if ($goal === null) {
            return null;
        }
        $now    = $now ?? time();
        $period = Window::of($goal['grain'], $goal['period'] !== '' ? $goal['period'] : $now);
        $fine   = $goal['grain'] === Window::DAY ? Window::HOUR : Window::DAY;
        // The finer periods of this one that have ended, and been computed, in order.
        $steps  = array_values(array_filter(Window::range($fine, $period->start, $period->end->modify('-1 second')), static fn (Window $w): bool => $w->ended($now)));
        $actual = $steps === [] ? [] : self::values($goal, $fine, $steps);
        $done   = 0;
        $sum    = 0.0;
        foreach ($actual as $value) {
            if ($value === null) {
                break;
            }
            $sum += $value;
            $done++;
        }
        $shares = [];
        foreach (self::references($goal, $period) as $reference) {
            $curve = self::values($goal, $fine, Window::range($fine, $reference->start, $reference->end->modify('-1 second')));
            if ($curve === [] || in_array(null, $curve, true)) {
                return null;   // a hole in the reference: no curve to judge by
            }
            $total = array_sum($curve);
            if ($total <= 0) {
                return null;
            }
            $shares[] = array_sum(array_slice($curve, 0, $done)) / $total;
        }
        if ($shares === []) {
            return null;
        }
        $share = array_sum($shares) / count($shares);

        return [
            'target'    => $goal['target'],
            'actual'    => $sum,
            'expected'  => $goal['target'] * $share,
            'forecast'  => $share > 0 ? $sum / $share : null,
            'share'     => $share,
            'through'   => $done > 0 ? $steps[$done - 1]->key() : null,
            'period'    => $period->key(),
            'reference' => $goal['reference'],
        ];
    }

    /**
     * The periods a goal's curve is read from: the same weekday a year earlier, or the same
     * weekday of each of the last weeks; for a month, the same month a year earlier.
     *
     * @param array<string, mixed> $goal
     * @return list<Window>
     */
    private static function references(array $goal, Window $period): array
    {
        if ($goal['grain'] === Window::MONTH) {
            return [$period->yearEarlier()];
        }
        if ($goal['reference'] === self::WEEKDAYS) {
            return array_map(static fn (int $weeks): Window => Window::of(Window::DAY, $period->start->modify('-' . (7 * $weeks) . ' days')), range(1, self::WEEKS));
        }

        // 52 weeks back: the same weekday, the nearest to a year.
        return [Window::of(Window::DAY, $period->start->modify('-364 days'))];
    }

    /**
     * A goal's metric over periods, with its filters and organisation.
     *
     * @param array<string, mixed> $goal
     * @param list<Window>         $periods
     * @return list<float|null>
     */
    private static function values(array $goal, string $grain, array $periods): array
    {
        $query = Metrics::query($goal['metric'])->grain($grain)->between($periods[0]->key(), end($periods)->key())->where($goal['filters']);
        if ($goal['tenant'] > 0) {
            $query->tenant($goal['tenant']);
        }

        return array_values($query->series()['values'][''] ?? array_fill(0, count($periods), null));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, metric: string, grain: string, period: string, tenant: int, filters: array<string, mixed>, target: float, reference: string}
     */
    private static function shape(array $row): array
    {
        return ['id' => (int) $row['goalid'], 'metric' => (string) $row['metric'], 'grain' => (string) $row['grain'], 'period' => (string) $row['period'],
            'tenant' => (int) $row['tenant'], 'filters' => (array) json_decode((string) ($row['filters'] ?? ''), true), 'target' => (float) $row['target'],
            'reference' => (string) $row['reference']];
    }
}
