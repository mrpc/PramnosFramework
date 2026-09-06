<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;

/**
 * A cache must not be able to kill the process it serves.
 *
 * Every rung of the adapter ladder constructs a class, and any throw from one
 * escaped `initializeAdapter()`, its caller, and the request. On a production
 * installation that arrived as
 * `Error: Class "Pramnos\Cache\Adapter\MemcachedAdapter" not found`, from a redis
 * blip taking the fallback path, inside `queue:process --daemon`.
 *
 * **What it cost was not the cache.** The fatal killed the worker mid-task, so the
 * task row stayed `processing` behind a lock nobody held and nothing picked it up
 * again; systemd restarted the worker to do it again five seconds later. Two
 * hours: 101 tasks orphaned, 34 failures recorded — the worker died before it
 * could write its own failure, so the ledger under-reported by a factor of three
 * and the queue read as slightly unwell rather than lossy.
 *
 * A caching layer whose fault domain is larger than the thing it accelerates is
 * not a caching layer.
 */
#[CoversClass(Cache::class)]
class CacheNeverFatalTest extends TestCase
{
    /**
     * A cache whose adapter construction throws, exactly as the missing class did.
     */
    private function exploding(string $method = 'redis'): Cache
    {
        return new class ($method) extends Cache {
            public function __construct(string $method)
            {
                parent::__construct(null, null, $method);
            }

            protected function buildAdapter($method)
            {
                throw new \Error(
                    'Class "Pramnos\Cache\Adapter\MemcachedAdapter" not found'
                );
            }
        };
    }

    /**
     * Construction survives an adapter that cannot be built.
     *
     * The assertion is that this line is reached at all. Before, the `\Error` left the
     * constructor, the caller and the request.
     */
    public function testAnAdapterThatThrowsDoesNotTakeTheCallerWithIt(): void
    {
        // Act
        $cache = $this->exploding();

        // Assert
        $this->assertInstanceOf(Cache::class, $cache);
    }

    /**
     * What is left behind is a cache that is off, and calling it is safe.
     *
     * Off rather than in-memory, and the first version of this fix got that wrong: an
     * array store is per *instance*, so `Database::cacheflush()` clears one object's
     * memory while the data sits in another's. A cached value a flush cannot reach is
     * worse than a miss — and one of this suite's own tests caught it, by finding the
     * previous test's row in an answer that had to be empty.
     *
     * `load()` and `save()` return false on `caching = false`, so callers take the path
     * they take when nothing is cached. Asserted here because "degrades" would otherwise
     * be free to mean "returns something that then explodes one line later".
     */
    public function testWhatIsLeftBehindIsSafeToCall(): void
    {
        // Arrange
        $cache = $this->exploding();

        // Act
        $cache->category = 'tests';
        $saved = $cache->save('value-under-test', 'probe');
        $read  = $cache->load('probe', 'tests');

        // Assert
        $this->assertFalse($cache->caching);
        $this->assertNull($cache->getAdapter());
        $this->assertFalse($saved);
        $this->assertFalse($read, 'a disabled cache answered with something');
    }

    /**
     * Every method fails this way, not only the one that was reported.
     *
     * The reported path was redis, and a fix that guarded only redis would leave the
     * same fatal one rung down — where it is harder to see, because nobody is looking
     * at memcache.
     */
    public function testEveryMethodDegradesRatherThanThrows(): void
    {
        foreach (array('redis', 'memcached', 'memcache', 'file', 'nonsense') as $method) {
            // Act
            $cache = $this->exploding($method);

            // Assert
            $this->assertFalse($cache->caching, $method . ' did not degrade');
            $this->assertNull($cache->getAdapter(), $method . ' kept an adapter');
        }
    }

    /**
     * The reason reaches the log rather than being swallowed.
     *
     * Degrading quietly would replace a loud failure with a silent one, and a cache that
     * is secretly not the cache is how a worker comes to read a different world from the
     * requests beside it. The exception's class and message are both carried, because
     * "the adapter could not be built" sends somebody to the wrong file.
     */
    public function testTheReasonIsRecorded(): void
    {
        // Arrange
        $recorded = array();

        $cache = new class ($recorded) extends Cache {
            /** @param array<int, string> $recorded */
            public function __construct(private array &$recorded)
            {
                parent::__construct(null, null, 'redis');
            }

            protected function buildAdapter($method)
            {
                throw new \Error('Class "…\MemcachedAdapter" not found');
            }

            protected function logAdapterFallback($from, $to, $reason)
            {
                $this->recorded[] = $from . '>' . $to . ': ' . $reason;
            }
        };

        // Assert
        $this->assertNotSame(array(), $recorded, 'the degrade was silent');
        $this->assertStringContainsString('Error', $recorded[0]);
        $this->assertStringContainsString('MemcachedAdapter', $recorded[0]);
        $this->assertInstanceOf(Cache::class, $cache);
    }
}
