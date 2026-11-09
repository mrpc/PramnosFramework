<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Dashboard\Widgets\MetricCard;
use Pramnos\Metrics\Goals;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * Progress towards a target in the report's period — a day or a month: the actual so far, what
 * was expected by now along the reference's curve, and the forecast ({@see Goals::progress()}).
 * Nothing is said where the reference has a hole.
 *
 * Settings: `title`, `metric`, `target`, `reference` (`lastyear`, `weekdays`), `filters`.
 */
class GoalWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.goal';
    }

    protected function name(): string
    {
        return 'Goal';
    }

    public function description(): string
    {
        return Figures::words('How near a target is, against the curve of the same time last year');
    }

    public function size(): string
    {
        return self::SMALL;
    }

    public function options(): array
    {
        return [
            'title'     => self::titleOption(),
            'metric'    => ['label' => 'Metric', 'values' => ['' => Figures::words('Choose…')] + self::metricChoices()],
            'target'    => ['label' => 'Target', 'input' => 'number', 'clean' => static fn (mixed $v): ?float => is_numeric($v) && (float) $v > 0 ? (float) $v : null],
            'reference' => ['label' => 'Expected as', 'values' => [Goals::LAST_YEAR => Figures::words('The same day last year'), Goals::WEEKDAYS => Figures::words('The same weekday, the last four weeks')]],
            'filters'   => self::filtersField(),
        ];
    }

    public function render(): string
    {
        $name   = (string) $this->setting('metric');
        $target = (float) ($this->setting('target') ?? 0);
        $report = $this->report();
        $metric = Metrics::all($report['user'], false)[$name] ?? null;
        if ($metric === null || $target <= 0) {
            return self::note(Figures::words('Choose a metric and a target in the card\'s settings.'));
        }
        $window = $report['window'];
        if (!in_array($window->grain, [Window::DAY, Window::MONTH], true)) {
            return self::note(Figures::words('A goal is for a day or a month.'));
        }
        $progress = Goals::progress(['id' => 0, 'metric' => $name, 'grain' => $window->grain, 'period' => $window->key(), 'tenant' => (int) ($report['tenant'] ?? 0),
            'filters' => $this->narrowed((array) ($this->setting('filters') ?? [])), 'target' => $target, 'reference' => (string) ($this->setting('reference') ?? Goals::LAST_YEAR)]);
        if ($progress === null) {
            return self::note(Figures::words('No curve to judge by: the reference period is not all there.'));
        }
        $unit = $metric->getUnit();
        $done = min(100.0, 100 * $progress['actual'] / $target);
        $due  = min(100.0, 100 * $progress['expected'] / $target);

        return \Pramnos\Html\StatTile::render(
            $this->heading() !== '' ? $this->heading() : Figures::words($metric->label()),
            MetricCard::format($progress['actual'], $unit) . ' / ' . MetricCard::format($target, $unit),
            sprintf(Figures::words('%s expected by now · %s forecast'), MetricCard::format($progress['expected'], $unit), $progress['forecast'] === null ? '—' : MetricCard::format($progress['forecast'], $unit)),
        ) . '<div role="img" aria-label="' . htmlspecialchars(round($done) . '%', ENT_QUOTES) . '" style="position:relative;height:.5em;background:rgba(127,127,127,.2);margin-top:.35rem">'
            . '<span style="position:absolute;inset:0 auto 0 0;width:' . round($done, 1) . '%;background:currentColor;opacity:.55"></span>'
            . '<span style="position:absolute;top:-.2em;bottom:-.2em;left:' . round($due, 1) . '%;border-left:2px dashed currentColor"></span></div>';
    }
}
