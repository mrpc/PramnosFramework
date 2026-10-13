<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A model's Markdown as HTML that is safe to print — for the server-rendered conversation page.
 *
 * A reply is text nobody here wrote, so everything is escaped **first** and only then are the
 * few shapes a model uses turned into markup: headings, **bold**, *italic*, `code`, fenced
 * code blocks, lists, tables, links and bare addresses. A link goes only to `http(s)` and opens
 * in a new tab, so following one does not leave the conversation. Anything else stays the
 * escaped text it was.
 *
 * Not a Markdown implementation: no nested lists, no images, no HTML. The SPA uses `marked` with
 * DOMPurify; this is the same promise — safe, readable — with no dependency.
 */
final class Markdown
{
    /**
     * A table is styled where it is drawn: `table table-sm` for Bootstrap and daisyUI, and the
     * spacing and rules inline for a theme with no such class — the conversation views are the
     * project's copies, so a fix here reaches them without a resync. Inline styles are allowed by
     * the framework's CSP (`style-src-attr`); a `<style>` element would need the page's nonce.
     */
    private const TABLE = '<div style="overflow-x:auto;margin:.5rem 0"><table class="table table-sm" style="border-collapse:collapse;width:auto">';

    private const TH = '<th style="text-align:left;padding:.25rem .75rem;border-bottom:1px solid currentColor">';

    private const TD = '<td style="padding:.25rem .75rem;border-bottom:1px solid rgba(128,128,128,.35)">';

    public static function toHtml(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $html  = [];
        $para  = [];
        $count = count($lines);

        $flush = static function () use (&$para, &$html): void {
            if ($para !== []) {
                $html[] = '<p>' . implode("<br>\n", array_map([self::class, 'inline'], $para)) . '</p>';
                $para   = [];
            }
        };

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // A fenced block, kept as it is — or a chart, when it says so and is one.
            if (preg_match('/^\s*```\s*(\w*)/', $line, $fence) === 1) {
                $flush();
                $code = [];
                for ($i++; $i < $count && preg_match('/^\s*```/', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }
                $block  = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                $chart  = strtolower($fence[1]) === 'chart' ? self::chart(implode("\n", $code)) : null;
                $html[] = $chart === null
                    ? $block
                    : '<div class="pf-chart" data-pf-chart="' . self::escape((string) json_encode($chart, JSON_UNESCAPED_UNICODE)) . '"'
                        . ' role="img" aria-label="' . self::escape((string) ($chart['title'] ?? 'Chart')) . '">' . $block . '</div>';
                continue;
            }

            if (trim($line) === '') {
                $flush();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $flush();
                $level  = min(6, strlen($m[1]) + 2);   // a reply's "#" is a section, not the page's title
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            // A table: a row, then a separator of dashes.
            if (str_contains($line, '|') && $i + 1 < $count && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[$i + 1]) === 1) {
                $flush();
                $rows = [self::cells($line)];
                for ($i += 2; $i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== ''; $i++) {
                    $rows[] = self::cells($lines[$i]);
                }
                $i--;
                $head   = array_shift($rows);
                $table  = self::TABLE . '<thead><tr>' . implode('', array_map(static fn (string $c): string => self::TH . self::inline($c) . '</th>', $head)) . '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $table .= '<tr>' . implode('', array_map(static fn (string $c): string => self::TD . self::inline($c) . '</td>', $row)) . '</tr>';
                }
                $html[] = $table . '</tbody></table></div>';
                continue;
            }

            if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line, $marker) === 1) {
                $flush();
                $ordered = ctype_digit($marker[1][0]);
                $items   = [];
                for (; $i < $count && preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $item) === 1; $i++) {
                    $items[] = '<li>' . self::inline($item[2]) . '</li>';
                }
                $i--;
                $tag    = $ordered ? 'ol' : 'ul';
                $html[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
                continue;
            }

            $para[] = $line;
        }

        $flush();

        return implode("\n", $html);
    }

    /** One line's inline marks, after escaping it. */
    private static function inline(string $text): string
    {
        $codes = [];
        // Code first, kept out of every other rule.
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::escape($m[1]) . '</code>';

            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);

        $links = [];
        $text  = (string) preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', static function (array $m) use (&$links): string {
            $links[] = self::link($m[2], self::escape($m[1]));

            return "\x01" . (count($links) - 1) . "\x01";
        }, $text);

        $text = self::escape($text);
        $text = (string) preg_replace_callback('/(?<![\w"\/])(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/', static fn (array $m): string => self::link(html_entity_decode($m[1], ENT_QUOTES), $m[1]), $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])|(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<em>$1$2</em>', $text);

        $text = (string) preg_replace_callback('/\x01(\d+)\x01/', static fn (array $m): string => $links[(int) $m[1]], $text);

        return (string) preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $codes[(int) $m[1]], $text);
    }

    /**
     * A ```chart block's spec, when it is one a page can draw; null keeps it as code.
     *
     * One JSON object: `type` (bar, hbar, line, area, pie, doughnut), `labels`, and `series` —
     * each `{name, data: [numbers]}`, one for a pie — with `title`, `subtitle`, `x`, `y`,
     * `stacked` and `min` if wanted. See {@see CHARTS}.
     *
     * @return array<string, mixed>|null
     */
    public static function chart(string $json): ?array
    {
        $spec = json_decode($json, true);

        if (!is_array($spec) || !in_array($spec['type'] ?? null, ['bar', 'hbar', 'line', 'area', 'pie', 'doughnut'], true)
            || !is_array($spec['labels'] ?? null) || !is_array($spec['series'] ?? null) || $spec['series'] === []) {
            return null;
        }

        foreach ($spec['series'] as $series) {
            if (!is_array($series) || !is_array($series['data'] ?? null)
                || array_filter($series['data'], static fn ($v): bool => !is_int($v) && !is_float($v) && $v !== null) !== []) {
                return null;
            }
        }

        return $spec;
    }

    /**
     * What a model is told so that it charts: when, and in what shape. An assistant adds it to
     * its prompt — the analyst's does.
     */
    public const CHARTS = 'When figures compare categories or change over time, you may add a chart: a fenced code block '
        . 'with the info string chart, holding one JSON object: {"type": "bar|hbar|line|area|pie|doughnut", "title": "…", '
        . '"labels": ["…"], "series": [{"name": "…", "data": [numbers]}], "x": "axis name", "y": "axis name", "stacked": false}. '
        . 'Use line or area for a series over time, bar or hbar to compare, pie or doughnut only for parts of one whole '
        . '(one series, a few slices). Numbers only in data, one per label. Keep the figures in a table or the text as well, '
        . 'so they can be read and copied.';

    /** @return list<string> A table row's cells */
    private static function cells(string $line): array
    {
        return array_map('trim', explode('|', trim(trim($line), '|')));
    }

    private static function link(string $url, string $label): string
    {
        return '<a href="' . self::escape($url) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
