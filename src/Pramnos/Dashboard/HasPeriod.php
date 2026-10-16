<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * A card each person sets the period of: today, the last 7, 30 or 90 days, or two days of their
 * own — kept in their layout with the card's other settings, and read back as the analyst's
 * tools take a window.
 *
 * ```php
 * final class Orders extends Widget
 * {
 *     use HasPeriod;
 *
 *     public function options(): array { return $this->periodOptions(); }
 *     public function defaultPeriod(): string { return '30'; }
 *     public function render(): string { return Orders::chart($this->window()); }  // ['days' => 30], or from/to
 * }
 * ```
 *
 * A trait rather than methods on Widget, which every application's cards extend: a card of an
 * application's own that already has a `period()` or a `window()` keeps it — a class's own method
 * wins over a trait's, where one added to the parent would have been a fatal for its signature.
 */
trait HasPeriod
{
    /** The periods offered, in the order the form lists them. @return list<string> */
    public static function periods(): array
    {
        return ['today', '7', '30', '90', 'custom'];
    }

    /** The period it shows unless set otherwise: one of {@see periods()}. */
    public function defaultPeriod(): string
    {
        return '7';
    }

    /** The longest period its source answers for, in days; 0 for no limit. */
    public function maxDays(): int
    {
        return 0;
    }

    /**
     * The settings that choose it, for the card's `options()`: `period`, with this card's default
     * first — the framework takes the first as the default — and `from` and `to`, shown only for
     * `custom`.
     *
     * @return array<string, mixed>
     */
    public function periodOptions(): array
    {
        $labels = [];
        foreach (static::periods() as $period) {
            $labels[$period] = match ($period) {
                'today'  => Widgets\Figures::words('Today'),
                'custom' => Widgets\Figures::words('Custom'),
                default  => sprintf(Widgets\Figures::words('Last %d days'), (int) $period),
            };
        }
        $default = $this->defaultPeriod();
        $day     = static function (mixed $value): ?string {
            $at = \DateTime::createFromFormat('!Y-m-d', (string) $value);

            return $at !== false && $at->format('Y-m-d') === (string) $value ? (string) $value : null;
        };

        return [
            'period' => ['label' => 'Period', 'values' => (isset($labels[$default]) ? [$default => $labels[$default]] : []) + $labels],
            'from'   => ['label' => 'From', 'input' => 'date', 'clean' => $day, 'when' => ['period' => 'custom']],
            'to'     => ['label' => 'To', 'input' => 'date', 'clean' => $day, 'when' => ['period' => 'custom']],
        ];
    }

    /**
     * The period chosen: one of {@see periods()} — `custom` only with two days in order, the card's
     * default otherwise — and no longer than {@see maxDays()}.
     */
    public function period(): string
    {
        $period = (string) ($this->setting('period') ?? $this->defaultPeriod());
        if ($period === 'custom') {
            $from = (string) $this->setting('from');
            $to   = (string) $this->setting('to');

            return $from !== '' && $to !== '' && $from <= $to ? 'custom' : $this->defaultPeriod();
        }
        if (!in_array($period, static::periods(), true)) {
            $period = $this->defaultPeriod();
        }

        return $this->maxDays() > 0 && is_numeric($period) && (int) $period > $this->maxDays() ? (string) $this->maxDays() : $period;
    }

    /**
     * The period as the analyst's tools take it: `['days' => n]`, or `['from' => …, 'to' => …]`.
     *
     * @return array<string, int|string>
     */
    public function window(): array
    {
        return match ($this->period()) {
            'custom' => ['from' => (string) $this->setting('from'), 'to' => (string) $this->setting('to')],
            'today'  => ['days' => 1],
            default  => ['days' => (int) $this->period()],
        };
    }

    /**
     * The period as two moments, for a query of the card's own: from the start of its first day
     * (or n days ago) to the end of its last (or now).
     *
     * @return array{0: int, 1: int}
     */
    public function periodRange(): array
    {
        if ($this->period() === 'custom') {
            return [(int) strtotime((string) $this->setting('from') . ' 00:00:00'), (int) strtotime((string) $this->setting('to') . ' 23:59:59')];
        }
        $days = $this->period() === 'today' ? 1 : (int) $this->period();

        return [time() - $days * 86400, time()];
    }

    /** The period as people read it, translated: "Last 7 days", "Today", or the two days. */
    public function periodLabel(): string
    {
        return match ($this->period()) {
            'today'  => Widgets\Figures::words('Today'),
            'custom' => date('d/m/Y', (int) strtotime((string) $this->setting('from'))) . ' – ' . date('d/m/Y', (int) strtotime((string) $this->setting('to'))),
            default  => sprintf(Widgets\Figures::words('Last %d days'), (int) $this->period()),
        };
    }
}
