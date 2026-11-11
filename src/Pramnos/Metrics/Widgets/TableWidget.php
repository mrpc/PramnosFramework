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
            ];
        });
    }

    /** The columns chosen, as {@see Report::fromArray()} takes them. @return array<string, mixed>|list<array<string, mixed>> */
    protected function columns(): array
    {
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
        $rows = (array) ($this->setting('rows') ?? []);
        if ($rows === []) {
            return null;
        }
        $report = $this->report();
        $built  = Report::fromArray([
            'name'     => $this->heading(),
            'grain'    => $report['grain'],
            'compare'  => $chart === null ? $report['compare'] : null,
            'columns'  => $columns,
            'sections' => [['title' => '', 'chart' => $chart, 'total' => (string) $this->setting('total') === '1', 'rows' => array_map(
                fn (array $row): array => ['filters' => $this->narrowed((array) ($row['filters'] ?? []))] + $row + ['breakdown' => ($row['breakdown'] ?? '') !== '' ? $row['breakdown'] : null],
                $rows,
            )]],
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

    public function render(): string
    {
        $table = $this->table();
        if ($table === null) {
            return self::note(Figures::words('Add a row: choose a metric in the card\'s settings.'));
        }
        $table['sections'][0]['title'] = '';

        return Report::html($table, $this->report()['static'] ? null : $this->report()['rowsUrl']);
    }
}
