<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Http\Middleware\RateLimitMiddleware;
use Pramnos\Http\Request;
use Pramnos\Http\TooManyRequestsException;

/**
 * A cache whose adapter can count atomically, standing in for Redis/Memcached.
 *
 * The point is not to emulate a network round trip but to model the property
 * that matters: increment reads and writes in one indivisible step, so no
 * increment can be lost however the calls interleave.
 */
class AtomicCountingCache extends Cache
{
    /** @var array<string, int> The counters, by key. */
    public array $counters = [];

    /** @var array<string, int> The TTL each counter was created with. */
    public array $ttls = [];

    /** Constructed without the parent's adapter machinery. */
    public function __construct()
    {
    }

    /** This stand-in counts atomically; that is its whole reason to exist. */
    public function supportsAtomicCounter(): bool
    {
        return true;
    }

    /**
     * Increment and return in one step, applying the TTL only on creation —
     * the same fixed-window semantics the real adapters implement.
     */
    public function increment($id, int $ttl)
    {
        if (!isset($this->counters[$id])) {
            $this->counters[$id] = 0;
            $this->ttls[$id]     = $ttl;
        }

        return ++$this->counters[$id];
    }
}

/**
 * A cache that cannot count atomically — the File and Array adapters' shape.
 */
class NonAtomicCache extends Cache
{
    /** @var array<string, string> The stored values, by key. */
    public array $values = [];

    /** Constructed without the parent's adapter machinery. */
    public function __construct()
    {
    }

    /** No atomic counter, so the middleware must take the fallback path. */
    public function supportsAtomicCounter(): bool
    {
        return false;
    }

    /** @return string|false */
    public function load($id, $category = null, $timeout = null)
    {
        return $this->values[$id] ?? false;
    }

    /** @return bool */
    public function save($data = '', $id = null)
    {
        $this->values[(string) $id] = (string) $data;

        return true;
    }
}

/**
 * A cache whose counter fails, standing in for a dropped Redis connection.
 */
class FailingCounterCache extends NonAtomicCache
{
    /** It claims to support counting… */
    public function supportsAtomicCounter(): bool
    {
        return true;
    }

    /** …and then fails, which is what a dropped connection looks like. */
    public function increment($id, int $ttl)
    {
        return false;
    }
}

/**
 * A middleware that exposes the read-modify-write as two separate steps, so a
 * test can interleave two requests the way concurrent processes would.
 */
class InterleavableRateLimit extends RateLimitMiddleware
{
    /** Expose the load for the interleaving test. */
    public function readWindow(string $key): array
    {
        return $this->loadTimestamps($key);
    }

    /** Expose the save for the interleaving test. */
    public function writeWindow(string $key, array $timestamps): void
    {
        $this->saveTimestamps($key, $timestamps);
    }
}

/**
 * Counting accuracy of the rate limiter under concurrency.
 *
 * WHAT: when N requests arrive together, does the limiter admit N?
 * WHY:  the limiter used to do load → filter → count → append → save with no
 *       lock and no compare-and-set. Two requests that overlap both read the
 *       same list and the second save overwrites the first, so a burst can
 *       advance the stored count by as little as 1. That makes the control
 *       least accurate exactly when it matters, because a flood is concurrent
 *       by definition and a slow trickle — which it counts perfectly — is the
 *       case nobody needed protection from.
 *
 * The first test below measures that loss rather than asserting it away: the
 * fallback path is still lossy and is documented as such, and a test that
 * pretended otherwise would be the same kind of lie the audit was about.
 */
class RateLimitConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        // A fixed peer, so every request in a test shares one bucket. No
        // trusted proxies are configured, so this is what clientIp() returns.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    /** A request object for the middleware to pass along. */
    private function request(): Request
    {
        return Request::getInstance();
    }

    /** The pass-through continuation. */
    private function next(): callable
    {
        return fn($request) => 'passed';
    }

    /**
     * The non-atomic path loses increments when requests interleave — measured.
     *
     * Two requests each read the window before either writes, which is exactly
     * what two PHP processes do when they arrive together. Both see an empty
     * window, both append one timestamp, and the second save overwrites the
     * first: two requests, one recorded. This documents the size of the loss
     * for the adapters that cannot do better.
     */
    public function testTheNonAtomicPathLosesInterleavedIncrements(): void
    {
        // Arrange
        $cache      = new NonAtomicCache();
        $middleware = new InterleavableRateLimit(10, 60, 'measure:', $cache);
        $key        = 'measure:' . md5('203.0.113.5');

        // Act — interleave two read-modify-write cycles
        $first  = $middleware->readWindow($key);
        $second = $middleware->readWindow($key);
        $first[]  = time();
        $second[] = time();
        $middleware->writeWindow($key, $first);
        $middleware->writeWindow($key, $second);

        // Assert — two requests happened; one was recorded
        $this->assertCount(
            1,
            $middleware->readWindow($key),
            'the later save overwrites the earlier: this is the undercount'
        );
    }

    /**
     * The atomic path admits exactly the limit and rejects the next request.
     *
     * The counter is shared and indivisible, so it does not matter how the
     * requests interleave — every one of them is counted.
     */
    public function testTheAtomicPathAdmitsExactlyTheLimit(): void
    {
        // Arrange
        $cache      = new AtomicCountingCache();
        $middleware = new RateLimitMiddleware(3, 60, 'atomic:', $cache);

        // Act — spend the whole allowance
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(
                'passed',
                $middleware->handle($this->request(), $this->next()),
                'request ' . ($i + 1) . ' is within the limit'
            );
        }

        // Assert — the fourth is refused
        $this->expectException(TooManyRequestsException::class);
        $middleware->handle($this->request(), $this->next());
    }

    /**
     * Every request counts, including the ones that were refused.
     *
     * A limiter that stopped counting once it started rejecting would let a
     * flood reset itself the moment it paused. The counter keeps climbing.
     */
    public function testRejectedRequestsStillCount(): void
    {
        // Arrange
        $cache      = new AtomicCountingCache();
        $middleware = new RateLimitMiddleware(1, 60, 'atomic:', $cache);
        $middleware->handle($this->request(), $this->next());

        // Act — two refusals
        for ($i = 0; $i < 2; $i++) {
            try {
                $middleware->handle($this->request(), $this->next());
            } catch (TooManyRequestsException) {
                // Expected; the count is what is under test.
            }
        }

        // Assert
        $this->assertSame([3], array_values($cache->counters));
    }

    /**
     * The rejection says how long to wait, and it is the time actually left.
     *
     * `Retry-After: 60` on a window with two seconds to run tells a
     * well-behaved client to stay away 30× longer than necessary.
     */
    public function testRetryAfterIsTheTimeLeftInTheWindow(): void
    {
        // Arrange
        $middleware = new RateLimitMiddleware(1, 60, 'atomic:', new AtomicCountingCache());
        $middleware->handle($this->request(), $this->next());

        // Act
        try {
            $middleware->handle($this->request(), $this->next());
            $this->fail('the second request should have been rejected');
        } catch (TooManyRequestsException $e) {
            $retryAfter = $e->getRetryAfter();
        }

        // Assert — somewhere inside the window, never zero or the full length+1
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $this->assertSame(60 - (time() % 60), $retryAfter, 'the remainder of the current window');
    }

    /**
     * A failing counter falls back to limiting, not to admitting everything.
     *
     * `increment()` returns false for "the counter did not work" — a dropped
     * Redis connection, say. Reading that as zero would turn an infrastructure
     * blip into an open door at precisely the moment the site is under strain.
     */
    public function testAFailingCounterFallsBackToTheApproximateLimitNotToNoLimit(): void
    {
        // Arrange
        $middleware = new RateLimitMiddleware(2, 60, 'broken:', new FailingCounterCache());

        // Act — spend the allowance through the fallback path
        $middleware->handle($this->request(), $this->next());
        $middleware->handle($this->request(), $this->next());

        // Assert — still limited, just less precisely
        $this->expectException(TooManyRequestsException::class);
        $middleware->handle($this->request(), $this->next());
    }

    /**
     * A cache with no atomic counter still limits, through the fallback.
     *
     * The Array and File adapters are what tests and small installations
     * actually run on. They must be limited — approximately, but limited — and
     * not silently pass everything because the preferred path was unavailable.
     */
    public function testACacheWithoutAnAtomicCounterStillLimits(): void
    {
        // Arrange
        $middleware = new RateLimitMiddleware(2, 60, 'fallback:', new NonAtomicCache());

        // Act
        $this->assertSame('passed', $middleware->handle($this->request(), $this->next()));
        $this->assertSame('passed', $middleware->handle($this->request(), $this->next()));

        // Assert
        $this->expectException(TooManyRequestsException::class);
        $middleware->handle($this->request(), $this->next());
    }

    /**
     * Two different clients get two different buckets.
     *
     * The obvious property, and the one that silently stopped holding behind a
     * proxy: with the key derived from the connecting peer, every visitor
     * shared a bucket and the limit fired for everybody at once.
     */
    public function testDifferentClientsGetDifferentBuckets(): void
    {
        // Arrange
        $cache      = new AtomicCountingCache();
        $middleware = new RateLimitMiddleware(1, 60, 'perclient:', $cache);

        // Act — one request each from two addresses
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $middleware->handle($this->request(), $this->next());
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $result = $middleware->handle($this->request(), $this->next());

        // Assert — the second client is not charged for the first one's request
        $this->assertSame('passed', $result);
        $this->assertCount(2, $cache->counters, 'one counter per client');
    }

    /**
     * An unverified `X-Forwarded-For` does not buy a fresh bucket.
     *
     * The bypass this work exists to prevent: if the header were trusted
     * without a proxy list, a fresh random value per request would give an
     * attacker unlimited allowance while the logs showed a healthy spread of
     * addresses.
     */
    public function testAForgedForwardedHeaderDoesNotEscapeTheLimit(): void
    {
        // Arrange
        $cache      = new AtomicCountingCache();
        $middleware = new RateLimitMiddleware(2, 60, 'forge:', $cache);

        // Act — three requests, each claiming a different origin
        $admitted = 0;
        foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3'] as $claimed) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $claimed;
            try {
                $middleware->handle($this->request(), $this->next());
                $admitted++;
            } catch (TooManyRequestsException) {
                // Counted below by its absence.
            }
        }

        // Assert — the limit held, and all three shared one bucket
        $this->assertSame(2, $admitted, 'the third request must be refused');
        $this->assertCount(1, $cache->counters, 'the header must not create buckets');
    }
}
