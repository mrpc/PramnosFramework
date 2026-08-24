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
            return $hit;
        }

        $response = $next($request);

        if ($response instanceof Response) {
            $this->cache->store($request, $response);
        }

        return $response;
    }

    /**
     * The `pagecache` block from the application config, or an empty array.
     *
     * Empty is a working answer: {@see PageCache::defaults()} has `enabled`
     * false, so a project that registers the middleware and writes no
     * configuration caches nothing rather than guessing at what is public.
     *
     * @return array<string,mixed>
     */
    private static function configFromApplication(): array
    {
        if (!class_exists('\Pramnos\Application\Settings')) {
            return [];   // @codeCoverageIgnore
        }

        $config = \Pramnos\Application\Settings::getSetting('pagecache');

        return is_array($config) ? $config : [];
    }
}
