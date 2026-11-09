<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * A metric ({@see Metrics}) as a dashboard card: one card per metric in the catalogue, offered to
 * those the metric's `allowed()` lets see it, off a new dashboard until somebody adds it.
 *
 * Each person sets, in the card's form: the grain, how many periods it shows (one is a tile with
 * this period's value, more a chart), the chart's type, a comparison for the tile (the period
 * before, or a year earlier), a breakdown by one of the metric's dimensions, and filters
 * (`region=north, plan=basic`).
 *
 * It reads the kept values, so it is as cheap as a query on `stats.metric_values`.
 */
final class MetricCard extends Widget
{
    /** The id prefix, before the metric's name. */
    public const PREFIX = 'metric.';

    public function __construct(private readonly Metric $metric)
    {
    }

    public function id(): string
    {
        return self::PREFIX . $this->metric->name;
    }

    public function title(): string
    {
        $title = trim((string) $this->setting('title'));

        return $title !== '' ? $title : Figures::words($this->metric->label());
    }

    public function description(): string
    {
        return $this->metric->description();
    }

    public function allowed(?object $user): bool
    {
        // The viewer the board was offered to: the figures are read as theirs.
        $this->viewer = $user;

        return $this->metric->allows($user);
    }

    /** Who the card is drawn for, as {@see allowed()} was asked; null: every organisation. */
    private ?object $viewer = null;

    /** The metric read as the card's viewer. */
    private function read(): \Pramnos\Metrics\Query
    {
        $query = Metrics::query($this->metric->name);

        return $this->viewer !== null ? $query->for($this->viewer) : $query;
    }

    public function byDefault(): bool
    {
        return false;
    }

    public function size(): string
    {
        return self::SMALL;
    }

    public function options(): array
    {
        $grains = [];
        foreach ([Window::MONTH, Window::WEEK, Window::DAY, Window::QUARTER, Window::YEAR] as $grain) {
            $grains[$grain] = Figures::words(ucfirst($grain));
        }
        $breakdowns = ['' => Figures::words('None')];
        foreach ($this->metric->getDimensions() as $dimension) {
            $breakdowns[$dimension] = Figures::words(Metrics::dimensionOf($dimension)->label);
        }
        $dimensions = $this->metric->getDimensions();

        return [
            'grain'     => ['label' => 'Period', 'values' => $grains],
            'periods'   => ['label' => 'Periods shown', 'values' => ['1' => '1', '6' => '6', '12' => '12', '24' => '24']],
            'chart'     => ['label' => 'Chart', 'values' => ['line' => Figures::words('Line'), 'bar' => Figures::words('Bars')]],
            'compare'   => ['label' => 'Compare with', 'values' => ['' => Figures::words('Nothing'), Metrics::PREVIOUS => Figures::words('The period before'), Metrics::YEAR_EARLIER => Figures::words('A year earlier')]],
            'breakdown' => ['label' => 'Broken down by', 'values' => $breakdowns],
            'filters'   => ['label' => 'Filters (name=value, …)', 'input' => 'text', 'clean' => static fn (mixed $v): ?string => self::filters((string) $v, $dimensions) === [] ? null : mb_substr(trim((string) $v), 0, 200)],
            'title'     => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 80) : null,
        ];
    }

    public function render(): string
    {
        $grain   = (string) ($this->setting('grain') ?? Window::MONTH);
        $periods = max(1, (int) ($this->setting('periods') ?? 1));
        $filters = self::filters((string) $this->setting('filters'), $this->metric->getDimensions());
        $now     = Window::of($grain, time());
        try {
            if ($periods === 1) {
                return $this->tile($now, $filters);
            }
            $breakdown = (string) $this->setting('breakdown');
            $first     = $now;
            for ($i = 1; $i < $periods; $i++) {
                $first = $first->previous();
            }
            $query  = $this->read()->grain($grain)->between($first->key(), $now->key())->where($filters)->by($breakdown !== '' ? $breakdown : null);
            $series = $query->series();
        } catch (\InvalidArgumentException|\LogicException $e) {
            return '<p class="pf-muted">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
        }
        $sets = [];
        foreach ($series['values'] as $key => $values) {
            $label  = $key === '' ? Figures::words($this->metric->label()) : Metrics::dimensionOf((string) $breakdown)->labelOf((string) $key);
            $sets[] = ['label' => $label, 'data' => $values];
        }

        return \Pramnos\Html\Chart::render(
            (string) ($this->setting('chart') ?? 'line'),
            array_map(static fn (Window $w): string => $w->label(), $series['periods']),
            $sets,
            ['title' => $this->title(), 'height' => 220, 'format' => $query->currency() !== null ? 'currency' : ($this->metric->getUnit() === '%' ? 'percent' : 'number'),
                'currency' => $query->currency(), 'decimals' => $this->metric->getUnit() === '' && $query->currency() === null ? 0 : 2,
                'annotations' => \Pramnos\Metrics\Annotations::forPeriods($series['periods'])],
        );
    }

    /** This period's value, and the comparison chosen under it. @param array<string, string> $filters */
    private function tile(Window $now, array $filters): string
    {
        $against = (string) $this->setting('compare');
        $note    = $now->label();
        $query   = $this->read()->grain($now->grain)->at($now->key())->where($filters);
        if ($against !== '') {
            $compare = $query->compare($against)->compared();
            $value   = $compare['value'];
            if ($compare['change'] !== null) {
                $note .= ' · ' . sprintf(Figures::words('%s against %s'), ($compare['change'] >= 0 ? '+' : '') . \Pramnos\General\NumberFormat::number($compare['change'] * 100, 1) . '%', $compare['against']);
            }
        } else {
            $value = $query->value();
        }

        return \Pramnos\Html\StatTile::render(Figures::words($this->metric->label()), $value === null ? '—' : $this->metric->display((float) $value, $query->currency()), $note);
    }

    /** A value as people read it, with the metric's unit. */
    public static function format(float $value, string $unit): string
    {
        $decimals = $unit === '' && floor($value) === $value ? 0 : ($unit === '' ? 2 : ($unit === '%' ? 1 : 2));
        // In the reader's language: a comma for the decimals in Greek, a dot in English.
        $number   = \Pramnos\General\NumberFormat::number($value, $decimals);

        return match ($unit) {
            ''      => $number,
            '%'     => $number . '%',
            default => $number . ' ' . $unit,
        };
    }

    /**
     * Filters as written in the form, `name=value, name=value`, kept for the metric's dimensions.
     *
     * @param list<string> $dimensions
     * @return array<string, string>
     */
    public static function filters(string $text, array $dimensions): array
    {
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($text)) ?: [] as $pair) {
            [$name, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            if ($value !== '' && in_array($name, $dimensions, true)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
