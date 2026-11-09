<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * Targets set on metrics, and how near they are: "how close are we to today's target".
 *
 * ```php
 * $id = Goals::set('payments.revenue', 'day', 2000.0);                 // every day, against last year
 * Goals::set('registrations', 'month', 900.0, '2026-11-01', reference: Goals::WEEKDAYS);
 * Goals::set('payments.revenue', 'day', 0, growth: 0.10);              // every day: the same day last year, plus 10%
 * Goals::update($id, ['growth' => 0.05]);
 * Goals::progress($id);   // ['actual' => 620.0, 'expected' => 580.0, 'forecast' => 2140.0, 'share' => 0.29, 'through' => '2026-11-09 11:00', …] or null
 * Goals::trace($id);      // the same, hour by hour: actual so far, expected, forecast — for a chart
 * ```
 *
 * **A relative target** (`growth`) is the reference period's total — the same day last year, or
 * the mean of the same weekdays — times 1 + growth, worked out each period: one goal set once
 * serves every day. Where the reference has a hole there is no target, and no progress.
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
    public static function set(string $metric, string $grain, float $target, ?string $period = null, array $filters = [], int $tenant = 0, string $reference = self::LAST_YEAR, ?int $by = null, ?float $growth = null): int
    {
        Metrics::get($metric);
        self::ready();
        if ($growth === null && $target <= 0) {
            throw new \InvalidArgumentException(t('A goal needs a target above nought, or a growth over its reference.'));
        }
        if (!in_array($grain, [Window::DAY, Window::MONTH], true)) {
            throw new \InvalidArgumentException(t('A goal is for a day or a month.'));
        }
        if (!in_array($reference, [self::LAST_YEAR, self::WEEKDAYS], true)) {
            throw new \InvalidArgumentException(t('A goal follows last year or the same weekdays.'));
        }
        $db     = Metrics::connection();
        $insert = $db->queryBuilder()->table(self::TABLE);
        if ($db->type === 'postgresql') {
            // The id from the INSERT itself: currval() after it can answer "not defined in this session" (FW-280).
            $insert->returning('goalid');
        }
        $result = $insert->insert([
            'metric' => $metric, 'grain' => $grain, 'period' => $period === null ? '' : Window::of($grain, $period)->key(), 'tenant' => $tenant,
            'filters' => $filters === [] ? null : json_encode($filters, JSON_UNESCAPED_UNICODE), 'target' => $growth === null ? $target : 0.0, 'growth' => $growth, 'reference' => $reference,
            'createdby' => $by, 'createdat' => time(),
        ]);

        return $db->type === 'postgresql' ? (int) ($result->fields['goalid'] ?? 0) : (int) $db->getInsertId();
    }

    /** The same length of time just before: what a total's growth is measured against by default. */
    public const PREVIOUS = 'previous';

    /** The period's first reading: what a level's growth is measured against. */
    public const FIRST = 'first';

    /** The references a goal is measured against. */
    public const REFERENCES = [self::LAST_YEAR, self::WEEKDAYS, self::PREVIOUS, self::FIRST];

    /**
     * A goal of any kind (Metrics-BI-Design §16):
     *
     * ```php
     * Goals::create(['metric' => 'payments.revenue', 'target' => 2000, 'repeats' => 'month']);
     * Goals::create(['metric' => 'followers', 'kind' => 'reach', 'target' => 10000, 'due' => '2026-12-31', 'filters' => ['channel' => '7']]);
     * Goals::create(['metric' => 'registrations', 'kind' => 'growth', 'target' => 10, 'repeats' => 'week', 'reference' => 'previous']);
     * ```
     *
     * Keys: `metric`, `kind` (the metric's default where left out or not its own), `target` (a
     * growth's %), `growth` (a count relative to its reference), `reference`, `repeats` (day,
     * week, month, quarter) or `starts` and `due` (`Y-m-d`; `starts` now when left out),
     * `filters`, `tenant`, `owner`, `note`, `notify`, `by`.
     *
     * @param array<string, mixed> $goal
     * @throws \InvalidArgumentException For a goal that cannot be judged
     */
    public static function create(array $goal): int
    {
        self::ready();
        $row = self::checked($goal);
        $db  = Metrics::connection();
        $insert = $db->queryBuilder()->table(self::TABLE);
        if ($db->type === 'postgresql') {
            $insert->returning('goalid');
        }
        $result = $insert->insert($row + ['createdby' => isset($goal['by']) ? (int) $goal['by'] : null, 'createdat' => time()]);

        return $db->type === 'postgresql' ? (int) ($result->fields['goalid'] ?? 0) : (int) $db->getInsertId();
    }

    /**
     * Change a goal: any key {@see create()} takes. A changed goal is judged afresh, its state
     * forgotten, so a change is not told as a turn.
     *
     * @param array<string, mixed> $changes
     */
    public static function update(int $id, array $changes): void
    {
        self::ready();
        $goal = self::get($id) ?? throw new \InvalidArgumentException(t('There is no such goal.'));
        if (isset($changes['reference']) && !in_array($changes['reference'], self::REFERENCES, true)) {
            throw new \InvalidArgumentException(t('A goal is measured against last year, the same weekdays, the time before or its first reading.'));
        }
        // What is not said stays.
        $merged = $changes + ['metric' => $goal['metric'], 'kind' => $goal['kind'], 'target' => $goal['target'], 'growth' => $goal['growth'],
            'reference' => $goal['reference'], 'filters' => $goal['filters'], 'tenant' => $goal['tenant'], 'owner' => $goal['owner'],
            'note' => $goal['note'], 'notify' => $goal['notify']];
        if (in_array($goal['grain'], GoalJudge::RECURRING, true) && $goal['period'] === '') {
            $merged += ['repeats' => $goal['grain']];
        } elseif ($goal['grain'] === 'once') {
            $zone    = Window::zoneOf((int) $goal['tenant']);
            $merged += ['starts' => (new \DateTimeImmutable('@' . $goal['starts_at']))->setTimezone($zone)->format('Y-m-d'),
                'due' => (new \DateTimeImmutable('@' . $goal['due_at']))->setTimezone($zone)->format('Y-m-d')];
        } else {
            // A goal for one day or month (FW-358): its own period kept.
            $merged += ['repeats' => $goal['grain']];
        }
        $row = self::checked($merged);
        if ($goal['period'] !== '' && $goal['grain'] !== 'once') {
            $row['period'] = $goal['period'];
        }
        Metrics::connection()->queryBuilder()->table(self::TABLE)->where('goalid', $id)->update($row + ['last_state' => null]);
    }

    /**
     * How a goal stands now: its state (met, on_track, behind, stalled or unknown), a sentence
     * saying so, the figures behind it, and for a recurring one its history and streak
     * ({@see GoalJudge}).
     *
     * @param int|array<string, mixed> $goal
     * @return array<string, mixed>|null Null for a goal that is not there
     */
    public static function judge(int|array $goal, ?int $now = null): ?array
    {
        $goal = is_int($goal) ? self::get($goal) : $goal;

        return $goal === null ? null : GoalJudge::judge($goal, $now);
    }

    /**
     * The goals of an organisation (0: those of none), or of every one.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(?int $tenant = null): array
    {
        self::ready();
        $query = Metrics::connection()->queryBuilder()->table(self::TABLE)->orderBy('goalid');
        if ($tenant !== null) {
            $query->where('tenant', $tenant);
        }
        try {
            return array_map(self::shape(...), $query->getAllOrFail());
        } catch (\Throwable) {
            return [];   // no goals table yet
        }
    }

    /**
     * A goal's row, checked: a metric there is, a kind it takes, a target or a growth, a period it
     * recurs every one of, or one-off dates.
     *
     * @param array<string, mixed> $goal
     * @return array<string, mixed>
     */
    private static function checked(array $goal): array
    {
        $metric = Metrics::get((string) ($goal['metric'] ?? ''));
        $kind   = GoalJudge::kindOf($metric, isset($goal['kind']) ? (string) $goal['kind'] : null);
        $target = (float) ($goal['target'] ?? 0);
        $growth = isset($goal['growth']) && $goal['growth'] !== null && $goal['growth'] !== '' ? (float) $goal['growth'] : null;
        if ($growth === null && $target <= 0) {
            throw new \InvalidArgumentException(t('A goal needs a target above nought, or a growth over its reference.'));
        }
        if ($kind === 'growth' && $target > 1000) {
            throw new \InvalidArgumentException(t('A growth is at most 1000%.'));
        }
        $reference = (string) ($goal['reference'] ?? '');
        if ($reference === '') {
            $reference = $kind === 'growth' && $metric->kind === Metric::LEVEL ? self::FIRST : ($kind === 'growth' ? self::PREVIOUS : self::LAST_YEAR);
        }
        if (!in_array($reference, self::REFERENCES, true)) {
            throw new \InvalidArgumentException(t('A goal is measured against last year, the same weekdays, the time before or its first reading.'));
        }
        $repeats = ($goal['repeats'] ?? '') ?: null;
        $row     = ['metric' => $metric->name, 'kind' => $kind, 'target' => $growth === null ? $target : ($kind === 'growth' ? $target : 0.0),
            'growth' => $growth, 'reference' => $reference, 'filters' => ($goal['filters'] ?? []) === [] ? null : json_encode($goal['filters'], JSON_UNESCAPED_UNICODE),
            'tenant' => (int) ($goal['tenant'] ?? 0), 'owner' => isset($goal['owner']) && (int) $goal['owner'] > 0 ? (int) $goal['owner'] : null,
            'note' => isset($goal['note']) && $goal['note'] !== '' ? mb_substr((string) $goal['note'], 0, 255) : null,
            'notify' => isset($goal['notify']) && $goal['notify'] !== '' ? mb_substr((string) $goal['notify'], 0, 16) : null, 'period' => ''];
        if ($repeats !== null) {
            if (!in_array($repeats, GoalJudge::RECURRING, true)) {
                throw new \InvalidArgumentException(t('A goal recurs every day, week, month or quarter.'));
            }

            // A recurring goal has no due date: each period is its own.
            return $row + ['grain' => $repeats, 'starts_at' => null, 'due_at' => null];
        }
        // Days in the organisation's zone: a goal starts at its midnight and ends at its end of day.
        $zone  = Window::zoneOf($row['tenant']);
        $day   = static fn (string $d): ?\DateTimeImmutable => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? new \DateTimeImmutable($d . ' 00:00:00', $zone) : null;
        $due   = $day((string) ($goal['due'] ?? ''));
        $start = ($goal['starts'] ?? '') !== '' ? $day((string) $goal['starts']) : new \DateTimeImmutable('today', $zone);
        if ($due === null) {
            throw new \InvalidArgumentException(t('A goal needs a date to be met by.'));
        }
        if ($start === null || $due < $start) {
            throw new \InvalidArgumentException(t('A goal is met by a day after it starts.'));
        }

        return $row + ['grain' => 'once', 'starts_at' => $start->getTimestamp(), 'due_at' => $due->modify('+1 day')->getTimestamp() - 1];
    }

    /** Take a goal away. */
    public static function delete(int $id): void
    {
        Metrics::connection()->queryBuilder()->table(self::TABLE)->where('goalid', $id)->delete();
    }

    /**
     * A goal, as kept, or null.
     *
     * @return array{id: int, metric: string, grain: string, period: string, tenant: int, filters: array<string, mixed>, target: float, growth: ?float, reference: string}|null
     */
    public static function get(int $id): ?array
    {
        self::ready();
        $row = Metrics::connection()->queryBuilder()->table(self::TABLE)->where('goalid', $id)->first()?->fetch();

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * The goal for a metric's period: the one set for that period, else the one for every period.
     *
     * @return array{id: int, metric: string, grain: string, period: string, tenant: int, filters: array<string, mixed>, target: float, growth: ?float, reference: string}|null
     */
    public static function for(string $metric, string $grain, string $at, int $tenant = 0): ?array
    {
        self::ready();
        $key  = Window::of($grain, $at)->key();
        try {
            $rows = Metrics::connection()->queryBuilder()->table(self::TABLE)->where('metric', $metric)->where('grain', $grain)
                ->where('tenant', $tenant)->whereIn('period', [$key, ''])->getAllOrFail();
        } catch (\Throwable) {
            return null;   // no goals table: `migrate` has not run since it was added
        }
        usort($rows, static fn (array $a, array $b): int => ($b['period'] !== '') <=> ($a['period'] !== ''));

        return $rows === [] ? null : self::shape($rows[0]);
    }

    /**
     * How near a goal is, now: the target (worked out, for a relative one), the actual so far, what
     * was expected by now, the forecast for the whole period, the share of it the reference had
     * done by now, and the last hour (or day) counted — or null where the reference has a hole, or
     * nothing to go by.
     *
     * @param int|array<string, mixed> $goal A goal's id, or a goal as {@see get()} gives it
     * @return array{target: float, actual: float, expected: float, forecast: ?float, share: float, through: ?string, period: string, reference: string}|null
     */
    public static function progress(int|array $goal, ?int $now = null): ?array
    {
        $trace = self::trace($goal, $now);

        return $trace === null ? null : array_diff_key($trace, ['steps' => 1]);
    }

    /**
     * {@see progress()}, and the way there, hour by hour (or day by day) for a chart: each step's
     * label, the actual so far (null for the steps to come), the expected along the reference's
     * curve, and the forecast from the last step counted on — or null as {@see progress()} is.
     *
     * @param int|array<string, mixed> $goal
     * @return array{target: float, actual: float, expected: float, forecast: ?float, share: float, through: ?string, period: string, reference: string,
     *     steps: list<array{key: string, label: string, actual: ?float, expected: float, forecast: ?float}>}|null
     */
    public static function trace(int|array $goal, ?int $now = null): ?array
    {
        $goal = is_int($goal) ? self::get($goal) : $goal;
        if ($goal === null) {
            return null;
        }
        $now    = $now ?? time();
        $period = Window::of($goal['grain'], $goal['period'] !== '' ? $goal['period'] : $now);
        $fine   = $goal['grain'] === Window::DAY ? Window::HOUR : Window::DAY;
        $all    = Window::range($fine, $period->start, $period->end->modify('-1 second'));
        // The finer periods of this one that have ended, and been computed, in order.
        $steps  = array_values(array_filter($all, static fn (Window $w): bool => $w->ended($now)));
        $actual = $steps === [] ? [] : self::values($goal, $fine, $steps);
        $done   = 0;
        $sum    = 0.0;
        $sofar  = [];
        foreach ($actual as $value) {
            if ($value === null) {
                break;
            }
            $sum    += $value;
            $sofar[] = $sum;
            $done++;
        }
        // Each reference's share done by the end of each step, averaged over the references.
        $curves = [];
        $totals = [];
        foreach (self::references($goal, $period) as $reference) {
            $curve = self::values($goal, $fine, Window::range($fine, $reference->start, $reference->end->modify('-1 second')));
            if ($curve === [] || in_array(null, $curve, true)) {
                return null;   // a hole in the reference: no curve to judge by
            }
            $total = array_sum($curve);
            if ($total <= 0) {
                return null;
            }
            $totals[]  = $total;
            $running   = 0.0;
            $curves[]  = array_map(static function (?float $v) use (&$running, $total): float {
                $running += (float) $v;

                return $running / $total;
            }, $curve);
        }
        if ($curves === []) {
            return null;
        }
        $count  = count($all);
        $shares = array_map(static fn (int $i): float => array_sum(array_map(static fn (array $c): float => $c[$i] ?? 1.0, $curves)) / count($curves), range(0, $count - 1));
        $target = ($goal['growth'] ?? null) !== null ? (array_sum($totals) / count($totals)) * (1 + (float) $goal['growth']) : (float) $goal['target'];
        $share  = $done > 0 ? $shares[$done - 1] : 0.0;
        $out    = [];
        foreach ($all as $i => $window) {
            $out[] = [
                'key'      => $window->key(),
                'label'    => $fine === Window::HOUR ? $window->start->format('H:00') : $window->label(),
                'actual'   => $sofar[$i] ?? null,
                'expected' => $target * $shares[$i],
                // Carried on along the curve from the last step counted.
                'forecast' => $share > 0 && $i >= $done - 1 ? $sum / $share * $shares[$i] : null,
            ];
        }

        return [
            'target'    => $target,
            'actual'    => $sum,
            'expected'  => $target * $share,
            'forecast'  => $share > 0 ? $sum / $share : null,
            'share'     => $share,
            'through'   => $done > 0 ? $steps[$done - 1]->key() : null,
            'period'    => $period->key(),
            'reference' => $goal['reference'],
            'steps'     => $out,
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
     * @return array{id: int, metric: string, grain: string, period: string, tenant: int, filters: array<string, mixed>, target: float, growth: ?float, reference: string}
     */
    private static function shape(array $row): array
    {
        $int = static fn (string $c): ?int => isset($row[$c]) && $row[$c] !== null && $row[$c] !== '' ? (int) $row[$c] : null;

        return ['id' => (int) $row['goalid'], 'metric' => (string) $row['metric'], 'grain' => (string) $row['grain'], 'period' => (string) $row['period'],
            'tenant' => (int) $row['tenant'], 'filters' => (array) json_decode((string) ($row['filters'] ?? ''), true), 'target' => (float) $row['target'],
            'growth' => isset($row['growth']) && $row['growth'] !== null ? (float) $row['growth'] : null, 'reference' => (string) $row['reference'],
            'kind' => ($row['kind'] ?? null) ?: null, 'starts_at' => $int('starts_at'), 'due_at' => $int('due_at'), 'owner' => $int('owner'),
            'note' => (string) ($row['note'] ?? ''), 'notify' => ($row['notify'] ?? null) ?: null, 'last_state' => ($row['last_state'] ?? null) ?: null,
            'last_told_at' => $int('last_told_at')];
    }

    /** @var array<int, true> The connections whose table was seen to have every column */
    private static array $ready = [];

    /**
     * The columns added after the table was first made (`growth`, and the kinds, one-off dates,
     * owner and state of FW-360), added where it lacks them — once, the first time a goal is read
     * or set — so an installation needs no step of its own.
     */
    private static function ready(): void
    {
        $db = Metrics::connection();
        if (isset(self::$ready[spl_object_id($db)])) {
            return;
        }
        $schema = $db->schema();
        if ($schema->hasTable(self::TABLE)) {
            // Each column a table made before it gains, the first time a goal is read or set.
            $columns = ['growth' => static fn ($t) => $t->double('growth')->nullable(), 'kind' => static fn ($t) => $t->string('kind', 12)->nullable(),
                'starts_at' => static fn ($t) => $t->integer('starts_at')->nullable(), 'due_at' => static fn ($t) => $t->integer('due_at')->nullable(),
                'owner' => static fn ($t) => $t->integer('owner')->nullable(), 'note' => static fn ($t) => $t->string('note', 255)->nullable(),
                'notify' => static fn ($t) => $t->string('notify', 16)->nullable(), 'last_state' => static fn ($t) => $t->string('last_state', 12)->nullable(),
                'last_told_at' => static fn ($t) => $t->integer('last_told_at')->nullable()];
            foreach ($columns as $column => $add) {
                if (!$schema->hasColumn(self::TABLE, $column)) {
                    $schema->table(self::TABLE, $add);
                }
            }
        }
        self::$ready[spl_object_id($db)] = true;
    }
}
