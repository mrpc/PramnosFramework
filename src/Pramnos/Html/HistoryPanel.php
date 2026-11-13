<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Changelog\ChangelogReader;
use Pramnos\Changelog\ChangelogRenderer;
use Pramnos\Changelog\History;

/**
 * A record's history, drawn in a server-rendered page — the "History" tab of an edit screen, in
 * the administration or on the site.
 *
 * ```php
 * <?php echo \Pramnos\Html\HistoryPanel::render('post', $post->postid); ?>
 * <?php echo \Pramnos\Html\HistoryPanel::render('post', $post->postid, ['changes' => true, 'perPage' => 50]); ?>
 * ```
 *
 * Who did what and when, newest first, a page at a time ({@see History::page()}): the events an
 * application recorded, and with `changes` every save's fields as well. Nothing for somebody who
 * may not read it ({@see History::allows()}). Older pages are links — `?history_page=2` — so it
 * needs no script; the scaffolded stylesheets style the `pf-history-*` hooks, and a SPA draws the
 * same with `HistoryPanel.svelte`.
 *
 * **Filtered by event type.** When the record has more than one distinct event code
 * ({@see ChangelogReader::events()}), a row of chips appears above the list — "All" and one per
 * code, named through {@see ChangelogRenderer::eventLabel()} — so a long mixed history (saves
 * and several kinds of application event) can be narrowed to one kind without paging through
 * everything else. `?history_event=verification.completed`, a plain link beside the pager; it
 * resets the page to 1, since the filtered total is a different count.
 */
final class HistoryPanel
{
    /**
     * @param array{perPage?: int, changes?: bool, title?: string, empty?: string, param?: string, eventParam?: string} $options
     *  - `perPage` — lines a page (20)
     *  - `changes` — every save's changed fields too, not only the events recorded (false)
     *  - `title`, `empty` — the heading, and what an empty history says
     *  - `param` — the query parameter that carries the page (`history_page`)
     *  - `eventParam` — the query parameter that carries the event filter (`history_event`)
     */
    public static function render(string $entity, string|int $itemid, array $options = []): string
    {
        $base = ['entity' => $entity, 'itemid' => (string) $itemid];
        if (!empty($options['changes'])) {
            $base['origins'] = ['events', 'feed'];
        }
        if (!History::allows(\Pramnos\User\User::current() ?: null, $base)) {
            return '';
        }
        $param      = (string) ($options['param'] ?? 'history_page');
        $eventParam = (string) ($options['eventParam'] ?? 'history_event');
        $selected   = (string) ($_GET[$eventParam] ?? '');
        $page       = max(1, (int) ($_GET[$param] ?? 1));

        try {
            $events = ChangelogReader::events($entity, $itemid, (array) ($base['origins'] ?? ['events']));
            // Trusted only once it is one of the record's own codes — a stale or guessed
            // value filters to nothing rather than silently falling back to "All".
            $filters = in_array($selected, $events, true) ? $base + ['event' => $selected] : $base;
            $found   = History::page($filters, $page, (int) ($options['perPage'] ?? 20));
        } catch (\Throwable) {
            // No change log here: no tab rather than a broken page.
            return '';
        }

        $html = '<section class="pf-history" aria-label="' . self::e(self::t((string) ($options['title'] ?? 'History'))) . '">'
            . '<h3 class="pf-history-title">' . self::e(self::t((string) ($options['title'] ?? 'History'))) . '</h3>';
        if (count($events) > 1) {
            $html .= self::filterChips($entity, $events, $selected, $eventParam, $param);
        }
        if ($found['lines'] === []) {
            return $html . '<p class="pf-history-empty">' . self::e(self::t((string) ($options['empty'] ?? 'Nothing recorded yet.'))) . '</p></section>';
        }
        $html .= '<ol class="pf-history-list">';
        foreach ($found['lines'] as $line) {
            $html .= '<li class="pf-history-line">'
                . '<span class="pf-history-what">' . self::e((string) $line['what']) . '</span>'
                . '<span class="pf-history-meta">'
                . '<span class="pf-history-who">' . self::e($line['who'] ?? self::t('System')) . '</span>'
                . ' · <time class="pf-history-when" datetime="' . self::e((string) $line['at']) . '">' . self::e(self::when((string) $line['at'])) . '</time>'
                . ($line['source'] !== '' ? ' · <span class="pf-history-source">' . self::e((string) $line['source']) . '</span>' : '')
                . '</span>';
            $html .= '</li>';
        }
        $html .= '</ol>';
        if ($found['pages'] > 1) {
            $html .= '<nav class="pf-history-pager" aria-label="' . self::e(self::t('History pages')) . '">'
                . ($page > 1 ? '<a href="' . self::e(self::link([$param => $page - 1])) . '">' . self::e(self::t('Newer')) . '</a>' : '')
                . '<span>' . $page . ' / ' . $found['pages'] . '</span>'
                . ($page < $found['pages'] ? '<a href="' . self::e(self::link([$param => $page + 1])) . '">' . self::e(self::t('Older')) . '</a>' : '')
                . '</nav>';
        }

        return $html . '</section>';
    }

    /**
     * The "All" chip and one per event code the record actually has, each naming the code
     * through {@see ChangelogRenderer::eventLabel()}; the current one marked `aria-current`.
     * Picking one resets the pager to page 1 — the filtered total is a different count.
     *
     * @param list<string> $events
     */
    private static function filterChips(string $entity, array $events, string $selected, string $eventParam, string $param): string
    {
        $chip = static function (string $label, string $href, bool $current): string {
            return '<a href="' . self::e($href) . '"' . ($current ? ' aria-current="true"' : '') . '>' . self::e($label) . '</a>';
        };
        $html = '<nav class="pf-history-filter" aria-label="' . self::e(self::t('Filter history')) . '">'
            . $chip(self::t('All'), self::link([$eventParam => null, $param => 1]), $selected === '');
        foreach ($events as $event) {
            $html .= $chip(ChangelogRenderer::eventLabel($entity, $event), self::link([$eventParam => $event, $param => 1]), $selected === $event);
        }

        return $html . '</nav>';
    }

    /**
     * This page's address with the given query parameters overridden — null removes one. Used
     * for the pager (one page at a time) and the event filter (one chip at a time), so a click
     * on either changes only what it names and keeps everything else — the other, the sort, a
     * search box above it — exactly as it was.
     *
     * @param array<string, int|string|null> $overrides
     */
    private static function link(array $overrides): string
    {
        $query = $_GET;
        unset($query['_option'], $query['_controller'], $query['_action']);
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return '?' . http_build_query($query);
    }

    /** A stored moment, short and in this server's time. */
    private static function when(string $at): string
    {
        $time = strtotime($at);

        return $time === false ? $at : date('j M Y, H:i', $time);
    }

    /** Translated where the application has the phrase. */
    private static function t(string $text): string
    {
        return function_exists('t') ? (string) \t($text) : $text;
    }

    /** Escaped for HTML. */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
