<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * One way to read a metric, for every reader — a card, a report, the JSON API, an export, the
 * assistant — so that who may read what, and of which organisation, is applied once:
 *
 * ```php
 * Metrics::query('payments.amount')
 *     ->grain('month')->between('2026-01-01', '2026-10-31')
 *     ->by('market')->where(['package' => ['premium', 'plus']])
 *     ->for($user)                     // allowed(), and the organisations the viewer may read
 *     ->series();                      // or ->value(), ->compared(), ->table(), ->top(10)
 * ```
 *
 * `for($user)` refuses a metric the viewer may not read ({@see Metric::allowed()}) and narrows the
 * values to the organisations `metrics.tenant` gives them, and to the dimension values
 * `metrics.scope` gives them ({@see Metrics::scoped()}); `tenant($id)` narrows further to one.
 * Without `for()` every organisation is read, as a scheduled job reads them. A period never
 * computed is null in every answer.
 */
final class Query
{
    private string $grain = Window::MONTH;

    private ?string $from = null;

    private ?string $to = null;

    private ?string $by = null;

    /** @var array<string, string|list<string>> */
    private array $filters = [];

    private string $against = Metrics::PREVIOUS;

    /** @var list<int>|null */
    private ?array $tenants = null;

    /** Whose scope the filters are narrowed to ({@see for()}); false: nobody's, every value. */
    private object|false|null $viewer = false;

    /** The definition's version read; null: the metric's own. */
    private ?int $version = null;

    /** A query of one metric; {@see Metrics::query()}. */
    public function __construct(public readonly string $metric)
    {
        Metrics::get($metric);
    }

    /** The grain: day, week, month, quarter or year. */
    public function grain(string $grain): self
    {
        Window::of($grain, 'now');
        $this->grain = $grain;

        return $this;
    }

    /** The periods from the one holding `$from` to the one holding `$to`. */
    public function between(string $from, string $to): self
    {
        [$this->from, $this->to] = [$from, $to];

        return $this;
    }

    /** One period: the one holding `$at`. */
    public function at(string $at): self
    {
        return $this->between($at, $at);
    }

    /** Broken down by one of the metric's dimensions; null for the total. */
    public function by(?string $dimension): self
    {
        $this->by = $dimension;

        return $this;
    }

    /** @param array<string, string|list<string>> $filters Dimension => value, or a list of values */
    public function where(array $filters): self
    {
        $this->filters = $filters + $this->filters;

        return $this;
    }

    /**
     * Read the figures of another version of the metric's definition — `version(1)` for "as
     * before", where the metric was corrected to version 2.
     */
    public function version(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    /** What {@see compared()} compares with: Metrics::PREVIOUS or Metrics::YEAR_EARLIER. */
    public function compare(string $against): self
    {
        $this->against = $against;

        return $this;
    }

    /**
     * Read as this viewer: a metric they may not read is refused, and the values are only of the
     * organisations they may read.
     *
     * @throws \DomainException When the viewer may not read the metric
     */
    public function for(?object $user): self
    {
        if (!isset(Metrics::all($user, false)[$this->metric])) {
            throw new \DomainException(sprintf(t('There is no metric "%s" you may read.'), $this->metric));
        }
        $this->tenants = Metrics::tenantsOf($user);
        $this->viewer  = $user;

        return $this;
    }

    /**
     * One organisation's values, within what {@see for()} allowed.
     *
     * @throws \DomainException When the viewer may not read that organisation
     */
    public function tenant(int $tenant): self
    {
        if ($this->tenants !== null && !in_array($tenant, $this->tenants, true)) {
            throw new \DomainException(t('That organisation\'s figures are not yours to read.'));
        }
        $this->tenants = [$tenant];

        return $this;
    }

    /**
     * The periods and the values in each: under `''` without a breakdown, under each value of
     * the dimension with one.
     *
     * @return array{periods: list<Window>, values: array<string, list<float|null>>}
     */
    public function series(): array
    {
        $periods = $this->periods();

        return ['periods' => $periods, 'values' => Metrics::read($this->metric, $periods, $this->filters($this->by), $this->by, $this->tenants, $this->version)];
    }

    /**
     * Why periods asked have no figure, by period key — before the source's data begins, or as
     * the metric says (`since()`, `missing()`) — for a reader to show the reason, not a nought.
     *
     * @return array<string, string>
     */
    public function gaps(): array
    {
        $metric = Metrics::get($this->metric);
        $out    = [];
        foreach ($this->periods() as $window) {
            $why = $metric->gapOf($window);
            if ($why !== null) {
                $out[$window->key()] = $why;
            }
        }

        return $out;
    }

    /** The value of the last period asked, or null when it was never computed. */
    public function value(): ?float
    {
        $periods = $this->periods();
        $values  = Metrics::read($this->metric, [end($periods)], $this->filters(), null, $this->tenants, $this->version);

        return $values[''][0] ?? null;
    }

    /**
     * The last period asked against the one before it or a year earlier.
     *
     * @return array{period: string, value: ?float, against: string, other: ?float, difference: ?float, change: ?float}
     */
    public function compared(): array
    {
        $periods = $this->periods();
        $window  = end($periods);
        $other   = $this->against === Metrics::YEAR_EARLIER ? $window->yearEarlier() : $window->previous();
        $values  = Metrics::read($this->metric, [$window, $other], $this->filters(), null, $this->tenants, $this->version);
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
     * The series as rows, for an export or the JSON API: one per period and breakdown value.
     *
     * @return list<array{period: string, key: string, value: ?float}>
     */
    public function table(): array
    {
        $series = $this->series();
        $rows   = [];
        foreach ($series['values'] as $key => $values) {
            foreach ($series['periods'] as $i => $window) {
                $rows[] = ['period' => $window->key(), 'key' => (string) $key, 'value' => $values[$i] ?? null];
            }
        }

        return $rows;
    }

    /**
     * The breakdown's largest values in the last period asked, largest first.
     *
     * @return array<string, float>
     */
    public function top(int $count): array
    {
        if ($this->by === null) {
            throw new \LogicException(t('Choose a dimension to rank by.'));
        }
        $periods = $this->periods();
        $last    = [];
        foreach (Metrics::read($this->metric, [end($periods)], $this->filters($this->by), $this->by, $this->tenants, $this->version) as $key => $values) {
            if (($values[0] ?? null) !== null) {
                $last[(string) $key] = (float) $values[0];
            }
        }
        arsort($last);

        return array_slice($last, 0, max(0, $count), true);
    }

    /** @return array<string, string|list<string>> The filters, narrowed to the viewer's scope where read for one */
    private function filters(?string $by = null): array
    {
        return $this->viewer === false ? $this->filters : Metrics::scoped($this->metric, $this->filters, $this->viewer ?: null, $by);
    }

    /** @return list<Window> The periods asked: today's when none were. */
    private function periods(): array
    {
        $today = date('Y-m-d');

        return Window::range($this->grain, $this->from ?? $today, $this->to ?? $this->from ?? $today);
    }
}
