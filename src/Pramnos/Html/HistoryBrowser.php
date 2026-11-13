<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Changelog\ChangelogReader;
use Pramnos\Changelog\History;

/**
 * Every change across every record, filtered and paged in a server-rendered admin page —
 * {@see HistoryPanel} is one record's tab; this is the whole log.
 *
 * ```php
 * <?php echo \Pramnos\Html\HistoryBrowser::render(); ?>
 * <?php echo \Pramnos\Html\HistoryBrowser::render(['entities' => ['post', 'invoice', 'package']]); ?>
 * ```
 *
 * A form reads `entity`, `event`, `logtype`, `source`, `from`, `to` from the query string —
 * {@see ChangelogReader::listing()}'s own filters, each optional and all of them together — and
 * resubmits as a plain GET, so no script is needed and the result is a bookmarkable address.
 * Older pages are links that keep the current filters, `?log_page=2`.
 *
 * **Who may read it.** The same {@see History::allows()} as `HistoryPanel`: without `entity`
 * matching the reader's own account, this is whoever may open the administration. A browser of
 * *everything* is an admin screen by default — there is no "my own history" shortcut here, since
 * a listing with no `entity`/`itemid` is never one person's own.
 */
final class HistoryBrowser
{
    /**
     * @param array{perPage?: int, title?: string, empty?: string, param?: string, entities?: list<string>} $options
     *  - `perPage` — lines a page (20)
     *  - `title`, `empty` — the heading, and what no match says
     *  - `param` — the query parameter that carries the page (`log_page`)
     *  - `entities` — offered as a `<select>`; without it, entity is free text
     */
    public static function render(array $options = []): string
    {
        $param   = (string) ($options['param'] ?? 'log_page');
        $filters = self::filtersFromQuery();
        if (!History::allows(\Pramnos\User\User::current() ?: null, $filters)) {
            return '';
        }

        $page = max(1, (int) ($_GET[$param] ?? 1));
        try {
            $found = History::page($filters, $page, (int) ($options['perPage'] ?? 20));
        } catch (\Throwable) {
            // No change log here: no page rather than a broken one.
            return '';
        }

        $html = '<section class="pf-history-browser">'
            . '<h3 class="pf-history-browser-title">' . self::e(self::t((string) ($options['title'] ?? 'All changes'))) . '</h3>'
            . self::filterForm($filters, (array) ($options['entities'] ?? []), $param);
        if ($found['lines'] === []) {
            return $html . '<p class="pf-history-browser-empty">'
                . self::e(self::t((string) ($options['empty'] ?? 'Nothing matches those filters.'))) . '</p></section>';
        }

        $html .= '<ol class="pf-history-browser-list">';
        foreach ($found['lines'] as $line) {
            $html .= '<li class="pf-history-browser-line">'
                . '<span class="pf-history-browser-record">' . self::e((string) $line['entity']) . ' #' . self::e((string) $line['itemid']) . '</span>'
                . '<span class="pf-history-what">' . self::e((string) $line['what']) . '</span>'
                . '<span class="pf-history-meta">'
                . '<span class="pf-history-who">' . self::e($line['who'] ?? self::t('System')) . '</span>'
                . ' · <time class="pf-history-when" datetime="' . self::e((string) $line['at']) . '">' . self::e(self::when((string) $line['at'])) . '</time>'
                . ($line['source'] !== '' ? ' · <span class="pf-history-source">' . self::e((string) $line['source']) . '</span>' : '')
                . '</span></li>';
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
     * {@see ChangelogReader::listing()}'s filters, read from the query string: `entity`,
     * `event`, `logtype`, `source` as given, `from`/`to` as `Y-m-d` dates turned into the Unix
     * times the reader expects. Anything blank is left out, so an empty form lists everything
     * rather than nothing.
     *
     * @return array<string, mixed>
     */
    private static function filtersFromQuery(): array
    {
        $filters = [];
        foreach (['entity', 'event', 'logtype', 'source'] as $name) {
            $value = trim((string) ($_GET[$name] ?? ''));
            if ($value === '') {
                continue;
            }
            $filters[$name] = $name === 'logtype' ? (int) $value : $value;
        }
        foreach (['from', 'to'] as $name) {
            $value = trim((string) ($_GET[$name] ?? ''));
            $time  = $value === '' ? false : strtotime($name === 'to' ? $value . ' 23:59:59' : $value);
            if ($time !== false) {
                $filters[$name] = $time;
            }
        }

        return $filters;
    }

    /**
     * The filter form: a plain GET, so submitting it is a normal navigation — the browser
     * builds the query string from these fields alone, which is also why the page number is
     * not one of them: a new filter starts back at page 1 without this form having to say so.
     *
     * @param array<string, mixed> $filters
     * @param list<string>         $entities
     */
    private static function filterForm(array $filters, array $entities, string $param): string
    {
        $field = static fn (string $label, string $input): string => '<label class="pf-history-browser-field">'
            . '<span>' . self::e($label) . '</span>' . $input . '</label>';

        $entityInput = $entities === []
            ? '<input type="text" name="entity" value="' . self::e((string) ($filters['entity'] ?? '')) . '">'
            : '<select name="entity"><option value=""></option>' . implode('', array_map(
                static fn (string $e): string => '<option value="' . self::e($e) . '"'
                    . (($filters['entity'] ?? '') === $e ? ' selected' : '') . '>' . self::e($e) . '</option>',
                $entities
            )) . '</select>';

        // A plain GET form replaces the whole query string with its own fields, so anything
        // else the address carries that this component does not itself manage — a tab this
        // browser is embedded under, say — rides along as a hidden field rather than vanishing
        // the moment the filters are submitted.
        $known  = ['entity', 'event', 'logtype', 'source', 'from', 'to', $param, '_option', '_controller', '_action'];
        $hidden = '';
        foreach ($_GET as $key => $value) {
            if (!in_array($key, $known, true) && is_scalar($value)) {
                $hidden .= '<input type="hidden" name="' . self::e((string) $key) . '" value="' . self::e((string) $value) . '">';
            }
        }

        $html = '<form method="get" class="pf-history-browser-filters">' . $hidden
            . $field(self::t('Entity'), $entityInput)
            . $field(self::t('Event'), '<input type="text" name="event" value="' . self::e((string) ($filters['event'] ?? '')) . '">')
            . $field(self::t('Log type'), '<input type="number" name="logtype" value="' . self::e(isset($filters['logtype']) ? (string) $filters['logtype'] : '') . '">')
            . $field(self::t('Source'), '<input type="text" name="source" value="' . self::e((string) ($filters['source'] ?? '')) . '">')
            . $field(self::t('From'), '<input type="date" name="from" value="' . self::e(isset($filters['from']) ? date('Y-m-d', (int) $filters['from']) : '') . '">')
            . $field(self::t('To'), '<input type="date" name="to" value="' . self::e(isset($filters['to']) ? date('Y-m-d', (int) $filters['to']) : '') . '">')
            . '<button type="submit">' . self::e(self::t('Filter')) . '</button>';
        if ($filters !== []) {
            // Every filter key removed, by name — not a hand-built '?log_page=1' — so whatever
            // else the address carries (a routing param this admin's pages rely on) survives.
            $html .= ' <a href="' . self::e(self::link(array_fill_keys([...array_keys($filters), $param], null))) . '">'
                . self::e(self::t('Clear')) . '</a>';
        }

        return $html . '</form>';
    }

    /** This page's address with the given query parameters overridden — null removes one. */
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
