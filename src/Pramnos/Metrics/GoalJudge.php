<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * How a goal stands: every kind of goal, on a metric's daily values, read through the one query
 * (Metrics-BI-Design §16). Used by {@see Goals::judge()}.
 *
 * On a **total** (a flow): `count` — the period's total reaches the target, expected along the
 * elapsed time or, kept by the hour, along last year's curve ({@see Goals::trace()}) — and
 * `growth` — the total reaches its reference times 1 + growth: the same length before
 * (`previous`), the same period a year earlier (`lastyear`) or the mean of the last four same
 * weekdays (`weekdays`).
 *
 * On a **level**: `reach` — a reading crosses the target in the metric's direction, with the
 * least-squares trend's crossing day compared with the due date; `sustain` — no reading worse
 * than the target until the due date (its worst reading said); `pace` — the change since the start
 * reaches the target; `growth` — that change in %.
 *
 * A one-off goal runs from `starts_at` to `due_at`; a recurring one (`day`, `week`, `month`,
 * `quarter`) is judged on its current period in the organisation's zone, with a history of past
 * periods, each as it stood at its end, and a streak of the met ones.
 */
final class GoalJudge
{
    /** The kinds a goal on a total takes, the default first. */
    public const KINDS_TOTAL = ['count', 'growth'];

    /** The kinds a goal on a level takes, the default first. */
    public const KINDS_LEVEL = ['reach', 'sustain', 'pace', 'growth'];

    /** The periods a goal recurs every one of. */
    public const RECURRING = [Window::DAY, Window::WEEK, Window::MONTH, Window::QUARTER];

    /** How many past periods a recurring goal's history shows. */
    public const HISTORY = [Window::DAY => 30, Window::WEEK => 12, Window::MONTH => 12, Window::QUARTER => 8];

    /** A one-off reach is judged from this many readings… */
    public const MIN_POINTS = 12;

    /** …spread over this many days. */
    public const MIN_DAYS = 7;

    /** The states that are good news; the others, but unknown, are bad. */
    public const GOOD = ['met', 'on_track'];

    /** The states that are bad news. */
    public const BAD = ['behind', 'stalled'];

    /** The kind a goal on this metric takes: the one asked where it fits, else the metric's default. */
    public static function kindOf(Metric $metric, ?string $kind): string
    {
        $kinds = $metric->kind === Metric::LEVEL ? self::KINDS_LEVEL : self::KINDS_TOTAL;

        return in_array($kind, $kinds, true) ? (string) $kind : $kinds[0];
    }

    /**
     * How a goal stands now; a recurring one with its history and streak.
     *
     * @param array<string, mixed> $goal As {@see Goals::get()} gives it
     * @return array<string, mixed>
     */
    public static function judge(array $goal, ?int $now = null): array
    {
        $now    = $now ?? time();
        $metric = Metrics::get($goal['metric']);
        $kind   = self::kindOf($metric, $goal['kind'] ?? null);
        if (in_array($goal['grain'], self::RECURRING, true) && ($goal['period'] ?? '') === '') {
            $current = self::period($goal, $now);
            $answer  = self::at($goal, $metric, $kind, $current->start->getTimestamp(), $current->end->getTimestamp(), $now, $current) + ['period' => $current->key()];
            $history = [];
            $window  = $current;
            for ($i = 0; $i < self::HISTORY[$goal['grain']]; $i++) {
                $window = $window->previous();
                $end    = $window->end->getTimestamp();
                $past   = self::at($goal, $metric, $kind, $window->start->getTimestamp(), $end, $end, $window);
                $met    = $past['enough'] ? $past['state'] === 'met' : null;
                array_unshift($history, ['from' => $window->key(), 'met' => $met, 'value' => $past['current'], 'target' => $past['target']]);
            }
            $streak = 0;
            for ($i = count($history) - 1; $i >= 0 && $history[$i]['met'] === true; $i--) {
                $streak++;
            }
            $answer['says']    = sprintf(t(['day' => 'Today: %s', 'week' => 'This week: %s', 'month' => 'This month: %s', 'quarter' => 'This quarter: %s'][$goal['grain']]), $answer['says']);
            $answer['history'] = $history;
            $answer['streak']  = $streak;

            return self::clean($answer, $kind);
        }
        $from = (int) ($goal['starts_at'] ?? 0);
        $to   = (int) ($goal['due_at'] ?? 0);
        if ($from <= 0 || $to <= $from) {
            // A goal for one period (a day or a month) set before one-off goals had dates.
            $window = Window::of($goal['grain'] === 'once' ? Window::DAY : $goal['grain'], $goal['period'] !== '' ? $goal['period'] : $now, Window::zoneOf((int) $goal['tenant']));

            return self::clean(self::at($goal, $metric, $kind, $window->start->getTimestamp(), $window->end->getTimestamp(), $now, $window) + ['period' => $window->key()], $kind);
        }

        return self::clean(self::at($goal, $metric, $kind, $from, $to + 1, $now, null), $kind);
    }

    /** The current period of a recurring goal, in its organisation's zone. */
    private static function period(array $goal, int $now): Window
    {
        return Window::of($goal['grain'], $now, Window::zoneOf((int) $goal['tenant']));
    }

    /**
     * How a goal stands in one span, `[$from, $to)`, judged at `$now`.
     *
     * @return array<string, mixed>
     */
    private static function at(array $goal, Metric $metric, string $kind, int $from, int $to, int $now, ?Window $window): array
    {
        $now = min($now, $to);
        self::$year = (new \DateTimeImmutable('@' . $now))->setTimezone(Window::zoneOf((int) $goal['tenant']))->format('Y');

        return $metric->kind === Metric::LEVEL
            ? self::level($goal, $metric, $kind, $from, $to, $now, $window)
            : self::total($goal, $metric, $kind, $from, $to, $now, $window);
    }

    /**
     * A goal on a total: count, or growth over a reference.
     *
     * @return array<string, mixed>
     */
    private static function total(array $goal, Metric $metric, string $kind, int $from, int $to, int $now, ?Window $window): array
    {
        $days    = self::daily($goal, $from, min($to, $now + 1) - 1);
        $values  = array_filter($days, static fn ($v): bool => $v !== null);
        self::$whole = self::counted($metric, $values);
        $total   = (float) array_sum($values);
        $target  = (float) $goal['target'];
        $elapsed = max(0.0, min(1.0, ($now - $from) / max(1, $to - $from)));
        $growth  = $kind === 'growth' ? ($goal['growth'] ?? ($goal['target'] / 100)) : ($goal['growth'] ?? null);
        $out     = ['current' => $total, 'target' => null, 'expected' => null, 'projected' => null, 'enough' => $values !== [], 'worst' => null, 'worst_at' => null, 'met_at' => null];
        // Kept by the hour and set against last year or the weekdays: the curve (FW-358).
        if ($window !== null && in_array($window->grain, [Window::DAY, Window::MONTH], true) && in_array($goal['reference'], [Goals::LAST_YEAR, Goals::WEEKDAYS], true)) {
            $trace = Goals::trace(['period' => $window->key(), 'growth' => $growth !== null ? (float) $growth : null] + $goal, $now);
            if ($trace !== null) {
                return self::verdictTotal(['current' => $trace['actual'], 'target' => $trace['target'], 'expected' => $trace['expected'],
                    'projected' => $trace['forecast'], 'steps' => $trace['steps']] + $out, $now >= $to);
            }
        }
        if ($growth !== null) {
            $base = self::reference($goal, $from, $to);
            if ($base === null || $base <= 0) {
                return ['state' => 'unknown', 'says' => t('Nothing in the time before to grow from yet.')] + $out;
            }
            $target = $base * (1 + (float) $growth);
        }

        return self::verdictTotal(['target' => $target, 'expected' => $target * $elapsed, 'projected' => $elapsed > 0 ? $total / $elapsed : null] + $out, $now >= $to);
    }

    /** @param array<string, mixed> $out */
    private static function verdictTotal(array $out, bool $over): array
    {
        $state = $out['current'] >= $out['target'] ? 'met' : (($over || $out['current'] < (float) $out['expected']) ? 'behind' : 'on_track');
        $says  = $state === 'met'
            ? sprintf(t('%s of %s: reached.'), self::n($out['current']), self::n($out['target']))
            : sprintf(t('%s of %s; %s expected by now.'), self::n($out['current']), self::n($out['target']), self::n((float) $out['expected']));

        return ['state' => $state, 'says' => $says] + $out;
    }

    /** The reference total a growth goal on a total grows from, or null without one. */
    private static function reference(array $goal, int $from, int $to): ?float
    {
        // In calendar days in the organisation's zone, not seconds: a day the clocks change is a day.
        $zone  = Window::zoneOf((int) $goal['tenant']);
        $start = (new \DateTimeImmutable('@' . $from))->setTimezone($zone);
        $end   = (new \DateTimeImmutable('@' . $to))->setTimezone($zone);
        $days  = max(1, (int) $start->diff($end)->days);
        $sum   = function (\DateTimeImmutable $a, \DateTimeImmutable $b) use ($goal): ?float {
            $values = array_filter(self::daily($goal, $a->getTimestamp(), $b->getTimestamp() - 1), static fn ($v): bool => $v !== null);

            return $values === [] ? null : (float) array_sum($values);
        };
        $back = static fn (string $by) => $sum($start->modify($by), $end->modify($by));

        return match ($goal['reference']) {
            Goals::LAST_YEAR => $back('-1 year'),
            Goals::WEEKDAYS  => ($four = array_filter(array_map(static fn (int $w) => $back('-' . (7 * $w) . ' days'), [1, 2, 3, 4]),
                static fn ($v): bool => $v !== null)) === [] ? null : array_sum($four) / count($four),
            default          => $sum($start->modify('-' . $days . ' days'), $start),
        };
    }

    /**
     * A goal on a level: reach, sustain, pace or growth.
     *
     * @return array<string, mixed>
     */
    private static function level(array $goal, Metric $metric, string $kind, int $from, int $to, int $now, ?Window $window): array
    {
        $direction = $metric->getDirection() === Metric::LOWER_IS_BETTER ? -1 : 1;
        // A recurring goal starts from the last reading before its period: growth compounds.
        $readings  = self::readings($goal, $window !== null ? $from - 86400 : $from, min($to, $now + 1) - 1);
        $target    = (float) $goal['target'];
        $out       = ['target' => $target, 'expected' => null, 'projected' => null, 'met_at' => null, 'worst' => null, 'worst_at' => null];
        if ($readings === []) {
            return ['state' => 'unknown', 'current' => null, 'enough' => false, 'says' => t('Nothing measured since this goal started.')] + $out;
        }
        self::$whole = self::counted($metric, $readings);
        $current = end($readings);
        $out    += ['current' => $current, 'enough' => count($readings) >= 2];
        $over    = $now >= $to;

        if ($kind === 'sustain') {
            $worstAt = array_key_first($readings);
            foreach ($readings as $at => $value) {
                if ($value * $direction < $readings[$worstAt] * $direction) {
                    $worstAt = $at;
                }
            }
            $held  = $readings[$worstAt] * $direction >= $target * $direction;
            $state = !$held ? 'behind' : ($over ? 'met' : 'on_track');
            $out   = ['worst' => $readings[$worstAt], 'worst_at' => self::day($goal, $worstAt)] + $out;

            return ['state' => $state, 'says' => $held ? sprintf(t('Held: never worse than %s.'), self::n($target))
                : sprintf(t('Not held: %s on %s.'), self::n($readings[$worstAt]), self::spoken($goal, $worstAt))] + $out;
        }

        if ($kind === 'pace' || $kind === 'growth') {
            if (count($readings) < 2) {
                return ['state' => 'unknown', 'says' => t('Not enough readings yet.')] + $out;
            }
            $first = reset($readings);
            if ($kind === 'growth' && $first <= 0) {
                return ['state' => 'unknown', 'says' => t('Nothing to grow from: the first reading is zero.')] + $out;
            }
            $done    = $kind === 'growth' ? ($current - $first) / $first * 100 : $current - $first;
            $elapsed = max(0.0, min(1.0, ($now - $from) / max(1, $to - $from)));
            $should  = $target * $elapsed;
            $state   = $done >= $target ? 'met' : (($over || $done < $should) ? 'behind' : 'on_track');
            $says    = $kind === 'growth'
                ? sprintf(t('From %s to %s now; the goal is %s by %s, and about %s by today.'), self::n($first), self::n($current),
                    self::n($first * (1 + $target / 100)), self::spoken($goal, $to - 1), self::n($first * (1 + $should / 100)))
                : sprintf(t('%s done of %s; %s expected by now.'), self::n($done), self::n($target), self::n($should));

            return ['state' => $state, 'says' => $says, 'expected' => $should, 'current' => $done] + $out;
        }

        // Reach: the first crossing, else the trend's.
        foreach ($readings as $at => $value) {
            if ($value * $direction >= $target * $direction) {
                return ['state' => 'met', 'met_at' => self::day($goal, $at), 'projected' => self::day($goal, $at),
                    'says' => sprintf(t('Reached on %s.'), self::spoken($goal, $at))] + $out;
            }
        }
        if ($over) {
            return ['state' => 'behind', 'says' => sprintf(t('Not reached by %s: %s.'), self::spoken($goal, $to - 1), self::n($current))] + $out;
        }
        $points = count($readings);
        $span   = (int) floor((array_key_last($readings) - array_key_first($readings)) / 86400) + 1;
        // A one-off goal needs a fortnight's worth; a recurring one, half its period.
        $needPoints = $window !== null ? max(2, (int) ceil(count(Window::range(Window::DAY, $window->start, $window->end->modify('-1 second'))) / 2)) : self::MIN_POINTS;
        $needDays   = $window !== null ? $needPoints : self::MIN_DAYS;
        if ($points < $needPoints) {
            return ['state' => 'unknown', 'says' => sprintf(t('Not enough history to say yet — %d more reading(s) needed.'), $needPoints - $points)] + $out;
        }
        if ($span < $needDays) {
            return ['state' => 'unknown', 'says' => sprintf(t('Not enough history to say yet — ask again in %d day(s).'), $needDays - $span)] + $out;
        }
        $slope = self::slopePerDay($readings);
        if ($slope * $direction <= 0) {
            return ['state' => 'stalled', 'says' => t('Not moving towards this on the current trend.')] + $out;
        }
        // In calendar days in the organisation's zone: a day the clocks change is still a day.
        $at    = (new \DateTimeImmutable('@' . array_key_last($readings)))->setTimezone(Window::zoneOf((int) $goal['tenant']))
            ->modify('+' . (int) ceil(($target - $current) / $slope) . ' days')->getTimestamp();
        $state = $at <= $to ? 'on_track' : 'behind';

        return ['state' => $state, 'projected' => self::day($goal, $at), 'says' => $state === 'on_track'
            ? sprintf(t('On this trend, %s.'), self::spoken($goal, $at))
            : sprintf(t('On this trend, %s — after the %s you asked for.'), self::spoken($goal, $at), self::spoken($goal, $to - 1))] + $out;
    }

    /**
     * The least-squares slope of readings, per day.
     *
     * @param array<int, float> $readings Unix time => value
     */
    private static function slopePerDay(array $readings): float
    {
        $x0 = array_key_first($readings);
        $xs = array_map(static fn (int $t): float => ($t - $x0) / 86400, array_keys($readings));
        $ys = array_values($readings);
        $n  = count($xs);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($xs as $i => $x) {
            $num += ($x - $mx) * ($ys[$i] - $my);
            $den += ($x - $mx) ** 2;
        }

        return $den > 0 ? $num / $den : 0.0;
    }

    /**
     * A goal's metric by the day, from one day to another (inclusive), with its filters and
     * organisation: null where a day was never computed.
     *
     * @return array<string, float|null> Day key => value
     */
    private static function daily(array $goal, int $from, int $to): array
    {
        if ($to < $from) {
            return [];
        }
        $zone  = Window::zoneOf((int) $goal['tenant']);
        $query = Metrics::query($goal['metric'])->grain(Window::DAY)
            ->between((new \DateTimeImmutable('@' . $from))->setTimezone($zone)->format('Y-m-d'), (new \DateTimeImmutable('@' . $to))->setTimezone($zone)->format('Y-m-d'))
            ->where((array) ($goal['filters'] ?? []));
        if ((int) $goal['tenant'] > 0) {
            $query->tenant((int) $goal['tenant']);
        }
        $series = $query->series();
        $out    = [];
        foreach ($series['periods'] as $i => $window) {
            $out[$window->key()] = $series['values'][''][$i] ?? null;
        }

        return $out;
    }

    /**
     * A level's daily readings: day start => value, the days never computed left out.
     *
     * @return array<int, float>
     */
    private static function readings(array $goal, int $from, int $to): array
    {
        $out  = [];
        $zone = Window::zoneOf((int) $goal['tenant']);
        foreach (self::daily($goal, $from, $to) as $day => $value) {
            if ($value !== null) {
                $out[Window::of(Window::DAY, $day, $zone)->start->getTimestamp()] = (float) $value;
            }
        }

        return $out;
    }

    /** A moment's day in the goal's organisation's zone. */
    private static function day(array $goal, int $at): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(Window::zoneOf((int) $goal['tenant']))->format('Y-m-d');
    }

    /**
     * A day as a sentence writes it, in the reader's language: "30 November", with the year when
     * it is not this one. The answer's fields keep `Y-m-d`.
     */
    private static function spoken(array $goal, int $at): string
    {
        $day   = (new \DateTimeImmutable('@' . $at))->setTimezone(Window::zoneOf((int) $goal['tenant']));
        $month = ['%d January', '%d February', '%d March', '%d April', '%d May', '%d June', '%d July', '%d August', '%d September',
            '%d October', '%d November', '%d December'][(int) $day->format('n') - 1];
        $said  = sprintf(t($month), (int) $day->format('j'));

        return $day->format('Y') === self::$year ? $said : $said . ' ' . $day->format('Y');
    }

    /** @var string The year the goal is judged in: a day in another names its year */
    private static string $year = '';

    /** @var bool Whether the goal judged counts whole things: its figures are written whole */
    private static bool $whole = false;

    /**
     * Whether a metric counts whole things — a number, every reading whole — so the figures worked
     * out from it (a target grown by a percentage, what is expected by today) are written whole.
     *
     * @param array<mixed, float|int|null> $values
     */
    private static function counted(Metric $metric, array $values): bool
    {
        if ($metric->getFormat() !== 'number') {
            return false;
        }
        foreach ($values as $value) {
            if ($value !== null && abs((float) $value - round((float) $value)) > 1e-9) {
                return false;
            }
        }

        return true;
    }

    /** A figure as a sentence writes it: whole for a metric that counts whole things. */
    private static function n(float $value): string
    {
        return \Pramnos\General\NumberFormat::trimmed(self::$whole ? round($value) : $value, self::$whole ? 0 : 2);
    }

    /**
     * The answer without what was only for working it out.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function clean(array $answer, string $kind): array
    {
        unset($answer['enough']);

        return ['kind' => $kind] + $answer;
    }
}
