<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Cache\Cache;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Is the cache running on the backend it was configured for?
 *
 * `Cache` walks down to the next adapter when the one it was asked for cannot be
 * reached, which is the right behaviour — a cache that cannot connect must not
 * take the site down. What was missing is anybody finding out.
 *
 * The fallback is reported in the DevPanel ("file fell back from redis"), and the
 * DevPanel is a development tool. In production nothing said it: the settings name
 * redis, the container is running, every page works, and the cache is on local
 * disk — so invalidation is per-server, a multi-server deployment serves stale
 * pages from whichever node did not clear, and the shared store nobody is using
 * still shows in the infrastructure bill.
 *
 * It is real rather than hypothetical: a project's image was built without the
 * `redis` extension while its settings, its `docker-compose.yml` and its
 * `docker-compose ps` all said redis. Two Redis-only bugs in this framework could
 * not surface for as long as Redis was never actually reached.
 *
 * **Degraded, not down.** The application is working; it is working on the wrong
 * store. Reporting `down` would page somebody for a site that is up, and a check
 * that cries wolf is a check that gets muted.
 *
 * @see \Pramnos\Health\Checks\RedisConnectivityCheck for the Redis connection
 *      manager, which is a different facility from the cache.
 */
class CacheBackendCheck implements HealthCheck
{
    private ?Cache $cache;

    public function __construct(?Cache $cache = null)
    {
        // Resolved here rather than at registration: building a cache in a
        // service provider would connect on every request, including the ones
        // that never touch the cache.
        $this->cache = $cache;
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function run(): HealthCheckResult
    {
        try {
            $cache = $this->cache ?? Cache::getInstance();
        } catch (\Throwable $ex) {
            return HealthCheckResult::down($this->getName(), 'Cache unavailable', [
                'error' => $ex->getMessage(),
            ]);
        }

        $active    = strtolower((string) $cache->method);
        $requested = strtolower((string) $cache->requestedMethod);

        if ($active === '') {
            return HealthCheckResult::down($this->getName(), 'No cache method resolved');
        }

        // Nothing was asked for, or it is what is running: the configured store
        // and the live one agree, which is all this check is about.
        if ($requested === '' || $requested === $active) {
            return HealthCheckResult::ok($this->getName(), $active, [
                'method' => $active,
            ]);
        }

        return HealthCheckResult::degraded(
            $this->getName(),
            'Running on ' . $active . ', configured for ' . $requested,
            [
                'method'    => $active,
                'requested' => $requested,
                // Named because it is almost always the answer: the container is
                // there and the PHP client for it is not.
                'hint'      => 'the ' . $requested . ' PHP extension may be missing from the image',
            ]
        );
    }
}
