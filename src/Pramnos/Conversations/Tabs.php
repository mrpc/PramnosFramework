<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * A conversation's tabs, which every member sees: the built-in ones (`messages`, `files`, `pins`)
 * and those added — a canvas (`canvas:<id>`) or a link (`link:<key>`, with a title and an address)
 * — in an order, some hidden.
 *
 * ```php
 * Tabs::of($id);                                        // [{id, kind, title, url, canvas, hidden}]
 * Tabs::add($id, 'link', $by, title: 'Roadmap', url: 'https://…');
 * Tabs::order($id, ['messages', 'canvas:4', 'files'], $by);
 * Tabs::hide($id, 'pins', true, $by);
 * Tabs::remove($id, 'link:9f2c1a7b', $by);              // a built-in one is hidden instead
 * ```
 *
 * Who manages them is the channel's `tabsBy`: `everyone` — any member — or `admins` — its
 * owner, its admins and the moderators ({@see Details::settings()}). `messages` is never hidden.
 * Each change is `conversation.changed` to its members.
 */
final class Tabs
{
    /** The built-in tabs, in their first order. */
    public const BUILT_IN = ['messages', 'files', 'pins'];

    /** The most tabs a conversation has. */
    public const MAX = 20;

    /**
     * A conversation's tabs, in order.
     *
     * @return list<array{id: string, kind: string, title: ?string, url: ?string, canvas: ?int, hidden: bool}>
     */
    public static function of(int $id): array
    {
        $row  = Conversations::db()->queryBuilder()->table('chat.conversations')->where('conversationid', $id)->limit(1)->getAllOrFail()[0] ?? null;
        $kept = $row !== null && isset($row['tabs']) && $row['tabs'] !== null ? json_decode((string) $row['tabs'], true) : null;
        $tabs = is_array($kept) ? array_values(array_filter(array_map([self::class, 'shape'], $kept))) : [];
        // A built-in tab not kept yet is there, after the others: none is ever lost.
        foreach (self::BUILT_IN as $builtIn) {
            if (!in_array($builtIn, array_column($tabs, 'id'), true)) {
                $tabs[] = self::shape(['id' => $builtIn]);
            }
        }

        return $tabs;
    }

    /**
     * Add a tab: a canvas of the conversation, or a link with a title and an address.
     *
     * @return string The tab's id
     */
    public static function add(int $id, string $kind, int $by, ?string $title = null, ?string $url = null, ?int $canvas = null): string
    {
        self::mayManage($id, $by);
        $tabs = self::of($id);
        if (count($tabs) >= self::MAX) {
            throw new \InvalidArgumentException(t('A conversation has at most %s tabs.', (string) self::MAX));
        }
        if ($kind === 'canvas') {
            $tab = ['id' => 'canvas:' . (int) $canvas, 'canvas' => (int) $canvas];
        } elseif ($kind === 'link') {
            $url   = trim((string) $url);
            $title = mb_substr(trim((string) $title), 0, 80);
            if (preg_match('~^(https?://|/(?!/))\S+$~i', $url) !== 1 || mb_strlen($url) > 500) {
                throw new \InvalidArgumentException(t('A tab\'s link is a web address or a path from /.'));
            }
            $tab = ['id' => 'link:' . bin2hex(random_bytes(4)), 'title' => $title !== '' ? $title : (string) parse_url($url, PHP_URL_HOST), 'url' => $url];
        } else {
            throw new \InvalidArgumentException(t('A tab added is a canvas or a link.'));
        }
        if (in_array($tab['id'], array_column($tabs, 'id'), true)) {
            return $tab['id'];
        }
        $tabs[] = self::shape($tab);
        self::keep($id, $tabs, $by, 'conversation.tab_added', ['tab' => $tab['id']]);

        return $tab['id'];
    }

    /** Remove a tab; a built-in one is hidden instead, and `messages` stays. */
    public static function remove(int $id, string $tab, int $by): void
    {
        if (in_array($tab, self::BUILT_IN, true)) {
            self::hide($id, $tab, true, $by);

            return;
        }
        self::mayManage($id, $by);
        $tabs = self::of($id);
        $left = array_values(array_filter($tabs, static fn (array $t): bool => $t['id'] !== $tab));
        if (count($left) !== count($tabs)) {
            self::keep($id, $left, $by, 'conversation.tab_removed', ['tab' => $tab]);
        }
    }

    /** Hide a tab from everyone, or show it again. `messages` is never hidden. */
    public static function hide(int $id, string $tab, bool $hidden, int $by): void
    {
        if ($tab === 'messages' && $hidden) {
            throw new \InvalidArgumentException(t('The messages are always there.'));
        }
        self::mayManage($id, $by);
        $tabs = self::of($id);
        foreach ($tabs as &$t) {
            if ($t['id'] === $tab) {
                $t['hidden'] = $hidden;
            }
        }
        unset($t);
        self::keep($id, $tabs, $by, 'conversation.tab_hidden', ['tab' => $tab, 'hidden' => $hidden]);
    }

    /**
     * Put the tabs in an order: those named, as named; the rest after them, as they were.
     *
     * @param list<string> $ids
     */
    public static function order(int $id, array $ids, int $by): void
    {
        self::mayManage($id, $by);
        $tabs  = array_column(self::of($id), null, 'id');
        $first = [];
        foreach ($ids as $tab) {
            if (isset($tabs[(string) $tab])) {
                $first[] = $tabs[(string) $tab];
                unset($tabs[(string) $tab]);
            }
        }
        self::keep($id, array_merge($first, array_values($tabs)), $by, 'conversation.tabs_ordered', ['order' => array_column($first, 'id')]);
    }

    /** A canvas deleted: its tab goes, whoever manages the tabs. */
    public static function forgetCanvas(int $id, int $canvas, int $by): void
    {
        $tabs = self::of($id);
        $left = array_values(array_filter($tabs, static fn (array $t): bool => $t['id'] !== 'canvas:' . $canvas));
        if (count($left) !== count($tabs)) {
            self::keep($id, $left, $by, 'conversation.tab_removed', ['tab' => 'canvas:' . $canvas]);
        }
    }

    /** Whether somebody manages a conversation's tabs: as its `tabsBy` says. */
    public static function manages(int $id, int $by): bool
    {
        $conversation = Conversations::get($id);
        if ($conversation === null || $conversation['kind'] === 'bot') {
            return false;
        }
        $user = new \Pramnos\User\User($by);
        if (Access::moderates($user, $id)) {
            return true;
        }

        return $conversation['tabsBy'] === 'everyone' && Conversations::member($id, $by) !== null && $conversation['archivedAt'] === null;
    }

    /** @throws \DomainException For somebody who may not */
    private static function mayManage(int $id, int $by): void
    {
        if (!self::manages($id, $by)) {
            throw new \DomainException(t('Only its admins manage this conversation\'s tabs.'));
        }
    }

    /**
     * Keep the tabs, told and logged.
     *
     * @param list<array<string, mixed>> $tabs
     * @param array<string, mixed> $data
     */
    private static function keep(int $id, array $tabs, int $by, string $event, array $data): void
    {
        $plain = array_map(static fn (array $t): array => array_filter($t, static fn ($v, $k): bool => $k === 'id' || ($v !== null && $v !== false), ARRAY_FILTER_USE_BOTH), $tabs);
        Conversations::db()->queryBuilder()->table('chat.conversations')->where('conversationid', $id)
            ->update(['tabs' => (string) json_encode(array_map(static fn (array $t): array => array_diff_key($t, ['kind' => 0]), $plain), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $id, $event, $data, by: $by);
        Realtime::conversationChanged($id, Conversations::members($id));
    }

    /**
     * A tab as kept, made whole; null for one that is none.
     *
     * @param mixed $t
     * @return array{id: string, kind: string, title: ?string, url: ?string, canvas: ?int, hidden: bool}|null
     */
    private static function shape(mixed $t): ?array
    {
        $tabId = is_array($t) ? (string) ($t['id'] ?? '') : '';
        $kind  = in_array($tabId, self::BUILT_IN, true) ? $tabId : (string) strstr($tabId, ':', true);
        if (!in_array($kind, [...self::BUILT_IN, 'canvas', 'link'], true)) {
            return null;
        }

        return ['id' => $tabId, 'kind' => $kind, 'title' => isset($t['title']) ? (string) $t['title'] : null, 'url' => isset($t['url']) ? (string) $t['url'] : null,
            'canvas' => $kind === 'canvas' ? (int) substr($tabId, 7) : null, 'hidden' => !empty($t['hidden']) && $tabId !== 'messages'];
    }
}
