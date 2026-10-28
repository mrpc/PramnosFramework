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
            'table' => sprintf(\Pramnos\Translator\Language::getInstance()->_('Table · %d rows'), count((array) ($content['rows'] ?? []))),
            'list'  => sprintf(\Pramnos\Translator\Language::getInstance()->_('List · %d %s'), count((array) ($content['ids'] ?? [])), (string) ($artifact['entity'] ?? '')),
            default => \Pramnos\Translator\Language::getInstance()->_('Text'),
        };

        $html = '<section class="pf-artifact ' . $e($frame) . '" data-pf-artifact="' . (int) ($artifact['artifact_id'] ?? 0) . '" data-pf-artifact-kind="' . $e($kind) . '">'
            . '<header><strong>' . $e($artifact['title'] ?? '') . '</strong> <span class="' . $e($muted) . '">' . $e($label) . '</span></header>';

        if ($kind === 'table') {
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
        } else {
            $html .= '<div class="pf-chat-answer">' . Markdown::toHtml((string) ($content['text'] ?? '')) . '</div>';
        }

        return $html . '</section>';
    }
}
