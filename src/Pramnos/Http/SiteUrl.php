<?php

declare(strict_types=1);

namespace Pramnos\Http;

/**
 * The site's public root — the one URL every absolute link has to be built from.
 *
 * ## Why `sURL` is not it
 *
 * `sURL` comes from {@see getUrl()}, which ends in `dirname($_SERVER['SCRIPT_NAME'])`.
 * That is **the directory of the script handling this request**, which is the site root
 * only by coincidence — it is, right up until the project grows a second front controller:
 *
 * ```
 * www/index.php      →  sURL = https://example.com/
 * www/api/index.php  →  sURL = https://example.com/api/     ← same site, same request
 * ```
 *
 * So an asset under the document root, built in an API request as
 * `sURL . 'uploads/x.jpg'`, is `https://example.com/api/uploads/x.jpg` — a 404. And the
 * expensive part is that the URL is *absolute and well-formed*, so every "is this a
 * fetchable address" guard passes it: one was handed to an external service, which fetched
 * it, got a 404, and answered with an error that never mentioned the URL. The visible half
 * was a broken thumbnail; the half that cost the afternoon was silent.
 *
 * ## And why a request cannot always answer it
 *
 * `getUrl()` needs `$_SERVER['SERVER_NAME']`, which a cron job does not have. A scheduled
 * task that has to put a public URL in an email, a webhook payload or a feed has nothing to
 * build one from — and the failure is an address like `http:///uploads/x.jpg` rather than
 * an error.
 *
 * That is why the configured value comes first and why {@see \Pramnos\Health\Checks\SiteUrlCheck}
 * reports its absence: on a web request the fallback hides the gap, and the first time
 * anybody notices is from the one process that cannot use it.
 *
 * ## Where the value comes from, in order
 *
 * 1. `APP_URL` in the environment — which is where a deployment already keeps this.
 * 2. `'site_url'` in `app/config/app.php`, for a project that configures in PHP.
 * 3. The current request: scheme and host, plus the path of the **document root** rather
 *    than of the script. Derived by taking `SCRIPT_NAME` and removing the part of
 *    `SCRIPT_FILENAME` that sits below `DOCUMENT_ROOT`, so `www/api/index.php` under
 *    `www/` answers `/` — the thing `getUrl()` gets wrong.
 * 4. `''`, in a CLI process with nothing configured. Empty rather than a guess: a caller
 *    can test for it, and a wrong absolute URL is the failure this class exists to stop.
 *
 * ```php
 * \Pramnos\Http\SiteUrl::to('uploads/' . $name);   // https://example.com/uploads/x.jpg
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
final class SiteUrl
{
    /**
     * Resolved once per process.
     *
     * Every branch below reads either the environment or `$_SERVER`, neither of which
     * changes within a request, and the derivation is string work nobody needs repeated
     * per link on a page.
     */
    private static ?string $resolved = null;

    /**
     * The site root, with a trailing slash, or `''` when nothing can answer.
     *
     * @return string
     */
    public static function get(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $configured = self::configured();
        if ($configured !== '') {
            return self::$resolved = self::withTrailingSlash($configured);
        }

        return self::$resolved = self::fromRequest();
    }

    /**
     * An absolute URL for a path below the site root.
     *
     * The path is joined, not concatenated: a leading slash on the argument would
     * otherwise produce a double slash, which is a different URL to a cache and to a
     * signature.
     *
     * @param  string $path Relative to the site root, with or without a leading slash
     * @return string The absolute URL, or the path unchanged when the root is unknown
     */
    public static function to(string $path): string
    {
        $root = self::get();

        if ($root === '') {
            // Better a relative path than an absolute one built on a guess: a relative
            // path is visibly incomplete to whatever receives it, and a wrong absolute URL
            // is accepted by everything and fetched by something.
            return $path;
        }

        return $root . ltrim($path, '/');
    }

    /**
     * Is the site root configured, rather than inferred from a request?
     *
     * What {@see \Pramnos\Health\Checks\SiteUrlCheck} asks. A web request almost always
     * infers a usable answer, so "it works on the site" says nothing about whether the
     * scheduler can build a URL.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::configured() !== '';
    }

    /**
     * Forget the resolved value.
     *
     * For tests, and for a long-running process whose environment has been changed
     * underneath it. Not called anywhere in a request.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$resolved = null;
    }

    /**
     * `APP_URL`, then `site_url` in the application's own configuration.
     *
     * @return string `''` when neither is set
     */
    private static function configured(): string
    {
        $fromEnv = getenv('APP_URL');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        // `$_ENV` as well as `getenv()`: `loadDotenv()` populates both, and a project that
        // sets `variables_order` without `E` has one and not the other.
        if (isset($_ENV['APP_URL']) && trim((string) $_ENV['APP_URL']) !== '') {
            return trim((string) $_ENV['APP_URL']);
        }

        try {
            $application = \Pramnos\Application\Application::getInstance();
            $configured  = $application->applicationInfo['site_url'] ?? '';
        } catch (\Throwable) {
            // No application yet — a very early boot, or a unit test. The request
            // fallback still applies.
            return '';
        }

        return is_string($configured) ? trim($configured) : '';
    }

    /**
     * The site root derived from the current request, or `''` outside one.
     *
     * @return string
     */
    private static function fromRequest(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if ($host === '') {
            // CLI. Nothing here can be invented: a cron job has no host, and guessing one
            // produces `http:///uploads/x.jpg`, which looks like a bug in the caller.
            return '';
        }

        return self::scheme() . '://' . $host . self::rootPath();
    }

    /**
     * `https` when anything says so, including a proxy that terminated TLS.
     *
     * @return string
     */
    private static function scheme(): string
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return 'https';
        }

        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return 'https';
        }

        return 'http';
    }

    /**
     * The URL path of the document root, with a trailing slash.
     *
     * This is the part {@see getUrl()} gets wrong. `SCRIPT_NAME` is the script's URL path
     * and `SCRIPT_FILENAME` is its file path; the portion of the file path below
     * `DOCUMENT_ROOT` is exactly the portion of the URL path below the site root, so
     * removing it from `SCRIPT_NAME` leaves the site root:
     *
     * ```
     * DOCUMENT_ROOT    /srv/site/www
     * SCRIPT_FILENAME  /srv/site/www/api/index.php   → below root: /api/index.php
     * SCRIPT_NAME      /api/index.php                → minus that: ''  → '/'
     * ```
     *
     * With a site in a subdirectory it holds just as well: `SCRIPT_NAME` of
     * `/shop/api/index.php` minus `/api/index.php` is `/shop`.
     *
     * When the two do not line up — a rewrite, a symlinked root, an odd SAPI — it falls
     * back to `dirname(SCRIPT_NAME)`, which is `getUrl()`'s answer and no worse than
     * today.
     *
     * @return string
     */
    private static function rootPath(): string
    {
        $scriptName     = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptFilename = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $documentRoot   = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        if ($scriptName === '') {
            return '/';
        }

        if ($scriptFilename !== '' && $documentRoot !== '') {
            $root   = rtrim(str_replace('\\', '/', $documentRoot), '/');
            $script = str_replace('\\', '/', $scriptFilename);

            if ($root !== '' && str_starts_with($script, $root . '/')) {
                $belowRoot = substr($script, strlen($root));

                if (str_ends_with($scriptName, $belowRoot)) {
                    return self::withTrailingSlash(
                        substr($scriptName, 0, strlen($scriptName) - strlen($belowRoot))
                    );
                }
            }
        }

        // No usable document root: the script's own directory, which is what `sURL` has
        // always been. Wrong for a sub-application, and this is the branch that cannot do
        // better — which is the argument for configuring `APP_URL`.
        return self::withTrailingSlash(dirname($scriptName));
    }

    /**
     * One trailing slash, whatever came in — including for an empty path.
     *
     * @param  string $value
     * @return string
     */
    private static function withTrailingSlash(string $value): string
    {
        $value = rtrim($value, '/');

        return $value === '' ? '/' : $value . '/';
    }
}
