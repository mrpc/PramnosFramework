<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;

/**
 * `GET /Csrf` — the session's current CSRF tokens, for a page that has been open a while.
 *
 * A form keeps the token it was drawn with. Signing in in another tab, or a session that expired
 * while the page sat open, gives the session a new one, and the form is then refused (419, or
 * {@see \Pramnos\Html\Form\TokenRejected}) with what was typed lost. `pf-utils.js` asks here when
 * a page comes back into view, and before a form is sent, once its token is ten minutes old, and
 * puts the answer into every `_csrf_token` field, `<meta name="csrf">`, and every form-token field
 * ({@see \Pramnos\Http\Session::getTokenField()}, whose name is the session's token).
 *
 * Answered as `{"token": "…", "form": {"name": "…", "value": "…", "value_ip": "…"}}`, never cached:
 * the form token's value with and without the client's address, since a form may pin either.
 * Another site cannot read it: a cross-origin script gets no response body without CORS, which
 * this does not send, and a request the browser marks as cross-site is refused outright. Served by the framework, so an application adds nothing.
 */
class Csrf extends Controller
{
    /** The token, to a page of this site. */
    public function display(): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return $this->answer(405, null);
        }
        // Sent by every current browser; missing only from one too old to say, or a script.
        if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin')) === 'cross-site') {
            return $this->answer(403, null);
        }

        $session = \Pramnos\Http\Session::getInstance();

        return $this->answer(200, [
            'token' => $session->getCsrfToken(),
            'form'  => [
                'name'     => (string) array_key_first($session->tokenParameters()),
                'value'    => $session->getFingerprint(),
                'value_ip' => $session->getFingerprint(true),
            ],
        ]);
    }

    /**
     * A JSON answer that no cache keeps.
     *
     * @param array<string, mixed>|null $body
     */
    protected function answer(int $status, ?array $body): mixed
    {
        return \Pramnos\Http\Response::make($body === null ? '' : (string) json_encode($body), $status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
