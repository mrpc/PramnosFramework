<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Audience\Groups;
use Pramnos\Audience\Segments;

/**
 * "Segments and groups" on a person's card: where they belong now, since when, each linked to its
 * page.
 *
 * ```php
 * echo \Pramnos\Html\AudiencePanel::person($userid);
 * ```
 *
 * The framework's Users screen shows it; an application's own person card puts it where it likes.
 * Neutral `pf-audience-panel` markup the theme's stylesheet dresses. A part whose tables are not
 * installed is left out, so the card never fails because of it.
 */
final class AudiencePanel
{
    /** The panel for one person. */
    public static function person(int $userid): string
    {
        $e        = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $segments = self::quietly(static fn (): array => Segments::of($userid));
        $groups   = self::quietly(static fn (): array => Groups::of($userid));
        $items    = [];
        foreach ($segments as $s) {
            $items[] = '<li class="pf-audience-panel-item"><span class="pf-audience-panel-kind">' . $e(t('segment')) . '</span> '
                . '<a href="' . $e(adminUrl('Segments/view/') . $s['id'] . '?person=' . $userid) . '">' . $e($s['name']) . '</a> '
                . '<span class="pf-audience-panel-since">' . $e(t('since %s', date('Y-m-d', $s['since']))) . '</span></li>';
        }
        foreach ($groups as $g) {
            $items[] = '<li class="pf-audience-panel-item"><span class="pf-audience-panel-kind">' . $e(t('group')) . '</span> '
                . '<a href="' . $e(adminUrl('Groups/view/') . $g['id']) . '">' . $e($g['name']) . '</a></li>';
        }

        return '<section class="pf-audience-panel" aria-label="' . $e(t('Segments and groups')) . '">'
            . '<h3 class="pf-audience-panel-title">' . $e(t('Segments and groups')) . '</h3>'
            . ($items === []
                ? '<p class="pf-audience-panel-empty">' . $e(t('In no segment or group.')) . '</p>'
                : '<ul class="pf-audience-panel-list">' . implode('', $items) . '</ul>')
            . '</section>';
    }

    /**
     * A list, or none where its tables are missing.
     *
     * @param  callable(): list<array<string, mixed>> $read
     * @return list<array<string, mixed>>
     */
    private static function quietly(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            return [];
        }
    }
}
