<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Email\MailAction;
use Pramnos\Email\MailingList as Lists;
use Pramnos\Email\MailTypes;
use Pramnos\Http\Request;

/**
 * `/mailinglist/subscribe` and `/mailinglist/confirm` — the public half of opt-in lists.
 *
 * A framework controller, so it answers in every application that runs the framework without a
 * wrapper, as `Unsubscribe` does. The class is `Mailinglist`, not `MailingList`: the router asks
 * for `ucfirst('mailinglist')`, and on a case-sensitive filesystem the autoloader finds only the
 * file whose name is exactly that. Both addresses are reached by somebody who may have no account:
 * the form on a landing page, and the link in the confirmation mail.
 *
 * ```html
 * <form method="post" action="/mailinglist/subscribe">
 *   <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
 *   <input type="hidden" name="list" value="newsletter">
 *   <input type="email" name="email" required>
 *   <p>News about the product, once a month.</p>   <!-- the type's description: what is stored -->
 *   <button>Subscribe</button>
 * </form>
 * ```
 *
 * **What the person agreed to is the type's description.** It is taken from the registered
 * {@see \Pramnos\Email\MailType}, not from the form, because a consent trail whose sentence the
 * visitor's browser supplied proves nothing. Show that sentence beside the button.
 */
class Mailinglist extends \Pramnos\Application\Controller
{
    use StandalonePage;

    public $actions = ['display', 'subscribe', 'confirm'];

    /** Requests one address may make an hour to the form. */
    public const FORM_LIMIT = 10;

    /** `/mailinglist` itself has nothing to show. */
    public function display(array $args = []): void
    {
        \Pramnos\Framework\Factory::getDocument('raw');
        $this->respond(404, 'Not found.');
    }

    /**
     * `POST /mailinglist/subscribe` — email, list, the form token, and an optional `source`.
     *
     * The answer is **the same whatever the address was** — new, pending, already subscribed —
     * so the form cannot be used to find out who is on a list. What differs is only a malformed
     * address or a list nobody declared, which say nothing about anybody.
     */
    public function subscribe(): void
    {
        \Pramnos\Framework\Factory::getDocument('raw');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->answer(405, 'Subscribing is a form post.');

            return;
        }

        if (!$this->validFormToken()) {
            $this->answer(403, 'This form has expired. Reload the page and try again.');

            return;
        }

        // Throws TooManyRequestsException past the limit, which the application renders as 429.
        $this->limiter()->handle(Request::getInstance(), function (): string {
            $this->handleSubscribe();

            return '';
        });
    }

    /**
     * `/mailinglist/confirm?t=…` — a page with a button (GET), then the confirmation (POST).
     *
     * Two steps, because mail scanners follow every link in a message: confirming on the GET
     * would subscribe everybody whose provider checks links, which is not consent. The token is
     * the credential, so the POST needs no session and no form token.
     */
    public function confirm(): void
    {
        \Pramnos\Framework\Factory::getDocument('raw');

        $token    = (string) ((new Request())->get('t', '', 'request'));
        $verified = MailAction::verify($token);

        if ($verified === null || $verified['action'] !== Lists::CONFIRM_ACTION) {
            $this->page(
                'This link no longer works',
                'It may have expired, or been cut short by your mail program. Subscribing again '
                . 'sends a new one.'
            );

            return;
        }

        $type  = MailTypes::byList((string) ($verified['claim']['l'] ?? ''));
        $label = htmlspecialchars($type !== null ? $type->label : 'this list', ENT_QUOTES);

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->standalonePageData = ['state' => 'awaiting-confirmation', 'list' => (string) ($verified['claim']['l'] ?? '')];
            $this->page(
                'Confirm your subscription',
                'Press the button to start receiving <strong>' . $label . '</strong>.',
                '<form method="post"><input type="hidden" name="t" value="'
                . htmlspecialchars($token, ENT_QUOTES) . '"><p><button type="submit">Confirm</button></p></form>'
            );

            return;
        }

        $confirmed = $this->lists()->confirm($token);

        // What a script on the page can count: the confirmed double opt-in, and of which list.
        $this->standalonePageData = [
            'state' => $confirmed !== null ? 'confirmed' : 'nothing-to-confirm',
            'list'  => (string) ($verified['claim']['l'] ?? ''),
        ];

        $this->page(
            $confirmed !== null ? 'You are subscribed' : 'Nothing to confirm',
            $confirmed !== null
                ? 'You will receive <strong>' . $label . '</strong> at <strong>'
                    . htmlspecialchars($confirmed['email'], ENT_QUOTES) . '</strong>. Every message '
                    . 'carries a link to stop.'
                : 'This address is already confirmed, or it left the list after this link was sent.'
        );
    }

    /** Validate, subscribe, answer — inside the rate limit. */
    private function handleSubscribe(): void
    {
        $email  = trim((string) ($_POST['email'] ?? ''));
        $list   = (string) ($_POST['list'] ?? '');
        $source = (string) ($_POST['source'] ?? 'form');
        $type   = MailTypes::byList($list);

        if ($type === null || !$type->optIn) {
            $this->answer(404, 'There is no such list.');

            return;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->answer(400, 'That is not an email address.');

            return;
        }

        try {
            $this->lists()->subscribe($list, $email, [
                // A short identifier or nothing: this value is the visitor's, not the application's.
                'source'   => preg_match('/^[a-z0-9_-]{1,32}$/', $source) ? $source : 'form',
                'consent'  => $type->description,
                'language' => (string) \Pramnos\Translator\Language::getInstance()->currentlang(),
                'ip'       => (string) Request::clientIp(''),
            ]);
        } catch (\Throwable $e) {
            // Logged, and answered like a success: telling a stranger that something failed for
            // this address is telling them something about it.
            \Pramnos\Logs\Logger::logError('Mailing list subscription failed: ' . $e->getMessage(), $e);
        }

        // Pending, whatever the address was: the answer must not tell who is already on the list.
        $this->standalonePageData = ['state' => 'pending', 'list' => $list];
        $this->answer(200, 'Check your inbox: we have sent a link to confirm the subscription. If it '
            . 'does not arrive, the address may already be subscribed.');
    }

    /**
     * Answer as JSON to a script and as a page to a browser.
     *
     * A form on a landing page posts either way, and the one it posts from decides.
     */
    private function answer(int $status, string $message): void
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

        if (str_contains($accept, 'application/json')) {
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => $status < 400, 'message' => $message] + $this->standalonePageData);

            return;
        }

        if (!headers_sent()) {
            http_response_code($status);
        }
        $this->page($status < 400 ? 'Almost there' : 'We could not do that', htmlspecialchars($message, ENT_QUOTES));
    }

    /** The form's synchronizer token — the one `CsrfMiddleware::tokenField()` renders. */
    protected function validFormToken(): bool
    {
        $submitted = (string) ($_POST['_csrf_token'] ?? '');

        return $submitted !== '' && \Pramnos\Http\Session::getInstance()->verifyCsrfToken($submitted);
    }

    /** Requests per address per hour. A seam, so a test can supply its own cache. */
    protected function limiter(): \Pramnos\Http\Middleware\RateLimitMiddleware
    {
        return new \Pramnos\Http\Middleware\RateLimitMiddleware(self::FORM_LIMIT, 3600, 'mailinglist:');
    }

    protected function lists(): Lists
    {
        return new Lists();
    }
}
