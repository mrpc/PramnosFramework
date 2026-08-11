<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Cache\Cache;
use Pramnos\Framework\Factory;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\TooManyRequestsException;

/**
 * Per-client rate limiter backed by the framework Cache abstraction.
 *
 * Unlike ThrottleMiddleware (which requires APCu), this middleware works with
 * any Cache adapter — Array (tests), File, Redis, or Memcached.
 *
 * **Two algorithms, chosen by what the adapter can do.** When the cache offers
 * an atomic counter (Redis, Memcached) the limit is a fixed window counted on
 * the server, which is exact under concurrency. When it does not (Array, File)
 * the limit falls back to a sliding window built from load-modify-save, whose
 * count is approximate under concurrency — see
 * {@see handleWithSlidingWindow()} for what that costs. The distinction
 * matters: a rate limit is a security control, and the fallback is least
 * accurate precisely when a flood is happening.
 *
 * The key is derived from the client's IP address — via {@see Request::clientIp()},
 * so it is the real client behind a configured proxy rather than the proxy
 * itself — and the keyPrefix. Pass a custom $keyPrefix to create independent
 * rate-limit buckets per route group.
 *
 * When the limit is exceeded the middleware throws a
 * {@see TooManyRequestsException} (an \Exception with code 429, so existing
 * handlers are unaffected). Application::exec() renders it as a 429 response,
 * carrying `Retry-After`.
 *
 * Usage — global limit for all API routes:
 *   $router->addGlobalMiddleware(new RateLimitMiddleware(120, 60));
 *
 * Usage — stricter limit on an expensive endpoint:
 *   $router->post('/api/export', fn() => …)
 *          ->middleware(new RateLimitMiddleware(5, 60, 'export:'));
 *
 * Usage — inject a Cache instance (useful for tests):
 *   new RateLimitMiddleware(10, 60, 'test:', $arrayBackedCache);
 *
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private Cache $cache;

    /**
     * @param int         $maxRequests Maximum requests allowed per window.
     * @param int         $perSeconds  Length of the sliding window in seconds.
     * @param string      $keyPrefix   Prefix for cache keys — isolates buckets.
     * @param Cache|null  $cache       Cache instance. Defaults to Factory::getCache().
     */
    public function __construct(
        private int    $maxRequests = 60,
        private int    $perSeconds  = 60,
        private string $keyPrefix   = 'ratelimit:',
        ?Cache         $cache       = null
    ) {
        $this->cache = $cache ?? Factory::getCache();
    }

    /**
     * Evaluate the rate limit and pass through or reject the request.
     */
    public function handle(Request $request, callable $next): mixed
    {
        $ip   = Request::clientIp('0.0.0.0');
        $base = $this->keyPrefix . md5($ip);

        if ($this->cache->supportsAtomicCounter()) {
            return $this->handleAtomically($request, $next, $base);
        }

        return $this->handleWithSlidingWindow($request, $next, $base);
    }

    /**
     * Fixed-window limiting on the cache server's own atomic counter.
     *
     * Preferred whenever the adapter can do it, because it is the only variant
     * that counts correctly under concurrency — and a flood, which is the thing
     * being defended against, is concurrent by definition.
     *
     * The trade is at the window boundary: a client can spend its full
     * allowance at the end of one window and again at the start of the next, so
     * up to 2× the limit can pass across a boundary. For a spam gate that is a
     * far better bargain than a sliding window that undercounts a burst.
     */
    private function handleAtomically(Request $request, callable $next, string $base): mixed
    {
        $now    = time();
        $window = intdiv($now, $this->perSeconds);
        $key    = $base . ':' . $window;

        $count = $this->cache->increment($key, $this->perSeconds + 1);

        // False is "the counter did not work", not "zero requests so far" — a
        // Redis connection that dropped must not read as an empty bucket. Fall
        // back to the approximate algorithm rather than admitting everything.
        if ($count === false) {
            return $this->handleWithSlidingWindow($request, $next, $base);
        }

        if ($count > $this->maxRequests) {
            throw new TooManyRequestsException(
                'Too many requests. Please slow down.',
                $this->retryAfter($now, $window)
            );
        }

        return $next($request);
    }

    /**
     * Sliding-window limiting on load-modify-save.
     *
     * Used when the adapter cannot count atomically — Array and File. The count
     * is **approximate under concurrency**: two simultaneous requests can read
     * the same list and the later save overwrites the earlier, so a burst of N
     * requests may advance the stored count by fewer than N. It is exact for a
     * trickle and lossy for a flood.
     *
     * That is a real limitation, not a rounding error, and it is why an adapter
     * with an atomic counter is strongly preferred for any limit that is doing
     * security work rather than politeness.
     */
    private function handleWithSlidingWindow(Request $request, callable $next, string $key): mixed
    {
        $timestamps = $this->loadTimestamps($key);
        $now        = time();
        $cutoff     = $now - $this->perSeconds;

        // Slide the window: discard requests older than the window start.
        $timestamps = array_values(array_filter($timestamps, fn(int $t) => $t > $cutoff));

        if (count($timestamps) >= $this->maxRequests) {
            throw new TooManyRequestsException(
                'Too many requests. Please slow down.',
                $this->perSeconds
            );
        }

        $timestamps[] = $now;
        $this->saveTimestamps($key, $timestamps);

        return $next($request);
    }

    /**
     * Seconds until the current fixed window ends — the honest Retry-After,
     * rather than the whole window length regardless of how much of it is left.
     */
    private function retryAfter(int $now, int $window): int
    {
        $endsAt = ($window + 1) * $this->perSeconds;

        return max(1, $endsAt - $now);
    }

    /**
     * Load the stored timestamp list for $key.
     *
     * Returns an empty array when no entry exists yet or when the stored value
     * cannot be decoded (defensive: treat corrupt entries as empty).
     *
     * @return int[]
     */
    protected function loadTimestamps(string $key): array
    {
        $this->cache->timeout = $this->perSeconds + 1;
        $stored = $this->cache->load($key);

        if ($stored === false || !is_string($stored)) {
            return [];
        }

        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist the updated timestamp list for $key.
     *
     * @param int[] $timestamps
     */
    protected function saveTimestamps(string $key, array $timestamps): void
    {
        $this->cache->timeout = $this->perSeconds + 1;
        $this->cache->save(json_encode($timestamps), $key);
    }
}
