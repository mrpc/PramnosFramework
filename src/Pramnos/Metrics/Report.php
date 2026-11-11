<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * A report built from metrics: sections of rows across columns, with an optional comparison and
 * chart. A cell is the row's metric, with the row's filters and the column's together, in the
 * column's period.
 *
 * ```php
 * $report = Report::fromArray([
 *     'name'    => 'Sales',
 *     'grain'   => 'month',
 *     // columns: a list of specs — each a label, filters, a metric of its own, and a period
 *     // relative to the report's (0 is the report's, -1 the one before) …
 *     'columns' => [
 *         ['label' => 'Total'],
 *         ['label' => 'North, new',      'filters' => ['region' => 'north', 'customer' => 'new']],
 *         ['label' => 'North, returning', 'filters' => ['region' => 'north', 'customer' => 'returning']],
 *         ['label' => 'Revenue',         'metric' => 'orders.revenue'],
 *     ],
 *     // … or a shorthand for one of two common shapes:
 *     //   ['periods' => 12]                                                    the last 12 periods
 *     //   ['dimension' => 'region', 'values' => ['north', 'south'], 'total' => true]   one per value, and the total
 *     'compare'  => 'year',   // 'previous', 'year' or null: each row, without the columns' filters, in the other period, and the change
 *     'sections' => [
 *         ['title' => 'Orders', 'chart' => 'line', 'total' => true, 'rows' => [
 *             ['label' => 'Orders', 'metric' => 'orders.count'],
 *             ['metric' => 'orders.count', 'breakdown' => 'plan', 'total' => true],   // a row per plan, then all plans
 *         ]],
 *     ],
 * ]);
 * $table = $report->build('2026-09-01', $user);
 * echo Report::html($table, '/admin/Metricreports/rows');   // a cell whose metric lists its records links to them
 * ```
 *
 * A report may instead have cards — `'layout' => [...]`, as the dashboard keeps one — tables,
 * charts, figures, text, records and section headings, arranged with the dashboard's editor; see
 * {@see ReportBoard}. Sections are then read as cards. Columns and sections can still be written
 * as text ({@see columnsFromText()}, {@see sectionsFromText()}).
 *
 * A row reads only a metric the person may see; the others are left out and counted. A cell the
 * metric cannot answer — a breakdown a distinct metric does not keep — says why. A breakdown row's
 * total is the metric without the breakdown, so a distinct one is counted, not added up. A closed
 * period's values are final, so a closed period's report is the same every time it is opened.
 */
final class Report
{
    /** The most columns a report has. */
    public const MAX_COLUMNS = 60;

    /** @param array<string, mixed> $definition As {@see fromArray()} cleaned it */
    private function __construct(public readonly array $definition)
    {
    }

    /**
     * A report from its definition, checked.
     *
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        $grain = (string) ($definition['grain'] ?? Window::MONTH);
        if (!in_array($grain, Window::GRAINS, true)) {
            throw new \InvalidArgumentException('Grain "' . $grain . '" is not one of: ' . implode(', ', Window::GRAINS) . '.');
        }
        $compare = $definition['compare'] ?? null;
        if (!in_array($compare, [null, '', Metrics::PREVIOUS, Metrics::YEAR_EARLIER], true)) {
            throw new \InvalidArgumentException('A report compares with "previous" or "year", not "' . $compare . '".');
        }
        $sections = [];
        foreach ((array) ($definition['sections'] ?? []) as $section) {
            $rows = [];
            foreach ((array) ($section['rows'] ?? []) as $row) {
                $metric = trim((string) ($row['metric'] ?? ''));
                if ($metric === '') {
                    continue;
                }
                $breakdown = trim((string) ($row['breakdown'] ?? ''));
                $rows[]    = [
                    'label'     => trim((string) ($row['label'] ?? '')),
                    'metric'    => $metric,
                    'filters'   => self::filters($row['filters'] ?? []),
                    'breakdown' => $breakdown !== '' ? $breakdown : null,
                    'total'     => $breakdown !== '' && (bool) ($row['total'] ?? false),
                ];
            }
            $sections[] = [
                'title' => trim((string) ($section['title'] ?? '')),
                'chart' => in_array($section['chart'] ?? null, ['line', 'bar'], true) ? (string) $section['chart'] : null,
                'total' => (bool) ($section['total'] ?? false),
                'rows'  => $rows,
            ];
        }

        $clean = [
            'name'     => trim((string) ($definition['name'] ?? '')),
            'grain'    => $grain,
            'columns'  => self::columns($definition['columns'] ?? ['periods' => 1]),
            'compare'  => $compare === '' ? null : $compare,
            'sections' => $sections,
        ];
        // Its cards, as the dashboard keeps a layout ({@see ReportBoard}); without them, the
        // sections are read as cards.
        if (is_array($definition['layout'] ?? null)) {
            $clean['layout'] = array_values(array_filter($definition['layout'], 'is_array'));
        }

        return new self($clean);
    }

    /**
     * The dimensions the report reads: what its rows, columns and cards filter on or break down
     * by — the only ones its page offers a picker for, so a dimension of thousands of values is
     * not listed for a report that never uses it.
     *
     * @return list<string>
     */
    public function dimensions(): array
    {
        $found = [];
        $walk  = static function (array $node) use (&$walk, &$found): void {
            foreach ($node as $key => $value) {
                if (($key === 'breakdown' || $key === 'dimension') && is_string($value) && $value !== '') {
                    $found[$value] = true;
                } elseif ($key === 'filters' && is_array($value)) {
                    $found += array_fill_keys(array_map('strval', array_keys($value)), true);
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($this->definition);

        return array_keys($found);
    }

    /**
     * Columns as given: a list of specs, or a shorthand kept as it is (expanded when built,
     * since a dimension's values may change).
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private static function columns(mixed $columns): array
    {
        $columns = (array) $columns;
        if (isset($columns['dimension']) && (string) $columns['dimension'] !== '') {
            return ['dimension' => (string) $columns['dimension'], 'values' => array_values(array_map('strval', (array) ($columns['values'] ?? []))), 'total' => (bool) ($columns['total'] ?? true)];
        }
        if (!array_is_list($columns) || $columns === []) {
            return ['periods' => max(1, min(self::MAX_COLUMNS, (int) ($columns['periods'] ?? 1)))];
        }
        $specs = [];
        foreach (array_slice($columns, 0, self::MAX_COLUMNS) as $spec) {
            $spec    = (array) $spec;
            $metric  = trim((string) ($spec['metric'] ?? ''));
            $specs[] = [
                'label'   => trim((string) ($spec['label'] ?? '')),
                'filters' => self::filters($spec['filters'] ?? []),
                'metric'  => $metric !== '' ? $metric : null,
                'offset'  => min(0, (int) ($spec['offset'] ?? 0)),
            ];
        }

        return $specs;
    }

    /** @return array<string, string|list<string>> */
    private static function filters(mixed $filters): array
    {
        return array_map(static fn ($v) => is_array($v) ? array_values(array_map('strval', $v)) : (string) $v, (array) $filters);
    }

    // ------------------------------------------------------------------ the text the screen edits

    /**
     * Sections from text: `# Title [chart=line] [total]` starts one; each other line is
     * `Label | metric | name=value, name=value | by=dimension [total]`. Blank lines are skipped.
     *
     * @return list<array<string, mixed>>
     */
    public static function sectionsFromText(string $text): array
    {
        $sections = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                preg_match('/\[chart=(line|bar)\]/', $line, $chart);
                $sections[] = [
                    'title' => trim((string) preg_replace('/\[[^\]]*\]/', '', ltrim($line, '# '))),
                    'chart' => $chart[1] ?? null,
                    'total' => str_contains($line, '[total]'),
                    'rows'  => [],
                ];
                continue;
            }
            if ($sections === []) {
                $sections[] = ['title' => '', 'chart' => null, 'total' => false, 'rows' => []];
            }
            $parts = array_map('trim', explode('|', $line)) + [1 => '', 2 => '', 3 => ''];
            preg_match('/by=([a-z][a-z0-9_]*)/', $parts[3], $by);
            $sections[array_key_last($sections)]['rows'][] = [
                'label'     => $parts[1] !== '' ? $parts[0] : '',
                'metric'    => $parts[1] !== '' ? $parts[1] : $parts[0],
                'filters'   => self::pairs($parts[2]),
                'breakdown' => $by[1] ?? null,
                'total'     => str_contains($parts[3], 'total'),
            ];
        }

        return $sections;
    }

    /**
     * Columns from text: `periods=12`, or `dimension=region north,south total`, or one column a
     * line, `Label | name=value, … | metric | offset`. Empty is one column, the report's period.
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function columnsFromText(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), static fn (string $l): bool => $l !== ''));
        if ($lines === []) {
            return ['periods' => 1];
        }
        if (preg_match('/^periods\s*=\s*(\d+)$/', $lines[0], $m)) {
            return ['periods' => (int) $m[1]];
        }
        if (preg_match('/^dimension\s*=\s*([a-z][a-z0-9_]*)\s*(.*)$/', $lines[0], $m)) {
            $rest   = trim($m[2]);
            $total  = (bool) preg_match('/(^|\s)total$/', $rest);
            $values = trim((string) preg_replace('/(^|\s)total$/', '', $rest));

            return ['dimension' => $m[1], 'values' => $values === '' ? [] : array_map('trim', explode(',', $values)), 'total' => $total];
        }
        $specs = [];
        foreach ($lines as $line) {
            $parts   = array_map('trim', explode('|', $line)) + [1 => '', 2 => '', 3 => ''];
            $specs[] = ['label' => $parts[0], 'filters' => self::pairs($parts[1]), 'metric' => $parts[2], 'offset' => (int) $parts[3]];
        }

        return $specs;
    }

    /** The columns written as {@see columnsFromText()} reads them. */
    public function columnsAsText(): string
    {
        $columns = $this->definition['columns'];
        if (isset($columns['periods'])) {
            return 'periods=' . $columns['periods'] . "\n";
        }
        if (isset($columns['dimension'])) {
            return trim('dimension=' . $columns['dimension'] . ' ' . implode(',', $columns['values']) . ($columns['total'] ? ' total' : '')) . "\n";
        }
        $lines = [];
        foreach ($columns as $spec) {
            $lines[] = rtrim(implode(' | ', [$spec['label'], self::pairsText($spec['filters']), (string) $spec['metric'], $spec['offset'] !== 0 ? (string) $spec['offset'] : '']), ' |');
        }

        return implode("\n", $lines) . "\n";
    }

    /** The sections written as {@see sectionsFromText()} reads them. */
    public function sectionsAsText(): string
    {
        $lines = [];
        foreach ($this->definition['sections'] as $section) {
            $lines[] = trim('# ' . $section['title'] . ($section['chart'] !== null ? ' [chart=' . $section['chart'] . ']' : '') . ($section['total'] ? ' [total]' : ''));
            foreach ($section['rows'] as $row) {
                $parts = [$row['label'], $row['metric'], self::pairsText($row['filters'])];
                if ($row['breakdown'] !== null) {
                    $parts[] = 'by=' . $row['breakdown'] . ($row['total'] ? ' total' : '');
                }
                // A row of its metric alone is that one word; otherwise every part, the label perhaps empty.
                $lines[] = $row['label'] === '' && count($parts) === 3 && $parts[2] === '' ? $row['metric'] : trim(rtrim(implode(' | ', $parts), ' |'));
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /** @return array<string, string|list<string>> `name=value, name=a;b` — `;` between several values, since `|` separates the parts */
    private static function pairs(string $text): array
    {
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($text)) ?: [] as $pair) {
            [$name, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            if ($name !== '' && $value !== '') {
                $out[$name] = str_contains($value, ';') ? array_map('trim', explode(';', $value)) : $value;
            }
        }

        return $out;
    }

    /** @param array<string, string|list<string>> $filters */
    private static function pairsText(array $filters): string
    {
        $out = [];
        foreach ($filters as $name => $value) {
            $out[] = $name . '=' . (is_array($value) ? implode(';', $value) : $value);
        }

        return implode(', ', $out);
    }

    // ------------------------------------------------------------------ building

    /**
     * The numbers, for one person, for the period holding `$at`.
     *
     * Each cell is `['value' => ?float, 'link' => ?array]`: `link` names the records behind it
     * (metric, grain, period, filters) where the metric lists them.
     *
     * @return array<string, mixed>
     */
    /** A metric read as the report's viewer: every organisation for a report built for nobody. */
    private function read(string $metric): Query
    {
        $query = Metrics::query($metric);
        $query = $this->viewer !== null ? $query->for($this->viewer) : $query;

        return $this->tenant !== null ? $query->tenant($this->tenant) : $query;
    }

    /** The one organisation the report is built for, or null for every one the viewer reads. */
    private ?int $tenant = null;

    /** Build the report for one organisation only: a board's chosen one. */
    public function forTenant(?int $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    /** Who {@see build()} builds for. */
    private ?object $viewer = null;

    public function build(string $at, ?object $user): array
    {
        $this->viewer = $user;
        $grain   = $this->definition['grain'];
        $anchor  = Window::of($grain, $at);
        $compare = $this->definition['compare'];
        $other   = $compare === Metrics::YEAR_EARLIER ? $anchor->yearEarlier() : $anchor->previous();
        $visible = Metrics::all($user, false);
        $columns = $this->expandColumns($anchor);
        $hidden  = 0;

        $sections = [];
        foreach ($this->definition['sections'] as $section) {
            $rows = [];
            foreach ($section['rows'] as $row) {
                if (!isset($visible[$row['metric']])) {
                    $hidden++;
                    continue;
                }
                foreach ($this->expandRow($row, $anchor, $columns) as $one) {
                    $rows[] = $this->buildRow($one, $visible, $columns, $anchor, $compare, $grain);
                }
            }
            $total = null;
            if ($section['total'] && $rows !== []) {
                $total = [];
                foreach (array_keys($columns) as $i) {
                    $cells     = array_filter(array_map(static fn (array $r): ?float => $r['cells'][$i]['value'] ?? null, array_filter($rows, static fn (array $r): bool => !$r['subtotal'])), static fn ($v): bool => $v !== null);
                    $total[$i] = $cells === [] ? null : array_sum($cells);
                }
                // Amounts in more than one currency, or money beside counts, make no total.
                if (count(array_unique(array_map(static fn (array $r): string => (string) $r['currency'], array_filter($rows, static fn (array $r): bool => !$r['subtotal'] && $r['error'] === null)))) > 1) {
                    $total = null;
                }
            }
            $sections[] = ['title' => $section['title'], 'chart' => $section['chart'], 'rows' => $rows, 'total' => $total];
        }

        return [
            'name'     => $this->definition['name'],
            'grain'    => $grain,
            'period'   => $anchor->label(),
            'columns'  => array_column($columns, 'label'),
            'compare'  => $compare !== null ? ['against' => $other->label()] : null,
            'hidden'   => $hidden,
            'sections' => $sections,
        ];
    }

    /**
     * The columns as specs, for the report's period: a shorthand made a list.
     *
     * @return list<array{label: string, filters: array<string, string|list<string>>, metric: ?string, window: Window}>
     */
    private function expandColumns(Window $anchor): array
    {
        $columns = $this->definition['columns'];
        $out     = [];
        if (isset($columns['periods'])) {
            $window = $anchor;
            for ($i = 0; $i < $columns['periods']; $i++) {
                array_unshift($out, ['label' => $window->label(), 'filters' => [], 'metric' => null, 'window' => $window]);
                $window = $window->previous();
            }

            return $out;
        }
        if (isset($columns['dimension'])) {
            $dimension = Metrics::dimensionOf($columns['dimension']);
            $values    = $columns['values'] !== [] ? $columns['values'] : array_map('strval', array_keys($dimension->all()));
            foreach ($values as $value) {
                $out[] = ['label' => $dimension->labelOf($value), 'filters' => [$columns['dimension'] => $value], 'metric' => null, 'window' => $anchor];
            }
            if ($columns['total']) {
                $out[] = ['label' => function_exists('t') ? (string) t('Total') : 'Total', 'filters' => [], 'metric' => null, 'window' => $anchor];
            }

            return $out;
        }
        foreach ($columns as $spec) {
            $window = $anchor;
            for ($i = 0; $i > $spec['offset']; $i--) {
                $window = $window->previous();
            }
            $label = $spec['label'] !== '' ? $spec['label'] : ($spec['offset'] !== 0 ? $window->label() : self::pairsText($spec['filters']));
            $out[] = ['label' => $label, 'filters' => $spec['filters'], 'metric' => $spec['metric'], 'window' => $window];
        }

        return $out;
    }

    /**
     * A row as the rows it stands for: itself, or one per value of its breakdown — the values
     * declared, and any found in the report's periods — then, when asked, the metric without it.
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    private function expandRow(array $row, Window $anchor, array $columns): array
    {
        $metric = Metrics::get($row['metric']);
        $label  = $row['label'] !== '' ? $row['label'] : $metric->label();
        if ($row['breakdown'] === null) {
            return [['label' => $label, 'metric' => $row['metric'], 'filters' => $row['filters'], 'subtotal' => false]];
        }
        $dimension = Metrics::dimensionOf($row['breakdown']);
        $values    = array_map('strval', array_keys($dimension->all()));
        $windows   = array_column($columns, 'window');
        usort($windows, static fn (Window $a, Window $b): int => $a->start <=> $b->start);
        try {
            $found  = $this->read($row['metric'])->grain($anchor->grain)->between($windows[0]->key(), $anchor->key())->where($row['filters'])->by($row['breakdown'])->series()['values'];
            $values = array_values(array_unique(array_merge($values, array_map('strval', array_keys($found)))));
        } catch (\InvalidArgumentException|\LogicException $e) {
            return [['label' => $label, 'metric' => $row['metric'], 'filters' => $row['filters'], 'subtotal' => false, 'error' => $e->getMessage()]];
        }
        $out = [];
        foreach ($values as $value) {
            // A part of its row: drawn under its row's name, set in, unless its total closes the group.
            $out[] = ['label' => $dimension->labelOf($value), 'metric' => $row['metric'], 'filters' => [$row['breakdown'] => $value] + $row['filters'], 'subtotal' => false,
                'part' => $label, 'headed' => !$row['total']];
        }
        if ($row['total']) {
            $out[] = ['label' => $label, 'metric' => $row['metric'], 'filters' => $row['filters'], 'subtotal' => true];
        }

        return $out;
    }

    /**
     * One row's cells.
     *
     * ponytail: a cell is one read of the kept values; a report of 60 columns and 100 rows is
     * 6,000 small queries — read each row's series at once when that is measured to matter.
     *
     * @param array<string, mixed>       $row
     * @param array<string, Metric>      $visible
     * @param list<array<string, mixed>> $columns
     * @return array<string, mixed>
     */
    private function buildRow(array $row, array $visible, array $columns, Window $anchor, ?string $compare, string $grain): array
    {
        $unit  = $visible[$row['metric']]->getUnit();
        $built = ['label' => $row['label'], 'unit' => $unit, 'currency' => null, 'subtotal' => $row['subtotal'], 'cells' => [], 'other' => null, 'change' => null, 'error' => $row['error'] ?? null,
            'part' => $row['part'] ?? null, 'headed' => $row['headed'] ?? false];
        if ($built['error'] !== null) {
            return $built;
        }
        foreach ($columns as $column) {
            $name = $column['metric'] ?? $row['metric'];
            if (!isset($visible[$name])) {
                $built['cells'][] = ['value' => null, 'link' => null, 'error' => null];
                continue;
            }
            $filters = $column['filters'] + $row['filters'];
            $gap     = $visible[$name]->gapOf($column['window']);
            if ($gap !== null) {
                // A period the source cannot answer says why, not a nought.
                $built['cells'][] = ['value' => null, 'link' => null, 'error' => $gap];
                continue;
            }
            try {
                $query = $this->read($name)->grain($grain)->at($column['window']->key())->where($filters);
                $value = $query->value();
                if ($name === $row['metric']) {
                    // The row's own amounts in one currency: its first cell's, the rest checked against it.
                    $built['currency'] ??= $query->currency();
                    if ($query->currency() !== $built['currency']) {
                        throw new \LogicException(sprintf(t('%s is in %s here, %s elsewhere in the row.'), $visible[$name]->label(), (string) $query->currency(), (string) $built['currency']));
                    }
                }
                $built['cells'][] = [
                    'currency' => $query->currency(),
                    'value' => $value,
                    'link'  => $visible[$name]->hasRows() ? ['metric' => $name, 'grain' => $grain, 'period' => $column['window']->key(), 'filters' => $filters,
                        'url' => $visible[$name]->drillUrl($column['window'], $filters)] : null,
                    'error' => null,
                ];
            } catch (\InvalidArgumentException|\LogicException $e) {
                // One cell the metric cannot answer — a column's filter a distinct does not keep — and the rest of the row stands.
                $built['cells'][] = ['value' => null, 'link' => null, 'error' => $e->getMessage()];
            }
        }
        try {
            if ($compare !== null) {
                $pair            = $this->read($row['metric'])->grain($grain)->at($anchor->key())->where($row['filters'])->compare($compare)->compared();
                $built['other']  = $pair['other'];
                $built['change'] = $pair['change'];
            }
        } catch (\InvalidArgumentException|\LogicException $e) {
            $built['error'] = $e->getMessage();
        }

        return $built;
    }

    // ------------------------------------------------------------------ output

    /**
     * A built report as tables, a chart above each section that asks for one; with `$rowsUrl`, a
     * cell whose metric lists its records links to them (`metric`, `grain`, `period`, `f[name]`).
     *
     * @param array<string, mixed> $table From {@see build()}
     */
    public static function html(array $table, ?string $rowsUrl = null): string
    {
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t    = static fn (string $w): string => function_exists('t') ? (string) t($w) : $w;
        $span = count($table['columns']) + ($table['compare'] !== null ? 2 : 0);
        // Values and their headings both right, inline: a theme that aligns only one of them makes
        // every heading read as the next column's.
        $col  = '<th scope="col" style="text-align:right">';
        $td   = '<td style="text-align:right">';
        $head = '<th scope="col"></th>';
        foreach ($table['columns'] as $label) {
            $head .= $col . $e($label) . '</th>';
        }
        if ($table['compare'] !== null) {
            $head .= $col . $e($table['compare']['against']) . '</th>' . $col . $e($t('Change')) . '</th>';
        }
        $html = '';
        foreach ($table['sections'] as $section) {
            if ($section['title'] !== '') {
                $html .= '<h3 class="pf-report-section">' . $e($section['title']) . '</h3>';
            }
            if ($section['chart'] !== null && $section['rows'] !== []) {
                $sets = [];
                foreach ($section['rows'] as $row) {
                    if ($row['error'] === null) {
                        $sets[] = ['label' => $row['label'], 'data' => array_column($row['cells'], 'value')];
                    }
                }
                $html .= \Pramnos\Html\Chart::render($section['chart'], $table['columns'], $sets, ['title' => $section['title'] !== '' ? $section['title'] : $table['name'], 'height' => 240]);
            }
            $body  = '';
            $group = null;
            foreach ($section['rows'] as $row) {
                $part = $row['part'] ?? null;
                // A breakdown's values under their row's name, set in and lighter, so three of them
                // in a section are never read as rows of their own.
                if ($part !== null && $part !== $group && !empty($row['headed'])) {
                    $body .= '<tr class="pf-report-group"><th scope="rowgroup" colspan="' . ($span + 1) . '">' . $e($part) . '</th></tr>';
                }
                $group = $part;
                $class = $row['subtotal'] ? 'pf-report-total' : ($part !== null ? 'pf-report-part' : '');
                $body .= '<tr' . ($class !== '' ? ' class="' . $class . '"' : '') . '><th scope="row"'
                    . ($part !== null ? ' style="padding-left:1.5em;font-weight:normal;opacity:.8"' : '') . '>' . $e($row['label']) . '</th>';
                if ($row['error'] !== null) {
                    $body .= '<td colspan="' . $span . '" class="pf-muted">' . $e($row['error']) . '</td></tr>';
                    continue;
                }
                foreach ($row['cells'] as $cell) {
                    if (($cell['error'] ?? null) !== null) {
                        $body .= '<td class="pf-muted" style="text-align:right" title="' . $e($cell['error']) . '">—</td>';
                        continue;
                    }
                    $text = $e(self::number($cell['value'], $row['unit'], $cell['currency'] ?? null));
                    if ($rowsUrl !== null && $cell['link'] !== null && $cell['value'] !== null) {
                        // The application's own list where the metric has one, else the framework's records.
                        $query = http_build_query(['metric' => $cell['link']['metric'], 'grain' => $cell['link']['grain'], 'period' => $cell['link']['period'], 'f' => $cell['link']['filters']]);
                        $href  = ($cell['link']['url'] ?? null) ?? $rowsUrl . (str_contains($rowsUrl, '?') ? '&' : '?') . $query;
                        $text  = '<a href="' . $e($href) . '">' . $text . '</a>';
                    }
                    $body .= $td . $text . '</td>';
                }
                if ($table['compare'] !== null) {
                    $body .= $td . $e(self::number($row['other'], $row['unit'], $row['currency'])) . '</td>' . $td . $e($row['change'] === null ? '—' : ($row['change'] >= 0 ? '+' : '') . \Pramnos\General\NumberFormat::number($row['change'] * 100, 1) . '%') . '</td>';
                }
                $body .= '</tr>';
            }
            if ($section['total'] !== null) {
                $body .= '<tr class="pf-report-total"><th scope="row">' . $e($t('Total')) . '</th>';
                $currency = $section['rows'][0]['currency'] ?? null;
                foreach ($section['total'] as $cell) {
                    $body .= $td . $e(self::number($cell, '', $currency)) . '</td>';
                }
                $body .= ($table['compare'] !== null ? '<td></td><td></td>' : '') . '</tr>';
            }
            // One or two columns of values: the table as wide as it needs, the numbers beside their names.
            $html .= '<div class="pf-report-scroll"><table class="pf-report table table-sm"' . ($span <= 2 ? ' style="width:auto;min-width:50%"' : '')
                . '><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
        }
        if ($table['hidden'] > 0) {
            $html .= '<p class="pf-muted">' . $e(sprintf($t('%d rows are not shown: their metrics are not available to you.'), $table['hidden'])) . '</p>';
        }

        return '<div class="pf-report-body">' . $html . '</div>';
    }

    /** A built report as CSV: one line per row, its section first. @param array<string, mixed> $table From {@see build()} */
    public static function csv(array $table): string
    {
        $out  = fopen('php://temp', 'r+');
        $head = array_merge(['section', 'row'], $table['columns']);
        if ($table['compare'] !== null) {
            $head = array_merge($head, [$table['compare']['against'], 'change']);
        }
        fputcsv($out, $head, ',', '"', '');
        foreach ($table['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                // An amount's currency beside its label: the cells stay plain numbers, to add up.
                $line = [$section['title'], $row['label'] . (($row['currency'] ?? null) !== null ? ' (' . $row['currency'] . ')' : '')];
                if ($row['error'] !== null) {
                    $line[] = $row['error'];
                } else {
                    foreach ($row['cells'] as $cell) {
                        $line[] = $cell['value'] ?? '';
                    }
                    if ($table['compare'] !== null) {
                        $line[] = $row['other'] ?? '';
                        $line[] = $row['change'] === null ? '' : round($row['change'], 4);
                    }
                }
                fputcsv($out, $line, ',', '"', '');
            }
            if ($section['total'] !== null) {
                fputcsv($out, array_merge([$section['title'], 'Total'], array_map(static fn ($v) => $v ?? '', $section['total'])), ',', '"', '');
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A cell as people read it: a dash for no value. */
    private static function number(?float $value, string $unit, ?string $currency = null): string
    {
        if ($value === null) {
            return '—';
        }

        return $currency !== null ? \Pramnos\General\NumberFormat::money($value, $currency) : \Pramnos\Dashboard\Widgets\MetricCard::format($value, $unit);
    }
}
