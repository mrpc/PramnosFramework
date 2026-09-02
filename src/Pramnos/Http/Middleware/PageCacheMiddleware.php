<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Cache\Page\PageCache;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * Serves and stores full pages around the rest of the pipeline.
 *
 * Register it **first**, before anything that touches the session or the
 * database — the point of a page cache is how little has run by the time it
 * answers, and a middleware that boots the session in front of it has already
 * spent most of what this saves.
 *
 * ```php
 * // app.php
 * 'middleware' => [
 *     \Pramnos\Http\Middleware\PageCacheMiddleware::class,
 *     \Pramnos\Http\Middleware\SessionMiddleware::class,
 * ],
 * ```
 *
 * A miss falls through to `$next` and the result is stored on the way back out
 * — but only when the pipeline actually returns a {@see Response}. An action
 * that echoes its output and returns a string, an array or nothing is left
 * alone: there is no reliable body to store and guessing at one would cache
 * half a page.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class PageCacheMiddleware implements MiddlewareInterface
{
    private PageCache $cache;

    /**
     * @param PageCache|null $cache Injected for tests; built from the
     *                              application's `pagecache` config otherwise.
     */
    public function __construct(?PageCache $cache = null)
    {
        $this->cache = $cache ?? new PageCache(self::configFromApplication());
    }

    /**
     * @param callable(Request):mixed $next
     */
    public function handle(Request $request, callable $next): mixed
    {
        PageCache::resetRuntime();

        $hit = $this->cache->lookup($request);
        if ($hit !== null) {
            return self::withCsp($hit);
        }

        $response = $next($request);

        if ($response instanceof Response) {
            $this->cache->store($request, $response);
        }

        return $response;
    }

    /**
     * Put the Content-Security-Policy back on a cache hit.
     *
     * `Application::sendCspHeader()` is called from `render()`. A hit answers
     * *before* the application runs, so `render()` never executes and the page
     * went out with **no policy at all** — and the header could not be replayed
     * from the stored entry either, because it was never on the `Response` in the
     * first place: it goes straight out through `header()`.
     *
     * The result was the worst shape a security regression can have. The page is
     * correct, the scripts run, nothing in the markup looks different — they run
     * because there is no longer a policy to stop them. On a framework whose
     * default is `default-src 'none'`, a cached page had lost the whole of it.
     *
     * The policy is built fresh here rather than stored, which is the only version
     * that is not a different bug: a stored policy carries the nonce of whichever
     * request populated the cache, handed to every visitor for the whole TTL — and
     * a nonce that is reused is not a nonce.
     *
     * The fresh one carries **no** nonce, because a hit never reached `exec()` and so
     * there is none. That is correct rather than a gap: {@see PageCache::store()}
     * refuses to store a body containing a nonce, so a stored page has no nonced
     * inline script for a nonce source to cover.
     *
     * The same policy {@see PageCache::serveEarly()} sends, by the same reasoning —
     * that path has no application at all and builds it from the config file.
     *
     * Set unconditionally, including over a policy that came out of the stored
     * entry because an application widened `headerWhitelist` to keep it. That is
     * the one case where the stored header is the wrong one — it carries the nonce
     * of whichever request populated the cache — so a fresh policy always wins.
     * A CSP middleware of the application's own is unaffected: registered after
     * this one it is inside it, and sets its header on the way out, after this.
     */
    private static function withCsp(Response $response): Response
    {
        $app = class_exists('\Pramnos\Application\Application')
            ? \Pramnos\Application\Application::currentInstance()
            : null;

        if ($app === null || !method_exists($app, 'cspPolicy')) {
            // no application, no policy to build
            return $response; // @codeCoverageIgnore
        }

        return $response->withHeader('Content-Security-Policy', $app->cspPolicy());
    }

    /**
     * The `pagecache` block from the application config, or an empty array.
     *
     * **`app.php` is read first**, then the settings store. In that order because
     * that is where the guide has always said to put it, and because it is the
     * answer that costs nothing: `Settings::getSetting()` falls through to a
     * database query for a key it does not hold, and a page cache that queries the
     * database to find out whether it is enabled has spent part of what it exists
     * to save.
     *
     * It used to read `Settings` alone. A `pagecache` block in `app.php` — exactly
     * as documented — was therefore never seen: the middleware built a `PageCache`
     * on {@see PageCache::defaults()}, `enabled` stayed false, and nothing was
     * cached and nothing said why. Reported from a consuming application that had
     * copied the guide verbatim.
     *
     * The pairing matches {@see \Pramnos\Application\Application::lazySessionEnabled()},
     * which reads `applicationInfo['session']` and falls back to
     * `Settings::getSetting('session')` — the same question deserved the same answer.
     *
     * Empty is still a working answer: `defaults()` has `enabled` false, so a
     * project that registers the middleware and writes no configuration caches
     * nothing rather than guessing at what is public.
     *
     * @return array<string,mixed>
     */
    private static function configFromApplication(): array
    {
        $app = class_exists('\Pramnos\Application\Application')
            ? \Pramnos\Application\Application::currentInstance()
            : null;

        $config = $app->applicationInfo['pagecache'] ?? null;

        if ($config === null && class_exists('\Pramnos\Application\Settings')) {
            $config = \Pramnos\Application\Settings::getSetting('pagecache');
        }

        // getSetting() returns a stdClass for a setting whose value is an array —
        // so even an application that put the block in the settings store got an
        // object here, failed the array test, and cached nothing.
        if (is_object($config)) {
            $config = (array) $config;
        }

        return is_array($config) ? $config : [];
    }
}
