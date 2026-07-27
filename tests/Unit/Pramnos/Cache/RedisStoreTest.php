<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\RedisStore;
use Pramnos\Cache\SimpleCacheInvalidArgumentException;

/**
 * In-memory stand-in for the phpredis surface RedisStore uses, so the store's
 * key/prefix/TTL/serialisation behaviour is testable without a live server.
 */
class FakeCacheRedis
{
    /** @var array<string,string> */
    public array $store = [];
    /** @var array<string,int> */
    public array $ttls = [];

    public function get(string $k): string|false
    {
        return $this->store[$k] ?? false;
    }

    public function set(string $k, string $v): bool
    {
        $this->store[$k] = $v;
        return true;
    }

    public function setex(string $k, int $ttl, string $v): bool
    {
        $this->store[$k] = $v;
        $this->ttls[$k] = $ttl;
        return true;
    }

    public function del(array|string $k): int
    {
        $n = 0;
        foreach ((array) $k as $kk) {
            if (isset($this->store[$kk])) {
                unset($this->store[$kk]);
                $n++;
            }
        }
        return $n;
    }

    public function exists(string $k): int
    {
        return isset($this->store[$k]) ? 1 : 0;
    }

    /** @return string[] */
    public function keys(string $pattern): array
    {
        $p = rtrim($pattern, '*');
        return array_values(array_filter(array_keys($this->store), fn ($k) => str_starts_with($k, $p)));
    }
}

/**
 * Unit tests for the flat-key PSR-16 Redis store.
 */
#[CoversClass(RedisStore::class)]
class RedisStoreTest extends TestCase
{
    private FakeCacheRedis $redis;

    private function store(string $prefix = 'app:'): RedisStore
    {
        $this->redis = new FakeCacheRedis();
        return new RedisStore(['prefix' => $prefix], fn () => $this->redis);
    }

    /**
     * Values round-trip through serialize under the verbatim prefixed key,
     * including colon-namespaced keys (which PSR-16 SimpleCache would reject).
     */
    public function testSetGetRoundTripWithColonKey(): void
    {
        $c = $this->store('app:');
        $c->set('chat:messages:hash', ['a' => 1, 'b' => 'x']);

        $this->assertArrayHasKey('app:chat:messages:hash', $this->redis->store);
        $this->assertSame(['a' => 1, 'b' => 'x'], $c->get('chat:messages:hash'));
    }

    public function testGetMissingReturnsDefault(): void
    {
        $c = $this->store();
        $this->assertSame('fallback', $c->get('nope', 'fallback'));
    }

    public function testFalseValueRoundTripsNotConfusedWithMiss(): void
    {
        $c = $this->store();
        $c->set('flag', false);
        $this->assertFalse($c->get('flag', 'default-should-not-win'));
    }

    /**
     * An integer TTL becomes a SETEX; ttl<=0 is treated as immediately expired.
     */
    public function testTtlUsesSetexAndZeroTtlDeletes(): void
    {
        $c = $this->store('app:');
        $c->set('k', 'v', 60);
        $this->assertSame(60, $this->redis->ttls['app:k'] ?? null);

        $c->set('k2', 'v', 0);
        $this->assertArrayNotHasKey('app:k2', $this->redis->store);
    }

    public function testDeleteAndHas(): void
    {
        $c = $this->store();
        $c->set('k', 'v');
        $this->assertTrue($c->has('k'));
        $c->delete('k');
        $this->assertFalse($c->has('k'));
    }

    public function testMultipleOps(): void
    {
        $c = $this->store();
        $c->setMultiple(['a' => 1, 'b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], iterator_to_array((function () use ($c) {
            foreach ($c->getMultiple(['a', 'b']) as $k => $v) {
                yield $k => $v;
            }
        })()));
        $c->deleteMultiple(['a', 'b']);
        $this->assertFalse($c->has('a'));
    }

    /**
     * clear() wipes only keys under the prefix — never the whole database.
     */
    public function testClearIsPrefixScoped(): void
    {
        $c = $this->store('app:');
        $c->set('one', 1);
        $c->set('two', 2);
        $this->redis->store['other:key'] = serialize('keep'); // different prefix

        $this->assertTrue($c->clear());
        $this->assertArrayNotHasKey('app:one', $this->redis->store);
        $this->assertArrayNotHasKey('app:two', $this->redis->store);
        $this->assertArrayHasKey('other:key', $this->redis->store, 'keys outside the prefix survive');
    }

    public function testClearWithoutPrefixRefuses(): void
    {
        $c = $this->store('');
        $this->assertFalse($c->clear(), 'refuse to clear with no prefix scope');
    }

    public function testEmptyKeyThrows(): void
    {
        $c = $this->store();
        $this->expectException(SimpleCacheInvalidArgumentException::class);
        $c->get('');
    }
}
