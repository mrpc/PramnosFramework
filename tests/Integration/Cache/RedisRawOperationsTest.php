<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * The Redis operations that have no equivalent on the other adapters.
 *
 * `StructuredOperationParityTest` covers the hashes, the lists and the counters — the things every
 * adapter must agree about — and it runs against a real server, so those bodies are exercised. What it
 * cannot cover is the half of this adapter that exists *because* the backend is Redis: an atomic swap,
 * a cursor walk over the keyspace, a pattern delete. Those have no File or Array counterpart, so a
 * parity test has nothing to compare them against and never calls them.
 *
 * The result was that every one of them had been *entered* — by
 * {@see \Pramnos\Tests\Unit\Cache\RedisAdapterDegradesTest}, which proves they return a safe answer
 * when there is no connection — and none of them had ever run its body. A method whose only test is
 * «it does nothing when switched off» is a method nobody has watched work.
 *
 * Two of them are the ones to get right. **SCAN, not KEYS**: `keys()` and `deleteByPattern()` walk the
 * keyspace in bounded steps, because `KEYS` holds the whole server for the length of the sweep — and
 * the sweep is over a production cache. And **GETSET**, because `swap()` is the atomic
 * compare-and-take that a lock or a leader election is built on; read-then-write in its place is a
 * race two callers both win.
 *
 * Its own scratch database (13) and its own key prefix, so a flush here cannot reach anything else's
 * keys — including the parity test's, which uses 11.
 */
#[CoversClass(RedisAdapter::class)]
class RedisRawOperationsTest extends TestCase
{
    private const REDIS_DB = 13;

    private ?RedisAdapter $cache = null;

    protected function setUp(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }

        $host = getenv('REDIS_HOST') ?: 'pramnos_redis';
        $adapter = new RedisAdapter($host, 6379, self::REDIS_DB, null, 'raw_');

        if (!$adapter->connect()) {
            $this->markTestSkipped('No Redis server at ' . $host . ':6379.');
        }

        $adapter->flushEverything();
        $this->cache = $adapter;
    }

    /**
     * A second, plain connection to the same database.
     *
     * The adapter has no public way to read a key's TTL — deliberately; nothing in the framework needs
     * one — and these assertions are about what Redis holds rather than about what the adapter
     * returns. Asking the server directly is also the stronger assertion: a `ttl()` the adapter
     * computed itself would be the adapter agreeing with itself.
     */
    private function server(): \Redis
    {
        $raw = new \Redis();
        $raw->connect(getenv('REDIS_HOST') ?: 'pramnos_redis', 6379, 1.0);
        $raw->select(self::REDIS_DB);

        return $raw;
    }

    protected function tearDown(): void
    {
        $this->cache?->flushEverything();
        $this->cache = null;

        parent::tearDown();
    }

    /**
     * `swap()` returns the value that was there, and only one caller can see it.
     *
     * GETSET, not get-then-set, and that is the whole reason the method exists. A lock, a leader
     * election and a «claim this job» are all «take the old value and put mine there, atomically» — and
     * with a read followed by a write, two callers both read the same old value and both believe they
     * won.
     *
     * The `null` for an unset key is asserted separately from the round trip because Redis answers
     * `false` there, and handing that on would make «the key held false» and «there was no key» the
     * same answer.
     */
    public function testSwapReturnsThePreviousValueAndNullWhenThereWasNone(): void
    {
        // Act & Assert — nothing was there
        $this->assertNull($this->cache->swap('claim', 'first'), 'an unset key answered with a value');

        // …and now the previous holder is handed back exactly once
        $this->assertSame('first', $this->cache->swap('claim', 'second'));
        $this->assertSame('second', $this->cache->swap('claim', 'third'));
        $this->assertSame('third', (string) $this->server()->get('claim'));
    }

    /**
     * A counter goes up, comes down, and reads back as an integer.
     *
     * Raw keys, not the serialized cache envelope — which is why they are worth their own assertions:
     * a counter stored through `save()` would round-trip as a PHP value and be useless to `INCRBY`,
     * and the two representations look identical from the outside until something tries to increment
     * one.
     */
    public function testACounterGoesUpAndDownAndReadsBackAsAnInteger(): void
    {
        // Act & Assert
        $this->assertSame(1, $this->cache->increment('hits'));
        $this->assertSame(4, $this->cache->increment('hits', 3));
        $this->assertSame(4, $this->cache->counter('hits'));

        $this->assertSame(1, $this->cache->decrement('hits', 3));
        $this->assertSame(1, $this->cache->counter('hits'));

        // …and a counter nobody has touched is zero rather than false
        $this->assertSame(0, $this->cache->counter('never_touched'));
    }

    /**
     * A sliding TTL is refreshed on every increment; a fixed one is set once.
     *
     * The distinction a rate limiter is built on, and the two behaviours are opposite. A fixed window
     * expires a fixed time after the *first* request, so a burst is counted and then forgotten. A
     * sliding one expires that long after the *last* request, so somebody who keeps knocking stays
     * blocked. Choosing the wrong one is a limiter that either never releases or never holds.
     */
    public function testASlidingTtlIsRefreshedAndAFixedOneIsNot(): void
    {
        // Arrange & Act — fixed: the expiry is set on the call that created the key
        $this->cache->increment('fixed', 1, 50);
        $this->cache->increment('fixed', 1, 50);

        // …sliding: every call pushes it out
        $this->cache->increment('sliding', 1, 50, true);
        $this->cache->increment('sliding', 1, 90, true);

        // Assert
        $this->assertSame(2, $this->cache->counter('fixed'));
        $this->assertSame(2, $this->cache->counter('sliding'));
        $server = $this->server();
        $this->assertGreaterThan(
            50,
            (int) $server->ttl('sliding'),
            'the sliding window was not pushed out by the second call'
        );
        $this->assertLessThanOrEqual(
            50,
            (int) $server->ttl('fixed'),
            'the fixed window was extended, so a burst is never forgotten'
        );
    }

    /**
     * `expire()` puts a lifetime on a key that was written without one.
     *
     * The separate call exists for the shape where the value and its lifetime are decided by different
     * pieces of code — a session written on sign-in and given its idle timeout by the middleware. A
     * no-op here leaves those keys permanent, which is a cache that grows and never forgets.
     */
    public function testExpireGivesALifetimeToAKeyWrittenWithoutOne(): void
    {
        // Arrange
        $this->cache->increment('permanent');
        $this->assertSame(-1, (int) $this->server()->ttl('permanent'), 'the fixture key had a TTL');

        // Act
        $this->cache->expire('permanent', 120);

        // Assert
        $this->assertGreaterThan(0, (int) $this->server()->ttl('permanent'));
    }

    /**
     * `keys()` walks the keyspace with SCAN and finds what matches.
     *
     * SCAN and not KEYS, which is the point: `KEYS` holds the server for the whole sweep, and the
     * sweep is over a production cache. The loop is bounded per step and reassembles the batches, so
     * the assertion that matters is that **nothing is lost across the cursor** — a `do/while` that
     * stopped on the first batch would pass with three keys and quietly truncate with three thousand.
     */
    public function testKeysWalksTheKeyspaceAndLosesNothingAcrossTheCursor(): void
    {
        // Arrange — comfortably more than one SCAN step's worth
        for ($i = 0; $i < 250; $i++) {
            $this->cache->increment('walk_' . $i);
        }
        $this->cache->increment('other_thing');

        // Act
        $matched = $this->cache->keys('*walk_*');

        // Assert
        $this->assertTrue($this->cache->supportsKeyEnumeration());
        $this->assertCount(250, $matched, 'the cursor walk dropped keys');
        $this->assertSame([], array_filter(
            $matched,
            static fn (string $key): bool => !str_contains($key, 'walk_')
        ), 'the pattern matched keys it should not have');
    }

    /**
     * Clearing everything is scoped to this installation's prefix, not to the database.
     *
     * `clear('')` reaches `deleteByPattern($prefix . '*')`, and the scoping is the safety property
     * rather than a detail. Several installations — and in a suite, several test classes — share one
     * Redis, so a clear that swept the whole database would empty somebody else's sessions along with
     * the page cache the operator asked to clear. And it would report success either way.
     *
     * More than one SCAN step's worth of keys on purpose: the sweep is a bounded cursor walk that
     * batches its deletes, so a loop that stopped after the first batch would pass with three keys and
     * quietly leave hundreds behind.
     */
    public function testClearingEverythingIsScopedToThePrefix(): void
    {
        // Arrange — inside the prefix…
        for ($i = 0; $i < 120; $i++) {
            $this->cache->increment('raw_doomed_' . $i);
        }

        // …and outside it, which nothing here is allowed to touch
        $this->cache->increment('elsewhere_kept');

        // Act
        $result = $this->cache->clear('');

        // Assert
        $this->assertTrue($result);
        $this->assertSame([], $this->cache->keys('raw_doomed_*'), 'prefixed keys survived the clear');
        $this->assertSame(
            1,
            $this->cache->counter('elsewhere_kept'),
            'the clear reached past its own prefix, so it would empty another installation'
        );
    }

    /**
     * `flushEverything()` empties this database and reports how many it removed.
     *
     * Scoped to the configured database, which is why this class uses one of its own: a flush that
     * reached the whole server would take the session store of whatever else shares it, and in a test
     * suite it would take the other Redis tests' fixtures with it.
     */
    public function testFlushingEmptiesThisDatabaseOnly(): void
    {
        // Arrange
        for ($i = 0; $i < 5; $i++) {
            $this->cache->increment('temp_' . $i);
        }
        $this->assertNotSame([], $this->cache->keys('*temp_*'));

        // Act
        $this->cache->flushEverything();

        // Assert
        $this->assertSame([], $this->cache->keys('*temp_*'));
        $this->assertSame(0, $this->cache->counter('temp_0'));
    }
}
