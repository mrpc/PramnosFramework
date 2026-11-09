<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * A cohort metric as a grid ({@see \Pramnos\Metrics\Metric::cohort()}): each of the last periods a
 * row — its cohort's size, and the share that reached the event within each span.
 *
 * Settings: `title`, `metric` (a cohort), `periods`.
 */
class CohortWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.cohorts';
    }

    protected function name(): string
    {
        return 'Cohorts';
    }

    public function description(): string
    {
        return Figures::words('Of those who began in each period, the share that reached the event within each span');
    }

    public function options(): array
    {
        return [
            'title'   => self::titleOption(),
            'metric'  => ['label' => 'Metric', 'values' => ['' => Figures::words('Choose…')] + self::metricChoices(static fn ($m): bool => in_array('within', $m->getDimensions(), true))],
            'periods' => ['label' => 'Periods', 'input' => 'number', 'clean' => static fn (mixed $v): ?int => is_numeric($v) ? max(1, min(24, (int) $v)) : null],
        ];
    }

    public function render(): string
    {
        $name   = (string) $this->setting('metric');
        $report = $this->report();
        $metric = Metrics::all($report['user'], false)[$name] ?? null;
        if ($metric === null || !in_array('within', $metric->getDimensions(), true)) {
            return self::note(Figures::words('Choose a cohort metric in the card\'s settings.'));
        }
        $last  = $report['window'];
        $first = $last;
        for ($i = 1; $i < (int) ($this->setting('periods') ?? 6); $i++) {
            $first = $first->previous();
        }
        try {
            $query  = $this->read($name)->grain($last->grain)->between($first->key(), $last->key())->where($this->narrowed([]));
            $sizes  = $query->series();
            $within = (clone $query)->by('within')->series()['values'];
        } catch (\InvalidArgumentException|\LogicException $e) {
            return self::note($e->getMessage());
        }
        $spans = array_keys($within);
        usort($spans, static fn ($a, $b): int => (int) $a <=> (int) $b);
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<table class="pf-report table table-sm"><thead><tr><th scope="col"></th><th scope="col" style="text-align:right">' . $e(Figures::words('Size')) . '</th>';
        foreach ($spans as $span) {
            $html .= '<th scope="col" style="text-align:right">' . $e(sprintf(Figures::words('%s days'), (string) $span)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($sizes['periods'] as $i => $window) {
            $size  = $sizes['values'][''][$i] ?? null;
            $html .= '<tr><th scope="row">' . $e($window->label()) . '</th><td style="text-align:right">' . ($size === null ? '—' : $e((string) (int) $size)) . '</td>';
            foreach ($spans as $span) {
                $reached = $within[$span][$i] ?? null;
                $html   .= '<td style="text-align:right">' . ($size === null || $size <= 0 || $reached === null ? '—' : $e(\Pramnos\General\NumberFormat::number(100 * $reached / $size, 1) . '%')) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }
}
