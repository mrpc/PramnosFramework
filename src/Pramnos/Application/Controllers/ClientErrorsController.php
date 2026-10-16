<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Logs\ClientErrors;

/**
 * `POST /ClientErrors` — where the application's pages report the errors they hit in the browser.
 *
 * `pf-utils.js` posts here from every server-rendered page that names this address in
 * `<meta name="pf-client-errors">`, and the service worker posts its own errors here. The body is
 * JSON (`message`, `source`, `line`, `column`, `stack`, `page`, `kind`, `release`), or the older
 * `error`/`agent` shape, as JSON or as a form; {@see ClientErrors} stores it.
 *
 * Open to anybody, signed in or not: an error a visitor hits before signing in is the one most
 * worth knowing about. So it is not guarded by a token — `sendBeacon()` cannot send a header,
 * and a visitor may have no session — but by what makes it useless to anybody else:
 *
 *   - **the same origin** — the `Origin` (or, without one, the `Referer`) is this site's;
 *   - **a size** — a body over 16 KB is refused unread;
 *   - **a rate** — 30 reports a minute from one address, where APCu counts them.
 *
 * It answers 204 with no body, and never an error page: a reporter that failed to report has
 * nothing it could do about it.
 *
 * An application puts it on an address with `src/Controllers/ClientErrors.php` extending this
 * class, which `init` writes.
 */
class ClientErrorsController extends Controller
{
    /** The largest body read, in bytes. */
    public const MAX_BODY = 16384;

    /** Reports a minute from one address. */
    public const PER_MINUTE = 30;

    /** Take one report. */
    public function display(): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');
        $this->releaseSession();

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return $this->answer(405);
        }
        if (!$this->sameOrigin()) {
            return $this->answer(403);
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            return $this->answer(413);
        }
        if ($this->overLimit()) {
            return $this->answer(429);
        }

        $report = $this->report();
        // Somebody else's code — an extension, a script of another host — is answered and not kept,
        // for the pages that report everything as for those that filter.
        if ($report !== [] && ClientErrors::foreign($report, (string) ($_SERVER['HTTP_HOST'] ?? ''), ClientErrors::hosts())) {
            return $this->answer(204);
        }
        if ($report !== []) {
            try {
                $user = \Pramnos\User\User::current();
                $this->errors()->record(
                    $report,
                    (string) ($report['agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''),
                    is_object($user) ? (int) ($user->userid ?? 0) : null
                );
            } catch (\Throwable $e) {
                // No table yet, or no database: the page's error is not worth a second one here.
                \Pramnos\Logs\Logger::log('A browser error could not be recorded: ' . $e->getMessage(), 'clienterrors');
            }
        }

        return $this->answer(204);
    }

    /** The store the reports go to. */
    protected function errors(): ClientErrors
    {
        return new ClientErrors();
    }

    /**
     * The report in the body: JSON, or a form.
     *
     * @return array<string, mixed>
     */
    protected function report(): array
    {
        $raw     = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }

        return array_intersect_key($_POST, array_flip(['message', 'source', 'line', 'column', 'stack', 'page', 'kind', 'release', 'error', 'agent']));
    }

    /** Whether the report comes from one of this site's own pages. */
    protected function sameOrigin(): bool
    {
        $from = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($from === '' || $host === '') {
            return false;
        }

        return strcasecmp((string) parse_url($from, PHP_URL_HOST), (string) parse_url('//' . $host, PHP_URL_HOST)) === 0;
    }

    /** Whether this address has sent more than its share this minute. */
    protected function overLimit(): bool
    {
        $address = md5(\Pramnos\Http\Request::clientIp('0.0.0.0'));
        if ($this->apcuCounts()) {
            $success = false;
            $count   = apcu_inc('clienterrors:' . $address, 1, $success, 60);
            if ($success) {
                return $count > self::PER_MINUTE;
            }
        }

        // Without APCu — production often has none — the application's cache, Redis where it is
        // one: the limit held nowhere before, and one broken browser filled a PHP pool.
        try {
            return !$this->bucket()->take('clienterrors:' . $address, self::PER_MINUTE, self::PER_MINUTE, 60)['allowed'];
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Browser reports are not rate-limited: no APCu, and the cache refused: ' . $e->getMessage(), 'clienterrors');

            return false;
        }
    }

    /** Whether APCu is there to count in. */
    protected function apcuCounts(): bool
    {
        return function_exists('apcu_inc') && function_exists('apcu_enabled') && apcu_enabled();
    }

    /** The bucket the limit is kept in, when there is no APCu. */
    protected function bucket(): \Pramnos\Http\TokenBucket
    {
        return new \Pramnos\Http\TokenBucket();
    }

    /**
     * Let go of the session before anything else. A report is nobody's business but the log's, and
     * a held session lock makes every other request of the same session wait behind it — each one
     * keeping a PHP worker — while the page that sent a burst of reports goes on loading.
     */
    protected function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** An empty answer with a status. */
    protected function answer(int $status): mixed
    {
        return \Pramnos\Http\Response::make('', $status);
    }
}
