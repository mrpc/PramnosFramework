<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Visits\Hits as Taken;
use Pramnos\Visits\Visits;

/**
 * `POST /Hits` — where `pf-hits.js` sends a page view and the clicks out of it — and
 * `GET /Hits/pixel`, the `<noscript>` image of a page without JavaScript.
 *
 * Reached at its own address in every application with `visits.sites`, with no controller of the
 * application's own, and from pages the page cache served: it asks nothing of the session but who
 * is signed in. It always answers 204 (the pixel a transparent GIF): a page being left has nobody
 * to tell, and a refused hit says nothing to whoever sent it. See {@see Taken}.
 */
class Hits extends ClientErrorsController
{
    /** The transparent GIF the pixel answers. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** Declares the pixel. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['pixel']);
        parent::__construct($application);
    }

    /** A page view, or a few hits of one page: `{k, u, r, t, e, s, p, c}`, or `{hits: [...]}`. */
    public function display(): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');
        $this->releaseSession();
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            return $this->answer(204);
        }
        $body   = $this->report();
        $events = isset($body['hits']) && is_array($body['hits']) ? $body['hits'] : [$body];
        $this->take(array_values($events));

        return $this->answer(204);
    }

    /** The image of a page without JavaScript: its address is the referrer. */
    public function pixel(): mixed
    {
        $this->releaseSession();
        $page = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($page !== '') {
            $this->take([['k' => 'view', 'u' => $page, 'n' => 1]]);
        }
        return \Pramnos\Http\Response::make((string) base64_decode(self::GIF), 200)
            ->withHeader('Content-Type', 'image/gif')->withHeader('Cache-Control', 'no-store');
    }

    /** @param list<mixed> $events */
    private function take(array $events): void
    {
        if (Visits::sites() === []) {
            return;
        }
        try {
            $user  = \Pramnos\User\User::current();
            $taken = Taken::take(array_values(array_filter($events, 'is_array')), $_SERVER, $_COOKIE,
                is_object($user) && (int) ($user->userid ?? 0) > 1 ? (int) $user->userid : null);
            $secure = (string) ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
            foreach ($taken['cookies'] as $name => [$value, $expires]) {
                if (!headers_sent()) {
                    setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
                }
            }
        } catch (\Throwable $e) {
            // No table yet, or no database: a hit is not worth an error.
            \Pramnos\Logs\Logger::log('A page hit could not be recorded: ' . $e->getMessage(), 'visits');
        }
    }
}
