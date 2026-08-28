<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Email\Tracking;

/**
 * A wrapped link from a tracked message: record the click, then send them where they were going.
 *
 * **The destination is inside the signed token**, never a query parameter. A tracker that takes
 * its destination from the URL is an open redirect, and an open redirect on a domain that sends
 * mail is a phishing kit somebody else gets to use — the link would come from your domain, in a
 * message that looks like yours, and land wherever the attacker chose.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Emailclick extends \Pramnos\Application\Controller
{
    public $actions = ['display'];

    public function display(array $args = []): void
    {
        \Pramnos\Framework\Factory::getDocument('raw');

        $request     = new \Pramnos\Http\Request();
        $destination = Tracking::recordClick((string) $request->get('c', '', 'get'));

        if ($destination === '') {
            /*
             * A link that does not verify. It has been edited, truncated by a mail client, or
             * signed before this installation's key changed — and there is nowhere safe to send
             * somebody holding one. The site's front page is the honest answer: it is a place
             * they meant to reach, and it is not somewhere an attacker chose.
             */
            $this->home(404);

            return;
        }

        $this->sendTo($destination);
    }

    /**
     * Send them on.
     *
     * Its own method so a test can assert the destination: headers are invisible to a test
     * runner, and "did it redirect to the right place" is the only question this controller has
     * to answer correctly.
     */
    protected function sendTo(string $destination): void
    {
        if (headers_sent()) {
            return;
        }

        // 302, not 301: a permanent redirect would be cached by the browser, and the second
        // click on the same link would never reach us to be counted.
        http_response_code(302);
        header('Location: ' . $destination);
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
    }

    /**
     * Send an unusable link to the front page rather than nowhere.
     */
    protected function home(int $status): void
    {
        $base = (string) (\Pramnos\Application\Settings::getSetting('site_url')
            ?: (defined('sURL') ? sURL : '/'));

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>This link is not valid</title></head><body>'
            . '<p>This link could not be read. It may have been cut short by your mail program.</p>'
            . '<p><a href="' . htmlspecialchars($base, ENT_QUOTES) . '">Go to the site</a></p>'
            . '</body></html>';
    }
}
