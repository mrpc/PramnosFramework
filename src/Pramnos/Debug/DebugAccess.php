<?php

declare(strict_types=1);

namespace Pramnos\Debug;

/**
 * Opens the debug toolbar for one browser, on a server where it is off.
 *
 * The toolbar is a development tool: `APP_DEBUG` turns it on for everybody, and
 * on a live server that is not an option. But the bugs worth a toolbar are
 * mostly the ones that only happen on the live server, with live data and live
 * traffic — and reproducing those anywhere else is the hard part of fixing them.
 *
 * So: a signed token, redeemed once, that turns the toolbar on for the browser
 * that presents it and nobody else.
 *
 * ```
 * php pramnos debug:token --ttl=2h
 *   https://example.com/?_debug=1786237200.9f86d0818986…
 * ```
 *
 * Opening that URL sets a cookie and the toolbar appears — for that browser,
 * until the token expires. `?_debug=off` clears it.
 *
 * ## Why a cookie and not the session
 *
 * Service providers boot before `Application::init()` starts the session, so at
 * the moment the toolbar has to decide whether to exist, there is no session to
 * ask. `$_COOKIE` is already populated. It also means the grant travels with
 * every later request on its own — including the XHR and fetch calls a page
 * makes after it has rendered, which is exactly where the toolbar could not see
 * before.
 *
 * ## What the token is
 *
 * `<expiry>.<hmac>` — the expiry as a unix timestamp, and an HMAC-SHA256 of it
 * under the application key. Self-contained: no storage, no session, nothing to
 * clean up, and it stops working by itself. A leaked token is a leak with an
 * end date rather than a permanent one, which is the property that makes this
 * safe enough to use at all.
 *
 * Comparison is {@see hash_equals()}, so a wrong token cannot be found one byte
 * at a time.
 *
 * ## Fail closed
 *
 * With no application key there is nothing to sign with, and every check returns
 * false rather than falling back to a default secret. A predictable secret here
 * would hand the query log of a live server to anyone who guessed it.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class DebugAccess
{
    /** @var string The cookie that carries the grant */
    public const COOKIE = 'pramnos_debug';

    /** @var string The query parameter that redeems a token, or revokes the grant */
    public const PARAM = '_debug';

    /** @var string What `?_debug=` must be set to in order to revoke */
    public const REVOKE = 'off';

    /** @var int Longest grant this will issue or honour — twelve hours */
    public const MAX_TTL = 43200;

    /**
     * Decided once per request, because it can set a cookie.
     *
     * @var bool|null
     */
    protected static ?bool $granted = null;

    /**
     * Is the toolbar open for this browser?
     *
     * Answers three questions in order: is the caller redeeming a token, is the
     * caller revoking, and is there a valid grant already. The first two can
     * change the cookie, so this runs once per request and remembers.
     */
    public static function isGranted(): bool
    {
        if (static::$granted !== null) {
            return static::$granted;
        }

        static::$granted = static::decide();

        return static::$granted;
    }

    /**
     * Work out this request's answer.
     */
    protected static function decide(): bool
    {
        $offered = static::offeredToken();

        if ($offered === static::REVOKE) {
            static::clearCookie();

            return false;
        }

        if ($offered !== null && static::verify($offered)) {
            static::setCookie($offered);

            return true;
        }

        $cookie = static::cookieToken();

        // A cookie that no longer verifies — expired, or signed with a key that
        // has since been rotated — is cleared rather than merely ignored, so the
        // browser stops sending it.
        if ($cookie !== null && !static::verify($cookie)) {
            static::clearCookie();

            return false;
        }

        return $cookie !== null;
    }

    /**
     * Mint a token.
     *
     * @param  int $ttl How long it should last, in seconds
     * @return string   `<expiry>.<hmac>`
     * @throws \RuntimeException When there is no application key to sign with
     */
    public static function issue(int $ttl = 3600): string
    {
        $secret = static::secret();

        if ($secret === '') {
            throw new \RuntimeException(
                'No application key: nothing to sign a debug token with. '
                . 'Run `php pramnos key:generate` first.'
            );
        }

        $ttl = max(60, min($ttl, static::MAX_TTL));
        $expiry = time() + $ttl;

        return $expiry . '.' . static::sign((string) $expiry, $secret);
    }

    /**
     * Is this token real, and still valid?
     *
     * @param  string $token
     * @return bool
     */
    public static function verify(string $token): bool
    {
        $secret = static::secret();

        if ($secret === '') {
            return false;
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        [$expiry, $signature] = $parts;

        if (!ctype_digit($expiry)) {
            return false;
        }

        // Signature first, expiry second: an unsigned token should not be able
        // to learn anything from how quickly it was rejected.
        if (!hash_equals(static::sign($expiry, $secret), $signature)) {
            return false;
        }

        return (int) $expiry > time();
    }

    /**
     * When the current grant runs out, as a unix timestamp.
     *
     * @return int|null Null when there is no valid grant
     */
    public static function expiresAt(): ?int
    {
        $token = static::cookieToken() ?? static::offeredToken();

        if ($token === null || $token === static::REVOKE || !static::verify($token)) {
            return null;
        }

        return (int) explode('.', $token, 2)[0];
    }

    /**
     * Forget this request's decision.
     *
     * For tests, and for a long-running process that handles more than one
     * request in a single PHP lifetime.
     */
    public static function reset(): void
    {
        static::$granted = null;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * The token the caller put in the query string, if any.
     */
    protected static function offeredToken(): ?string
    {
        $offered = $_GET[static::PARAM] ?? null;

        return is_string($offered) && $offered !== '' ? $offered : null;
    }

    /**
     * The token the browser is carrying, if any.
     */
    protected static function cookieToken(): ?string
    {
        $cookie = $_COOKIE[static::COOKIE] ?? null;

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /**
     * The HMAC of a token's expiry.
     */
    protected static function sign(string $expiry, string $secret): string
    {
        return hash_hmac('sha256', 'debug:' . $expiry, $secret);
    }

    /**
     * The key tokens are signed with.
     *
     * `APP_KEY` first, because that is where an application's secrets live and
     * rotating it should invalidate outstanding tokens. A dedicated
     * `debug_token_secret` setting overrides it for an installation that wants
     * to hand out debug access without sharing the key everything else uses.
     */
    protected static function secret(): string
    {
        $setting = \Pramnos\Application\Settings::getSetting('debug_token_secret');

        if (is_string($setting) && $setting !== '') {
            return $setting;
        }

        $key = getenv('APP_KEY');

        if (is_string($key) && $key !== '') {
            return $key;
        }

        $key = $_ENV['APP_KEY'] ?? null;

        return is_string($key) ? $key : '';
    }

    /**
     * Remember the grant in the browser.
     *
     * The cookie expires with the token it carries, so a browser stops sending a
     * grant that has run out instead of asking the server about it every time.
     * See {@see cookieOptions()} for how it is written.
     */
    protected static function setCookie(string $token): void
    {
        // So that the rest of *this* request already sees the grant, rather than
        // only the next one. Done before the SAPI guard, because it is true
        // regardless of whether a header can still be sent.
        $_COOKIE[static::COOKIE] = $token;

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        // @codeCoverageIgnoreStart
        // Emitting a Set-Cookie header is HTTP-only; unreachable under CLI,
        // which is where the tests run. What the header *says* is decided by
        // cookieOptions(), which is tested.
        setcookie(
            static::COOKIE,
            $token,
            static::cookieOptions((int) explode('.', $token, 2)[0])
        );
        // @codeCoverageIgnoreEnd
    }

    /**
     * Take the grant away.
     */
    protected static function clearCookie(): void
    {
        unset($_COOKIE[static::COOKIE]);

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        // @codeCoverageIgnoreStart
        setcookie(static::COOKIE, '', static::cookieOptions(time() - 3600));
        // @codeCoverageIgnoreEnd
    }

    /**
     * How the grant cookie is written.
     *
     * `HttpOnly` because no script needs to read it and a stolen token is a live
     * server`s query log. `Secure` whenever the request arrived over HTTPS.
     * `SameSite=Lax` so it still rides along with a normal navigation but not
     * with a cross-site POST.
     *
     * Separate from the call that uses it so that these four decisions can be
     * asserted: they are the difference between a debug grant and a security
     * incident, and `setcookie()` itself cannot be observed under CLI.
     *
     * @param  int $expires When the cookie should die, as a unix timestamp
     * @return array<string, mixed>
     */
    protected static function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => static::isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * Did this request arrive over HTTPS?
     *
     * Includes the proxy header, because a live installation behind a load
     * balancer terminates TLS there and would otherwise never mark the cookie
     * secure.
     */
    protected static function isSecureRequest(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return true;
        }

        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
