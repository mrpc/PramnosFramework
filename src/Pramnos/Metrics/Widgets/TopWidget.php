<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Dashboard\Widgets\MetricCard;
use Pramnos\Metrics\Metrics;

/**
 * The largest values of a breakdown in the report's period — the ten areas with the most orders —
 * each with a bar to the largest.
 *
 * Settings: `title`, `metric`, `dimension`, `count` (5, 10, 20), `filters`.
 */
class TopWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.top';
    }

    protected function name(): string
    {
        return 'Top values';
    }

    public function description(): string
    {
        return Figures::words('The largest values of a breakdown, ranked');
    }

    public function options(): array
    {
        $dimensions = ['' => Figures::words('Choose…')];
        foreach (Metrics::dimensions() as $name => $dimension) {
            $dimensions[(string) $name] = Figures::words($dimension->label);
        }

        return [
            'title'     => self::titleOption(),
            'metric'    => ['label' => 'Metric', 'values' => ['' => Figures::words('Choose…')] + self::metricChoices()],
            'dimension' => ['label' => 'Broken down by', 'values' => $dimensions],
            'count'     => ['label' => 'How many', 'values' => ['10' => '10', '5' => '5', '20' => '20']],
            'filters'   => self::filtersField(),
        ];
    }

    public function render(): string
    {
        $name      = (string) $this->setting('metric');
        $dimension = (string) $this->setting('dimension');
        $report    = $this->report();
        $metric    = Metrics::all($report['user'], false)[$name] ?? null;
        if ($metric === null || $dimension === '') {
            return self::note(Figures::words($metric === null && $name !== '' ? 'This metric is not available to you.' : 'Choose a metric and what to rank by in the card\'s settings.'));
        }
        try {
            $top = $this->read($name)->grain($report['window']->grain)->at($report['window']->key())
                ->where($this->narrowed((array) ($this->setting('filters') ?? [])))->by($dimension)->top((int) ($this->setting('count') ?? 10));
        } catch (\InvalidArgumentException|\LogicException $e) {
            return self::note($e->getMessage());
        }
        if ($top === []) {
            return self::note(Figures::words('Nothing in this period.'));
        }
        $most = max(array_map('abs', $top)) ?: 1.0;
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<table class="pf-report table table-sm"><tbody>';
        foreach ($top as $key => $value) {
            $html .= '<tr><th scope="row">' . $e(Metrics::dimensionOf($dimension)->labelOf((string) $key)) . '</th>'
                . '<td style="width:50%"><span style="display:block;height:.6em;background:currentColor;opacity:.35;width:' . round(100 * abs($value) / $most, 1) . '%"></span></td>'
                . '<td style="text-align:right">' . $e(MetricCard::format($value, $metric->getUnit())) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }
}
