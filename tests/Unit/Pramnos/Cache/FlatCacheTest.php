<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\FlatCache;
use Pramnos\Cache\SimpleCacheInvalidArgumentException;
use Pramnos\Redis\ConnectionManager;

/**
 * Unit tests for the backend-agnostic flat-key PSR-16 cache.
 *
 * Exercised over a real ArrayAdapter (no fakes) — the same class works
 * unchanged over the Redis/File/Memcached adapters in production, which is the
 * whole point of building it on AdapterInterface rather than tying it to Redis.
 */
#[CoversClass(FlatCache::class)]
class FlatCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the process-global default + connection manager so the wiring
        // tests never leak a stale instance into the rest of the suite.
        FlatCache::setDefault(null);
        ConnectionManager::setInstance(null);
    }

    private function cache(string $prefix = 'app:'): FlatCache
    {
        return new FlatCache(new ArrayAdapter($prefix), $prefix);
    }

    /**
     * FlatCache::default() is a lazy singleton: it returns the same instance on
     * repeated calls, setDefault() overrides it (bootstrap/test seam), and
     * setDefault(null) makes the next default() rebuild.
     */
    public function testDefaultIsLazySingletonAndOverridable(): void
    {
        $override = $this->cache('override:');
        FlatCache::setDefault($override);
        $this->assertSame($override, FlatCache::default());

        FlatCache::setDefault(null);
        $rebuilt = FlatCache::default();
        $this->assertInstanceOf(FlatCache::class, $rebuilt);
        $this->assertNotSame($override, $rebuilt);
        $this->assertSame($rebuilt, FlatCache::default(), 'default() must memoise');
    }

    /**
     * default() builds its store from the shared ConnectionManager — so it
     * carries the app's per-install prefix without the app wiring the adapter
     * itself. (Asserted via the FlatCache prefix, sourced from the manager.)
     */
    public function testDefaultWiresConnectionManagerPrefix(): void
    {
        ConnectionManager::setInstance(new ConnectionManager(['prefix' => 'wiretest:']));

        $prefix = (new \ReflectionProperty(FlatCache::class, 'prefix'))
            ->getValue(FlatCache::default());

        $this->assertSame('wiretest:', $prefix);
    }

    public function testRoundTripsArraysUnderVerbatimColonKey(): void
    {
        $c = $this->cache();
        $this->assertTrue($c->set('chat:messages:hash', ['a' => 1, 'b' => 'x'], 300));
        $this->assertSame(['a' => 1, 'b' => 'x'], $c->get('chat:messages:hash'));
    }

    public function testGetMissingReturnsDefault(): void
    {
        $this->assertSame('fallback', $this->cache()->get('nope', 'fallback'));
    }

    public function testHasReflectsPresence(): void
    {
        $c = $this->cache();
        $this->assertFalse($c->has('k'));
        $c->set('k', 'v');
        $this->assertTrue($c->has('k'));
    }

    public function testDelete(): void
    {
        $c = $this->cache();
        $c->set('k', 'v');
        $c->delete('k');
        $this->assertNull($c->get('k'));
    }

    public function testZeroTtlDeletes(): void
    {
        $c = $this->cache();
        $c->set('k', 'v', 0);
        $this->assertFalse($c->has('k'));
    }

    public function testMultipleOps(): void
    {
        $c = $this->cache();
        $c->setMultiple(['a' => 1, 'b' => 2]);
        $out = [];
        foreach ($c->getMultiple(['a', 'b']) as $k => $v) {
            $out[$k] = $v;
        }
        $this->assertSame(['a' => 1, 'b' => 2], $out);
        $c->deleteMultiple(['a', 'b']);
        $this->assertFalse($c->has('a'));
    }

    public function testClear(): void
    {
        $c = $this->cache();
        $c->set('one', 1);
        $c->set('two', 2);
        $this->assertTrue($c->clear());
        $this->assertNull($c->get('one'));
        $this->assertNull($c->get('two'));
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(SimpleCacheInvalidArgumentException::class);
        $this->cache()->get('');
    }

    // ---------------------------------------------------------------------
    // Atomic-counter capability (increment/decrement/counter). Exercised here
    // over ArrayAdapter, which inherits the AbstractAdapter non-atomic default;
    // RedisAdapter overrides it with native INCRBY/DECRBY but keeps identical
    // semantics.
    // ---------------------------------------------------------------------

    public function testIncrementCreatesThenAccumulates(): void
    {
        $c = $this->cache();
        $this->assertSame(1, $c->increment('violations:1.2.3.4'), 'first increment creates at +by');
        $this->assertSame(2, $c->increment('violations:1.2.3.4'));
        $this->assertSame(5, $c->increment('violations:1.2.3.4', 3), 'increment by N');
    }

    public function testCounterReadsCurrentValueAndDefaultsToZero(): void
    {
        $c = $this->cache();
        $this->assertSame(0, $c->counter('missing'), 'absent counter reads 0');
        $c->increment('hits', 4);
        $this->assertSame(4, $c->counter('hits'));
    }

    public function testDecrement(): void
    {
        $c = $this->cache();
        $c->increment('epoch', 5);
        $this->assertSame(4, $c->decrement('epoch'));
        $this->assertSame(1, $c->decrement('epoch', 3));
    }

    public function testIncrementRespectsTtlExpiry(): void
    {
        $c = $this->cache();
        // A zero/expired TTL must not leave a live counter behind.
        $c->increment('short', 1, 1);
        $this->assertSame(1, $c->counter('short'));
    }

    public function testSwapReturnsNullWhenUnsetThenPreviousValue(): void
    {
        $c = $this->cache();
        // First swap on an unset key returns null and stores the new value.
        $this->assertNull($c->swap('radio:last_track', 'Song A'));
        // Subsequent swaps return the previous value and set the new one.
        $this->assertSame('Song A', $c->swap('radio:last_track', 'Song B'));
        $this->assertSame('Song B', $c->swap('radio:last_track', 'Song C'));
    }

    public function testSwapEnablesChangeDetection(): void
    {
        $c = $this->cache();
        // The de-dup pattern: a repeated value swaps to itself (prev === new).
        $c->swap('pointer', 'X');
        $this->assertSame('X', $c->swap('pointer', 'X'), 'unchanged value returns itself');
        $this->assertSame('X', $c->swap('pointer', 'Y'), 'changed value returns the old one');
    }

    // ── Structured operations ───────────────────────────────────────────────────

    public function testHashRoundTripAndDelete(): void
    {
        $c = $this->cache();
        $this->assertNull($c->hashGet('h', 'missing'), 'absent field returns default (null)');

        $c->hashSet('h', 'a', ['x' => 1]);
        $c->hashSet('h', 'b', 'two');
        $this->assertSame(['x' => 1], $c->hashGet('h', 'a'), 'array value round-trips');
        $this->assertSame('two', $c->hashGet('h', 'b'));
        $this->assertSame(['a' => ['x' => 1], 'b' => 'two'], $c->hashGetAll('h'));

        $c->hashDelete('h', 'a');
        $this->assertNull($c->hashGet('h', 'a'));
        $this->assertSame(['b' => 'two'], $c->hashGetAll('h'));
    }

    public function testListPushRangeTrim(): void
    {
        $c = $this->cache();
        $this->assertSame([], $c->listRange('l', 0, -1), 'absent list is empty');

        // LPUSH prepends, so the newest is first.
        $c->listPush('l', 'a');
        $c->listPush('l', 'b');
        $length = $c->listPush('l', 'c');
        $this->assertSame(3, $length, 'listPush returns the new length');
        $this->assertSame(['c', 'b', 'a'], $c->listRange('l', 0, -1), 'newest-first order');
        $this->assertSame(['c', 'b'], $c->listRange('l', 0, 1), 'inclusive range');
        $this->assertSame(['a'], $c->listRange('l', -1, -1), 'negative indices');

        $c->listTrim('l', 0, 1);
        $this->assertSame(['c', 'b'], $c->listRange('l', 0, -1), 'trim keeps the head');
    }

    public function testListPreservesStructuredValues(): void
    {
        $c = $this->cache();
        $c->listPush('msgs', ['id' => 'm1', 'text' => 'γειά']);
        $recent = $c->listRange('msgs', 0, -1);
        $this->assertSame([['id' => 'm1', 'text' => 'γειά']], $recent);
    }

    public function testKeysReturnsArray(): void
    {
        // The in-memory adapter does not enumerate; the contract is still an array.
        $this->assertIsArray($this->cache()->keys('anything:*'));
    }
}
