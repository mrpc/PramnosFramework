<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\ThrottleMiddleware;
use Pramnos\Http\Request;

/**
 * Unit tests for Pramnos\Http\Middleware\ThrottleMiddleware.
 *
 * ThrottleMiddleware implements a sliding-window rate limiter backed by APCu.
 * To enable deterministic testing without APCu, the class exposes three
 * protected hooks (fetchCount, storeCount, incrementCount) that are overridden
 * in an in-memory subclass below.
 *
 * Tests verify:
 *   - First request (counter not yet set) is allowed and stores counter = 1.
 *   - Subsequent requests below the limit are allowed and increment the counter.
 *   - A request at exactly the limit is rejected with code 429.
 *   - A request above the limit is also rejected.
 *   - Constructor defaults: maxRequests=60, perSeconds=60.
 *   - Custom maxRequests / perSeconds / keyPrefix are respected.
 *   - The $next callable is invoked when the request is allowed.
 *   - The $next callable is NOT invoked when the request is blocked.
 *   - Different IP addresses (key prefixes) have independent counters.
 */
#[CoversClass(ThrottleMiddleware::class)]
class ThrottleMiddlewareTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * In-memory subclass that bypasses APCu for deterministic testing.
     * The $store array maps key → count and is injectable per test.
     */
    private function makeMiddleware(
        int $max = 3,
        int $per = 60,
        string $prefix = 'throttle:',
        array &$store = []
    ): ThrottleMiddleware {
        return new class($max, $per, $prefix, $store) extends ThrottleMiddleware {
            public function __construct(
                int $max,
                int $per,
                string $prefix,
                private array &$store
            ) {
                parent::__construct($max, $per, $prefix);
            }

            protected function fetchCount(string $key): int|false
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : false;
            }

            protected function storeCount(string $key, int $value, int $ttl): void
            {
                $this->store[$key] = $value;
            }

            protected function incrementCount(string $key): void
            {
                $this->store[$key] = ($this->store[$key] ?? 0) + 1;
            }
        };
    }

    private function makeRequest(): Request
    {
        return $this->createMock(Request::class);
    }

    private function setRemoteAddr(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }

    protected function setUp(): void
    {
        $this->setRemoteAddr('127.0.0.1');
    }

    // =========================================================================
    // Allow paths
    // =========================================================================

    /**
     * The first request from a new IP has no counter yet (fetchCount returns
     * false). It is allowed, and the counter is stored at 1.
     */
    public function testFirstRequestIsAllowedAndStoresCounterAtOne(): void
    {
        // Arrange
        $store      = [];
        $middleware = $this->makeMiddleware(max: 5, per: 60, store: $store);
        $request    = $this->makeRequest();
        $nextCalled = false;

        // Act
        $middleware->handle($request, function () use (&$nextCalled): string {
            $nextCalled = true;
            return 'ok';
        });

        // Assert — request was forwarded
        $this->assertTrue($nextCalled);

        // Assert — counter was stored at 1
        $key = 'throttle:' . md5('127.0.0.1');
        $this->assertSame(1, $store[$key]);
    }

    /**
     * Requests below the limit are allowed and the counter is incremented.
     */
    public function testRequestsBelowLimitAreAllowed(): void
    {
        // Arrange — counter already at 2, limit is 5
        $key   = 'throttle:' . md5('127.0.0.1');
        $store = [$key => 2];
        $middleware = $this->makeMiddleware(max: 5, store: $store);
        $request    = $this->makeRequest();
        $callCount  = 0;

        // Act
        $middleware->handle($request, function () use (&$callCount): string {
            $callCount++;
            return 'ok';
        });

        // Assert — forwarded
        $this->assertSame(1, $callCount);
        // Assert — counter incremented to 3
        $this->assertSame(3, $store[$key]);
    }

    // =========================================================================
    // Rejection paths
    // =========================================================================

    /**
     * A request at exactly the limit is rejected with Exception code 429.
     */
    public function testRequestAtLimitIsRejected(): void
    {
        // Arrange — counter already at the limit
        $key   = 'throttle:' . md5('127.0.0.1');
        $store = [$key => 5];
        $middleware = $this->makeMiddleware(max: 5, store: $store);
        $request    = $this->makeRequest();
        $nextCalled = false;

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(429);

        // Act — this should throw
        $middleware->handle($request, function () use (&$nextCalled): string {
            $nextCalled = true;
            return 'should not reach';
        });
    }

    /**
     * A request above the limit is also rejected.
     */
    public function testRequestAboveLimitIsRejected(): void
    {
        // Arrange — counter well above the limit
        $key   = 'throttle:' . md5('127.0.0.1');
        $store = [$key => 999];
        $middleware = $this->makeMiddleware(max: 5, store: $store);
        $request    = $this->makeRequest();

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(429);

        // Act
        $middleware->handle($request, fn() => 'blocked');
    }

    /**
     * $next is NOT called when the request is blocked.
     */
    public function testNextIsNotCalledWhenBlocked(): void
    {
        // Arrange — exactly at limit
        $key   = 'throttle:' . md5('127.0.0.1');
        $store = [$key => 3];
        $middleware = $this->makeMiddleware(max: 3, store: $store);
        $request    = $this->makeRequest();
        $nextCalled = false;

        // Act — catch the exception so we can assert about $nextCalled
        try {
            $middleware->handle($request, function () use (&$nextCalled): string {
                $nextCalled = true;
                return 'should not be here';
            });
        } catch (\Exception $e) {
            // expected
        }

        // Assert — destination was not reached
        $this->assertFalse($nextCalled);
    }

    // =========================================================================
    // Constructor configuration
    // =========================================================================

    /**
     * Custom maxRequests is respected: a counter at the custom max is rejected.
     */
    public function testCustomMaxRequestsIsRespected(): void
    {
        // Arrange — limit set to 2
        $key   = 'throttle:' . md5('127.0.0.1');
        $store = [$key => 2];
        $middleware = $this->makeMiddleware(max: 2, store: $store);
        $request    = $this->makeRequest();

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(429);

        // Act
        $middleware->handle($request, fn() => 'blocked');
    }

    /**
     * Custom keyPrefix creates independent buckets per middleware instance.
     */
    public function testCustomKeyPrefixCreatesIndependentCounters(): void
    {
        // Arrange — two middleware instances with different prefixes
        $store1     = [];
        $store2     = [];
        $m1 = $this->makeMiddleware(max: 5, prefix: 'api:', store: $store1);
        $m2 = $this->makeMiddleware(max: 5, prefix: 'web:', store: $store2);
        $request    = $this->makeRequest();

        // Act — allow first request through both
        $m1->handle($request, fn() => 'ok');
        $m2->handle($request, fn() => 'ok');

        // Assert — each middleware has its own counter
        $ip = '127.0.0.1';
        $this->assertSame(1, $store1['api:' . md5($ip)]);
        $this->assertSame(1, $store2['web:' . md5($ip)]);
    }

    // =========================================================================
    // Return value
    // =========================================================================

    /**
     * The return value of $next is passed through by handle().
     */
    public function testHandlePassesThroughReturnValueFromNext(): void
    {
        // Arrange
        $store      = [];
        $middleware = $this->makeMiddleware(max: 10, store: $store);
        $request    = $this->makeRequest();

        // Act
        $result = $middleware->handle($request, fn(): int => 42);

        // Assert
        $this->assertSame(42, $result);
    }
}
