<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * A cookie that says only "somebody is signed in here" — readable by a CDN rule, a service worker
 * and the page cache's early path, none of which can open a session.
 *
 * ```php
 * // app/app.php
 * 'session' => ['login_marker' => 'logged_in'],
 * ```
 *
 * Its value is `1` and it holds no secret, so it is not HttpOnly: a worker deciding whether it may
 * keep a page, and an edge deciding whether to bypass, have to be able to read it. It is set when
 * somebody signs in — remembered, for as long as the remember-me sign-in — and cleared when they
 * sign out, or when a request finds it with nobody signed in (the session expired). Off until it
 * is named. The default name begins with `logged`, which the page cache's `bypassCookies` already
 * bypasses on, so a page for a signed-in visitor is never served from or stored in the cache.
 */
final class LoginMarker
{
    /** Days a remembered marker lives — as long as the remember-me cookies. */
    private const DAYS = 14;

    /** The marker's name, or '' when the application has not asked for one. */
    public static function name(): string
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $name = is_object($app) ? ($app->applicationInfo['session']['login_marker'] ?? '') : '';

        return is_string($name) && preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1 ? $name : '';
    }

    /**
     * Say somebody is signed in: for as long as the browser runs, or — remembered — as long as the
     * remember-me sign-in.
     */
    public static function set(bool $remembered): void
    {
        $name = self::name();
        if ($name === '') {
            return;
        }
        self::write($name, '1', $remembered ? time() + self::DAYS * 86400 : 0);
        $_COOKIE[$name] = '1';
    }

    /** Say nobody is. */
    public static function clear(): void
    {
        $name = self::name();
        if ($name === '' || !isset($_COOKIE[$name])) {
            return;
        }
        self::write($name, '', time() - 3600);
        unset($_COOKIE[$name]);
    }

    /**
     * Bring the marker in line with the session, once a request knows who it is: set where
     * somebody is signed in without one, cleared where it stayed behind a session that ended.
     */
    public static function sync(bool $signedIn): void
    {
        $name = self::name();
        if ($name === '') {
            return;
        }
        if ($signedIn && !isset($_COOKIE[$name])) {
            self::set(\Pramnos\Http\Request::getInstance()->cookieget('auth') !== null);
        } elseif (!$signedIn && isset($_COOKIE[$name])) {
            self::clear();
        }
    }

    private static function write(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => \Pramnos\Http\Request::getInstance()->isHttps(),
            // Read by a worker and an edge: it holds no secret.
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
