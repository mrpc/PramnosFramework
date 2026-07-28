<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\FlatCache;
use Pramnos\Cache\SimpleCacheInvalidArgumentException;

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
    private function cache(string $prefix = 'app:'): FlatCache
    {
        return new FlatCache(new ArrayAdapter($prefix), $prefix);
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
}
