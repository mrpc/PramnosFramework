<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Scope;

/**
 * The assistant on a record's own screen — a customer's card, a ticket — as a panel: what the
 * person reading has about this record (artifacts made with its `subject`, theirs and shared with
 * them), each opening on a page of its own, and a way to ask about it.
 *
 * ```php
 * echo SubjectPanel::html(new Scope($me->userid, null, Scope::ADMIN), 'user', $user->userid, 'Summarise this customer');
 * ```
 *
 * The question opens the Ask with the record named (`user:123`), so what it keeps is about it.
 * Already escaped; neutral classes (`pf-subject-panel`), styled by the application.
 */
final class SubjectPanel
{
    /**
     * @param array{open?: string, ask?: string} $urls Where an artifact opens (its id appended) and where a question is asked (`?q=` appended); the Ask's by default
     */
    public static function html(Scope $scope, string $entity, string|int $id, string $question = '', array $urls = []): string
    {
        $e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t     = static fn (string $s): string => $e(\Pramnos\Translator\Language::getInstance()->_($s));
        $open  = $urls['open'] ?? (function_exists('adminUrl') ? adminUrl('Ai/printable/') : '');
        $ask   = $urls['ask'] ?? (function_exists('adminUrl') ? adminUrl('Ai') : '');
        $about = (new Artifacts($scope))->about($entity, $id);
        $html  = '<section class="pf-subject-panel" data-pf-subject="' . $e($entity . ':' . $id) . '"><h3>' . $t('The assistant on this') . '</h3>';
        if ($about === []) {
            $html .= '<p class="pf-subject-empty">' . $t('Nothing made about it yet.') . '</p>';
        } else {
            $html .= '<ul>';
            foreach ($about as $one) {
                $html .= '<li><a href="' . $e($open . $one['artifact_id']) . '" target="_blank" rel="noopener">' . $e($one['title']) . '</a> '
                    . '<small>' . $e(substr($one['updated_at'], 0, 10)) . ($one['shared'] ? ' · ' . $t('shared with you') : '') . '</small></li>';
            }
            $html .= '</ul>';
        }
        if ($ask !== '') {
            // The record named in the question: what the answer keeps is about it.
            $q     = trim($question !== '' ? $question : (string) \Pramnos\Translator\Language::getInstance()->_('Tell me about this record')) . ' (' . $entity . ':' . $id . ')';
            $html .= '<p><a class="pf-subject-ask" href="' . $e($ask . (str_contains($ask, '?') ? '&' : '?') . 'q=' . rawurlencode($q)) . '">' . $t('Ask about this') . '</a></p>';
        }

        return $html . '</section>';
    }
}
