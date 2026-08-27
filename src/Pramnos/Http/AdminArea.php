<?php

declare(strict_types=1);

namespace Pramnos\Http;

/**
 * An administration area mounted under a URL prefix.
 *
 * A project with admin screens wants them under one path — `/admin/users`,
 * `/admin/Applications` — with their own layout and a floor on who may reach
 * them. Every project that has wanted this has ended up writing the same thing:
 * a second set of controllers, or a prefix check in each one, or a rewrite rule
 * per screen.
 *
 * None of that is necessary, because the controllers are already the right ones.
 * All that separates `/admin/Users` from `/Users` is the prefix, so the prefix is
 * removed before routing sees the request and remembered here. Routing, actions,
 * `_option` and the key/value tail then behave exactly as they do without it —
 * there is no second code path to keep in step.
 *
 * ## Configuration
 *
 * ```php
 * // app/app.php
 * 'admin' => [
 *     'prefix'             => 'admin',     // omit or leave empty to switch the area off
 *     'theme'              => 'admin',     // theme used inside the area; optional
 *     'min_usertype'       => 80,          // floor for reaching any of it; optional
 *     'default_controller' => 'Dashboard', // what the bare prefix opens; optional
 * ],
 * ```
 *
 * ## When detection happens
 *
 * `Application::__construct()` calls `detect()`, which is before anything builds
 * a `Request` — the prefix has to be gone by then, because that is when the path
 * is split into controller and action. An application that constructs a `Request`
 * of its own *before* its `Application` will route the prefix as a controller
 * name; nothing here can help that, and no scaffolded front controller does it.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
final class AdminArea
{
    /** Whether the current request is inside the area. */
    private static bool $active = false;

    /** The configured prefix, with no surrounding slashes. */
    private static string $prefix = '';

    /** Minimum usertype for the area, or 0 when unrestricted. */
    private static int $minUserType = 0;

    /**
     * Decide whether this request is inside the area, and strip the prefix.
     *
     * Only the routing parameter is rewritten. `REQUEST_URI` keeps the address
     * the visitor actually asked for, because session tracking, logging and every
     * `return=` round trip need the real URL — an admin page that redirected to
     * login and came back to the prefix-stripped path would land outside the
     * area it started in.
     *
     * Idempotent: calling it twice does not strip twice, so an application that
     * constructs more than one `Application` in a process (tests do) is safe.
     *
     * @param  string $prefix      Path segment to mount the area under
     * @param  int    $minUserType Floor for reaching it, or 0 for none
     * @return bool                Whether the request is inside the area
     */
    public static function detect(string $prefix, int $minUserType = 0): bool
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return false;
        }

        self::$prefix      = $prefix;
        self::$minUserType = $minUserType;

        if (self::$active) {
            return true;
        }

        $route = (string) ($_GET['r'] ?? '');
        $route = ltrim($route, '/');

        if ($route === $prefix) {
            // The bare `/admin` — the area's own front page, which is whatever
            // the default controller is.
            $_GET['r']     = '';
            self::$active  = true;
            return true;
        }

        if (str_starts_with($route, $prefix . '/')) {
            $_GET['r']    = substr($route, strlen($prefix) + 1);
            self::$active = true;
            return true;
        }

        return false;
    }

    /** Is the current request inside the administration area? */
    public static function isActive(): bool
    {
        return self::$active;
    }

    /** The configured prefix, with no surrounding slashes. */
    public static function prefix(): string
    {
        return self::$prefix;
    }

    /** The minimum usertype for the area, or 0 when unrestricted. */
    public static function minUserType(): int
    {
        return self::$minUserType;
    }

    /**
     * An absolute URL inside the area.
     *
     * ```php
     * AdminArea::url('Users');       // https://example.com/admin/Users
     * AdminArea::url();              // https://example.com/admin/
     * ```
     *
     * Falls back to a plain `sURL`-relative URL when no area is configured, so a
     * caller does not have to branch on whether one exists.
     *
     * **With no path it ends in a slash, exactly as `sURL` does.** That is not
     * cosmetic: a base is something callers concatenate onto, and a breadcrumb doing
     * `$base = adminUrl(); … $base . 'users'` produced `/adminusers` — a 404 on every
     * trail in the area, and only in an application that had an area configured, since
     * without one the same code got `sURL` and its trailing slash.
     */
    public static function url(string $path = ''): string
    {
        $base = defined('sURL') ? \sURL : '/';
        $path = ltrim($path, '/');

        if (self::$prefix === '') {
            return $base . $path;
        }

        return $base . self::$prefix . '/' . $path;
    }

    /**
     * Forget the detected state.
     *
     * For test isolation: the class is static because the answer is a property of
     * the request, and a suite runs many requests in one process.
     */
    public static function reset(): void
    {
        self::$active      = false;
        self::$prefix      = '';
        self::$minUserType = 0;
    }
}
