<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Metrics\Metrics;

/**
 * One figure on a report: a metric's value in the report's period, with filters, and its change
 * against the period before, a year earlier, or as the report compares.
 *
 * Settings: `title`, `metric`, `filters`, `compare` (`report`, `none`, `previous`, `year`).
 */
class KpiWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.kpi';
    }

    protected function name(): string
    {
        return 'Figure';
    }

    public function description(): string
    {
        return Figures::words('One metric\'s value, and its change');
    }

    public function size(): string
    {
        return self::SMALL;
    }

    public function options(): array
    {
        return [
            'title'   => self::titleOption(),
            'metric'  => ['label' => 'Metric', 'values' => ['' => Figures::words('Choose…')] + self::metricChoices()],
            'filters' => self::filtersField(),
            'compare' => ['label' => 'Compare with', 'values' => [
                'report'             => Figures::words('As the report'),
                'none'               => Figures::words('Nothing'),
                Metrics::PREVIOUS     => Figures::words('The period before'),
                Metrics::YEAR_EARLIER => Figures::words('A year earlier'),
            ]],
        ];
    }

    public function render(): string
    {
        $name   = (string) $this->setting('metric');
        $report = $this->report();
        $metric = Metrics::all($report['user'], false)[$name] ?? null;
        if ($metric === null) {
            return self::note(Figures::words($name === '' ? 'Choose a metric in the card\'s settings.' : 'This metric is not available to you.'));
        }
        $filters = $this->narrowed((array) ($this->setting('filters') ?? []));
        $compare = (string) ($this->setting('compare') ?? 'report');
        $against = $compare === 'report' ? (string) $report['compare'] : ($compare === 'none' ? '' : $compare);
        $window  = $report['window'];
        $note    = $window->label();
        try {
            $query = $this->read($name)->grain($window->grain)->at($window->key())->where($filters);
            if ($against !== '') {
                $result = $query->compare($against)->compared();
                $value  = $result['value'];
                if ($result['change'] !== null) {
                    $note .= ' · ' . sprintf(Figures::words('%s against %s'), ($result['change'] >= 0 ? '+' : '') . \Pramnos\General\NumberFormat::number($result['change'] * 100, 1) . '%', $result['against']);
                }
            } else {
                $value = $query->value();
            }
        } catch (\InvalidArgumentException|\LogicException $e) {
            return self::note($e->getMessage());
        }

        return \Pramnos\Html\StatTile::render(
            $this->heading() !== '' ? $this->heading() : Figures::words($metric->label()),
            $value === null ? '—' : $metric->display((float) $value, $query->currency()),
            $note,
        );
    }
}
