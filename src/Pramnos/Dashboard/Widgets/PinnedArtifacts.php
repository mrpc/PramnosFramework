<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;

/**
 * What the person pinned from Ask to their dashboard: each artifact as it was kept — a table, a
 * chart, a list — newest pin last. Offered where the AI feature is on; each person sees their own.
 */
final class PinnedArtifacts extends Widget
{
    public function id(): string
    {
        return 'ai.pinned';
    }

    public function title(): string
    {
        return 'Pinned from Ask';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'The tables, charts and lists you pinned from your Ask conversations.';
    }

    /** Where the AI feature is on, for whoever is signed in. */
    public function allowed(?object $user): bool
    {
        return $user !== null && (int) ($user->userid ?? 0) > 1 && \Pramnos\Application\FeatureRegistry::isEnabled('llm');
    }

    public function render(): string
    {
        $user = \Pramnos\User\User::getCurrentUser();
        $list = is_object($user) ? \Pramnos\Llm\Chat\Artifacts::pinned($user) : [];
        if ($list === []) {
            return '<p>' . htmlspecialchars(\Pramnos\Translator\Language::getInstance()->_('Nothing pinned yet: pin a table or a chart from an Ask answer.'), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return implode('', array_map(static fn (array $a): string => \Pramnos\Llm\Chat\Web\ArtifactView::html($a), $list));
    }
}
