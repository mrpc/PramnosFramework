<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Auth\FactorEnrolment;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Walks a privileged account to the second-factor setup screen until it has enrolled one.
 *
 * The other half of `auth.security.require_second_factor_from_usertype`. That switch makes a
 * second factor a condition of signing in, and an account with nothing set up satisfies it
 * with a six-digit code by email — the only on-ramp there is, since enrolment happens after
 * signing in. This is what stops the on-ramp being the destination: with
 * `require_factor_enrolment_from_usertype` set, an account at or above that usertype gets
 * every page redirected to the setup screen until it holds an authenticator, a passkey, or
 * an adaptor of comparable strength. See {@see FactorEnrolment}.
 *
 * ```php
 * // app/app.php
 * 'middleware' => [
 *     'Pramnos\Http\Middleware\SessionTrackingMiddleware',
 *     'Pramnos\Http\Middleware\RequireFactorEnrolmentMiddleware',
 * ],
 * 'auth' => ['security' => [
 *     'require_second_factor_from_usertype'    => 80,
 *     'require_factor_enrolment_from_usertype' => 80,
 * ]],
 * ```
 *
 * Registered rather than always-on: it is a redirect on every request of a signed-in
 * session, and an application that has not asked for that should not get it. With the switch
 * unset it does nothing at all, so registering it is safe on its own.
 *
 * ## What is allowed through
 *
 * The wall has to leave open the doors that lead out of it, or it is a lockout:
 *
 * - **the setup screens** — the destination, and the passkey endpoints the browser calls
 *   from them;
 * - **the sign-in flow** — a half-finished login must be able to finish, and the account
 *   screen it lands on is where the setup link lives;
 * - **signing out** — always; a wall somebody cannot leave is a trap;
 * - **the API and the discovery documents** — a machine client is not going to read a
 *   redirect, and the sign-in half of the requirement already governs the token exchange;
 * - **health and assets** — a monitor's request is not a person to be walked anywhere.
 *
 * Everything else is redirected, including POSTs: an operator action that must not run is
 * one whose body is better dropped than replayed later.
 *
 * ## It fails open
 *
 * Anything this cannot answer — no session, no application, a store that will not read —
 * lets the request through. The failure mode of guessing wrong in the other direction is
 * every administrator redirected in a loop, and the screen that would fix it is one of the
 * ones being redirected.
 */
class RequireFactorEnrolmentMiddleware implements MiddlewareInterface
{
    /**
     * Path prefixes that are never gated, lower-case, without leading slashes.
     *
     * @var list<string>
     */
    private const ALWAYS_ALLOWED = array(
        // The destination and its endpoints.
        'twofactorauth',
        'twofactor',
        'passkey',
        // The sign-in flow, and the account screen it lands on.
        'login',
        'logout',
        'account',
        // Machines.
        'api',
        '.well-known',
        'oauth',
        'health',
        // Files.
        'assets',
        'media',
        'favicon.ico',
        'robots.txt',
    );

    /** @var list<string> Extra prefixes this application wants left open. */
    private array $allowed;

    /** Where somebody is sent, relative to `sURL`. */
    private string $setupPath;

    private FactorEnrolment $enrolment;

    /**
     * @param list<string>     $allowed   Extra path prefixes to leave open.
     * @param string           $setupPath Where to send them; the bundled 2FA setup screen.
     * @param ?FactorEnrolment $enrolment Seam for tests.
     */
    public function __construct(
        array $allowed = array(),
        string $setupPath = 'TwoFactorAuth/setup',
        ?FactorEnrolment $enrolment = null
    ) {
        $this->allowed   = array_map('strtolower', $allowed);
        $this->setupPath = ltrim($setupPath, '/');
        $this->enrolment = $enrolment ?? new FactorEnrolment();
    }

    public function handle(Request $request, callable $next): mixed
    {
        if (!$this->mustEnrol($request)) {
            return $next($request);
        }

        // A flag, because a redirect with no explanation reads as a broken link and this one
        // lands on a screen the person did not ask for. Straight into `$_SESSION`: `Session`
        // reads with `get()` and has no writer, which is how every other flag here is set.
        $_SESSION['factor_enrolment_required'] = true;

        $target = (defined('sURL') ? sURL : '/') . $this->setupPath;

        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        }

        // Not `$next()`: the request stops here. Returning null short-circuits the pipeline,
        // which is what `MiddlewareInterface` documents.
        return null;
    }

    /**
     * Whether this particular request has to be redirected.
     */
    protected function mustEnrol(Request $request): bool
    {
        try {
            $floor = \Pramnos\Auth\SecurityPolicy::factorEnrolmentFromUsertype();

            if ($floor < 1) {
                return false;
            }

            if (!\Pramnos\Http\Session::staticIsLogged()) {
                return false;
            }

            if ($this->isAllowed((string) $request->getRequestUri())) {
                return false;
            }

            $user = \Pramnos\User\User::getCurrentUser();

            if (!is_object($user)) {
                return false;
            }

            return $this->enrolment->isRequiredFor(
                (int) ($user->userid ?? 0),
                (int) ($user->usertype ?? 0)
            );
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'RequireFactorEnrolmentMiddleware could not decide, so the request was '
                . 'allowed: ' . $exception->getMessage(),
                'auth'
            );

            return false;
        }
    }

    /**
     * Whether a path is one of the doors that lead out of the wall.
     *
     * Compared on the first segment rather than as a substring: `logo.png` is not `logout`,
     * and a substring test on a list this short is how an exemption ends up wider than it
     * reads.
     */
    protected function isAllowed(string $uri): bool
    {
        $path    = strtolower(trim(parse_url($uri, PHP_URL_PATH) ?? $uri, '/'));
        $segment = explode('/', $path)[0] ?? '';

        if ($path === '') {
            // The front page. Gated like everything else — an administrator with no factor
            // has nothing to do there that cannot wait.
            return false;
        }

        foreach (array_merge(self::ALWAYS_ALLOWED, $this->allowed) as $prefix) {
            if ($segment === $prefix || $path === $prefix) {
                return true;
            }
        }

        return false;
    }
}
