<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Health;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Health\Checks\CacheBackendCheck;
use Pramnos\Health\HealthStatus;

/**
 * The check that says the cache is not on the store it was configured for.
 *
 * `Cache` falls back to the next adapter when the configured one cannot be reached,
 * and that is right — a cache that cannot connect must not take the site down. What
 * was missing is anybody being told: the settings name redis, the container is
 * running, every page works, and the cache is on local disk. Invalidation is then
 * per-server, so a two-node deployment serves whatever the node that was not asked
 * still holds.
 *
 * Found the slow way. Two Redis-only bugs in this framework could not surface for as
 * long as no project ever actually reached Redis.
 */
class CacheBackendCheckTest extends TestCase
{
    /**
     * Configured store, live store, same answer: nothing to report.
     */
    public function testAgreementIsHealthy(): void
    {
        // Arrange
        $cache = $this->cacheRunning('redis', 'redis');

        // Act
        $result = (new CacheBackendCheck($cache))->run();

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertSame('redis', $result->details['method'] ?? null);
    }

    /**
     * A fallback is degraded — and names both stores, which is the whole point.
     *
     * "Cache unhealthy" sends somebody to look at Redis, which is running. What has
     * to be on the line is that the application is not talking to it.
     */
    public function testAFallbackIsReportedWithBothStoresNamed(): void
    {
        // Arrange — configured for redis, running on files
        $cache = $this->cacheRunning('file', 'redis');

        // Act
        $result = (new CacheBackendCheck($cache))->run();

        // Assert
        $this->assertSame(HealthStatus::Degraded, $result->status);
        $this->assertStringContainsString('file', $result->message);
        $this->assertStringContainsString('redis', $result->message);
        $this->assertSame('redis', $result->details['requested'] ?? null);
        $this->assertStringContainsString(
            'extension',
            (string) ($result->details['hint'] ?? ''),
            'the missing PHP extension is almost always the cause and is worth naming'
        );
    }

    /**
     * Degraded, never down: the site is up, on the wrong store.
     *
     * A check that pages somebody for a working site is a check that gets muted, and
     * then it reports nothing at all.
     */
    public function testAFallbackIsNotReportedAsDown(): void
    {
        // Arrange
        $cache = $this->cacheRunning('file', 'memcached');

        // Act
        $result = (new CacheBackendCheck($cache))->run();

        // Assert
        $this->assertNotSame(HealthStatus::Down, $result->status);
    }

    /**
     * A project that configured nothing is not degraded.
     *
     * `requestedMethod` is empty until something asks for a backend by name, and
     * "you are running the default" is not a finding.
     */
    public function testNoConfiguredBackendIsHealthy(): void
    {
        // Arrange
        $cache = $this->cacheRunning('file', '');

        // Act
        $result = (new CacheBackendCheck($cache))->run();

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    /**
     * A cache with no resolved adapter at all is down — nothing is caching.
     */
    public function testNoResolvedMethodIsDown(): void
    {
        // Arrange
        $cache = $this->cacheRunning('', 'redis');

        // Act
        $result = (new CacheBackendCheck($cache))->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
    }

    /**
     * A cache reporting the two methods, without connecting to either.
     *
     * The check reads two properties; a real `Cache` would open a socket to answer
     * the same question.
     */
    private function cacheRunning(string $active, string $requested): Cache
    {
        $cache = $this->getMockBuilder(Cache::class)
            ->disableOriginalConstructor()
            ->getMock();
        $cache->method          = $active;
        $cache->requestedMethod = $requested;

        return $cache;
    }
}
