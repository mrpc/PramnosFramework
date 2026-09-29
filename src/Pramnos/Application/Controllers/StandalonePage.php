<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * A page that stands on its own, and a plain-text answer — for the endpoints a mail links to.
 *
 * `Unsubscribe` and `MailingList` are reached from a message, by a person who may have no
 * account and no session, on an application whose theme may need a signed-in user to render.
 * So the page carries its own markup and styles (light and dark), says `noindex`, and needs
 * nothing from the application but its name and address.
 */
trait StandalonePage
{
    /**
     * A plain response for a machine.
     */
    protected function respond(int $status, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo $message . "\n";
    }

    /**
     * A page for a person.
     *
     * Self-contained, and with `noindex` on it: an unsubscribe URL carries a token, and a
     * search engine that indexed one would publish somebody's ability to unsubscribe them.
     */
    protected function page(string $title, string $body, string $extra = ''): void
    {
        $siteName = htmlspecialchars(
            (string) \Pramnos\Application\Settings::getSetting('sitename'),
            ENT_QUOTES
        );
        $siteUrl = (string) (\Pramnos\Application\Settings::getSetting('site_url')
            ?: (defined('sURL') ? sURL : ''));

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<style>'
            . 'body{margin:0;padding:0;background:#f3f4f6;color:#1f2937;'
            . 'font:16px/1.6 Helvetica,Arial,sans-serif;}'
            . '.card{max-width:520px;margin:12vh auto;background:#fff;border:1px solid #e5e7eb;'
            . 'border-radius:8px;padding:32px;}'
            . 'h1{margin:0 0 12px;font-size:22px;}'
            . 'h2{margin:28px 0 8px;font-size:15px;text-transform:uppercase;'
            . 'letter-spacing:.04em;color:#6b7280;}'
            . 'a{color:#2563eb;}'
            . '.prefs{list-style:none;margin:0;padding:0;}'
            . '.prefs li{padding:12px 0;border-top:1px solid #e5e7eb;font-size:15px;}'
            . '.what{color:#6b7280;font-size:14px;}'
            . '.off{color:#b45309;}'
            . '.site{margin:0 0 20px;font-size:13px;letter-spacing:.04em;'
            . 'text-transform:uppercase;color:#6b7280;}'
            . '@media (prefers-color-scheme: dark){'
            . 'body{background:#111827;color:#e5e7eb;}'
            . '.card{background:#1f2937;border-color:#374151;}'
            . '.site{color:#9ca3af;}'
            . '.prefs li{border-color:#374151;}'
            . '.what{color:#9ca3af;}'
            . '.off{color:#fbbf24;}}'
            . '</style></head><body><div class="card">'
            . ($siteName !== '' ? '<p class="site">' . $siteName . '</p>' : '')
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
            . '<p>' . $body . '</p>'
            . $extra
            . ($siteUrl !== ''
                ? '<p><a href="' . htmlspecialchars($siteUrl, ENT_QUOTES) . '">'
                    . 'Back to the site</a></p>'
                : '')
            . '</div></body></html>';
    }
}
