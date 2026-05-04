<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Rate-limits requests per client IP using APCu as a sliding-window counter.
 *
 * Requires the APCu PHP extension. When APCu is unavailable, the middleware
 * passes through without limiting (graceful degradation — log a warning in
 * production if you depend on this).
 *
 * Usage — global throttle for all API routes:
 *   $router->addGlobalMiddleware(new ThrottleMiddleware(maxRequests: 120, perSeconds: 60));
 *
 * Usage — stricter limit on expensive endpoints:
 *   $router->post('/api/export', fn() => ...)
 *          ->middleware(new ThrottleMiddleware(maxRequests: 5, perSeconds: 60));
 *
 * Usage — custom key prefix to isolate counters per route group:
 *   new ThrottleMiddleware(60, 60, keyPrefix: 'api:')
 *
 * When the limit is exceeded, throws an Exception with code 429.
 * Application::exec() renders this as a 429 response.
 *
 * @package    PramnosFramework
 * @subpackage Http\Middleware
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int    $maxRequests = 60,
        private int    $perSeconds  = 60,
        private string $keyPrefix   = 'throttle:'
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = $this->keyPrefix . md5($ip);

        $count = $this->fetchCount($key);

        if ($count === false) {
            $this->storeCount($key, 1, $this->perSeconds);
        } elseif ($count >= $this->maxRequests) {
            header('Retry-After: ' . $this->perSeconds);
            throw new \Exception('Too many requests. Please slow down.', 429);
        } else {
            $this->incrementCount($key);
        }

        return $next($request);
    }

    /**
     * Fetch the current request count for $key.
     * Returns false when no counter exists yet (first request).
     * Override in tests to inject an in-memory store.
     *
     * @codeCoverageIgnore — pure APCu adapter; logic tested via in-memory subclass.
     */
    protected function fetchCount(string $key): int|false
    {
        if (!function_exists('apcu_fetch')) {
            return false;
        }
        $value = apcu_fetch($key);
        return $value === false ? false : (int) $value;
    }

    /**
     * Store an initial counter value with the given TTL.
     * Override in tests to inject an in-memory store.
     *
     * @codeCoverageIgnore — pure APCu adapter; logic tested via in-memory subclass.
     */
    protected function storeCount(string $key, int $value, int $ttl): void
    {
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);
        }
    }

    /**
     * Increment an existing counter by 1.
     * Override in tests to inject an in-memory store.
     *
     * @codeCoverageIgnore — pure APCu adapter; logic tested via in-memory subclass.
     */
    protected function incrementCount(string $key): void
    {
        if (function_exists('apcu_inc')) {
            apcu_inc($key);
        }
    }
}
