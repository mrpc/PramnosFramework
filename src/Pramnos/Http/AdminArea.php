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

    /** Where a guest signs in for this area; the site's login when empty. */
    private static string $loginUrl = '';

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

    /**
     * This application **is** the area: an administration with a front controller of its own —
     * `www/admin/index.php` — rather than a prefix the site's routing strips. Every request it
     * serves is inside the area, so links, help and the side panel are built on the area's URL
     * (`sURL . 'admin/'`), and screens apply the area's floor. Called once, from the
     * application's `init()`, in place of {@see detect()}; nothing is rewritten.
     *
     * @param  string $prefix      The path the area is served under, e.g. `admin`
     * @param  int    $minUserType Floor for reaching it, or 0 for none
     */
    public static function serve(string $prefix, int $minUserType = 0): void
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return;
        }

        self::$prefix      = $prefix;
        self::$minUserType = $minUserType;
        self::$active      = true;
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
     * An address in the area that holds where no request is inside it — a queue job's link.
     *
     * `$base` first: the area's URL as the request that started the job saw it (`url()`), handed
     * to the job. A worker runs whichever application started it — often the site's, with no
     * area configured — and has no host of its own, so neither its prefix nor its `sURL` says
     * where the area is. Then the area's URL, in a request inside it. Then, in a web request with
     * no area configured, the serving application's own URL ({@see servingUrl()}): an
     * administration that is an application of its own, mounted at `/admin/`, has `URL` as its
     * address and `sURL` as the site's. Last — a console process — the configured prefix under the
     * site root, or from the host's root without one.
     */
    public static function address(string $path, string $base = ''): string
    {
        $path = ltrim($path, '/');

        // Only an address: anything else handed in is ignored rather than linked to.
        if (preg_match('#^(https?://[^\s"<>]+|/(?![/\\\\])[^\s"<>]*)$#i', $base) === 1) {
            return rtrim($base, '/') . '/' . $path;
        }

        if (self::$active) {
            return self::url($path);
        }

        $app    = \Pramnos\Application\Application::currentInstance();
        $prefix = trim((string) (is_object($app) ? ($app->applicationInfo['admin']['prefix'] ?? '') : ''), '/');

        if ($prefix === '' && !\Pramnos\Application\Application::inConsoleContext()) {
            $serving = self::$servingOverride ?? self::servingUrl();
            if ($serving !== '') {
                return rtrim($serving, '/') . '/' . $path;
            }
        }
        $path   = ($prefix !== '' ? $prefix . '/' : '') . $path;

        return \Pramnos\Http\SiteUrl::get() !== '' ? \Pramnos\Http\SiteUrl::to($path) : '/' . $path;
    }

    /** @var string|null The serving application's URL, where the constants cannot say it */
    private static ?string $servingOverride = null;

    /**
     * The URL of the application serving the request, when `URL` cannot say it — a test of an
     * application mounted in a subdirectory, where the constants are the suite's. Null for the
     * constants again.
     */
    public static function useServingUrl(?string $url): void
    {
        self::$servingOverride = $url;
    }

    /**
     * The base URL of the application serving this request, without a trailing slash.
     *
     * Where a developer page — the DevPanel, Adminer, the debug-toolbar grant — builds its own
     * links. It is reachable both at the site root and inside an area, and links built from
     * `sURL` alone sent a page served at `/admin/devpanel` to `/devpanel` for its tabs and
     * `/adminer` for its stylesheet.
     *
     * With an area configured, the area's URL when the request is inside it and `sURL` when it
     * is not — `URL` cannot tell the two apart, since it is the area's URL on every request.
     * Without one, `URL`: an application mounted as a secondary application defines it as its
     * own URL, and the main application as `sURL`.
     */
    public static function servingUrl(): string
    {
        if (self::$servingOverride !== null) {
            return rtrim(self::$servingOverride, '/');
        }

        if (self::$prefix !== '') {
            $base = self::$active ? self::url() : (defined('sURL') ? (string) \sURL : '');
        } else {
            $base = defined('URL') ? (string) \URL : (defined('sURL') ? (string) \sURL : '');
        }

        return rtrim($base, '/');
    }

    /**
     * Forget the detected state.
     *
     * For test isolation: the class is static because the answer is a property of
     * the request, and a suite runs many requests in one process.
     */
    /**
     * The area's own sign-in page, for an application whose administration signs in somewhere
     * other than the site's `login` — called once, beside {@see detect()}.
     */
    public static function useLogin(string $url): void
    {
        self::$loginUrl = $url;
    }

    /** Where a guest in this area signs in: the area's own page, or the site's `login`. */
    public static function loginUrl(): string
    {
        return self::$loginUrl !== '' ? self::$loginUrl : (defined('sURL') ? \sURL : '/') . 'login';
    }

    /**
     * The sign-in address that brings a guest back to where they were — the whole path, area
     * prefix and all, so they come back into the area rather than to the site's page of the
     * same name.
     */
    public static function loginRedirect(string $requestUri): string
    {
        $login = self::loginUrl();

        if ($requestUri === '' || $requestUri === '/') {
            return $login;
        }

        return $login . (str_contains($login, '?') ? '&' : '?') . 'return=' . urlencode($requestUri);
    }

    public static function reset(): void
    {
        self::$active      = false;
        self::$prefix      = '';
        self::$minUserType = 0;
        self::$loginUrl    = '';
    }
}
