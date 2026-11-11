<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Report;

/**
 * A table of metrics on a report: rows across columns, as {@see Report} builds them — a row a
 * metric (with filters, or broken down by a dimension, with its total), the columns the report's
 * or the table's own: the last N periods, a dimension's values, or columns of their own (a label,
 * filters, another metric, an earlier period). With the report's comparison, its change; a cell
 * whose metric lists its records links to them; a total row on request.
 *
 * Settings: `title`, `rows` (a list: label, metric, filters, breakdown, total), `columns` (`report`,
 * `periods`, `dimension`, `custom`) with `periods`, `dimension`, `values`, `column_total` and
 * `custom` (a list: label, metric, filters, offset), and `total`.
 */
class TableWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.table';
    }

    protected function name(): string
    {
        return 'Table of metrics';
    }

    public function description(): string
    {
        return Figures::words('Metrics as rows, periods or values as columns');
    }

    public function options(): array
    {
        $dimensions = ['' => Figures::words('Choose…')];
        foreach (Metrics::dimensions() as $name => $dimension) {
            $dimensions[(string) $name] = Figures::words($dimension->label);
        }

        return [
            'title'        => self::titleOption(),
            'rows'         => ['label' => 'Rows', 'input' => 'list', 'fields' => self::rowFields(true), 'clean' => static fn (mixed $v): ?array => self::cleanRows($v)],
            'columns'      => ['label' => 'Columns', 'values' => [
                'report'    => Figures::words('As the report'),
                'periods'   => Figures::words('The last periods'),
                'dimension' => Figures::words('A dimension\'s values'),
                'custom'    => Figures::words('Columns of my own'),
            ]],
            'periods'      => ['label' => 'Periods', 'input' => 'number', 'when' => ['columns' => 'periods'], 'clean' => static fn (mixed $v): ?int => is_numeric($v) ? max(1, min(Report::MAX_COLUMNS, (int) $v)) : null],
            'dimension'    => ['label' => 'Dimension', 'values' => $dimensions, 'when' => ['columns' => 'dimension']],
            'values'       => ['label' => 'Values (all if none)', 'input' => 'text', 'when' => ['columns' => 'dimension'], 'clean' => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 300) : null],
            'column_total' => ['label' => 'Total column', 'values' => ['1' => Figures::words('Yes'), '' => Figures::words('No')], 'when' => ['columns' => 'dimension']],
            'custom'       => ['label' => 'Columns', 'input' => 'list', 'when' => ['columns' => 'custom'], 'fields' => [
                'label'   => ['label' => 'Label', 'input' => 'text'],
                'metric'  => ['label' => 'Metric (the row\'s if none)', 'input' => 'select', 'values' => self::metricChoices()],
                'filters' => self::filtersField('Filters', null),
                'offset'  => ['label' => 'Periods back', 'input' => 'number'],
            ], 'clean' => static fn (mixed $v): ?array => self::cleanList($v, static fn (array $c): array => [
                'label'   => mb_substr(trim((string) ($c['label'] ?? '')), 0, 80),
                'metric'  => is_string($c['metric'] ?? null) ? $c['metric'] : '',
                'filters' => self::cleanFilters($c['filters'] ?? []),
                'offset'  => -abs((int) ($c['offset'] ?? 0)),
            ], Report::MAX_COLUMNS)],
            'total'        => ['label' => 'Total row', 'values' => ['' => Figures::words('No'), '1' => Figures::words('Yes')]],
            // A table of measures, as a report's definition sets it (Report::measuresOf()): kept,
            // never drawn in the card's form — `when` a setting no form has.
            'by'           => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?string => is_string($v) && preg_match('/^[a-z][a-z0-9_]*$/', $v) === 1 ? $v : null],
            'by_values'    => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?array => is_array($v) ? array_values(array_map('strval', array_filter($v, 'is_scalar'))) : null],
            'measures'     => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?array => is_array($v) && $v !== [] ? array_values(array_filter($v, 'is_array')) : null],
            'filters'      => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?array => is_array($v) ? self::cleanFilters($v) : null],
            // A section's note, its link and its issue, as a report's definition sets them.
            'note'         => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 2000) : null],
            'link'         => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?string => is_string($v) && preg_match('#^(https?://|/)#i', $v) === 1 ? $v : null],
            'hide_empty'   => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?string => !empty($v) && $v !== 'false' ? '1' : null],
            'own_columns'  => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?array => is_array($v) && $v !== [] ? $v : null],
            'jira'         => ['when' => ['measures_from_definition' => '1'], 'clean' => static fn (mixed $v): ?string => is_string($v) && preg_match('/^[A-Za-z][A-Za-z0-9_]*-\d+$/', trim($v)) === 1 ? trim($v) : null],
        ];
    }

    /**
     * A row's fields: label, metric, filters, breakdown — and its total, for a table.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function rowFields(bool $total): array
    {
        $fields = [
            'label'     => ['label' => 'Label', 'input' => 'text'],
            'metric'    => ['label' => 'Metric', 'input' => 'select', 'values' => self::metricChoices()],
            'filters'   => self::filtersField(),
            'breakdown' => ['label' => 'Broken down by', 'input' => 'select', 'by' => 'metric', 'valuesBy' => self::breakdownsBy(), 'blank' => Figures::words('None')],
        ];
        if ($total) {
            $fields['total'] = ['label' => 'With its total', 'input' => 'checkbox'];
        }

        return $fields;
    }

    /** Rows as kept: each with a metric. @return list<array<string, mixed>>|null */
    protected static function cleanRows(mixed $value): ?array
    {
        return self::cleanList($value, static function (array $row): ?array {
            // A heading inside the table, over the rows after it: words, no metric.
            $heading = trim((string) ($row['heading'] ?? ''));
            if ($heading !== '') {
                return ['label' => mb_substr($heading, 0, 120), 'heading' => mb_substr($heading, 0, 120), 'metric' => '', 'filters' => [], 'breakdown' => '', 'total' => false];
            }
            $metric = trim((string) ($row['metric'] ?? ''));
            if ($metric === '' || preg_match('/^[a-z0-9_.:-]{1,100}$/i', $metric) !== 1) {
                return null;
            }
            $breakdown = trim((string) ($row['breakdown'] ?? ''));

            return [
                'label'     => mb_substr(trim((string) ($row['label'] ?? '')), 0, 120),
                'metric'    => $metric,
                'filters'   => self::cleanFilters($row['filters'] ?? []),
                'breakdown' => preg_match('/^[a-z][a-z0-9_]*$/', $breakdown) === 1 ? $breakdown : '',
                'total'     => !empty($row['total']) && $row['total'] !== 'false',
            // What a report's definition gives a row beyond the form's fields — a formula over its
            // operands, a description and a link — kept, for Report::fromArray() to check.
            ] + array_intersect_key($row, array_flip(['formula', 'operands', 'unit', 'description', 'link', 'format', 'decimals', 'zero', 'values', 'hide_empty']));
        });
    }

    /** The columns chosen, as {@see Report::fromArray()} takes them. @return array<string, mixed>|list<array<string, mixed>> */
    protected function columns(): array
    {
        // Columns a report's definition gave in a shape the form has no field for: as they are.
        if (is_array($this->setting('own_columns'))) {
            return $this->setting('own_columns');
        }

        return $this->onlyChosen(match ((string) $this->setting('columns')) {
            'periods'   => ['periods' => (int) ($this->setting('periods') ?? 12)],
            'dimension' => (string) $this->setting('dimension') !== ''
                ? ['dimension' => (string) $this->setting('dimension'), 'values' => array_values(array_filter(array_map('trim', explode(',', (string) $this->setting('values'))), 'strlen')), 'total' => (string) $this->setting('column_total') === '1']
                : $this->report()['columns'],
            'custom'    => (array) ($this->setting('custom') ?? []) ?: $this->report()['columns'],
            default     => $this->report()['columns'],
        });
    }

    /**
     * A dimension's columns, only those of the values the page is narrowed to — a board's
     * variable (`?v[market]=el`), the viewer's scope — and no total beside a single one.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $columns
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function onlyChosen(array $columns): array
    {
        if (!isset($columns['dimension'])) {
            return $columns;
        }
        $name   = (string) $columns['dimension'];
        $report = $this->report();
        $chosen = null;
        foreach ([$report['filters'][$name] ?? null, (Metrics::scopeOf($report['user'] ?? null) ?? [])[$name] ?? null] as $narrow) {
            if ($narrow !== null && $narrow !== '' && $narrow !== []) {
                $narrow = array_map('strval', (array) $narrow);
                $chosen = $chosen === null ? $narrow : array_values(array_intersect($chosen, $narrow));
            }
        }
        if ($chosen === null) {
            return $columns;
        }
        $given  = array_map('strval', (array) ($columns['values'] ?? []));
        // None of the report's own among them: the chosen ones, never an empty list, which is every value.
        $values = $given === [] ? $chosen : (array_values(array_intersect($given, $chosen)) ?: $chosen);
        if ($values === []) {
            return $columns;
        }

        return ['values' => $values, 'total' => count($values) > 1 && !empty($columns['total'])] + $columns;
    }

    /** The rows' report: one section of this card's rows, in the report's grain and comparison. */
    protected function built(array $columns, ?string $chart = null, ?string $at = null): ?array
    {
        $rows     = (array) ($this->setting('rows') ?? []);
        $measures = (array) ($this->setting('measures') ?? []);
        if ($rows === [] && $measures === []) {
            return null;
        }
        $report = $this->report();
        $built  = Report::fromArray([
            'name'     => $this->heading(),
            'grain'    => $report['grain'],
            'compare'  => $chart === null ? $report['compare'] : null,
            'columns'  => $columns,
            'sections' => [['title' => '', 'chart' => $chart, 'total' => (string) $this->setting('total') === '1' || $this->setting('total') === true, 'rows' => array_map(
                fn (array $row): array => ['filters' => $this->narrowed((array) ($row['filters'] ?? []))] + $row + ['breakdown' => ($row['breakdown'] ?? '') !== '' ? $row['breakdown'] : null],
                $rows,
            )] + array_filter(['note' => $this->setting('note'), 'link' => $this->setting('link'), 'jira' => $this->setting('jira'), 'hide_empty' => $this->setting('hide_empty')]) + ($measures === [] ? [] : [
                // A table of measures: the board's variables narrow the whole section.
                'by' => $this->setting('by'), 'values' => (array) ($this->setting('by_values') ?? []), 'measures' => $measures,
                'filters' => $this->narrowed((array) ($this->setting('filters') ?? [])),
            ])],
        ]);
        if (($report['tenant'] ?? null) !== null) {
            $built->forTenant((int) $report['tenant']);
        }

        return $built->build($at ?? $report['at'], $report['user']);
    }

    /** The table as built — for the report's CSV — or null for a card with no rows. @return array<string, mixed>|null */
    public function table(): ?array
    {
        $table = $this->built($this->columns());
        if ($table !== null && $table['sections'] !== []) {
            $table['sections'][0]['title'] = $this->heading();
        }

        return $table;
    }

    /** The periods a viewer may chart a table over. */
    public const CHART_PERIODS = [6, 12, 24];

    public function render(): string
    {
        $table = $this->table();
        if ($table === null) {
            return self::note(Figures::words('Add a row: choose a metric in the card\'s settings.'));
        }
        // A section with nothing but noughts, its report said to leave out: nothing to draw.
        if ($table['sections'] === []) {
            return '';
        }
        $table['sections'][0]['title'] = '';
        $static = (bool) $this->report()['static'];

        // The place the chart's links come back to: the reader lands on this table, not the top of the page.
        return ($static ? '' : '<span id="pf-chart-' . $this->chartKey() . '"></span>' . $this->chartAsked()) . Report::html($table, $static ? null : $this->report()['rowsUrl']) . ($static ? '' : $this->chartChoices());
    }

    /** This table among the page's, for the address that charts it: its settings, hashed. */
    private function chartKey(): string
    {
        return substr(md5((string) json_encode($this->dashboardSettings)), 0, 10);
    }

    /**
     * The chart a viewer asked for over this table — `?chart[<key>]=line:12` — its rows over the
     * last periods up to the report's; '' for none. The report's definition does not change: an
     * owner keeps one with a chart card in Customise.
     */
    private function chartAsked(): string
    {
        $asked = (string) (((array) ($_GET['chart'] ?? []))[$this->chartKey()] ?? '');
        if (preg_match('/^(line|bar):(\d{1,2})$/', $asked, $m) !== 1 || !in_array((int) $m[2], self::CHART_PERIODS, true)) {
            return '';
        }
        $built = $this->built(['periods' => (int) $m[2]], $m[1]);
        if ($built === null || $built['sections'] === []) {
            return '';
        }
        $sets = [];
        foreach ($built['sections'][0]['rows'] as $row) {
            if ($row['error'] === null && empty($row['subtotal'])) {
                $sets[] = ['label' => $row['label'], 'data' => array_column($row['cells'], 'value')];
            }
        }

        return $sets === [] ? '' : \Pramnos\Html\Chart::render($m[1], $built['columns'], $sets, ['title' => $this->heading(), 'height' => 240]);
    }

    /**
     * The viewer's choice of a chart over this table: lines or bars, over 6, 12 or 24 periods, or
     * none — links, no script, each back at this table (`#pf-chart-<key>`), the one shown marked.
     */
    private function chartChoices(): string
    {
        if (((array) ($this->setting('measures') ?? [])) !== []) {
            return '';
        }
        $e      = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $path   = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        $key    = $this->chartKey();
        $asked  = (string) (((array) ($_GET['chart'] ?? []))[$key] ?? '');
        $anchor = '#pf-chart-' . $key;
        $link   = static function (array $query, string $text, bool $current) use ($e, $path, $anchor): string {
            return $current ? '<strong aria-current="true">' . $e($text) . '</strong>'
                : '<a href="' . $e($path . ($query === [] ? '' : '?' . http_build_query($query)) . $anchor) . '">' . $e($text) . '</a>';
        };
        $groups = [];
        foreach (['line' => Figures::words('Line'), 'bar' => Figures::words('Bars')] as $type => $label) {
            $links = [];
            foreach (self::CHART_PERIODS as $n) {
                $query = $_GET;
                $query['chart'][$key] = $type . ':' . $n;
                $links[] = $link($query, (string) $n, $asked === $type . ':' . $n);
            }
            $groups[] = '<span>' . $e($label) . ': ' . implode(' · ', $links) . '</span>';
        }
        if ($asked !== '') {
            $query = $_GET;
            unset($query['chart'][$key]);
            if (($query['chart'] ?? []) === []) {
                unset($query['chart']);
            }
            $groups[] = '<span>' . $link($query, Figures::words('No chart'), false) . '</span>';
        }

        return '<details class="pf-report-chartit pf-no-print"' . ($asked !== '' ? ' open' : '') . '><summary>' . $e(Figures::words('Chart')) . '</summary>'
            . '<p style="display:flex;flex-wrap:wrap;gap:.25rem 1rem;margin:.25rem 0">' . implode('<span aria-hidden="true">|</span>', $groups) . '</p></details>';
    }
}
