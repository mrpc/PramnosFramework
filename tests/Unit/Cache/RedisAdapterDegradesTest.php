<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * What the Redis adapter does when there is no Redis — 21 guards, none executed.
 *
 * Every public method opens with the same line:
 *
 * ```php
 * if (!$this->caching || !$this->connected) {
 *     return …;   // false, 0, null, [], $default, or nothing
 * }
 * ```
 *
 * That is the most important line in the file, and the least interesting to write a test for,
 * which is presumably why none existed. A cache is the one dependency an application is supposed
 * to survive losing: if the adapter *raises* when Redis is unreachable, then a Redis restart takes
 * every page with it — and the failure arrives as a 500 on the site, not as a cache miss.
 *
 * So this walks the whole native surface with no connection and asserts each documented "nothing".
 * The values are not interchangeable: `counter()` answering `null` instead of `0` breaks the
 * arithmetic in a rate limiter, `hashGetAll()` answering `false` instead of `[]` breaks a
 * `foreach`, and `hashGet()` has to answer the caller's own `$default` rather than a fixed null.
 *
 * No server needed, and that is the point — this is the no-server case. The adapter is constructed
 * and never connected, which is exactly the state it is in after `connect()` has failed.
 */
#[CoversClass(RedisAdapter::class)]
class RedisAdapterDegradesTest extends TestCase
{
    /**
     * An adapter that was never connected.
     *
     * Port 1 and no `connect()` call: `$connected` is false, which is the state a failed connect
     * leaves behind. Nothing here touches the network.
     */
    private function disconnected(): RedisAdapter
    {
        return new RedisAdapter('127.0.0.1', 1, 0, null, 'down_');
    }

    /**
     * Every read answers "nothing", in the shape its caller expects.
     *
     * The shapes matter individually. `counter()` returning `0` is what lets a rate limiter add to
     * it without a null check; `hashGetAll()` and `listRange()` returning `[]` is what lets a
     * caller `foreach` the answer; and `hashGet()` returning the caller's `$default` is what makes
     * "no cache" indistinguishable from "not in the cache", which is the whole contract of a cache.
     */
    public function testEveryReadAnswersNothingInTheShapeItsCallerExpects(): void
    {
        // Arrange
        $cache = $this->disconnected();

        // Act & Assert
        $this->assertFalse($cache->load('k'), 'load must answer false, not raise');
        $this->assertSame(0, $cache->counter('k'), 'a counter must stay countable');
        $this->assertSame([], $cache->hashGetAll('k'), 'hashGetAll must stay foreach-able');
        $this->assertSame([], $cache->listRange('k', 0, -1), 'listRange must stay foreach-able');
        $this->assertSame([], $cache->keys('*'));
        $this->assertSame([], $cache->getCategories());
        $this->assertNull($cache->swap('k', 'v'), 'swap must answer null — nobody held the slot');

        // The caller's own default, not a fixed null.
        $this->assertSame('fallback', $cache->hashGet('k', 'f', 'fallback'));
        $this->assertNull($cache->hashGet('k', 'f'));
    }

    /**
     * Every write answers "did not happen" rather than raising.
     *
     * A caller that checks the return can log it; a caller that ignores it carries on with an
     * uncached value, which is correct. Neither is served by an exception.
     */
    public function testEveryWriteAnswersDidNotHappen(): void
    {
        // Arrange
        $cache = $this->disconnected();

        // Act & Assert
        $this->assertFalse($cache->save('k', 'v'), 'save must report that it did not');
        $this->assertFalse($cache->delete('k'));
        $this->assertFalse($cache->increment('k'));
        $this->assertFalse($cache->decrement('k'));
        $this->assertSame(0, $cache->listPush('k', 'v'), 'listPush must answer a length');
        $this->assertFalse($cache->clear());
        $this->assertFalse($cache->flushEverything());
    }

    /**
     * The methods that return nothing at all simply return.
     *
     * `hashSet()`, `hashDelete()`, `listTrim()` and `expire()` are `void`, so the only thing that
     * can go wrong is an exception — which is precisely what this asserts does not happen.
     */
    public function testTheVoidMethodsReturnRatherThanRaise(): void
    {
        // Arrange
        $cache = $this->disconnected();

        // Act — a raise here is the failure; reaching the assertion is the pass.
        $cache->hashSet('k', 'f', 'v');
        $cache->hashSet('k', 'f', 'v', 60);
        $cache->hashDelete('k', 'f');
        $cache->listTrim('k', 0, 9);
        $cache->expire('k', 60);

        // Assert
        $this->assertFalse($cache->load('k'), 'the adapter stopped answering after a write');
    }

    /**
     * The screens that describe the cache still render.
     *
     * `getStats()` and `getAllItems()` feed the cache panel — the screen somebody opens *because*
     * the cache looks wrong. Raising there would mean the diagnostic page is the one page that
     * cannot be loaded when there is something to diagnose.
     */
    public function testTheDiagnosticScreensStillRender(): void
    {
        // Arrange
        $cache = $this->disconnected();

        // Act
        $stats = $cache->getStats();
        $items = $cache->getAllItems();

        // Assert
        $this->assertIsArray($stats);
        $this->assertIsArray($items);
        $this->assertArrayHasKey(
            'method',
            $stats,
            'the panel cannot say which adapter it is looking at'
        );
    }

    /**
     * `test()` reports a failure rather than claiming a working round trip.
     *
     * It is what the health endpoint calls. Answering true with no connection would make a
     * monitored installation report itself healthy while its cache was gone.
     */
    public function testTheRoundTripReportsFailure(): void
    {
        // Act & Assert
        $this->assertFalse($this->disconnected()->test());
    }

    /**
     * Switching caching off does the same thing as losing the server.
     *
     * The other half of the same guard, and it has a separate caller: an installation that turns
     * caching off in configuration, or a request that turns it off to read past a stale entry.
     * Both must be a no-op rather than an error, and both go through the same line — so a change
     * that only kept one of them working would look right from wherever it was tested.
     */
    public function testCachingSwitchedOffBehavesLikeNoServer(): void
    {
        // Arrange
        $cache = $this->disconnected();
        $cache->setCaching(false);

        // Act & Assert
        $this->assertFalse($cache->isCachingEnabled());
        $this->assertFalse($cache->load('k'));
        $this->assertFalse($cache->save('k', 'v'));
        $this->assertSame(0, $cache->counter('k'));
        $this->assertSame([], $cache->hashGetAll('k'));
    }

    /**
     * A failed connection is reported, not thrown.
     *
     * `connect()` is the one method allowed to know that Redis is unreachable, and it says so with
     * `false`. Everything above depends on it leaving the adapter in a usable, degraded state
     * rather than half-constructed.
     */
    public function testAFailedConnectionIsReportedRatherThanThrown(): void
    {
        // Arrange — nothing listens on port 1.
        $cache = $this->disconnected();

        // Act
        $connected = $cache->connect();

        // Assert
        $this->assertFalse($connected, 'a refused connection was not reported as one');
        $this->assertFalse($cache->load('k'), 'the adapter is unusable after a failed connect');
    }
}
