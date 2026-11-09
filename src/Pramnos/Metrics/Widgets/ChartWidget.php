<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Metrics\ChartImage;
use Pramnos\Metrics\Report;
use Pramnos\Metrics\Window;

/**
 * A chart on a report: series of metrics (each with filters, or broken down by a dimension) over
 * the last N periods up to the report's, as lines or bars — and, on request, the same periods a
 * year earlier beside them.
 *
 * Drawn for a mail or a file, it is a PNG where the server can make one ({@see ChartImage}), and
 * its numbers as a table otherwise.
 *
 * Settings: `title`, `series` (a list: label, metric, filters, breakdown), `chart` (line, bar),
 * `periods`, `year`.
 */
class ChartWidget extends TableWidget
{
    public function id(): string
    {
        return 'report.chart';
    }

    protected function name(): string
    {
        return 'Chart';
    }

    public function description(): string
    {
        return Figures::words('Metrics over time, lines or bars, beside the year before');
    }

    public function options(): array
    {
        return [
            'title'   => self::titleOption(),
            'series'  => ['label' => 'Series', 'input' => 'list', 'fields' => self::rowFields(false), 'clean' => static fn (mixed $v): ?array => self::cleanRows($v)],
            'chart'   => ['label' => 'Chart', 'values' => ['line' => Figures::words('Line'), 'bar' => Figures::words('Bars')]],
            'periods' => ['label' => 'Periods', 'input' => 'number', 'clean' => static fn (mixed $v): ?int => is_numeric($v) ? max(2, min(Report::MAX_COLUMNS, (int) $v)) : null],
            'year'    => ['label' => 'The year before', 'values' => ['' => Figures::words('No'), '1' => Figures::words('Beside it')]],
        ];
    }

    /** The series are the table's rows. */
    public function setting(string $name): mixed
    {
        return parent::setting($name === 'rows' ? 'series' : $name);
    }

    /** No table of a chart in the report's CSV. */
    public function table(): ?array
    {
        return null;
    }

    public function render(): string
    {
        $periods = (int) ($this->setting('periods') ?? 12);
        $type    = (string) ($this->setting('chart') ?? 'line');
        $now     = $this->built(['periods' => $periods], $type);
        if ($now === null) {
            return self::note(Figures::words('Add a series: choose a metric in the card\'s settings.'));
        }
        $sets = self::sets($now, '');
        if ((string) $this->setting('year') === '1') {
            $report  = $this->report();
            $earlier = Window::of($report['grain'], $report['at'])->yearEarlier();
            $before  = $this->built(['periods' => $periods], $type, $earlier->key());
            $sets    = array_merge($sets, self::sets((array) $before, ' (' . $earlier->label() . ')'));
        }
        $labels = $now['columns'];
        if ($this->report()['static']) {
            $png = ChartImage::png($type, $labels, $sets, $this->heading());
            if ($png !== null) {
                return '<img src="' . htmlspecialchars(\Pramnos\Metrics\ReportBoard::image($png), ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($this->title(), ENT_QUOTES, 'UTF-8') . '" width="' . ChartImage::WIDTH . '" style="max-width:100%;height:auto">';
            }

            // No image: the numbers, each series a row — the year before among them.
            return self::numbers($labels, $sets);
        }

        return \Pramnos\Html\Chart::render($type, $labels, $sets, ['title' => $this->heading(), 'height' => 260]);
    }

    /**
     * The chart's numbers as a table, for a mail without its image.
     *
     * @param list<string>                                  $labels
     * @param list<array{label: string, data: list<?float>}> $sets
     */
    private static function numbers(array $labels, array $sets): string
    {
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<div class="pf-report-scroll"><table class="pf-report table table-sm"><thead><tr><th scope="col"></th>';
        foreach ($labels as $label) {
            $html .= '<th scope="col">' . $e($label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($sets as $set) {
            $html .= '<tr><th scope="row">' . $e($set['label']) . '</th>';
            foreach ($set['data'] as $value) {
                $html .= '<td>' . ($value === null ? '—' : $e(rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ','))) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /** A built report's rows as datasets, each label followed by `$suffix`. @return list<array{label: string, data: list<?float>}> */
    private static function sets(array $built, string $suffix): array
    {
        $sets = [];
        foreach ($built['sections'][0]['rows'] ?? [] as $row) {
            if ($row['error'] === null) {
                $sets[] = ['label' => $row['label'] . $suffix, 'data' => array_column($row['cells'], 'value')];
            }
        }

        return $sets;
    }
}
