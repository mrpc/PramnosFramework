<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Audience\SiteMessages as Store;

/**
 * The site messages of whoever is signed in: banners across the top, a popup, cards — each with a
 * close button that needs no script.
 *
 * ```php
 * // the layout, where banners belong — and where cards do
 * echo \Pramnos\Html\SiteMessages::render();            // banners and the popup
 * echo \Pramnos\Html\SiteMessages::render('card');      // cards, in their own slot
 * ```
 *
 * Neutral `pf-site-message` markup the theme's stylesheet dresses; the popup is a `<dialog open>`;
 * closing is a small form POSTing to `/SiteMessages/dismiss/{id}` with the session's token, back
 * to the page. Nothing for a visitor, and nothing before the table is installed.
 */
final class SiteMessages
{
    /**
     * The messages of these kinds for the signed-in person.
     *
     * @param string|null $kind `card` for cards alone; null for banners and the popup
     */
    public static function render(?string $kind = null, ?int $person = null): string
    {
        if ($person === null) {
            $user   = \Pramnos\User\User::current();
            $person = $user ? (int) $user->userid : 0;
        }
        if ($person < 1) {
            return '';
        }
        $messages = array_values(array_filter(Store::pending($person),
            static fn (array $m): bool => $kind === null ? $m['kind'] !== 'card' : $m['kind'] === $kind));
        $html  = '';
        $popup = false;
        foreach ($messages as $message) {
            if ($message['kind'] === 'popup') {
                if ($popup) {
                    continue;   // one at a time; the next shows on the next page
                }
                $popup = true;
            }
            $html .= self::one($message);
        }

        return $html;
    }

    /** @param array{id: int, kind: string, title: string, body: string, link: string, linkText: string} $m */
    private static function one(array $m): string
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $close = '<form method="post" action="' . $e((defined('sURL') ? (string) \sURL : '/') . 'SiteMessages/dismiss/' . $m['id']) . '" class="pf-site-message-close">'
            . \Pramnos\Http\Session::getInstance()->getTokenField()
            . '<input type="hidden" name="back" value="' . $e((string) ($_SERVER['REQUEST_URI'] ?? '/')) . '">'
            . '<button type="submit" aria-label="' . $e(t('Close')) . '">×</button></form>';
        $inner = '<div class="pf-site-message-text"><strong class="pf-site-message-title">' . $e($m['title']) . '</strong>'
            . ($m['body'] !== '' ? '<div class="pf-site-message-body">' . $m['body'] . '</div>' : '')
            . ($m['link'] !== '' ? '<a class="pf-site-message-link" href="' . $e($m['link']) . '">' . $e($m['linkText'] !== '' ? $m['linkText'] : t('More')) . '</a>' : '')
            . '</div>' . $close;
        $class = 'pf-site-message pf-site-message-' . $m['kind'];

        return $m['kind'] === 'popup'
            ? '<dialog open class="' . $class . '" aria-labelledby="pf-site-message-' . $m['id'] . '"><span hidden id="pf-site-message-' . $m['id'] . '">' . $e($m['title']) . '</span>' . $inner . '</dialog>'
            : '<section class="' . $class . '" role="' . ($m['kind'] === 'banner' ? 'status' : 'region') . '" aria-label="' . $e($m['title']) . '">' . $inner . '</section>';
    }
}
