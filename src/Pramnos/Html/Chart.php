<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * A chart, drawn with the page's Chart.js in the theme's colours, with no inline script.
 *
 * ```php
 * echo \Pramnos\Html\Chart::render('bar', ['Jan', 'Feb', 'Mar'], [
 *     ['label' => 'Revenue', 'data' => [1200, 1850, 990]],
 *     ['label' => 'Target',  'data' => [1500, 1500, 1500], 'type' => 'line', 'color' => 'warning'],
 * ], ['title' => 'Revenue by month', 'format' => 'currency', 'currency' => 'EUR']);
 * ```
 *
 * The markup carries the data as JSON (`<script type="application/json">`, which a browser does
 * not run, so a CSP without `'unsafe-inline'` lets it through); `pf-utils.js` draws every
 * `[data-pf-chart-figure]` once Chart.js is on the page, and draws it again when the theme changes —
 * `data-theme` or the OS colour scheme — because the colours are read from the theme's tokens
 * when drawn, not written into the page.
 *
 * Under it is a table of the same numbers. It is what a screen reader reads, and what a page
 * without Chart.js shows; once the chart is drawn it is hidden visually and kept for the former.
 *
 * Rendering enqueues the `chartjs` asset when the document has it registered.
 *
 * Not the chart in an assistant's answer: that one (`data-pf-chart`, from `Llm\Chat\Markdown`) is
 * drawn by `pf-chat.js` as SVG, on pages that may have no Chart.js.
 */
final class Chart
{
    /** The chart types Chart.js draws without a plugin. */
    public const TYPES = ['line', 'bar', 'pie', 'doughnut', 'radar', 'polarArea'];

    /** How the numbers read: in the tooltip, on the axis and in the table. */
    public const FORMATS = ['number', 'currency', 'percent'];

    /**
     * @param string                    $type     One of {@see TYPES}
     * @param list<string|int|float>    $labels   One per point: the x axis, or a slice's name
     * @param list<array<string, mixed>> $datasets Each `['label' => …, 'data' => [numbers]]`, and
     *        optionally `'color'` — a theme colour (`primary`, `secondary`, `accent`, `info`,
     *        `success`, `warning`, `error`) or any CSS colour — `'colors'`, one per slice of a pie
     *        or doughnut, and `'type'` to mix a line into bars
     * @param array<string, mixed>      $options
     *  - `title` — the caption, and the chart's accessible name
     *  - `height` — in pixels (300)
     *  - `format` — one of {@see FORMATS} (`number`); `currency` takes `currency` (`EUR`)
     *  - `decimals` — the most fraction digits shown (2; 0 for none)
     *  - `stacked` — stack the bars or the areas
     *  - `chart` — Chart.js options merged over the ones drawn, for anything not modelled here
     *  - `id` — the figure's id
     */
    public static function render(string $type, array $labels, array $datasets, array $options = []): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Chart type "' . $type . '" is not one of: ' . implode(', ', self::TYPES) . '.');
        }
        $format = (string) ($options['format'] ?? 'number');
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('Chart format "' . $format . '" is not one of: ' . implode(', ', self::FORMATS) . '.');
        }

        $labels   = array_values(array_map('strval', $labels));
        $datasets = array_values(array_map(static fn (array $set): array => [
            'label' => (string) ($set['label'] ?? ''),
            'data'  => array_values(array_map(static fn ($v) => $v === null ? null : (float) $v, (array) ($set['data'] ?? []))),
        ] + array_filter([
            'color'  => isset($set['color']) ? (string) $set['color'] : null,
            'colors' => isset($set['colors']) ? array_values(array_map('strval', (array) $set['colors'])) : null,
            'type'  => isset($set['type']) ? (string) $set['type'] : null,
        ], static fn ($v): bool => $v !== null), $datasets));

        $title    = (string) ($options['title'] ?? '');
        $decimals = max(0, min(6, (int) ($options['decimals'] ?? 2)));
        $config   = [
            'type'     => $type,
            'labels'   => $labels,
            'datasets' => $datasets,
            'format'   => array_filter([
                'style'    => $format === 'number' ? null : $format,
                'currency' => $format === 'currency' ? strtoupper((string) ($options['currency'] ?? 'EUR')) : null,
                'decimals' => $decimals,
            ], static fn ($v): bool => $v !== null),
            'stacked'  => !empty($options['stacked']),
            'height'   => max(80, (int) ($options['height'] ?? 300)),
            'options'  => (object) (array) ($options['chart'] ?? []),
        ];

        self::enqueueLibrary();

        // JSON_HEX_TAG: a label holding `</script>` cannot close the element early.
        $json = (string) json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<figure class="pf-chart-figure" data-pf-chart-figure'
            . (isset($options['id']) ? ' id="' . self::e((string) $options['id']) . '"' : '') . '>'
            . ($title !== '' ? '<figcaption class="pf-chart-title">' . self::e($title) . '</figcaption>' : '')
            . '<div class="pf-chart-canvas" hidden><canvas aria-hidden="true"></canvas></div>'
            . '<script type="application/json" data-pf-chart-config>' . $json . '</script>'
            . self::table($title, $labels, $datasets, $format, $decimals, (string) ($config['format']['currency'] ?? ''))
            . '</figure>';
    }

    /**
     * The numbers as a table: what a screen reader reads, and what shows without Chart.js.
     *
     * @param list<string> $labels
     * @param list<array{label: string, data: list<float|null>}> $datasets
     */
    private static function table(string $title, array $labels, array $datasets, string $format, int $decimals, string $currency): string
    {
        $html = '<table class="pf-chart-data">'
            . ($title !== '' ? '<caption>' . self::e($title) . '</caption>' : '')
            . '<thead><tr><td></td>';
        foreach ($datasets as $set) {
            $html .= '<th scope="col">' . self::e($set['label']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($labels as $i => $label) {
            $html .= '<tr><th scope="row">' . self::e($label) . '</th>';
            foreach ($datasets as $set) {
                $value = $set['data'][$i] ?? null;
                $html .= '<td>' . ($value === null ? '' : self::e(self::number($value, $format, $decimals, $currency))) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /** A number as the table shows it; the browser reformats it in the page's language when it draws. */
    private static function number(float $value, string $format, int $decimals, string $currency): string
    {
        $shown = number_format($format === 'percent' ? $value * 100 : $value, $decimals, '.', ',');
        if ($decimals > 0) {
            // Up to `decimals`, as Intl's maximumFractionDigits shows them: 12.50 reads 12.5.
            $shown = rtrim(rtrim($shown, '0'), '.');
        }

        return match ($format) {
            'percent'  => $shown . '%',
            'currency' => $shown . ' ' . $currency,
            default    => $shown,
        };
    }

    /** Put Chart.js on the page, where the document knows the library. */
    private static function enqueueLibrary(): void
    {
        try {
            $doc = \Pramnos\Framework\Factory::getDocument();
        } catch (\Throwable) {
            return;
        }
        if (is_object($doc) && method_exists($doc, 'isScriptRegistered') && $doc->isScriptRegistered('chartjs')) {
            $doc->enqueueScript('chartjs');
        }
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
