<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Email\MailAction as Service;

/**
 * The endpoint a one-click email action calls.
 *
 * - **POST** performs the action. This is what a mailbox provider sends when the reader presses
 *   the button Gmail draws beside the subject; it arrives from Google's servers, with no cookies
 *   and no session, so the signed token in the URL is the entire authorisation.
 * - **GET** shows a page with a button, and performs nothing — unless the action said at
 *   registration that a GET is safe for it. That default is the point: a GET is issued by things
 *   nobody asked for, a link scanner in a mail gateway, a client prefetching a preview, an
 *   antivirus proxy. If a GET acted, those would act.
 *
 * Public on purpose, and it must stay that way: requiring a login would fail every one-click
 * request, which arrives from a machine that has never signed in to anything.
 *
 * Bundled so the feature works in a fresh project without a controller having to be written —
 * an application that wants its own look declares its own `MailAction` controller, which takes
 * precedence over this one.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailAction extends \Pramnos\Application\Controller
{
    public $actions = ['display'];

    /**
     * Run it, or ask for confirmation, and say what happened.
     */
    public function display(array $args = []): void
    {
        /*
         * A self-contained answer, so the site's layout is not rendered after it.
         *
         * Without this the framework goes on to render the whole page once the controller
         * returns: the plain-text line a mailbox provider reads was followed by 180 KB of the
         * site's HTML, and the page a person sees was followed by the site's header, navigation
         * and footer. `raw` is the document type the framework already uses for self-contained
         * output — the log-viewer iframe — and it renders the body and nothing around it.
         */
        \Pramnos\Framework\Factory::getDocument('raw');

        $request = new \Pramnos\Http\Request();
        $token   = (string) $request->get('a', '', 'request');
        $isPost  = strtoupper((string) $request->getRequestMethod()) === 'POST';

        if ($token === '') {
            $this->answer($isPost, 400, 'This link is not valid.', '');

            return;
        }

        $result = Service::dispatch($token, $isPost);

        /*
         * 405 is not an error here — it is the confirmation step.
         *
         * A person followed the visible link, the action does not allow a GET, so they are shown
         * a button that posts the same token. Reported to a machine as 405 because that is what
         * it means; shown to a person as a page, because "405" is not an answer.
         */
        if ($result['status'] === 405 && !$isPost) {
            $this->confirmationPage($token, $result['action']);

            return;
        }

        $this->answer($isPost, $result['status'], $result['message'], $result['action']);
    }

    /**
     * A machine gets a status and a line; a person gets a page.
     */
    protected function answer(bool $isPost, int $status, string $message, string $action): void
    {
        if ($isPost) {
            $this->respond($status, $message);

            return;
        }

        $this->page(
            $status === 200 ? 'Done' : 'This link did not work',
            htmlspecialchars($message, ENT_QUOTES)
        );
    }

    /**
     * The page a person sees when the action needs a deliberate press.
     *
     * A form rather than a link, because the whole reason for this step is that a GET must not
     * perform the action — and a scanner that follows links does not submit forms.
     */
    protected function confirmationPage(string $token, string $action): void
    {
        $this->page(
            'One more tap',
            'Press the button to confirm.'
            . '<form method="post" action="" style="margin-top:16px">'
            . '<input type="hidden" name="a" value="' . htmlspecialchars($token, ENT_QUOTES) . '">'
            . '<button type="submit" style="font:inherit;padding:10px 18px;border:0;'
            . 'border-radius:6px;background:#2563eb;color:#fff;cursor:pointer">Confirm</button>'
            . '</form>'
        );
    }

    /**
     * A plain-text answer for the provider's server.
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
     * Self-contained, and `noindex`: the URL carries a token, and a search engine that indexed
     * one would publish somebody's ability to perform the action.
     */
    protected function page(string $title, string $body): void
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
            . 'a{color:#2563eb;}'
            . '.site{margin:0 0 20px;font-size:13px;letter-spacing:.04em;'
            . 'text-transform:uppercase;color:#6b7280;}'
            . '@media (prefers-color-scheme: dark){'
            . 'body{background:#111827;color:#e5e7eb;}'
            . '.card{background:#1f2937;border-color:#374151;}'
            . '.site{color:#9ca3af;}}'
            . '</style></head><body><div class="card">'
            . ($siteName !== '' ? '<p class="site">' . $siteName . '</p>' : '')
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
            . '<p>' . $body . '</p>'
            . ($siteUrl !== ''
                ? '<p><a href="' . htmlspecialchars($siteUrl, ENT_QUOTES) . '">'
                    . 'Back to the site</a></p>'
                : '')
            . '</div></body></html>';
    }
}
