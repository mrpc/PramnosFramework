<?php

declare(strict_types=1);

namespace Pramnos\Assist;

use Pramnos\User\User;

/**
 * "What's new": the administration's help pages somebody chose to announce, in a window each
 * person closes for themselves — and a button in the header that opens it again for a while.
 *
 * A page is announced by `whatsnew:` in its front matter: the lines are what the window says, and
 * a page without them is not announced. Most changelog posts are not worth interrupting anybody
 * for; the one that is says so.
 *
 * ```markdown
 * ---
 * title: Invoices in two currencies
 * section: Changelog
 * date: 2026-10-19
 * whatsnew:
 *   - An invoice can now be issued in dollars as well as euros.
 *   - Reports show both, converted at the day's rate.
 * ---
 * ```
 *
 * Who has seen what is kept in each person's `usersettings` ({@see SETTING}). Somebody who has
 * never had it starts with everything dated before today seen: a new administrator is not shown
 * the history, and whoever opens the administration on the day of an announcement still sees it.
 *
 * ```php
 * // the theme's header and footer — both nothing for whoever it is not for
 * <?php echo \Pramnos\Assist\WhatsNew::button('<li>', '</li>'); ?>
 * <?php echo \Pramnos\Assist\WhatsNew::window(); ?>
 * ```
 *
 * The window opens without a script while something is unseen: the newest {@see shown()} in full,
 * the rest counted, with a link to the help. Closing it, or "More", is a POST to
 * `Help/whatsnew` that marks everything announced as seen. Opened again from the header — while
 * something was announced in the last {@see recentDays()} days — closing it is only a click.
 *
 * `'help' => ['whatsnew' => ['shown' => 3, 'recent_days' => 14, 'min_usertype' => 90]]` in
 * app.php; `min_usertype` is the administration area's floor unless set.
 */
final class WhatsNew
{
    /** The user setting holding the slugs a person has closed. */
    public const SETTING = 'whatsnew.seen';

    /** Shown in full unless app.php says; older ones are counted. */
    public const SHOWN = 3;

    /** How many days an announcement keeps the header's button, unless app.php says. */
    public const RECENT_DAYS = 14;

    /** @var array{unseen: list<array<string, mixed>>, recent: list<array<string, mixed>>}|null This request's, once */
    private static ?array $current = null;

    /**
     * The announced pages, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function announced(?HelpBook $book = null): array
    {
        $pages = array_values(array_filter(($book ?? Help::admin())->all(), static fn (array $page): bool => ($page['whatsnew'] ?? []) !== []));
        usort($pages, static fn (array $a, array $b): int => [(string) $b['updated'], (string) $b['slug']] <=> [(string) $a['updated'], (string) $a['slug']]);

        return $pages;
    }

    /**
     * What this person has not closed yet, newest first; nothing for somebody it is not for.
     * The first time, everything dated before today is counted as seen, and kept so.
     *
     * @return list<array<string, mixed>>
     */
    public static function unseen(?User $user, ?HelpBook $book = null, ?string $today = null): array
    {
        return self::eligible($user) ? self::unseenOf($user, self::announced($book), $today ?? self::today()) : [];
    }

    /**
     * Announced in the last {@see recentDays()} days, today included, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(?HelpBook $book = null, ?string $today = null): array
    {
        return self::recentOf(self::announced($book), $today ?? self::today());
    }

    /** Everything announced now is seen by this person. */
    public static function dismiss(User $user, ?HelpBook $book = null): void
    {
        $user->setSetting(self::SETTING, array_column(self::announced($book), 'slug'));
        self::$current = null;
    }

    /** Whether the window is for this person: the help on, and somebody of the administration. */
    public static function eligible(?User $user): bool
    {
        return $user !== null && (int) $user->userid > 1 && Help::enabled()
            && (int) $user->usertype >= self::config('min_usertype', \Pramnos\Http\AdminArea::minUserType());
    }

    /** Where to go back to after closing it: a path of this site, or the administration's home. */
    public static function back(string $path): string
    {
        return preg_match('#^/[^/\\\\]#', $path) === 1 ? $path : \Pramnos\Http\AdminArea::address('');
    }

    /** How many are shown in full. */
    public static function shown(): int
    {
        return max(1, self::config('shown', self::SHOWN));
    }

    /** How many days an announcement keeps the header's button. */
    public static function recentDays(): int
    {
        return max(0, self::config('recent_days', self::RECENT_DAYS));
    }

    /**
     * The header's button, while something was announced lately: it opens the window. '' otherwise,
     * `$before` and `$after` included — a list item round it in a menu.
     */
    public static function button(string $before = '', string $after = ''): string
    {
        if (self::current()['recent'] === []) {
            return '';
        }
        $label = self::e(t('What\'s new'));

        return $before . '<label for="pf-whatsnew" class="pf-whatsnew-button" title="' . $label . '">'
            . '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>'
            . '<span>' . $label . '</span></label>' . $after;
    }

    /**
     * The window: open, with what this person has not closed yet; otherwise closed, with what was
     * announced lately, for the header's button. '' when there is neither.
     */
    public static function window(): string
    {
        $current = self::current();
        $open    = $current['unseen'] !== [];
        $pages   = $open ? $current['unseen'] : $current['recent'];
        if ($pages === []) {
            return '';
        }

        $body = '';
        foreach (array_slice($pages, 0, self::shown()) as $page) {
            $body .= '<section class="pf-whatsnew-post"><h3>' . self::e((string) $page['title']) . '</h3><ul>';
            foreach ((array) $page['whatsnew'] as $line) {
                $body .= '<li>' . self::e((string) $line) . '</li>';
            }
            $body .= '</ul><button type="submit" name="more" value="' . self::e((string) $page['slug']) . '" class="pf-whatsnew-more">' . self::e(t('More')) . ' →</button></section>';
        }
        $older = count($pages) - self::shown();
        if ($older > 0) {
            $body .= '<p><button type="submit" name="more" value="news" class="pf-whatsnew-more">'
                . self::e(sprintf($older === 1 ? t('1 more announcement in the help') : t('%d more announcements in the help'), $older)) . ' →</button></p>';
        }
        // Opened by the server, only its buttons close it, and both say so; opened again from the
        // header, everything in it was seen, and closing it is only a click.
        $close = $open
            ? '<button type="submit" class="pf-whatsnew-close">' . self::e(t('Close')) . '</button>'
            : '<label for="pf-whatsnew" class="pf-whatsnew-close">' . self::e(t('Close')) . '</label>';

        return '<input type="checkbox" id="pf-whatsnew" class="pf-whatsnew-toggle"' . ($open ? ' checked' : '') . '>'
            . '<div class="pf-whatsnew" role="dialog" aria-modal="true" aria-labelledby="pf-whatsnew-title">'
            . ($open ? '<div class="pf-whatsnew-backdrop"></div>' : '<label class="pf-whatsnew-backdrop" for="pf-whatsnew"></label>')
            . '<form class="pf-whatsnew-box" method="post" action="' . self::e(\Pramnos\Http\AdminArea::address('Help/whatsnew')) . '">'
            . \Pramnos\Http\Middleware\CsrfMiddleware::tokenField()
            . '<input type="hidden" name="return" value="' . self::e((string) ($_SERVER['REQUEST_URI'] ?? '')) . '">'
            . '<h2 id="pf-whatsnew-title">' . self::e(t('What\'s new')) . '</h2>'
            . '<div class="pf-whatsnew-body">' . $body . '</div>'
            . '<div class="pf-whatsnew-actions">' . $close . '</div>'
            . '</form></div>';
    }

    /** Forget this request's answer — after a dismissal, or between a worker's requests. */
    public static function forget(): void
    {
        self::$current = null;
    }

    /**
     * This request's person: unseen and recent, worked out once for the header and the window.
     *
     * @return array{unseen: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    private static function current(): array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        self::$current = ['unseen' => [], 'recent' => []];
        try {
            $user = User::getCurrentUser();
            if ($user instanceof User && self::eligible($user)) {
                $pages         = self::announced();
                self::$current = ['unseen' => self::unseenOf($user, $pages, self::today()), 'recent' => self::recentOf($pages, self::today())];
            }
        } catch (\Throwable $e) {
            // A window that cannot be worked out is no window, not a page that fails to draw.
            \Pramnos\Logs\Logger::log('What\'s new could not be read: ' . $e->getMessage(), 'help');
        }

        return self::$current;
    }

    /**
     * unseen() of pages already read.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private static function unseenOf(User $user, array $pages, string $today): array
    {
        $seen = $user->getSetting(self::SETTING);
        if (!is_array($seen)) {
            $seen = array_column(array_filter($pages, static fn (array $p): bool => (string) $p['updated'] < $today), 'slug');
            $user->setSetting(self::SETTING, $seen);
        }

        return array_values(array_filter($pages, static fn (array $p): bool => !in_array($p['slug'], $seen, true)));
    }

    /**
     * recent() of pages already read.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private static function recentOf(array $pages, string $today): array
    {
        $since = (new \DateTimeImmutable($today))->modify('-' . self::recentDays() . ' days')->format('Y-m-d');

        return array_values(array_filter($pages, static fn (array $p): bool => (string) $p['updated'] >= $since));
    }

    /** Today, in the application's time zone. */
    private static function today(): string
    {
        return date('Y-m-d');
    }

    /** One number of `'help' => ['whatsnew' => [...]]` in app.php. */
    private static function config(string $key, int $default): int
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $value = is_object($app) ? ($app->applicationInfo['help']['whatsnew'][$key] ?? null) : null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
