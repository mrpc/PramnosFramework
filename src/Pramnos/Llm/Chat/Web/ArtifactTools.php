<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Artifacts;

/**
 * What can be done with an artifact besides sharing it, as buttons under it, already escaped:
 * download it as CSV (rows only), open it on a page of its own to print or save as PDF, and pin
 * it to the dashboard or take it off.
 */
final class ArtifactTools
{
    /**
     * @param array<string, mixed>  $artifact As Artifacts::shape() answers
     * @param array<string, string> $urls     download, print, pin (each followed by the id)
     * @param list<int>             $pinned   The ids this person has pinned
     * @param array<string, string> $c        The theme's classes: btnGhost
     */
    public static function buttons(array $artifact, array $urls, array $pinned, int $returnTo, string $token, array $c = []): string
    {
        $e  = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t  = static fn (string $s): string => $e(\Pramnos\Translator\Language::getInstance()->_($s));
        $id = (int) ($artifact['artifact_id'] ?? 0);
        $html = '<span class="pf-artifact-tools" data-pf-artifact-tools="' . $id . '">';
        if (($urls['download'] ?? '') !== '' && Artifacts::csv($artifact) !== null) {
            $html .= '<a href="' . $e($urls['download'] . $id) . '" class="' . $e($c['btnGhost'] ?? '') . '" download>' . $t('Download CSV') . '</a> ';
        }
        if (($urls['print'] ?? '') !== '') {
            $html .= '<a href="' . $e($urls['print'] . $id) . '" class="' . $e($c['btnGhost'] ?? '') . '" target="_blank" rel="noopener">' . $t('Print or save as PDF') . '</a> ';
        }
        if (($urls['pin'] ?? '') !== '') {
            $on    = in_array($id, $pinned, true);
            $html .= '<form method="post" action="' . $e($urls['pin'] . $id) . '" style="display:inline">' . $token
                . '<input type="hidden" name="conversation_id" value="' . $returnTo . '"><input type="hidden" name="pinned" value="' . ($on ? '0' : '1') . '">'
                . '<button type="submit" class="' . $e($c['btnGhost'] ?? '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '">' . ($on ? $t('Unpin from dashboard') : $t('Pin to dashboard')) . '</button></form>';
        }

        return $html . '</span>';
    }

    /** One artifact on a page of its own, for printing or saving as PDF: the title and the artifact, nothing else. */
    public static function printable(array $artifact): string
    {
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $e($artifact['title'] ?? '') . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:2rem;color:#111}table{border-collapse:collapse}th,td{border:1px solid #999;padding:4px 8px;text-align:left}'
            . '@media print{.pf-no-print{display:none}}</style></head><body>'
            . '<p class="pf-no-print"><button type="button" onclick="window.print()">' . $e(\Pramnos\Translator\Language::getInstance()->_('Print or save as PDF')) . '</button></p>'
            . ArtifactView::html($artifact) . '</body></html>';
    }
}
