<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Notification\Preferences;

/**
 * The signed-in person's notification choices as a form, for the application's account page: a
 * switch per type the application declares (`notifications.types`), and whether the bell reads
 * what stays in sight. It posts to `/Notifications/preferences` and comes back to `$back`.
 *
 * ```php
 * <?php echo \Pramnos\Html\NotificationPreferences::render('/Myaccount/notifications'); ?>
 * ```
 *
 * Nothing for somebody signed out. The scaffolded stylesheets style the `pf-*` classes.
 */
final class NotificationPreferences
{
    /**
     * @param string $back Where the form comes back to: a path on this site
     * @param string $url  The preferences' address; `/Notifications/preferences` on the site by default
     */
    public static function render(string $back, string $url = ''): string
    {
        $user = \Pramnos\User\User::current();
        if (!is_object($user) || (int) ($user->userid ?? 0) <= 1) {
            return '';
        }
        $url  = $url !== '' ? $url : (defined('sURL') ? \sURL : '/') . 'Notifications/preferences';
        $mine = Preferences::of($user);
        $html = '<form method="post" action="' . self::e($url) . '" class="pf-notify-preferences">'
            . \Pramnos\Http\Middleware\CsrfMiddleware::tokenField()
            . '<input type="hidden" name="back" value="' . self::e($back) . '">';
        if ($mine['types'] !== []) {
            $html .= '<fieldset class="pf-field"><legend class="pf-label">' . self::e(self::t('Tell me about')) . '</legend>';
            foreach ($mine['types'] as $type) {
                // The hidden 0 first: an unticked box sends nothing, and nothing would leave it as it was.
                $name  = 'types[' . self::e($type['type']) . ']';
                $html .= '<input type="hidden" name="' . $name . '" value="0">'
                    . '<label class="pf-check"><input type="checkbox" name="' . $name . '" value="1"' . ($type['on'] ? ' checked' : '') . '> '
                    . self::e($type['label']) . '</label><br>';
            }
            $html .= '</fieldset>';
        }

        return $html . '<input type="hidden" name="readOnView" value="0">'
            . '<label class="pf-check"><input type="checkbox" name="readOnView" value="1"' . ($mine['readOnView'] ? ' checked' : '') . '> '
            . self::e(self::t('Mark as read what I have seen in the bell')) . '</label>'
            . '<div><button type="submit" class="pf-btn pf-btn-primary">' . self::e(self::t('Save')) . '</button></div></form>';
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
