<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Http\TokenBucket;
use Pramnos\Tests\Support\InMemoryBucketCache;

/**
 * A token bucket: a burst at once, then a steady rate.
 *
 * An application's limit is "N requests per window, bursts of B". The bucket is what makes the
 * two numbers mean something together: B requests may arrive at once, and after that the client
 * is held to N per window. Tested on both stores: Redis, where the take is one Lua script, and
 * any other cache, where it is a read and a write.
 */
#[CoversClass(TokenBucket::class)]
class TokenBucketTest extends TestCase
{
    /** A cache backed by the suite's Redis, or a skip when there is none. */
    private function redisCache(): Cache
    {
        $adapter = new \Pramnos\Cache\Adapter\RedisAdapter((string) (getenv('CACHE_HOST') ?: 'redis'), 6379);
        try {
            $adapter->connect();
            if (!$adapter->getConnection() instanceof \Redis) {
                $this->markTestSkipped('No Redis to test the atomic path against.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('No Redis to test the atomic path against: ' . $e->getMessage());
        }

        return new class ($adapter) extends InMemoryBucketCache {
            /** @param \Pramnos\Cache\Adapter\RedisAdapter $redis */
            public function __construct(private $redis)
            {
            }

            public function getAdapter()
            {
                return $this->redis;
            }
        };
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stores(): array
    {
        return ['redis' => ['redis'], 'any other cache' => ['memory']];
    }

    /** The bucket under test, on one store. */
    private function bucket(string $store): TokenBucket
    {
        return new TokenBucket($store === 'redis' ? $this->redisCache() : new InMemoryBucketCache());
    }

    /**
     * The burst goes through at once; the next request waits for a token, and is told how long.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function testTheBurstPassesAndTheNextWaits(string $store): void
    {
        // Arrange — three at once, then one an hour
        $bucket = $this->bucket($store);
        $key    = 'test:' . bin2hex(random_bytes(6));

        // Act
        $takes = [];
        for ($i = 0; $i < 4; $i++) {
            $takes[] = $bucket->take($key, 3, 1, 3600);
        }

        // Assert
        $this->assertSame([true, true, true, false], array_column($takes, 'allowed'));
        $this->assertSame([2, 1, 0], array_column(array_slice($takes, 0, 3), 'remaining'));
        // An empty bucket refills one token an hour.
        $this->assertGreaterThan(3500, $takes[3]['retry_after']);
        $this->assertLessThanOrEqual(3600, $takes[3]['retry_after']);
    }

    /**
     * Tokens come back at the rate: at one a second, a second later there is one again.
     *
     * One a second, not faster: the check that the token is spent runs two takes apart, and at
     * ten a second a pause of a tenth of a second between them — a loaded suite — had already
     * refilled it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function testTokensComeBackAtTheRate(string $store): void
    {
        // Arrange
        $bucket = $this->bucket($store);
        $key    = 'test:' . bin2hex(random_bytes(6));
        $bucket->take($key, 1, 1, 1);
        $this->assertFalse($bucket->take($key, 1, 1, 1)['allowed'], 'the one token is spent');

        // Act
        self::wait(1.05);
        $later = $bucket->take($key, 1, 1, 1);

        // Assert
        $this->assertTrue($later['allowed']);
    }

    /**
     * A clock that steps back — NTP correcting it, a virtual machine resumed — is no time passed,
     * not negative time: the bucket keeps its tokens. Read as negative, an hour back drained a
     * full bucket far below zero, and its client was refused for the hour.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function testAClockSteppedBackTakesNoTokensAway(string $store): void
    {
        // Arrange — one token of three taken, then the last take recorded an hour in the future
        $cache  = $store === 'redis' ? $this->redisCache() : new InMemoryBucketCache();
        $bucket = new TokenBucket($cache);
        $key    = 'test:' . bin2hex(random_bytes(6));
        $bucket->take($key, 3, 1, 1);
        $full   = (defined('sURL') ? md5((string) sURL) . ':' : '') . $key;
        $future = microtime(true) + 3600;
        if ($store === 'redis') {
            $cache->getAdapter()->getConnection()->hSet('tokenbucket:' . $full, 'at', sprintf('%.6F', $future));
        } else {
            $id                  = 'tokenbucket-' . md5($full);
            $state               = json_decode($cache->values[$id], true);
            $cache->values[$id]  = (string) json_encode(['tokens' => $state['tokens'], 'at' => $future]);
        }

        // Act
        $next = $bucket->take($key, 3, 1, 1);

        // Assert
        $this->assertTrue($next['allowed'], 'the two tokens left are still there');
        $this->assertSame(1, $next['remaining']);
    }

    /**
     * Two keys are two buckets.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function testEachKeyHasItsOwnBucket(string $store): void
    {
        // Arrange
        $bucket = $this->bucket($store);
        $suffix = bin2hex(random_bytes(6));
        $bucket->take('a:' . $suffix, 1, 1, 3600);

        // Act
        $other = $bucket->take('b:' . $suffix, 1, 1, 3600);

        // Assert
        $this->assertTrue($other['allowed']);
        $this->assertFalse($bucket->take('a:' . $suffix, 1, 1, 3600)['allowed']);
    }

    /**
     * A Redis that does not answer is no Redis: the bucket is kept the other way, and still limits.
     */
    public function testAnUnreachableRedisFallsBackToTheCache(): void
    {
        // Arrange — a Redis adapter pointed at a port nothing listens on
        $adapter = new \Pramnos\Cache\Adapter\RedisAdapter('127.0.0.1', 1);
        $cache   = new class ($adapter) extends InMemoryBucketCache {
            /** @param \Pramnos\Cache\Adapter\RedisAdapter $redis */
            public function __construct(private $redis)
            {
            }

            public function getAdapter()
            {
                return $this->redis;
            }
        };
        $bucket = new TokenBucket($cache);

        // Act
        $first  = $bucket->take('down', 1, 1, 3600);
        $second = $bucket->take('down', 1, 1, 3600);

        // Assert
        $this->assertTrue($first['allowed']);
        $this->assertFalse($second['allowed'], 'still limited, in the cache');
        $this->assertNotSame([], $cache->values);
    }

    /**
     * A burst of 0 is a bucket of one: no burst, only the rate.
     */
    public function testAZeroBurstIsStillOneRequest(): void
    {
        // Act
        $take = $this->bucket('memory')->take('zero', 0, 100, 3600);

        // Assert
        $this->assertTrue($take['allowed']);
        $this->assertSame(0, $take['remaining']);
    }

    /**
     * The refill holds whatever the `precision` ini setting is. phpredis writes a float into a
     * script's arguments by that setting, and at a low one the time reached Redis rounded to whole
     * seconds, so a token due a tenth of a second later did not come back within the same second —
     * this test's sibling failed that way under a full run.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function testTheRefillDoesNotDependOnTheIniPrecision(string $store): void
    {
        // Arrange
        $bucket   = $this->bucket($store);
        $key      = 'test:' . bin2hex(random_bytes(6));
        $previous = ini_get('precision');
        ini_set('precision', '8');

        try {
            $bucket->take($key, 1, 10, 1);

            // Act
            self::wait(0.15);
            $later = $bucket->take($key, 1, 10, 1);

            // Assert
            $this->assertTrue($later['allowed'], 'a token came back after a tenth of a second');
        } finally {
            ini_set('precision', (string) $previous);
        }
    }

    /**
     * Wait at least `$seconds`.
     *
     * Not one `usleep()`: a signal interrupts it early — the suite has workers that install
     * handlers with async signals on — and a refill test that slept 40ms of its 150 failed under a
     * full run and passed alone.
     */
    private static function wait(float $seconds): void
    {
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            usleep(10000);
        }
    }
}
