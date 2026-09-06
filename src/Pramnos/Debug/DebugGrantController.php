<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Application\Controller;
use Pramnos\Http\Middleware\CsrfMiddleware;

/**
 * Turn the debug toolbar on for your own browser, from a screen.
 *
 * {@see DebugAccess} has always been able to do this; the only door was
 * `php pramnos debug:token`, which needs a shell on the server. That is the right
 * door for a deploy box and the wrong one for the moment somebody is *looking at*
 * a page that is behaving oddly — the round trip is shell, copy, paste, and the
 * page they were on is gone by the time they get back.
 *
 * So: two buttons. The grant is the same signed token the CLI mints, taken up
 * through the same `?_debug=` redemption, and it lands you back on the page you
 * came from with the toolbar on.
 *
 * ## Why this is not in the DevPanel
 *
 * The DevPanel refuses to open unless `APP_DEBUG` or `DEVELOPMENT` says the
 * deployment is a development one, and that lock is argued at length on
 * {@see \Pramnos\DevPanel\DevPanelController}: it browses the database, reads the
 * cache and dumps the container, and none of that should be reachable on a live
 * server by anything as light as a permission.
 *
 * The toolbar is a different size of thing. It shows **this request** — the
 * queries it ran, the routes it matched, its own timings — to the one browser
 * that redeemed a grant. It does not read other traffic and it does not open the
 * database. And its whole reason for existing is the server where `APP_DEBUG` is
 * off, so putting it behind the DevPanel's lock would mean it worked only where
 * nobody needs it.
 *
 * ## What it still shows, and what that means for who may click
 *
 * The query collector reports the SQL each request ran, and this framework
 * interpolates values into statements — so the toolbar shows real column values,
 * including personal ones, for the rows your own browsing touches. The session
 * collector already masks `password`, `token`, `secret` and friends; the query log
 * does not.
 *
 * That is why the floor here is a user type and not merely "signed in", why it is
 * configurable upwards, and why every grant is written to the `auth` log with who
 * asked and for how long. A shell leaves a trace by existing; a button has to be
 * made to leave one.
 *
 * ```php
 * // app.php — raise the floor, or lower it for a staging box
 * 'debug' => ['grant_min_usertype' => 95],
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class DebugGrantController extends Controller
{
    /** Who may issue a grant, unless `app.php` says otherwise. */
    public const DEFAULT_MIN_USERTYPE = 90;

    /** How long a grant lasts when the form does not say. */
    public const DEFAULT_TTL = 3600;

    /** The TTLs the screen offers, in seconds. */
    public const TTL_CHOICES = array(900 => '15 minutes', 3600 => '1 hour', 14400 => '4 hours');

    public function __construct(
        ?\Pramnos\Application\Application $application = null,
        $userPermissions = array()
    ) {
        // All three require a session. `enable` and `disable` are reached by POST, so
        // `CsrfMiddleware` validates them — a state change behind a GET link is a link
        // somebody else can get you to click.
        $this->addAuthAction(array('display', 'enable', 'disable'));

        // Both arguments forwarded, because `getFrameworkController()` passes both and a
        // constructor that quietly dropped the second would leave `user_permissions`
        // empty. This class does not gate on it — see mayGrant() — but a subclass an
        // application writes might, and a base that swallows what it was given is a
        // surprise nobody goes looking for.
        parent::__construct($application, $userPermissions);
    }

    /**
     * The screen: whether a grant is live, and the buttons.
     */
    public function display(): mixed
    {
        if (!$this->mayGrant()) {
            return $this->refuse();
        }

        echo $this->screen();

        return null;
    }

    /**
     * Issue a grant and go back to where the request came from, with it applied.
     *
     * Redirected through `?_debug=<token>` rather than setting the cookie here, so
     * redemption keeps happening in exactly one place — {@see DebugAccess::isGranted()}
     * — and this action cannot drift away from what the CLI's printed URL does.
     */
    public function postEnable(): mixed
    {
        if (!$this->mayGrant()) {
            return $this->refuse();
        }

        $ttl = (int) ($_POST['ttl'] ?? self::DEFAULT_TTL);

        if (!isset(self::TTL_CHOICES[$ttl])) {
            $ttl = self::DEFAULT_TTL;
        }

        try {
            $token = DebugAccess::issue($ttl);
        } catch (\RuntimeException $exception) {
            /*
             * No application key. `DebugAccess` throws rather than signing with a
             * predictable secret — a default there would hand the query log of a live
             * server to anybody who guessed it.
             *
             * Caught rather than allowed to become a 500, because the fix is one command
             * and the person reading this is the person who can run it. The first
             * version of this checked for an empty return value, which `issue()` never
             * produces: a branch that could not run, standing in for handling that was
             * not there.
             */
            echo $this->screen($exception->getMessage());

            return null;
        }

        \Pramnos\Logs\Logger::log(
            'Debug toolbar grant issued for ' . $ttl . 's to user '
            . (int) ($this->getUserId()) . ' from ' . $this->clientIp(),
            'auth'
        );

        $this->redirect($this->applyTo($this->returnUrl(), $token));

        return null;
    }

    /**
     * End the grant now, rather than waiting for it to expire.
     */
    public function postDisable(): mixed
    {
        if (!$this->mayGrant()) {
            return $this->refuse();
        }

        \Pramnos\Logs\Logger::log(
            'Debug toolbar grant revoked by user ' . (int) ($this->getUserId()),
            'auth'
        );

        $this->redirect($this->applyTo($this->returnUrl(), DebugAccess::REVOKE));

        return null;
    }

    // =========================================================================
    // Guard
    // =========================================================================

    /**
     * May the person on this request issue a grant?
     *
     * Checked here rather than left to `addActionPermission()`, and the reason is worth
     * knowing: `Controller::auth()` enforces action permissions **only when
     * `$this->user_permissions` is non-empty**. On an installation that populates
     * nothing there, a declared permission is skipped in silence — which is a fine
     * default for a screen that lists things and the wrong one for a switch that turns
     * on a query log.
     */
    protected function mayGrant(): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if (!is_object($user) || (int) ($user->userid ?? 0) < 1) {
            return false;
        }

        return (int) ($user->usertype ?? 0) >= $this->minUserType();
    }

    /**
     * The user-type floor, from `app.php` and nowhere else.
     *
     * A setting row would put "who may read the query log of this live server" behind a
     * field on a screen. This is a property of the deployment, so it lives with the code
     * — the same argument the DevPanel makes about its own mount point.
     */
    protected function minUserType(): int
    {
        // `currentInstance()` and not `getInstance()`: the second is a factory that would
        // read app.php, define constants and boot a whole application to answer a
        // question about a floor. `?->` because a unit test has no instance at all, and
        // the default is the safe answer.
        $configured = \Pramnos\Application\Application::currentInstance()
            ?->applicationInfo['debug']['grant_min_usertype'] ?? null;

        return ($configured === null || $configured === '')
            ? self::DEFAULT_MIN_USERTYPE
            : (int) $configured;
    }

    private function getUserId(): int
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) ? (int) ($user->userid ?? 0) : 0;
    }

    private function clientIp(): string
    {
        return \Pramnos\Http\Request::clientIp('an unknown address');
    }

    /**
     * Refused, with the reason and the floor.
     *
     * Not a bare 403: somebody who should have this and does not is going to ask why,
     * and the answer is one number in `app.php`.
     */
    private function refuse(): mixed
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>403</title><h1>403</h1>'
            . '<p>Issuing a debug toolbar grant needs user type '
            . (int) $this->minUserType() . ' or above on this installation.</p>';

        return null;
    }

    // =========================================================================
    // Where to go back to
    // =========================================================================

    /**
     * The page this was opened from, or the site root.
     *
     * `DevPanelController::returnUrlFor()` already does exactly this and does the part
     * that is easy to get wrong: it remembers the referrer in the session, because the
     * POST that issues the grant has this screen as its own referrer, and it refuses
     * anything that is not on this site. A redirect target taken from a request without
     * that check is an open redirect.
     */
    protected function returnUrl(): string
    {
        /*
         * An explicit `return`, when the caller named one and it is on this site.
         *
         * The DevPanel's switch does: it wants you left where you were working rather
         * than sent to the site root, and it is the one caller that knows which of its
         * own tabs you were on.
         *
         * Validated against `sURL` before it is used. A redirect target taken from a
         * request without that check is an open redirect, and this one arrives in a POST
         * field where anybody can put anything.
         */
        $asked = (string) ($_POST['return'] ?? '');
        $base  = defined('sURL') ? rtrim((string) sURL, '/') : '';

        if ($asked !== '' && $base !== ''
            && \Pramnos\DevPanel\DevPanelController::isReturnable($asked, $base)
        ) {
            return $asked;
        }

        /*
         * The DevPanel excluded too, and that omission is what made this feature look
         * broken: its tab links here, so the referrer on the way in is the panel — a page
         * that `echo`es its own HTML and never carries the toolbar. Enabling the grant
         * then landed back on it, with nothing to show for the click.
         */
        return \Pramnos\DevPanel\DevPanelController::returnUrlFor(
            'debugbar',
            array((defined('sURL') ? rtrim((string) sURL, '/') : '') . '/debugbar')
        );
    }

    /**
     * Put `?_debug=<value>` on a URL that may already have a query string.
     */
    private function applyTo(string $url, string $value): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . DebugAccess::PARAM . '=' . rawurlencode($value);
    }

    // =========================================================================
    // The screen
    // =========================================================================

    /**
     * Small and self-contained on purpose: this has to render on a live server whose
     * theme may be mid-deploy, and it is two buttons.
     */
    protected function screen(string $problem = ''): string
    {
        $expires = DebugAccess::expiresAt();
        $granted = $expires !== null;
        $action  = htmlspecialchars($this->baseUrl() . '/debugbar', ENT_QUOTES);

        $html = '<!doctype html><meta charset="utf-8"><title>Debug toolbar</title>'
            . '<style>body{font:14px/1.5 system-ui,sans-serif;max-width:34rem;margin:3rem auto;'
            . 'padding:0 1rem}h1{font-size:1.3rem}form{display:inline}button{font:inherit;'
            . 'padding:.4rem .8rem}.muted{color:#666}.warn{background:#fff4e5;padding:.6rem .8rem;'
            . 'border-left:3px solid #e59700}</style><h1>Debug toolbar</h1>';

        if ($problem !== '') {
            $html .= '<p class="warn">' . htmlspecialchars($problem) . '</p>';
        }

        if ($granted) {
            $html .= '<p>On for this browser until <strong>'
                . htmlspecialchars(date('H:i', (int) $expires)) . '</strong>.</p>'
                . '<form method="post" action="' . $action . '/disable">'
                . CsrfMiddleware::tokenField()
                . '<button type="submit">Turn it off</button></form>';
        } else {
            $html .= '<p>Off. Turning it on applies to <strong>this browser only</strong> and '
                . 'stops by itself.</p>';

            foreach (self::TTL_CHOICES as $seconds => $label) {
                $html .= '<form method="post" action="' . $action . '/enable">'
                    . CsrfMiddleware::tokenField()
                    . '<input type="hidden" name="ttl" value="' . (int) $seconds . '">'
                    . '<button type="submit">' . htmlspecialchars($label) . '</button></form> ';
            }
        }

        $html .= '<p class="muted">The toolbar reports the queries <em>your own</em> requests '
            . 'run, with their values. Every grant is recorded in the auth log.</p>';

        return $html;
    }

    private function baseUrl(): string
    {
        return defined('sURL') ? rtrim((string) sURL, '/') : '';
    }
}
