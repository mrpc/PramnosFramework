<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Dashboard\Layouts;
use Pramnos\Dashboard\Widget;
use Pramnos\Dashboard\WidgetRegistry;

/**
 * A report as a dashboard board: its cards — tables, charts, figures, text, records, section
 * headings ({@see Widgets\ReportWidget}) — arranged with the dashboard's editor, and drawn for one
 * period of the report.
 *
 * The board of a report is `report:<id>` (`report:5`, `report:app:monthly`). The dashboard
 * controller serves it like any board — Customise, move, resize, tabs, the card form — and keeps
 * its layout in the report's definition rather than in each person's settings: a report has one
 * layout, its owner's. Its catalogue is the report widgets, and any widget whose `boards()`
 * names `report`.
 *
 * While a report's cards are drawn the report is "entered" ({@see enter()}): its grain, the period
 * looked at, its comparison and its columns are what each card reads ({@see context()}).
 *
 * A definition written before reports had cards — `sections` — is read as cards ({@see layout()}):
 * each section a heading, its table, and its chart where it asked for one.
 */
final class ReportBoard
{
    /** A report's board: this, then the report's id. */
    public const PREFIX = 'report:';

    /** The catalogue every report's board offers, and what a report widget's `boards()` names. */
    public const CATALOGUE = 'report';

    /** @var array<string, mixed>|null The report being drawn */
    private static ?array $context = null;

    /** Whether a board name is a report's. */
    public static function isReportBoard(?string $board): bool
    {
        return is_string($board) && str_starts_with($board, self::PREFIX) && strlen($board) > strlen(self::PREFIX);
    }

    /** The board of a report, by its id. */
    public static function board(string $reportId): string
    {
        return self::PREFIX . $reportId;
    }

    /** The report a board is of. */
    public static function reportId(string $board): string
    {
        return substr($board, strlen(self::PREFIX));
    }

    /**
     * Draw a report's cards from here on: its grain, period, comparison and columns, for `$user`.
     *
     * @param array<string, mixed> $entry  As {@see Reports::find()} gives it
     * @param string               $at     A day of the period looked at, `Y-m-d`
     * @param string|null          $rowsUrl Where a cell's records are listed; null for none
     * @param bool                 $static Drawn for a mail or a file: no scripts, charts as images
     */
    public static function enter(array $entry, string $at, ?object $user, ?string $rowsUrl = null, bool $static = false): void
    {
        $definition    = $entry['report']->definition;
        self::$context = [
            'entry'   => $entry,
            'grain'   => $definition['grain'],
            'at'      => $at,
            'window'  => Window::of($definition['grain'], $at),
            'compare' => $definition['compare'],
            'columns' => $definition['columns'],
            'user'    => $user,
            'rowsUrl' => $rowsUrl,
            'static'  => $static,
            'images'  => [],
        ] + self::variables();
    }

    /**
     * The board's variables, from the address — `?v[region]=north&v[tenant]=7` — that narrow
     * every card: a declared dimension's value each, and an organisation. What is not a declared
     * dimension is ignored.
     *
     * @return array{filters: array<string, string>, tenant: ?int}
     */
    public static function variables(): array
    {
        $given   = is_array($_GET['v'] ?? null) ? $_GET['v'] : [];
        $filters = [];
        foreach (Metrics::dimensions() as $name => $dimension) {
            if (isset($given[$name]) && is_scalar($given[$name]) && (string) $given[$name] !== '') {
                $filters[(string) $name] = mb_substr((string) $given[$name], 0, 100);
            }
        }

        return ['filters' => $filters, 'tenant' => isset($given['tenant']) && is_numeric($given['tenant']) && (int) $given['tenant'] > 0 ? (int) $given['tenant'] : null];
    }

    /**
     * The report being drawn — or, outside one, this month for the person signed in, so a card
     * drawn elsewhere still shows something true.
     *
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        if (self::$context !== null) {
            return self::$context;
        }
        $at = date('Y-m-d');

        return ['entry' => null, 'grain' => Window::MONTH, 'at' => $at, 'window' => Window::of(Window::MONTH, $at), 'compare' => null,
            'columns' => ['periods' => 1], 'user' => \Pramnos\User\User::current(), 'rowsUrl' => null, 'static' => false, 'images' => [], 'filters' => [], 'tenant' => null];
    }

    /** Stop drawing the report entered. */
    public static function leave(): void
    {
        self::$context = null;
    }

    /**
     * Keep an image a card made for a static drawing — a chart as PNG — and answer how the
     * drawing refers to it: `cid:<name>`, for the mail to carry it.
     */
    public static function image(string $png): string
    {
        $name = 'chart-' . (count(self::$context['images'] ?? []) + 1) . '.png';
        if (self::$context !== null) {
            self::$context['images'][$name] = $png;
        }

        return 'cid:' . $name;
    }

    /**
     * The images the static drawing made, by name, for the mail to carry inline.
     *
     * @return array<string, string>
     */
    public static function images(): array
    {
        return (array) (self::$context['images'] ?? []);
    }

    /**
     * A report's cards as the dashboard keeps a layout: its own, or its sections read as cards.
     *
     * @return list<array<string, mixed>>
     */
    public static function layout(Report $report): array
    {
        $layout = $report->definition['layout'] ?? null;
        if (is_array($layout)) {
            return $layout;
        }
        $cards = [];
        foreach ($report->definition['sections'] as $i => $section) {
            if ($section['title'] !== '') {
                $cards[] = ['id' => 'report.section~s' . $i, 'size' => Widget::FULL, 'settings' => ['title' => $section['title']]];
            }
            // The section's own columns, as a table card takes them: the last N periods, a dimension's values, or its own.
            $own = self::columnsAsSettings($section['columns'] ?? null);
            if ($section['chart'] !== null && $section['rows'] !== []) {
                // Untitled: the section's heading is drawn once, by the section above it.
                $cards[] = ['id' => 'report.chart~c' . $i, 'size' => Widget::FULL, 'settings' => (isset($own['periods']) ? ['periods' => $own['periods']] : []) + [
                    'series' => array_map(static fn (array $row): array => ['label' => $row['label'], 'metric' => $row['metric'], 'filters' => $row['filters'], 'breakdown' => (string) $row['breakdown']],
                        array_values(array_filter($section['rows'], static fn (array $row): bool => !isset($row['formula'])))),
                    'chart'  => $section['chart'],
                ]];
            }
            $cards[] = ['id' => 'report.table~t' . $i, 'size' => Widget::FULL, 'settings' => array_intersect_key($section, array_flip(['by', 'filters', 'measures', 'note', 'link', 'jira'])) + (isset($section['measures']) ? ['by_values' => $section['values']] : []) + $own + [
                'rows'  => array_map(static fn (array $row): array => ['label' => $row['label'], 'metric' => $row['metric'], 'filters' => $row['filters'], 'breakdown' => (string) $row['breakdown'], 'total' => $row['total']]
                    + array_intersect_key($row, array_flip(['formula', 'operands', 'unit', 'description', 'link'])), $section['rows']),
                'total' => $section['total'] ? '1' : '',
            ]];
        }

        return $cards;
    }

    /**
     * A section's own columns as a table card's settings: `periods`, a dimension's values, or a
     * list of its own; none for a section with the report's.
     *
     * @return array<string, mixed>
     */
    private static function columnsAsSettings(?array $columns): array
    {
        if ($columns === null || $columns === []) {
            return [];
        }
        if (isset($columns['periods'])) {
            return ['columns' => 'periods', 'periods' => (int) $columns['periods']];
        }
        if (isset($columns['dimension'])) {
            return ['columns' => 'dimension', 'dimension' => (string) $columns['dimension'], 'values' => implode(',', (array) ($columns['values'] ?? [])),
                'column_total' => !empty($columns['total']) ? '1' : ''];
        }

        return ['columns' => 'custom', 'custom' => array_values($columns)];
    }

    /**
     * A report drawn without the page: each card in turn, its heading over it, for a mail or a
     * printout. Charts are images where the server can make them ({@see ChartImage}), and their
     * numbers otherwise. The report stays entered, so `images()` has the images the drawing refers
     * to; call `leave()` once they are taken.
     *
     * @param array<string, mixed> $entry As {@see Reports::find()} gives it
     */
    public static function html(array $entry, string $at, ?object $user, ?string $rowsUrl = null, bool $static = true): string
    {
        self::enter($entry, $at, $user, $rowsUrl, $static);
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '';
        foreach (self::cards($entry['report'], $user) as [$widget, $card]) {
            try {
                $inside = $widget->configure($card['settings'])->render();
            } catch (\Throwable $ex) {
                \Pramnos\Logs\Logger::log('Report card ' . $card['id'] . ' failed: ' . $ex->getMessage(), 'metrics');
                $inside = '';
            }
            if ($widget instanceof Widgets\SectionWidget) {
                $html .= $inside;
                continue;
            }
            $title = $widget instanceof Widgets\ReportWidget ? $widget->heading() : $widget->title();
            $html .= '<section class="pf-report-card" style="margin:0 0 20px">'
                . ($title !== '' ? '<h3 style="margin:0 0 8px">' . $e($title) . '</h3>' : '') . $inside . '</section>';
        }

        return '<div class="pf-report-body">' . $html . '</div>';
    }

    /**
     * A report as CSV: its tables one under another, each line its section — the table's heading,
     * or the section it is under — and row.
     *
     * @param array<string, mixed> $entry As {@see Reports::find()} gives it
     */
    public static function csv(array $entry, string $at, ?object $user): string
    {
        self::enter($entry, $at, $user);
        $out = '';
        try {
            $section = '';
            foreach (self::cards($entry['report'], $user) as [$widget]) {
                if ($widget instanceof Widgets\SectionWidget) {
                    $section = $widget->heading();
                    continue;
                }
                if ($widget instanceof Widgets\TableWidget) {
                    $table = $widget->table();
                    if ($table !== null) {
                        // A table without a heading of its own is its section's.
                        if ($table['sections'][0]['title'] === '') {
                            $table['sections'][0]['title'] = $section;
                        }
                        $out .= Report::csv($table);
                    }
                }
            }
        } finally {
            self::leave();
        }

        return $out;
    }

    /**
     * Every card of a report this person may have, in order — a group's tabs one after another —
     * each with its widget, configured.
     *
     * @return list<array{0: Widget, 1: array<string, mixed>}>
     */
    public static function cards(Report $report, ?object $user): array
    {
        $available = WidgetRegistry::available($user, self::CATALOGUE);
        $out       = [];
        foreach (Layouts::cards(Layouts::clean(self::layout($report), $user, self::CATALOGUE)) as $card) {
            $widget = WidgetRegistry::forCard($available, $card['id']);
            if ($widget !== null) {
                $out[] = [(clone $widget)->configure($card['settings']), $card];
            }
        }

        return $out;
    }
}
