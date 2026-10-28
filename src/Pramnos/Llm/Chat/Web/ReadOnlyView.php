<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Markdown;

/**
 * A report or a share for somebody who cannot open Ask, already escaped: its title, a line saying
 * what it is, and each message with its artifacts — nothing to ask, nothing to change.
 */
final class ReadOnlyView
{
    /**
     * @param list<array<string, mixed>> $messages role, content, artifacts
     * @param array<string, string>      $c        The theme's classes: card, muted
     */
    public static function html(string $title, array $messages, string $note, array $c = []): string
    {
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<div class="px-4 py-6" data-pf-read-only><h1>' . $e($title) . '</h1>'
            . ($note !== '' ? '<p class="' . $e($c['muted'] ?? '') . '">' . $e($note) . '</p>' : '')
            . '<p class="' . $e($c['muted'] ?? '') . '">' . $e(\Pramnos\Translator\Language::getInstance()->_('Read only.')) . '</p>';
        foreach ($messages as $m) {
            $mine  = ($m['role'] ?? '') === 'user';
            $html .= '<article class="' . $e($c['card'] ?? '') . '" style="padding:12px;margin:12px 0" data-pf-chat-role="' . ($mine ? 'user' : 'assistant') . '">'
                . ($mine ? '<div style="white-space:pre-wrap">' . $e($m['content'] ?? '') . '</div>' : '<div class="pf-chat-answer">' . Markdown::toHtml((string) ($m['content'] ?? '')) . '</div>');
            foreach ((array) ($m['artifacts'] ?? []) as $artifact) {
                $html .= ArtifactView::html($artifact, $c['card'] ?? '', 'table', $c['muted'] ?? '');
            }
            $html .= '</article>';
        }

        return $html . '</div>';
    }
}
