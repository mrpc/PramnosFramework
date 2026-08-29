<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Email\MailTypes;
use Pramnos\Email\Unsubscribe as UnsubscribeService;

/**
 * `/unsubscribe` — the endpoint the `List-Unsubscribe` headers point at.
 *
 * Two methods, and the difference between them is the whole reason this exists:
 *
 * - **POST** is RFC 8058 one-click. A mailbox provider's server sends it on the reader's
 *   behalf, so there is no session, no login and no confirmation step — it unsubscribes and
 *   answers 200. Gmail and Yahoo require this of anyone sending in volume, and they do not
 *   report a failure back: mail from a sender whose endpoint refuses them is quietly filed as
 *   spam, including the mail people wanted.
 * - **GET** is a person clicking the link in the footer. It unsubscribes and says so on a page,
 *   with a way back for somebody who pressed it by accident.
 *
 * Public on purpose. Requiring a login here would fail every one-click request and most of the
 * human ones: people read mail in a browser they are not signed in to, and an address on a list
 * does not always have an account at all. The signed token is the authorisation, and it is a
 * better fit — it names one address and one list, it cannot be edited into naming somebody
 * else's, and it works for a recipient this installation has never seen.
 *
 * The page is self-contained rather than themed, deliberately: it is one sentence shown to
 * somebody who is leaving, and an application whose theme has no view for it would otherwise
 * get a fatal at the worst possible moment. An application that wants its own look declares its
 * own `Unsubscribe` controller, which takes precedence over this one.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Unsubscribe extends \Pramnos\Application\Controller
{
    public $actions = ['display'];

    /**
     * Unsubscribe, and say what happened.
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
        $token   = (string) $request->get('u', '', 'request');
        $method  = strtoupper((string) $request->getRequestMethod());
        $oneClick = $method === 'POST';

        /*
         * `a=in` is a person turning something back on from the preferences list below.
         *
         * Never honoured for one-click: RFC 8058 says a POST to this endpoint unsubscribes, and
         * a provider that saw a parameter turn that into a subscribe would be right to stop
         * trusting the endpoint. The signed token is the same either way — it names one address
         * and one list, which is the authorisation for both directions.
         */
        $optingIn = !$oneClick && (string) $request->get('a', '', 'request') === 'in';

        $claim = $token === '' ? null : UnsubscribeService::verify($token);

        if ($claim === null) {
            /*
             * A token that does not verify. Answered the same way whether it was truncated by
             * a mail client, edited by somebody guessing, or signed before this installation's
             * key changed — there is nothing useful to distinguish, and a message that
             * confirmed which of those it was would be a message telling an attacker how close
             * they are.
             *
             * One-click gets a 400 rather than a page: it is a machine, and a provider reading
             * "we could not do it" as success is worse than the error.
             */
            if ($oneClick) {
                $this->respond(400, 'This unsubscribe link is not valid.');

                return;
            }

            $this->page(
                'This link is not valid',
                'We could not read this unsubscribe link. It may have been cut short by your '
                . 'mail program. Replying to the message and asking to be removed works too, '
                . 'and somebody will do it by hand.'
            );

            return;
        }

        $recorded = $optingIn
            ? $this->optIn($claim['email'], $claim['list'])
            : $this->optOut(
                $claim['email'],
                $claim['list'],
                $oneClick ? 'one_click' : 'page'
            );

        if (!$recorded) {
            /*
             * The request verified and could not be written. Answered as a failure rather
             * than with the usual page, because the page is a promise: "we have removed you"
             * while the record does not exist is how somebody keeps receiving mail they have
             * already unsubscribed from twice, and decides the sender is lying.
             *
             * A 500 for one-click, so a provider retries — this is exactly the case retrying
             * fixes, since the cause is a database that was briefly unavailable.
             */
            if ($oneClick) {
                $this->respond(500, 'Could not record the request. Please retry.');

                return;
            }

            $this->page(
                'We could not complete that',
                'Something went wrong at our end and your request was not saved. Please try '
                . 'the link again in a few minutes — or reply to the message and ask to be '
                . 'removed, and somebody will do it by hand.'
            );

            return;
        }

        if ($oneClick) {
            // A body nobody reads, but a 200 a provider does.
            $this->respond(200, 'Unsubscribed.');

            return;
        }

        $address = htmlspecialchars($claim['email'], ENT_QUOTES);

        if ($optingIn) {
            $this->page(
                'You are subscribed again',
                'We will send these messages to <strong>' . $address . '</strong> again.',
                $this->preferences($claim['email'])
            );

            return;
        }

        $this->page(
            'You have been unsubscribed',
            'We have removed <strong>' . $address . '</strong> from these messages. Nothing '
            . 'else about your account has changed, and messages you need in order to use it — '
            . 'a password reset, a security code — are not affected.',
            $this->preferences($claim['email'])
        );
    }

    /**
     * The rest of what this address receives, and a way to change each one.
     *
     * An unsubscribe page that offers only «you are off this list» leaves somebody who wanted
     * fewer emails with one button, and the button says *none, ever*. That is how a person who
     * would have kept one of four messages ends up receiving none of them — and the sender
     * reads it as a clean unsubscribe rather than as the failure it was.
     *
     * Built from {@see MailTypes}, so it lists what this application actually sends, described
     * in words somebody can act on. An installation that has registered nothing optional gets
     * nothing here, which is correct: there is no choice to offer.
     *
     * Each row is a link carrying its own signed token for **this address and that list**, so
     * the page needs no session and cannot be edited into changing somebody else's settings.
     */
    protected function preferences(string $email): string
    {
        $types = MailTypes::optional();

        if ($types === []) {
            return '';
        }

        $rows = '';

        foreach ($types as $type) {
            $off   = $this->isOptedOut($email, $type->list);
            $token = UnsubscribeService::token($email, $type->list);
            $url   = UnsubscribeService::url($token) . ($off ? '&a=in' : '');

            $rows .= '<li><strong>' . htmlspecialchars($type->label, ENT_QUOTES) . '</strong> — '
                . ($off ? '<span class="off">not receiving</span>' : 'receiving')
                . '<br><span class="what">'
                . htmlspecialchars($type->description, ENT_QUOTES) . '</span>'
                . ' <a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . ($off ? 'Turn back on' : 'Turn off') . '</a></li>';
        }

        return '<h2>Everything else you can receive</h2><ul class="prefs">' . $rows . '</ul>';
    }

    /** Undo an opt-out. A seam, like {@see optOut()}. */
    protected function optIn(string $email, string $list): bool
    {
        return UnsubscribeService::optIn($email, $list);
    }

    /** Is this address off this list? A seam, like {@see optOut()}. */
    protected function isOptedOut(string $email, string $list): bool
    {
        return UnsubscribeService::isOptedOut($email, $list);
    }

    /**
     * Record the unsubscribe. A seam, so a test needs no database to assert the answers.
     */
    protected function optOut(string $email, string $list, string $source): bool
    {
        return UnsubscribeService::optOut($email, $list, $source);
    }

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
