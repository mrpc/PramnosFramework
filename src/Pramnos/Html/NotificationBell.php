<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The notification bell for a theme's header: a button with the unread count, and a panel with the
 * latest notifications, tabs by group, and "mark all read".
 *
 * ```php
 * <?php echo \Pramnos\Html\NotificationBell::render(); ?>
 * <?php echo \Pramnos\Html\NotificationBell::render([
 *     'url'    => adminUrl('Notifications'),
 *     'groups' => ['' => 'For me', 'tasks' => 'Tasks', 'system' => 'System'],
 *     'class'  => 'btn btn-ghost btn-circle',
 * ]); ?>
 * ```
 *
 * The markup only: `pf-utils.js` does the rest — the count, the list, read and read-all, the
 * polling — through the data attributes, and the scaffolded stylesheets style the `pf-notify-*`
 * hooks. A theme may write its own markup instead with the same `data-pf-notifications*`
 * attributes, and an item of its own with `<template data-pf-notifications-item>` whose parts
 * carry `data-field="title|message|time"`.
 *
 * Nothing for somebody signed out. The count is drawn already, so the page does not show 0 and
 * then the real number.
 */
final class NotificationBell
{
    /**
     * The bell for a scaffolded header, between `$before` and `$after` — and nothing for somebody
     * signed out, or where there is no feed to read: an application without the `notifications`
     * table.
     *
     * ```php
     * <?php echo \Pramnos\Html\NotificationBell::headerButton('<li class="nav-item">', '</li>'); ?>
     * ```
     *
     * One header serves the site and the administration, so the bell is in both; a SPA draws the
     * same bell with its `NotificationBell.svelte` over `Notification\FeedApi`.
     *
     * @param array<string, mixed> $options As {@see render()}
     */
    public static function headerButton(string $before = '', string $after = '', array $options = []): string
    {
        try {
            self::$hasFeed ??= \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('notifications');
        } catch (\Throwable) {
            self::$hasFeed = false;
        }
        $bell = self::$hasFeed ? self::render($options) : '';

        return $bell === '' ? '' : $before . $bell . $after;
    }

    /** @var bool|null Whether this installation has the notifications table, asked once */
    private static ?bool $hasFeed = null;

    /**
     * @param array{url?: string, interval?: int, channel?: string, groups?: array<string, string>, class?: string, label?: string, empty?: string, readAll?: string, all?: string, allLabel?: string, titleFlash?: bool, sound?: bool|string} $options
     *  - `url` — the feed's address: `/Notifications` on the site by default
     *  - `interval` — seconds between counts, while the tab is visible (60)
     *  - `channel` — a realtime channel whose `notification.created` refreshes it at once; by
     *    default the user's own (`private-users.{id}`) where `broadcasting.transport` is
     *    `websocket` or `pusher`, none otherwise
     *  - `groups` — tabs: group => label, `''` for all; none by default
     *  - `class` — the bell button's classes
     *  - `all` — the address of the page listing every notification: a link at the foot of the
     *    panel, under the list and when it is empty; none by default
     *  - `allLabel` — that link's words ("See all")
     *  - `titleFlash` — the tab's title carries "(N) " while N are unread, and alternates with
     *    a notification's title when one arrives, until the tab is looked at (on by default)
     *  - `sound` — played when one arrives, once per browser: `true` for a short tone made in
     *    the page, a sound's address, or `false` for none (the default)
     */
    public static function render(array $options = []): string
    {
        $user = \Pramnos\User\User::current();
        $id   = is_object($user) ? (int) ($user->userid ?? 0) : 0;
        if ($id <= 1) {
            return '';
        }

        try {
            $unread = (new \Pramnos\Notification\Feed($id))->unreadCount();
        } catch (\Throwable) {
            // No table yet: the bell still draws, and the first count tells the truth.
            $unread = 0;
        }

        $url   = (string) ($options['url'] ?? ((defined('sURL') ? \sURL : '/') . 'Notifications'));
        // The user's own channel, where the page has a live connection to hear it on.
        if (!isset($options['channel'])) {
            $realtime = \Pramnos\Application\Application::currentInstance()?->applicationInfo['broadcasting'] ?? null;
            if (is_array($realtime) && \Pramnos\Broadcasting\RealtimeConfig::forPage($realtime) !== null) {
                $options['channel'] = \Pramnos\Broadcasting\RealtimeConfig::userChannel($id, $realtime);
            }
        }
        $label = self::t((string) ($options['label'] ?? 'Notifications'));

        $html = '<div class="pf-notify" data-pf-notifications data-url="' . self::e($url) . '"'
            . ' data-interval="' . (int) ($options['interval'] ?? 60) . '"'
            // An empty channel is none: `'channel' => ''` keeps a bell off the realtime connection.
            . ((string) ($options['channel'] ?? '') !== '' ? ' data-channel="' . self::e((string) $options['channel']) . '"' : '')
            . (($options['titleFlash'] ?? true) === false ? ' data-title-flash="0"' : '')
            . self::soundAttribute($options['sound'] ?? false) . '>'
            . '<button type="button" class="pf-notify-toggle ' . self::e((string) ($options['class'] ?? '')) . '"'
            . ' data-pf-notifications-toggle data-label="' . self::e($label) . '" aria-haspopup="true" aria-expanded="false"'
            . ' aria-label="' . self::e($label . ($unread > 0 ? ' (' . $unread . ')' : '')) . '">'
            . '<svg class="pf-notify-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>'
            . '<span class="pf-notify-count" data-pf-notifications-count' . ($unread > 0 ? '' : ' hidden') . '>' . ($unread > 99 ? '99+' : $unread) . '</span>'
            . '</button>'
            . '<div class="pf-notify-panel" data-pf-notifications-panel hidden>'
            . '<div class="pf-notify-head"><strong>' . self::e($label) . '</strong>'
            . '<button type="button" class="pf-notify-read-all" data-pf-notifications-read-all>' . self::e(self::t((string) ($options['readAll'] ?? 'Mark all read'))) . '</button></div>';

        $groups = (array) ($options['groups'] ?? []);
        if ($groups !== []) {
            $html .= '<nav class="pf-notify-tabs" role="tablist">';
            $first = true;
            foreach ($groups as $group => $name) {
                $html .= '<button type="button" role="tab" class="pf-notify-tab" data-pf-notifications-group="' . self::e((string) $group) . '"'
                    . ' aria-selected="' . ($first ? 'true' : 'false') . '">' . self::e(self::t((string) $name))
                    . ((string) $group !== '' ? ' <span class="pf-notify-tab-count" data-pf-notifications-group-count="' . self::e((string) $group) . '" hidden></span>' : '')
                    . '</button>';
                $first = false;
            }
            $html .= '</nav>';
        }

        return $html
            . '<ul class="pf-notify-list" data-pf-notifications-list></ul>'
            . '<p class="pf-notify-empty" data-pf-notifications-empty hidden>' . self::e(self::t((string) ($options['empty'] ?? 'No notifications'))) . '</p>'
            . ((string) ($options['all'] ?? '') !== ''
                ? '<div class="pf-notify-foot"><a class="pf-notify-all" href="' . self::e((string) $options['all']) . '">'
                    . self::e(self::t((string) ($options['allLabel'] ?? 'See all'))) . '</a></div>'
                : '')
            . '</div></div>';
    }

    /** `data-sound` for the `sound` option: a tone made in the page, an address, or nothing. */
    private static function soundAttribute(mixed $sound): string
    {
        if ($sound === true) {
            return ' data-sound="beep"';
        }

        return is_string($sound) && $sound !== '' ? ' data-sound="' . self::e($sound) . '"' : '';
    }

    private static function t(string $words): string
    {
        return function_exists('t') ? (string) t($words) : $words;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
