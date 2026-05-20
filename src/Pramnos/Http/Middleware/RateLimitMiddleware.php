<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Cache\Cache;
use Pramnos\Framework\Factory;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Sliding-window rate limiter backed by the framework Cache abstraction.
 *
 * Unlike ThrottleMiddleware (which requires APCu), this middleware works with
 * any Cache adapter — Array (tests), File, Redis, or Memcached.
 *
 * Algorithm: for each (key, window), we store a JSON array of Unix timestamps
 * representing admitted requests. On every call we:
 *   1. Load the stored timestamps.
 *   2. Discard entries older than now − perSeconds (slide the window).
 *   3. Count the remaining entries.
 *   4. If count >= maxRequests → reject (HTTP 429).
 *   5. Otherwise append now, save, and pass the request to $next.
 *
 * The key is derived from the client's IP address and the keyPrefix. Pass a
 * custom $keyPrefix to create independent rate-limit buckets per route group.
 *
 * When the limit is exceeded, the middleware throws an \Exception with code 429.
 * Application::exec() renders this as a 429 Too Many Requests response.
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
 * @package    PramnosFramework
 * @subpackage Http\Middleware
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
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = $this->keyPrefix . md5($ip);

        $timestamps = $this->loadTimestamps($key);
        $now        = time();
        $cutoff     = $now - $this->perSeconds;

        // Slide the window: discard requests older than the window start.
        $timestamps = array_values(array_filter($timestamps, fn(int $t) => $t > $cutoff));

        if (count($timestamps) >= $this->maxRequests) {
            header('Retry-After: ' . $this->perSeconds);
            throw new \Exception('Too many requests. Please slow down.', 429);
        }

        $timestamps[] = $now;
        $this->saveTimestamps($key, $timestamps);

        return $next($request);
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
