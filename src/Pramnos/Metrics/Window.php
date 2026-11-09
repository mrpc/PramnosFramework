<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * One period a metric is counted over: a grain — day, week (Monday first), month, quarter or year
 * — and its half-open span `[start, next start)` in the application's time zone.
 *
 * ```php
 * $w = Window::of(Window::MONTH, '2026-09-14');   // September 2026
 * $w->startText();   // '2026-09-01 00:00:00' — compare with `>=`
 * $w->endText();     // '2026-10-01 00:00:00' — compare with `<`
 * $w->startsAt();    // the same moments as Unix seconds, for an integer column
 * ```
 *
 * Half-open on purpose: `<= 23:59:59` loses the last second's fraction and `<` the last second,
 * and two sources that disagree on the end of a day disagree on every total.
 */
final class Window
{
    public const HOUR    = 'hour';
    public const DAY     = 'day';
    public const WEEK    = 'week';
    public const MONTH   = 'month';
    public const QUARTER = 'quarter';
    public const YEAR    = 'year';

    /** The grains, finest first. */
    public const GRAINS = [self::HOUR, self::DAY, self::WEEK, self::MONTH, self::QUARTER, self::YEAR];

    /** The grains a metric is kept at unless it says otherwise: an hour only where a metric asks. */
    public const DEFAULT_GRAINS = [self::DAY, self::WEEK, self::MONTH, self::QUARTER, self::YEAR];

    private function __construct(
        public readonly string $grain,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
    ) {
    }

    /**
     * The period of a grain that holds a day or a moment.
     *
     * @param \DateTimeInterface|string|int $at A date (`2026-09-14`), a moment, or Unix seconds
     */
    public static function of(string $grain, \DateTimeInterface|string|int $at, ?\DateTimeZone $zone = null): self
    {
        if (!in_array($grain, self::GRAINS, true)) {
            throw new \InvalidArgumentException('Grain "' . $grain . '" is not one of: ' . implode(', ', self::GRAINS) . '.');
        }
        $zone ??= self::zone();
        $day = match (true) {
            $at instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($at)->setTimezone($zone),
            is_int($at)                       => (new \DateTimeImmutable('@' . $at))->setTimezone($zone),
            default                           => new \DateTimeImmutable($at, $zone),
        };
        $hour = $day->setTime((int) $day->format('G'), 0);
        $day  = $day->setTime(0, 0);
        [$y, $m] = [(int) $day->format('Y'), (int) $day->format('n')];
        $start = match ($grain) {
            self::HOUR    => $hour,
            self::DAY     => $day,
            self::WEEK    => $day->modify('-' . ((int) $day->format('N') - 1) . ' days'),
            self::MONTH   => $day->setDate($y, $m, 1),
            self::QUARTER => $day->setDate($y, intdiv($m - 1, 3) * 3 + 1, 1),
            self::YEAR    => $day->setDate($y, 1, 1),
        };

        return new self($grain, $start, self::after($grain, $start));
    }

    /** The zone periods are counted in: `'metrics' => ['timezone' => …]` in app.php, or PHP's. */
    public static function zone(): \DateTimeZone
    {
        $zone = Metrics::config()['timezone'] ?? date_default_timezone_get();

        return new \DateTimeZone(is_string($zone) && in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC');
    }

    /** The period after this one. */
    public function next(): self
    {
        return new self($this->grain, $this->end, self::after($this->grain, $this->end));
    }

    /** The period before this one. */
    public function previous(): self
    {
        return self::of($this->grain, $this->grain === self::HOUR ? $this->start->getTimestamp() - 3600 : $this->start->modify('-1 day'));
    }

    /** The same period a year earlier: the same month, quarter, ISO week or day. */
    public function yearEarlier(): self
    {
        return $this->grain === self::WEEK
            ? self::of(self::WEEK, $this->start->setISODate((int) $this->start->format('o') - 1, (int) $this->start->format('W')))
            : self::of($this->grain, $this->start->modify('-1 year'));
    }

    /** Whether it is over by a moment (now, by default). */
    public function ended(?int $now = null): bool
    {
        return $this->end->getTimestamp() <= ($now ?? time());
    }

    /** Whether it has begun by a moment (now, by default): a period that has not is never computed. */
    public function begun(?int $now = null): bool
    {
        return $this->start->getTimestamp() <= ($now ?? time());
    }

    /** The first day, as stored: `Y-m-d`. */
    public function key(): string
    {
        return $this->start->format($this->grain === self::HOUR ? 'Y-m-d H:00' : 'Y-m-d');
    }

    /** Its start, Unix seconds — compare with `>=`. */
    public function startsAt(): int
    {
        return $this->start->getTimestamp();
    }

    /** The next period's start, Unix seconds — compare with `<`. */
    public function endsAt(): int
    {
        return $this->end->getTimestamp();
    }

    /** Its start in local time, `Y-m-d H:i:s`. */
    public function startText(): string
    {
        return $this->start->format('Y-m-d H:i:s');
    }

    /** The next period's start in local time, `Y-m-d H:i:s`. */
    public function endText(): string
    {
        return $this->end->format('Y-m-d H:i:s');
    }

    /** As people read it: `2026-09-14`, `2026-W38`, `2026-09`, `2026-Q3`, `2026`. */
    public function label(): string
    {
        return match ($this->grain) {
            self::HOUR    => $this->start->format('Y-m-d H:00'),
            self::DAY     => $this->start->format('Y-m-d'),
            self::WEEK    => $this->start->format('o-\WW'),
            self::MONTH   => $this->start->format('Y-m'),
            self::QUARTER => $this->start->format('Y') . '-Q' . (intdiv((int) $this->start->format('n') - 1, 3) + 1),
            self::YEAR    => $this->start->format('Y'),
        };
    }

    /**
     * Every period of a grain from the one holding `$from` to the one holding `$to`, in order.
     *
     * @return list<self>
     */
    public static function range(string $grain, \DateTimeInterface|string|int $from, \DateTimeInterface|string|int $to): array
    {
        $window = self::of($grain, $from);
        $last   = self::of($grain, $to);
        $out    = [];
        while ($window->start <= $last->start && count($out) < 10000) {
            $out[]  = $window;
            $window = $window->next();
        }

        return $out;
    }

    /**
     * Where a period that starts at `$start` ends. An hour is 3600 seconds on, counted in time,
     * not on the clock: the night the clocks go back has two 03:00s, and both are hours.
     */
    private static function after(string $grain, \DateTimeImmutable $start): \DateTimeImmutable
    {
        return $grain === self::HOUR
            ? (new \DateTimeImmutable('@' . ($start->getTimestamp() + 3600)))->setTimezone($start->getTimezone())
            : $start->modify(self::step($grain));
    }

    private static function step(string $grain): string
    {
        return match ($grain) {
            self::DAY     => '+1 day',
            self::WEEK    => '+1 week',
            self::MONTH   => '+1 month',
            self::QUARTER => '+3 months',
            default       => '+1 year',
        };
    }
}
