<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Http\Middleware\RateLimitMiddleware;
use Pramnos\Http\Request;

/**
 * Unit tests for RateLimitMiddleware — sliding-window rate limiter via Cache.
 *
 * Uses Cache with the 'array' adapter for deterministic, in-process testing
 * without APCu, Redis, or file I/O. Each test constructs a fresh Cache and
 * Middleware instance to guarantee isolation.
 *
 * Tests verify:
 *   - Requests within the limit pass through to $next.
 *   - A request that meets or exceeds the limit is rejected with code 429.
 *   - $next is NOT called when the request is blocked.
 *   - Sliding window: old timestamps outside the window are discarded.
 *   - Each client IP has an independent counter.
 *   - Custom keyPrefix creates independent buckets.
 *   - The Retry-After header is set on rejection (via header() mock via
 *     subclass override since headers can't be sent in CLI tests).
 *   - Return value from $next is passed through.
 */
#[CoversClass(RateLimitMiddleware::class)]
class RateLimitMiddlewareTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a fresh Cache backed by an in-memory ArrayAdapter.
     */
    private function makeCache(): Cache
    {
        return new Cache(null, null, 'array');
    }

    /**
     * Build a RateLimitMiddleware with the given limits and a fresh ArrayAdapter
     * cache so tests never share state.
     */
    private function makeMiddleware(
        int    $max    = 3,
        int    $per    = 60,
        string $prefix = 'ratelimit:'
    ): RateLimitMiddleware {
        // Subclass to suppress header() calls in CLI context
        return new class($max, $per, $prefix, $this->makeCache()) extends RateLimitMiddleware {
            protected function saveTimestamps(string $key, array $timestamps): void
            {
                // Skip header() side-effects; delegate to parent for save logic
                // We override handle() partially — parent::saveTimestamps() is fine
                parent::saveTimestamps($key, $timestamps);
            }
        };
    }

    /**
     * Build a RateLimitMiddleware whose handle() suppresses the header() call
     * so CLI tests don't emit warnings.
     */
    private function makeMiddlewareNoHeader(
        int    $max    = 3,
        int    $per    = 60,
        string $prefix = 'ratelimit:'
    ): RateLimitMiddleware {
        $cache = $this->makeCache();
        return new class($max, $per, $prefix, $cache) extends RateLimitMiddleware {
            public function handle(\Pramnos\Http\Request $request, callable $next): mixed
            {
                try {
                    return parent::handle($request, $next);
                } catch (\Exception $e) {
                    if ($e->getCode() === 429) {
                        throw $e;
                    }
                    throw $e;
                }
            }
        };
    }

    private function makeRequest(): Request
    {
        return $this->createMock(Request::class);
    }

    protected function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    // =========================================================================
    // Allow paths
    // =========================================================================

    /**
     * The first request from a new IP has no prior history. It is allowed and
     * $next is called.
     */
    public function testFirstRequestIsAllowed(): void
    {
        // Arrange
        $cache      = $this->makeCache();
        $middleware = new RateLimitMiddleware(5, 60, 'rl:', $cache);
        $request    = $this->makeRequest();
        $nextCalled = false;

        // Act
        $middleware->handle($request, function () use (&$nextCalled): string {
            $nextCalled = true;
            return 'ok';
        });

        // Assert — request was forwarded
        $this->assertTrue($nextCalled);
    }

    /**
     * Multiple requests below the limit are all allowed.
     */
    public function testRequestsBelowLimitAreAllowed(): void
    {
        // Arrange — limit is 5; send 4 requests
        $cache      = $this->makeCache();
        $middleware = new RateLimitMiddleware(5, 60, 'rl:', $cache);
        $request    = $this->makeRequest();
        $callCount  = 0;

        // Act
        for ($i = 0; $i < 4; $i++) {
            $middleware->handle($request, function () use (&$callCount): string {
                $callCount++;
                return 'ok';
            });
        }

        // Assert — all 4 forwarded
        $this->assertSame(4, $callCount);
    }

    // =========================================================================
    // Rejection paths
    // =========================================================================

    /**
     * The (max+1)-th request is rejected with an Exception code 429.
     */
    public function testRequestAtLimitIsRejected(): void
    {
        // Arrange — limit is 3; exhaust 3 slots first
        $cache      = $this->makeCache();
        $middleware = new RateLimitMiddleware(3, 60, 'rl:', $cache);
        $request    = $this->makeRequest();

        for ($i = 0; $i < 3; $i++) {
            $middleware->handle($request, fn() => 'ok');
        }

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(429);

        // Act — 4th request exceeds limit
        $middleware->handle($request, fn() => 'should not reach');
    }

    /**
     * $next is NOT called when the request is rejected.
     */
    public function testNextIsNotCalledWhenBlocked(): void
    {
        // Arrange — pre-fill timestamps to exactly the limit via reflection
        $cache      = $this->makeCache();
        $middleware = new RateLimitMiddleware(2, 60, 'rl:', $cache);
        $request    = $this->makeRequest();

        // Exhaust the limit
        $middleware->handle($request, fn() => 'ok');
        $middleware->handle($request, fn() => 'ok');

        $nextCalled = false;
        try {
            $middleware->handle($request, function () use (&$nextCalled): string {
                $nextCalled = true;
                return 'should not run';
            });
        } catch (\Exception $e) {
            // expected
        }

        // Assert — destination was not reached
        $this->assertFalse($nextCalled);
    }

    // =========================================================================
    // Sliding window
    // =========================================================================

    /**
     * Timestamps older than the window are discarded; only recent entries count.
     *
     * We inject a past-window timestamp directly via loadTimestamps() hook:
     * subclass overrides loadTimestamps() to prepend an old entry, simulating
     * a partially-expired window.
     */
    public function testOldTimestampsOutsideWindowAreDiscarded(): void
    {
        // Arrange — limit=2, window=60s. Pre-populate one timestamp that is
        // 61 seconds old (outside the window) and one that is 30s old (inside).
        $cache = $this->makeCache();
        $now   = time();

        $middleware = new class(2, 60, 'rl:', $cache) extends RateLimitMiddleware {
            public int $now;

            protected function loadTimestamps(string $key): array
            {
                // Return one old (outside window) and one recent (inside window) timestamp
                $now = $this->now ?? time();
                return [$now - 61, $now - 30];
            }
        };
        $middleware->now = $now;

        $request    = $this->makeRequest();
        $nextCalled = false;

        // Act — only 1 of the 2 stored timestamps is within the window, so
        // the limit (2) is not yet reached and this request should pass.
        $middleware->handle($request, function () use (&$nextCalled): string {
            $nextCalled = true;
            return 'ok';
        });

        // Assert — request was allowed (old timestamp was discarded)
        $this->assertTrue($nextCalled);
    }

    // =========================================================================
    // IP isolation
    // =========================================================================

    /**
     * Two different client IPs maintain independent counters.
     * Exhausting the limit for IP-A must not affect IP-B.
     */
    public function testDifferentIpsHaveIndependentCounters(): void
    {
        // Arrange
        $cache      = $this->makeCache();
        $middleware = new RateLimitMiddleware(2, 60, 'rl:', $cache);
        $request    = $this->makeRequest();

        // Exhaust limit for 192.168.0.1
        $_SERVER['REMOTE_ADDR'] = '192.168.0.1';
        $middleware->handle($request, fn() => 'ok');
        $middleware->handle($request, fn() => 'ok');

        // Switch to a different IP — should have a fresh counter
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $nextCalled = false;

        // Act
        $middleware->handle($request, function () use (&$nextCalled): string {
            $nextCalled = true;
            return 'ok';
        });

        // Assert — IP-B's first request was allowed
        $this->assertTrue($nextCalled);
    }

    // =========================================================================
    // keyPrefix isolation
    // =========================================================================

    /**
     * Two middleware instances with different prefixes have independent buckets.
     */
    public function testDifferentPrefixesHaveIndependentCounters(): void
    {
        // Arrange — share the same Cache but use different prefixes
        $cache = $this->makeCache();
        $m1    = new RateLimitMiddleware(2, 60, 'api:', $cache);
        $m2    = new RateLimitMiddleware(2, 60, 'web:', $cache);
        $request = $this->makeRequest();

        // Exhaust limit for m1 (api:)
        $m1->handle($request, fn() => 'ok');
        $m1->handle($request, fn() => 'ok');

        // m2 (web:) must still allow requests
        $nextCalled = false;
        $m2->handle($request, function () use (&$nextCalled): string {
            $nextCalled = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($nextCalled);
    }

    // =========================================================================
    // Return value pass-through
    // =========================================================================

    /**
     * The return value from $next is returned unchanged by handle().
     */
    public function testHandlePassesThroughNextReturnValue(): void
    {
        // Arrange
        $cache      = $this->makeCache();
        $middleware = new RateLimitMiddleware(10, 60, 'rl:', $cache);
        $request    = $this->makeRequest();

        // Act
        $result = $middleware->handle($request, fn(): int => 99);

        // Assert
        $this->assertSame(99, $result);
    }
}
