<?php

declare(strict_types=1);

namespace Pramnos\Html;

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
 */
final class HistoryPanel
{
    /**
     * @param array{perPage?: int, changes?: bool, title?: string, empty?: string, param?: string} $options
     *  - `perPage` — lines a page (20)
     *  - `changes` — every save's changed fields too, not only the events recorded (false)
     *  - `title`, `empty` — the heading, and what an empty history says
     *  - `param` — the query parameter that carries the page (`history_page`)
     */
    public static function render(string $entity, string|int $itemid, array $options = []): string
    {
        $filters = ['entity' => $entity, 'itemid' => (string) $itemid];
        if (!empty($options['changes'])) {
            $filters['origins'] = ['events', 'feed'];
        }
        if (!History::allows(\Pramnos\User\User::current() ?: null, $filters)) {
            return '';
        }
        $param = (string) ($options['param'] ?? 'history_page');
        $page  = max(1, (int) ($_GET[$param] ?? 1));
        try {
            $found = History::page($filters, $page, (int) ($options['perPage'] ?? 20));
        } catch (\Throwable) {
            // No change log here: no tab rather than a broken page.
            return '';
        }

        $html = '<section class="pf-history" aria-label="' . self::e(self::t((string) ($options['title'] ?? 'History'))) . '">'
            . '<h3 class="pf-history-title">' . self::e(self::t((string) ($options['title'] ?? 'History'))) . '</h3>';
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
                . ($page > 1 ? '<a href="' . self::e(self::link($param, $page - 1)) . '">' . self::e(self::t('Newer')) . '</a>' : '')
                . '<span>' . $page . ' / ' . $found['pages'] . '</span>'
                . ($page < $found['pages'] ? '<a href="' . self::e(self::link($param, $page + 1)) . '">' . self::e(self::t('Older')) . '</a>' : '')
                . '</nav>';
        }

        return $html . '</section>';
    }

    /** This page's address with another history page. */
    private static function link(string $param, int $page): string
    {
        $query = $_GET;
        unset($query['_option'], $query['_controller'], $query['_action']);
        $query[$param] = $page;

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
