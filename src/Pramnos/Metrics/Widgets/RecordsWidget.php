<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Metrics\Metrics;

/**
 * The records behind a metric in the report's period — the orders, the sign-ups — where the metric
 * lists them (`rows()` in its declaration): the first ones as a table, and a link to all of them.
 *
 * Settings: `title`, `metric`, `filters`, `limit`.
 */
class RecordsWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.rows';
    }

    protected function name(): string
    {
        return 'Records';
    }

    public function description(): string
    {
        return Figures::words('The records behind a metric, in the report\'s period');
    }

    public function options(): array
    {
        return [
            'title'   => self::titleOption(),
            'metric'  => ['label' => 'Metric', 'values' => ['' => Figures::words('Choose…')] + self::metricChoices(static fn (\Pramnos\Metrics\Metric $m): bool => $m->hasRows())],
            'filters' => self::filtersField(),
            'limit'   => ['label' => 'Shown', 'values' => ['10' => '10', '25' => '25', '50' => '50', '100' => '100']],
        ];
    }

    public function render(): string
    {
        $name   = (string) $this->setting('metric');
        $report = $this->report();
        $metric = Metrics::all($report['user'], false)[$name] ?? null;
        if ($metric === null || !$metric->hasRows()) {
            return self::note(Figures::words($name === '' ? 'Choose a metric in the card\'s settings.' : 'This metric is not available to you.'));
        }
        $filters = (array) ($this->setting('filters') ?? []);
        $window  = $report['window'];
        try {
            $rows = Metrics::records($name, $window->grain, $window->key(), Metrics::scoped($name, $filters, $report['user'], null, true));
        } catch (\DomainException $e) {
            return self::note($e->getMessage());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Report records of ' . $name . ' failed: ' . $e->getMessage(), 'metrics');

            return self::note(Figures::words('The records could not be read: the log has why.'));
        }
        if ($rows === []) {
            return self::note(Figures::words('None in this period.'));
        }
        $e     = static fn ($v): string => htmlspecialchars(is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), ENT_QUOTES, 'UTF-8');
        $shown = array_slice($rows, 0, (int) ($this->setting('limit') ?? 10));
        $html  = '<div class="pf-report-scroll"><table class="pf-report table table-sm"><thead><tr>';
        foreach (array_keys((array) $shown[0]) as $column) {
            $html .= '<th scope="col">' . $e($column) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($shown as $row) {
            $html .= '<tr>';
            foreach ((array) $row as $value) {
                $html .= '<td>' . $e($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        if ($report['rowsUrl'] !== null && !$report['static']) {
            $query = http_build_query(['metric' => $name, 'grain' => $window->grain, 'period' => $window->key(), 'f' => $filters]);
            $html .= '<p><a href="' . $e($report['rowsUrl'] . (str_contains((string) $report['rowsUrl'], '?') ? '&' : '?') . $query) . '">'
                . $e(sprintf(Figures::words('All %d'), count($rows))) . '</a></p>';
        }

        return $html;
    }
}
