<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Metrics\Goals;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * Progress towards a target in a day or a month: the actual so far, what was expected by now along
 * the reference's curve, and the forecast ({@see Goals::trace()}) — as a bar, or as a chart of the
 * way there, hour by hour: the actual so far, the curve expected, the forecast and the target.
 * Nothing is said where the reference has a hole.
 *
 * The target is the card's own (`target`), or relative to the reference period (`growth`, a
 * percentage: 10 is the same day last year plus 10%), or else the goal kept for the metric
 * ({@see Goals::for()}). On a report it reads the report's period; on the dashboard, today (or
 * this month, with `grain`).
 *
 * Settings: `title`, `metric`, `target`, `growth`, `reference` (`lastyear`, `weekdays`), `grain`,
 * `show` (`bar`, `chart`), `filters`.
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
            'growth'    => ['label' => 'Or: growth over the reference (%)', 'input' => 'number', 'clean' => static fn (mixed $v): ?float => is_numeric($v) ? max(-100.0, (float) $v) : null],
            'reference' => ['label' => 'Expected as', 'values' => [Goals::LAST_YEAR => Figures::words('The same day last year'), Goals::WEEKDAYS => Figures::words('The same weekday, the last four weeks')]],
            'grain'     => ['label' => 'On the dashboard', 'values' => [Window::DAY => Figures::words('Today'), Window::MONTH => Figures::words('This month')]],
            'show'      => ['label' => 'Show', 'values' => ['bar' => Figures::words('A bar'), 'chart' => Figures::words('The way there, hour by hour')]],
            'filters'   => self::filtersField(),
        ];
    }

    public function render(): string
    {
        $name   = (string) $this->setting('metric');
        $report = $this->report();
        $metric = Metrics::all($report['user'], false)[$name] ?? null;
        if ($metric === null) {
            return self::note(Figures::words('Choose a metric and a target in the card\'s settings.'));
        }
        // A report's period; elsewhere today, or this month.
        $window = $report['entry'] !== null ? $report['window']
            : Window::of((string) ($this->setting('grain') ?: Window::DAY) === Window::MONTH ? Window::MONTH : Window::DAY, date('Y-m-d'));
        if (!in_array($window->grain, [Window::DAY, Window::MONTH], true)) {
            return self::note(Figures::words('A goal is for a day or a month.'));
        }
        $goal = $this->goal($name, $window, (int) ($report['tenant'] ?? 0));
        if ($goal === null) {
            return self::note(Figures::words('Choose a metric and a target in the card\'s settings.'));
        }
        if (!Metrics::withinScope($name, $goal['filters'], $report['user'])) {
            return self::note(Figures::words('This goal is for more than the figures you may read.'));
        }
        $trace = Goals::trace($goal);
        if ($trace === null) {
            return self::note(Figures::words('No curve to judge by: the reference period is not all there.'));
        }
        $currency = $metric->getCurrency((int) ($report['tenant'] ?? 0));
        $show     = static fn (float $v): string => $metric->display($v, $currency);
        $title    = $this->heading() !== '' ? $this->heading() : Figures::words($metric->label());
        $tile     = \Pramnos\Html\StatTile::render(
            $title,
            $show($trace['actual']) . ' / ' . $show($trace['target']),
            sprintf(Figures::words('%s expected by now · %s forecast'), $show($trace['expected']), $trace['forecast'] === null ? '—' : $show($trace['forecast'])),
        );
        if ((string) $this->setting('show') === 'chart') {
            $chart = $this->chart($trace, $title, $currency);
            if ($chart !== null) {
                return $tile . $chart;
            }
        }
        $target = max($trace['target'], 0.000001);
        $done   = min(100.0, 100 * $trace['actual'] / $target);
        $due    = min(100.0, 100 * $trace['expected'] / $target);

        return $tile . '<div role="img" aria-label="' . htmlspecialchars(round($done) . '%', ENT_QUOTES) . '" style="position:relative;height:.5em;background:rgba(127,127,127,.2);margin-top:.35rem">'
            . '<span style="position:absolute;inset:0 auto 0 0;width:' . round($done, 1) . '%;background:currentColor;opacity:.55"></span>'
            . '<span style="position:absolute;top:-.2em;bottom:-.2em;left:' . round($due, 1) . '%;border-left:2px dashed currentColor"></span></div>';
    }

    /**
     * The goal the card shows: its own target, its own growth over the reference, or the goal kept
     * for the metric's period — or null with none of them.
     *
     * @return array<string, mixed>|null
     */
    private function goal(string $name, Window $window, int $tenant): ?array
    {
        $target = (float) ($this->setting('target') ?? 0);
        $growth = $this->setting('growth');
        if ($target > 0 || is_numeric($growth)) {
            return ['id' => 0, 'metric' => $name, 'grain' => $window->grain, 'period' => $window->key(), 'tenant' => $tenant,
                'filters' => $this->narrowed((array) ($this->setting('filters') ?? [])), 'target' => $target,
                'growth' => $target > 0 ? null : (float) $growth / 100, 'reference' => (string) ($this->setting('reference') ?: Goals::LAST_YEAR)];
        }
        $kept = Goals::for($name, $window->grain, $window->key(), $tenant);

        return $kept === null ? null : ['period' => $window->key()] + $kept;
    }

    /**
     * The way there as a chart — an image in a mail — or null where a mail's image cannot be made.
     *
     * @param array<string, mixed> $trace From {@see Goals::trace()}
     */
    private function chart(array $trace, string $title, ?string $currency): ?string
    {
        $labels = array_column($trace['steps'], 'label');
        $sets   = [
            ['label' => Figures::words('So far'), 'data' => array_column($trace['steps'], 'actual')],
            ['label' => Figures::words('Expected'), 'data' => array_column($trace['steps'], 'expected')],
            ['label' => Figures::words('Forecast'), 'data' => array_column($trace['steps'], 'forecast')],
            ['label' => Figures::words('Target'), 'data' => array_fill(0, count($labels), $trace['target'])],
        ];
        if ($this->report()['static']) {
            $png = \Pramnos\Metrics\ChartImage::png('line', $labels, $sets, $title);

            return $png === null ? null : '<img src="' . htmlspecialchars(\Pramnos\Metrics\ReportBoard::image($png), ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                . '" width="' . \Pramnos\Metrics\ChartImage::WIDTH . '" style="max-width:100%;height:auto">';
        }

        return \Pramnos\Html\Chart::render('line', $labels, $sets, ['height' => 200] + ($currency !== null ? ['format' => 'currency', 'currency' => $currency] : []));
    }
}
