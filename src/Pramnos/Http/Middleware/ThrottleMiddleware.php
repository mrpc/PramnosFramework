<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\TooManyRequestsException;

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
        $ip  = Request::clientIp('0.0.0.0');
        $key = $this->keyPrefix . md5($ip);

        $count = $this->bumpCount($key);

        if ($count !== false && $count > $this->maxRequests) {
            throw new TooManyRequestsException(
                'Too many requests. Please slow down.',
                $this->perSeconds
            );
        }

        return $next($request);
    }

    /**
     * Count this request and return the new total for the window.
     *
     * `apcu_inc()` creates the counter when it is absent and returns the new
     * value in a single operation, which is what makes this correct under
     * concurrency. The previous fetch-then-compare-then-increment lost
     * increments when requests overlapped: two arriving together both read the
     * same count and only one increment survived. A rate limiter that
     * undercounts a burst is at its least accurate exactly when it is needed.
     *
     * @return int|false The new count, or false when APCu is unavailable — in
     *                   which case the middleware passes everything through, as
     *                   it always has.
     */
    protected function bumpCount(string $key): int|false
    {
        // An application that overrode the original seams keeps the original
        // behaviour, including its looser counting: silently routing around a
        // subclass's storage would be worse than the race it fixes.
        if ($this->usesLegacySeams()) {
            return $this->bumpCountViaLegacySeams($key);
        }

        if (!function_exists('apcu_inc')) {
            return false;
        }

        $success = false;
        $new     = apcu_inc($key, 1, $success, $this->perSeconds);

        return $success ? (int) $new : false;
    }

    /**
     * Whether a subclass has replaced any of the original storage seams.
     */
    private function usesLegacySeams(): bool
    {
        foreach (['fetchCount', 'storeCount', 'incrementCount'] as $method) {
            if ((new \ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The original read-modify-write path, for subclasses that supply their own
     * storage. Returns the count *including* this request, matching
     * {@see bumpCount()}.
     */
    private function bumpCountViaLegacySeams(string $key): int|false
    {
        $count = $this->fetchCount($key);

        if ($count === false) {
            $this->storeCount($key, 1, $this->perSeconds);

            return 1;
        }

        $this->incrementCount($key);

        return $count + 1;
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
