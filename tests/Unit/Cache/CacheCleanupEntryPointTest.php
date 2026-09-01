<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\AbstractAdapter;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Adapter\FileAdapter;
use Pramnos\Cache\AdapterInterface;
use Pramnos\Cache\Cache;

/**
 * Asking for the expired-entry sweep, now.
 *
 * `FileAdapter` already sweeps: it walks the tree, deletes what has expired, and prunes the empty
 * directories a cache write leaves behind — 3,064 of them on one container before that was fixed.
 * But it did so from `shouldCollectGarbage()`, which fires on about **one call in a hundred** and
 * never at all under `PRAMNOS_TESTING`. That is the right shape for amortising the cost over
 * ordinary traffic and no guarantee whatsoever, and the method was `protected`, so an installation
 * with a scheduled housekeeping task had nothing to call.
 *
 * `flushEverything()` is not the same thing and not a substitute: it removes what is still valid,
 * which on a warm cache is an expensive thing to do on a schedule.
 *
 * Three properties, and the second is the one that makes this testable at all:
 *
 *   - a sweep removes what has expired and **leaves what has not**;
 *   - an explicit sweep runs **regardless of the sampling and of `PRAMNOS_TESTING`** — the guard
 *     belongs to the automatic path, and a deterministic entry point that could be silenced by an
 *     environment flag would be no entry point;
 *   - the count comes back, because the caller is a cron task with somewhere to log it and
 *     "how much was there to reclaim" is what decides whether the schedule is frequent enough.
 */
#[CoversClass(FileAdapter::class)]
#[CoversClass(AbstractAdapter::class)]
#[CoversClass(Cache::class)]
class CacheCleanupEntryPointTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-cleanup-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->dir);
    }

    /**
     * The sweep removes what has expired and keeps what has not.
     *
     * Both halves in one test, because a sweep that removed everything would pass a test that only
     * checked the expired entry was gone — and that is exactly the failure the legacy sweeper had
     * when pointed at this adapter's files: it read the stored TTL as an object property where this
     * one stores an array, got `null` every time, and `filemtime < time() - null` is true of every
     * file on disk.
     */
    public function testItRemovesTheExpiredAndKeepsTheRest(): void
    {
        // Arrange
        $adapter = new FileAdapter($this->dir, '');
        $adapter->save('gone', 'value', 1);
        $adapter->save('kept', 'value', 86400);
        $adapter->save('forever', 'value', 0);
        $this->ageEverythingBy(60);

        // Act
        $removed = $adapter->cleanup();

        // Assert
        $this->assertSame(1, $removed, 'the count does not match what went');
        $this->assertFalse($adapter->load('gone', 0), 'the expired entry is still readable');
        $this->assertSame('value', $adapter->load('kept', 0), 'a live entry was swept');
        $this->assertSame(
            'value',
            $adapter->load('forever', 0),
            'an entry saved to never expire was swept — the worst possible reading of "expired"'
        );
    }

    /**
     * An explicit sweep runs even under `PRAMNOS_TESTING`.
     *
     * The guard that silences the automatic sweep belongs to the automatic sweep: it stops a test
     * suite paying for a random one-in-a-hundred walk, which is a good reason and not a reason to
     * refuse a caller that asked. A deterministic entry point that an environment flag could
     * silence would be no entry point — and this very test could not exist.
     */
    public function testAnExplicitSweepIgnoresTheSamplingAndTheTestingFlag(): void
    {
        // Arrange
        $this->assertTrue(
            defined('PRAMNOS_TESTING'),
            'precondition: the suite defines the flag the automatic sweep respects'
        );

        $adapter = new class ($this->dir) extends FileAdapter {
            public int $sampled = 0;

            public function __construct(string $dir)
            {
                parent::__construct($dir, '');
            }

            protected function shouldCollectGarbage(): bool
            {
                $this->sampled++;

                return parent::shouldCollectGarbage();
            }
        };

        $adapter->save('gone', 'value', 1);
        $this->ageEverythingBy(60);

        // Act
        $removed = $adapter->cleanup();

        // Assert
        $this->assertSame(1, $removed, 'the explicit sweep did nothing');
        $this->assertSame(
            0,
            $adapter->sampled,
            'the explicit sweep went through the sampling, which can refuse it'
        );
    }

    /**
     * It prunes the empty directories too, which is half of what it is for.
     *
     * Every cache write creates a directory for its category, and nothing removed them: 3,064
     * empty directories on one container, each one walked again by the next sweep, which is how a
     * clear over an empty cache came to cost 1,358 ms.
     */
    public function testItPrunesTheDirectoriesTheEntriesLeaveBehind(): void
    {
        // Arrange
        $adapter = new FileAdapter($this->dir, '');
        $adapter->setCategory('somecategory');
        $adapter->save('somecategory_thing', 'value', 1);
        $this->assertDirectoryExists($this->dir . '/somecategory', 'precondition: a category dir');
        $this->ageEverythingBy(60);

        // Act
        $adapter->cleanup();

        // Assert
        $this->assertDirectoryDoesNotExist(
            $this->dir . '/somecategory',
            'the directory the swept entry lived in was left behind'
        );
        $this->assertDirectoryExists($this->dir, 'the cache root is configuration, not an entry');
    }

    /** A sweep over an empty cache removes nothing and says so. */
    public function testAnEmptyCacheSweepsToZero(): void
    {
        // Act & Assert
        $this->assertSame(0, (new FileAdapter($this->dir, ''))->cleanup());
    }

    /**
     * A backend that expires its own entries has nothing to sweep, and says 0.
     *
     * Redis, Memcached and the in-process array all drop an entry when its TTL passes, so there is
     * no accumulation to reclaim. Answering 0 rather than raising is what lets a scheduled task
     * call this without knowing which adapter the installation configured.
     */
    public function testABackendThatExpiresItsOwnEntriesAnswersZero(): void
    {
        // Arrange
        $adapter = new ArrayAdapter();
        $adapter->save('k', 'v', 1);

        // Act & Assert
        $this->assertSame(0, $adapter->cleanup());
        $this->assertSame('v', $adapter->load('k', 0), 'the no-op sweep removed something');
    }

    // ── Through the facade, which is what a scheduled task calls ──────────────

    /**
     * `Cache::cleanup()` forwards to whichever adapter is configured.
     *
     * The entry point a housekeeping task actually has a handle on: it holds a `Cache`, not an
     * adapter, and `getAdapter()` was no help while the sweep was protected.
     */
    public function testTheFacadeForwardsToTheAdapter(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'array');

        // Act & Assert — the array store expires its own entries, so zero is the right answer.
        $this->assertSame(0, $cache->cleanup());
    }

    /**
     * An adapter that predates this method is asked nothing, and the answer is 0.
     *
     * `AdapterInterface` deliberately does **not** declare `cleanup()`: an application with its own
     * adapter implements that interface, and adding a method to it would break every one of them on
     * upgrade. So the facade asks whether the adapter has it. An installation with such an adapter
     * gets a scheduled task that does nothing rather than a fatal error on the first run.
     */
    public function testAnAdapterWithoutTheMethodIsNotAsked(): void
    {
        // Arrange — the shape a third-party adapter has: the interface, and nothing more.
        $cache   = new Cache(null, null, 'array');
        $adapter = new class implements AdapterInterface {
            public function connect() { return true; }
            public function load($key, $timeout = null) { return false; }
            public function save($key, $data, $timeout = 3600) { return true; }
            public function delete($key) { return true; }
            public function clear($category = '') { return true; }
            public function flushEverything() { return false; }
            public function getCategories($prefix = '') { return []; }
            public function getStats() { return []; }
            public function categoryHash($category, $prefix = '', $reset = false) { return ''; }
            public function getAllItems($category = '', $limit = 100) { return []; }
            public function setPrefix($prefix) { return $this; }
            public function setCategory($category) { return $this; }
            public function generateKey($id, $category = '', $extension = 'cache') { return $id; }
            public function test() { return true; }
        };

        $property = new \ReflectionProperty(Cache::class, 'adapter');
        $property->setValue($cache, $adapter);

        // Act & Assert — a fatal here is the failure this guard exists to prevent.
        $this->assertSame(0, $cache->cleanup());
    }

    /**
     * Move every entry's mtime back, which is what time passing looks like to the sweep.
     *
     * The sweep measures age from the file's mtime, the same as `load()` — so a sweep and a read
     * agree about which entries are stale.
     */
    private function ageEverythingBy(int $seconds): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile()) {
                touch($file->getPathname(), time() - $seconds);
            }
        }
    }
}
