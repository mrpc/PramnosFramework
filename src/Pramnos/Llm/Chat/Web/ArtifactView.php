<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Markdown;

/**
 * An artifact drawn as HTML, already escaped — the same markup in every theme, which passes its
 * own classes for the frame and the table.
 *
 * A table is a table; a list shows how many records it names and the first of them, by label
 * where it has labels; a text is its Markdown. Each carries `data-pf-artifact` with its id, the
 * hook for whatever a page adds to it (open, share, act on).
 */
final class ArtifactView
{
    /** List entries drawn before "and N more". */
    public const LIST_PREVIEW = 20;

    /**
     * @param array<string, mixed> $artifact As {@see \Pramnos\Llm\Chat\Artifacts::shape()} answers
     * @param string               $frame    The theme's class for the frame
     * @param string               $table    The theme's class for a table
     * @param string               $muted    The theme's class for secondary text
     */
    public static function html(array $artifact, string $frame = '', string $table = '', string $muted = ''): string
    {
        $e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $content = (array) ($artifact['content'] ?? []);
        $kind    = (string) ($artifact['kind'] ?? '');
        $label   = match ($kind) {
            'table' => is_array($content['query'] ?? null)
                ? sprintf(\Pramnos\Translator\Language::getInstance()->_('Query · %d rows · run %s'), count((array) ($content['rows'] ?? [])), date('Y-m-d H:i', (int) ($content['query']['ran_at'] ?? 0)))
                : sprintf(\Pramnos\Translator\Language::getInstance()->_('Table · %d rows'), count((array) ($content['rows'] ?? []))),
            'chart' => \Pramnos\Translator\Language::getInstance()->_('Chart'),
            'list'  => sprintf(\Pramnos\Translator\Language::getInstance()->_('List · %d %s'), count((array) ($content['ids'] ?? [])), (string) ($artifact['entity'] ?? '')),
            'proposal' => \Pramnos\Translator\Language::getInstance()->_(match ((string) ($content['status'] ?? 'pending')) {
                'approved' => 'Proposed change · approved', 'rejected' => 'Proposed change · rejected', default => 'Proposed change · waiting for you',
            }),
            default => \Pramnos\Translator\Language::getInstance()->_('Text'),
        };

        $html = '<section class="pf-artifact ' . $e($frame) . '" data-pf-artifact="' . (int) ($artifact['artifact_id'] ?? 0) . '" data-pf-artifact-kind="' . $e($kind) . '">'
            . '<header><strong>' . $e($artifact['title'] ?? '') . '</strong> <span class="' . $e($muted) . '">' . $e($label) . '</span></header>';

        $view = $kind === 'table' && is_array($content['view'] ?? null) ? $content['view'] : null;
        if ($view !== null && in_array($view['by'] ?? '', (array) ($content['columns'] ?? []), true)) {
            $html .= $view['type'] === 'calendar' ? self::calendar($content, (string) $view['by'], $muted) : self::board($content, (string) $view['by'], $muted);
        } elseif ($kind === 'table') {
            $html .= '<div style="overflow:auto;max-height:24rem"><table class="' . $e($table) . '"><thead><tr>';
            foreach ((array) ($content['columns'] ?? []) as $column) {
                $html .= '<th>' . $e($column) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ((array) ($content['rows'] ?? []) as $row) {
                $html .= '<tr>';
                foreach ((array) $row as $cell) {
                    $html .= '<td>' . $e(is_bool($cell) ? ($cell ? '✓' : '✗') : $cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        } elseif ($kind === 'list') {
            $ids    = array_values((array) ($content['ids'] ?? []));
            $labels = array_values((array) ($content['labels'] ?? []));
            $html  .= '<ul>';
            foreach (array_slice($ids, 0, self::LIST_PREVIEW) as $i => $id) {
                $html .= '<li>' . $e(isset($labels[$i]) && $labels[$i] !== '' ? $labels[$i] . ' (#' . $id . ')' : '#' . $id) . '</li>';
            }
            $html .= '</ul>';
            if (count($ids) > self::LIST_PREVIEW) {
                $html .= '<p class="' . $e($muted) . '">' . $e(sprintf(\Pramnos\Translator\Language::getInstance()->_('and %d more'), count($ids) - self::LIST_PREVIEW)) . '</p>';
            }
        } elseif ($kind === 'chart') {
            // Drawn by the page's Chart.js, with a table of the same numbers under it.
            $html .= \Pramnos\Html\Chart::render((string) ($content['chart'] ?? 'bar'), (array) ($content['labels'] ?? []),
                array_map(static fn (array $s): array => ['label' => (string) ($s['label'] ?? ''), 'data' => (array) ($s['data'] ?? [])], (array) ($content['series'] ?? [])),
                ['title' => (string) ($artifact['title'] ?? '')]);
        } elseif ($kind === 'proposal') {
            // What it would do, in full: the person approves exactly this.
            $html .= '<p>' . $e(sprintf(\Pramnos\Translator\Language::getInstance()->_('%s, on %d %s'), (string) ($content['label'] ?? ''),
                count((array) ($content['ids'] ?? [])), (string) ($artifact['entity'] ?? ''))) . '</p>';
            if (($content['reason'] ?? '') !== '') {
                $html .= '<p class="' . $e($muted) . '">' . $e($content['reason']) . '</p>';
            }
            $html .= '<ul>';
            foreach ((array) ($content['params'] ?? []) as $name => $value) {
                $html .= '<li>' . $e($name) . ': ' . $e(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)) . '</li>';
            }
            $html .= '<li>' . $e(\Pramnos\Translator\Language::getInstance()->_('Records')) . ': ' . $e(implode(', ', array_slice(array_map('strval', (array) ($content['ids'] ?? [])), 0, self::LIST_PREVIEW))
                . (count((array) ($content['ids'] ?? [])) > self::LIST_PREVIEW ? ' …' : '')) . '</li></ul>';
            if (is_array($content['result'] ?? null)) {
                $result = $content['result'];
                $html  .= '<p class="' . $e($muted) . '">' . $e((int) ($result['queued'] ?? 0) > 0
                    ? sprintf(\Pramnos\Translator\Language::getInstance()->_('%d queued'), (int) $result['queued'])
                    : ((string) ($result['says'] ?? '') !== '' ? (string) $result['says'] : sprintf(\Pramnos\Translator\Language::getInstance()->_('Done for %d.'), (int) ($result['done'] ?? 0)))) . '</p>';
            }
        } else {
            $html .= '<div class="pf-chat-answer">' . Markdown::toHtml((string) ($content['text'] ?? '')) . '</div>';
        }

        return $html . '</section>';
    }

    /**
     * A table as a board: a column for each value of `by`, in the order they first appear, a card
     * for each row — its first other cell as the title, the rest under it.
     *
     * @param array<string, mixed> $content
     */
    private static function board(array $content, string $by, string $muted): string
    {
        $e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $columns = array_values((array) ($content['columns'] ?? []));
        $at      = (int) array_search($by, $columns, true);
        $lanes   = [];
        foreach ((array) ($content['rows'] ?? []) as $row) {
            $row = array_values((array) $row);
            $lanes[(string) ($row[$at] ?? '')][] = $row;
        }
        $html = '<div class="pf-artifact-board" style="display:flex;gap:.75rem;overflow-x:auto;align-items:flex-start">';
        foreach ($lanes as $lane => $rows) {
            $html .= '<section class="pf-artifact-lane" style="flex:0 0 14rem;min-width:0"><h4 style="margin:.25rem 0">' . $e($lane === '' ? '—' : $lane)
                . ' <span class="' . $e($muted) . '">' . count($rows) . '</span></h4>';
            foreach ($rows as $row) {
                $cells = [];
                foreach ($columns as $i => $column) {
                    if ($i !== $at && ($row[$i] ?? null) !== null && (string) $row[$i] !== '') {
                        $cells[] = [$column, $row[$i]];
                    }
                }
                $title = array_shift($cells);
                $html .= '<article class="pf-artifact-card" style="border:1px solid rgba(127,127,127,.35);border-radius:.4rem;padding:.4rem .5rem;margin:.35rem 0">'
                    . '<strong>' . $e($title[1] ?? '—') . '</strong>';
                foreach ($cells as [$column, $value]) {
                    $html .= '<div class="' . $e($muted) . '" style="font-size:.85em">' . $e($column) . ': ' . $e(is_bool($value) ? ($value ? '✓' : '✗') : $value) . '</div>';
                }
                $html .= '</article>';
            }
            $html .= '</section>';
        }

        return $html . '</div>';
    }

    /**
     * A table on a calendar: each month its rows fall in, a grid of weeks, a row under its day —
     * its first other cell. Rows whose `by` is not a date are listed after it.
     *
     * @param array<string, mixed> $content
     */
    private static function calendar(array $content, string $by, string $muted): string
    {
        $e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $columns = array_values((array) ($content['columns'] ?? []));
        $at      = (int) array_search($by, $columns, true);
        $label   = $at === 0 ? 1 : 0;
        $days    = [];
        $undated = [];
        foreach ((array) ($content['rows'] ?? []) as $row) {
            $row  = array_values((array) $row);
            $time = strtotime((string) ($row[$at] ?? ''));
            $time === false || trim((string) ($row[$at] ?? '')) === '' ? $undated[] = $row : $days[date('Y-m-d', $time)][] = (string) ($row[$label] ?? '');
        }
        ksort($days);
        $html   = '<div class="pf-artifact-calendar">';
        // ponytail: every month with a row, at most six; a year of rows reads better as the table.
        $months = array_slice(array_values(array_unique(array_map(static fn (string $d): string => substr($d, 0, 7), array_keys($days)))), 0, 6);
        foreach ($months as $month) {
            $first = new \DateTimeImmutable($month . '-01');
            $html .= '<table style="width:100%;table-layout:fixed;border-collapse:collapse;margin:.5rem 0"><caption style="text-align:left;font-weight:600">'
                . $e($first->format('F Y')) . '</caption><tr>';
            foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $name) {
                $html .= '<th class="' . $e($muted) . '" style="font-weight:normal">' . $e(\Pramnos\Translator\Language::getInstance()->_($name)) . '</th>';
            }
            $html .= '</tr><tr>' . str_repeat('<td></td>', (int) $first->format('N') - 1);
            for ($d = $first; $d->format('Y-m') === $month; $d = $d->modify('+1 day')) {
                $items = $days[$d->format('Y-m-d')] ?? [];
                $html .= '<td style="vertical-align:top;border:1px solid rgba(127,127,127,.25);padding:2px;height:3.5rem"><div class="' . $e($muted) . '" style="font-size:.8em">' . $d->format('j') . '</div>';
                foreach ($items as $item) {
                    $html .= '<div style="font-size:.85em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . $e($item) . '">' . $e($item) . '</div>';
                }
                $html .= '</td>' . ((int) $d->format('N') === 7 ? '</tr><tr>' : '');
            }
            $html .= '</tr></table>';
        }
        if ($undated !== []) {
            $html .= '<ul class="' . $e($muted) . '">';
            foreach ($undated as $row) {
                $html .= '<li>' . $e((string) ($row[$label] ?? '')) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '</div>';
    }
}
