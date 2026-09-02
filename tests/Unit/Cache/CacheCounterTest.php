<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;

/**
 * `Cache::increment()` — the front door to an atomic counter.
 *
 * Six statements, never executed, in front of the operation rate limits and replay guards are
 * built on. Its docblock states the one thing a caller must not get wrong, and it is worth pinning
 * because nothing in the type system says it: **`false` is not zero.** A caller that treats the
 * answer as a number reads a broken cache as "no requests yet", which is a rate limiter that lets
 * everything through at exactly the moment it cannot count.
 *
 * Two guards produce that `false`: caching turned off, and an adapter that cannot count. Neither is
 * an error — a file cache genuinely cannot do this, and a read-modify-write would lose increments
 * under concurrency, which for a limiter is the worst possible failure because a flood is
 * concurrent by definition.
 */
#[CoversClass(Cache::class)]
class CacheCounterTest extends TestCase
{
    /** A cache wired to a given adapter, sharing nothing with the process-wide instances. */
    private function cacheWith(?object $adapter, bool $caching = true): Cache
    {
        $cache = new Cache();
        (new \ReflectionProperty(Cache::class, 'adapter'))->setValue($cache, $adapter);
        (new \ReflectionProperty(Cache::class, 'caching'))->setValue($cache, $caching);

        return $cache;
    }

    /**
     * A counting adapter is asked for one, with the caller's TTL.
     *
     * The amount is always `1` and the TTL is the caller's — the third argument, which is the one
     * a wrapper gets wrong. A TTL passed as the amount would increment by ninety and expire
     * immediately.
     */
    public function testACountingAdapterIsAskedForOneWithTheCallersTtl(): void
    {
        // Arrange
        $adapter = new class {
            /** @var list<array{0: string, 1: int, 2: int}> */
            public array $calls = [];

            public function supportsAtomicCounter(): bool
            {
                return true;
            }

            public function increment($key, $by = 1, $ttl = null, $sliding = false)
            {
                $this->calls[] = [(string) $key, (int) $by, (int) $ttl];

                return 7;
            }
        };

        // Act
        $result = $this->cacheWith($adapter)->increment('hits', 90);

        // Assert
        $this->assertSame(7, $result);
        $this->assertCount(1, $adapter->calls);
        $this->assertSame(1, $adapter->calls[0][1], 'the amount should always be one');
        $this->assertSame(90, $adapter->calls[0][2], 'the TTL did not reach the adapter');
    }

    /**
     * The key handed to the adapter is the generated cache name, not the raw id.
     *
     * The name carries the prefix and category, which is what keeps two installations sharing a
     * Redis from counting into each other's keys — a rate limit one of them would then hit for
     * traffic it never received.
     */
    public function testTheAdapterGetsTheGeneratedNameRatherThanTheRawId(): void
    {
        // Arrange
        $adapter = new class {
            public string $key = '';

            public function supportsAtomicCounter(): bool
            {
                return true;
            }

            public function increment($key, $by = 1, $ttl = null, $sliding = false)
            {
                $this->key = (string) $key;

                return 1;
            }
        };

        // Act
        $this->cacheWith($adapter)->increment('some-id', 60);

        // Assert
        $this->assertNotSame('', $adapter->key);
        $this->assertNotSame('some-id', $adapter->key, 'the raw id was used as the cache key');
    }

    /**
     * An adapter that cannot count atomically answers `false`, and is not called.
     *
     * Not `0`: a caller told zero would read a cache that cannot count as a counter that has seen
     * nothing. And the adapter is not asked at all, because a read-modify-write here loses
     * increments under exactly the concurrency a limiter exists for.
     */
    public function testAnAdapterThatCannotCountAnswersFalseWithoutBeingAsked(): void
    {
        // Arrange
        $adapter = new class {
            public bool $called = false;

            public function supportsAtomicCounter(): bool
            {
                return false;
            }

            public function increment($key, $by = 1, $ttl = null, $sliding = false)
            {
                $this->called = true;

                return 1;
            }
        };

        // Act
        $result = $this->cacheWith($adapter)->increment('hits', 90);

        // Assert
        $this->assertFalse($result, 'false, and never zero — zero reads as "no requests yet"');
        $this->assertFalse($adapter->called);
    }

    /**
     * With caching off, the counter answers `false` too.
     *
     * An installation that turned the cache off has not turned counting into something else: the
     * honest answer is "I cannot tell you", and it has to be distinguishable from a count.
     */
    public function testWithCachingOffTheAnswerIsFalse(): void
    {
        // Arrange
        $adapter = new class {
            public bool $called = false;

            public function supportsAtomicCounter(): bool
            {
                return true;
            }

            public function increment($key, $by = 1, $ttl = null, $sliding = false)
            {
                $this->called = true;

                return 5;
            }
        };

        // Act
        $result = $this->cacheWith($adapter, caching: false)->increment('hits', 90);

        // Assert
        $this->assertFalse($result);
        $this->assertFalse($adapter->called, 'the server was contacted with caching turned off');
    }

    /**
     * With no adapter at all, `false` rather than a fatal.
     *
     * The state before a cache is configured. `supportsAtomicCounter()` checks for null first, so
     * this never reaches a method call on nothing.
     */
    public function testWithNoAdapterTheAnswerIsFalse(): void
    {
        // Act + Assert
        $this->assertFalse($this->cacheWith(null)->increment('hits', 90));
    }

    /**
     * An adapter that fails mid-operation passes its `false` straight through.
     *
     * Which is why `false` has to mean "I cannot tell you" rather than a number: the same value
     * covers "cannot count", "not configured" and "the server went away", and a caller only needs
     * one branch for all three.
     */
    public function testAFailingAdapterPassesItsFalseThrough(): void
    {
        // Arrange
        $adapter = new class {
            public function supportsAtomicCounter(): bool
            {
                return true;
            }

            public function increment($key, $by = 1, $ttl = null, $sliding = false)
            {
                return false;
            }
        };

        // Act + Assert
        $this->assertFalse($this->cacheWith($adapter)->increment('hits', 90));
    }
}
