<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\AbstractAdapter;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Adapter\FileAdapter;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * The hash, list and counter operations must mean the same thing on every adapter.
 *
 * `AbstractAdapter` carries non-atomic defaults for the whole Redis-shaped API — `hashSet()`,
 * `listPush()`, `listTrim()`, `increment()`, `swap()`, `expire()` — implemented by keeping the
 * structure under one key through `load()`/`save()`. `RedisAdapter` overrides all of them with
 * native commands. So there are two implementations of one contract, application code is written
 * against whichever adapter the developer runs locally, and **45 of `AbstractAdapter`'s 105
 * statements had never executed**.
 *
 * They disagreed. Two faults, in opposite directions, both on the File adapter, and both found by
 * running these methods for the first time:
 *
 *   - **A permanent hash was readable for one hour.** `hashGet()` and `listRange()` read with
 *     `load()`'s default `$timeout` of 3600, meaning nothing by it, and `FileAdapter::load()`
 *     treated the reader's argument as the expiry. A structure saved with no TTL vanished an hour
 *     after it was written.
 *   - **A one-second counter never expired.** `counter()` reads with `$timeout = 0`, `0 > 0` is
 *     false, so no expiry was checked at all. On the File adapter a rate-limit window never
 *     closed — which is the direction that matters, because that is the caller this API has.
 *
 * `FileAdapter::save()` had recorded the TTL all along; `load()` ignored it. It is authoritative
 * now, with the reader's `$timeout` kept as the additional maximum age `Cache::load()` has always
 * meant it to be.
 *
 * Every adapter, not every database: this is the cache layer, and the equivalent of covering all
 * four SQL backends here is covering all three stores that implement the contract. The Redis rows
 * skip when no server is reachable, as the other Redis tests in this directory do.
 */
#[CoversClass(AbstractAdapter::class)]
#[CoversClass(FileAdapter::class)]
#[CoversClass(RedisAdapter::class)]
class StructuredOperationParityTest extends TestCase
{
    private const REDIS_DB = 11;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-parity-' . bin2hex(random_bytes(6));
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

    /** @return array<string, array{0: string}> */
    public static function adapters(): array
    {
        return ['array' => ['array'], 'file' => ['file'], 'redis' => ['redis']];
    }

    /**
     * One adapter of the named kind, or a skip when its backend is not here.
     *
     * Built per test rather than shared: the File adapter writes into this test's own directory,
     * and the Redis one flushes a scratch database, so a shared instance would leak state between
     * the rows of a data provider.
     */
    private function adapter(string $kind): AbstractAdapter
    {
        if ($kind === 'array') {
            return new ArrayAdapter();
        }

        if ($kind === 'file') {
            return new FileAdapter($this->dir, '');
        }

        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }

        $host    = getenv('REDIS_HOST') ?: 'pramnos_redis';
        $adapter = new RedisAdapter($host, 6379, self::REDIS_DB, null, 'sop_');

        if (!$adapter->connect()) {
            $this->markTestSkipped('No Redis server at ' . $host . ':6379.');
        }

        $adapter->flushEverything();

        return $adapter;
    }

    // ── Hashes ────────────────────────────────────────────────────────────────

    /**
     * A hash round-trips field by field, and a missing field is the default.
     *
     * `null` for an absent field rather than `false`: the value stored *could* be `false`, and a
     * caller cannot tell "the field says no" from "there is no field" if both answer the same
     * thing.
     *
     * @param string $kind Which adapter
     */
    #[DataProvider('adapters')]
    public function testAHashRoundTripsAndAMissingFieldIsTheDefault(string $kind): void
    {
        // Arrange
        $adapter = $this->adapter($kind);

        // Act
        $adapter->hashSet('h', 'name', 'Yannis');
        $adapter->hashSet('h', 'count', 3);
        $adapter->hashSet('h', 'off', false);

        // Assert
        $this->assertSame('Yannis', $adapter->hashGet('h', 'name'));
        $this->assertSame(3, (int) $adapter->hashGet('h', 'count'));
        $this->assertNull($adapter->hashGet('h', 'absent'));
        $this->assertSame('fallback', $adapter->hashGet('h', 'absent', 'fallback'));
        $this->assertSame(
            'fallback',
            $adapter->hashGet('no-such-hash', 'name', 'fallback'),
            'a hash that was never written is not the same as an empty one'
        );
    }

    /** A field can be removed, and removing one that is not there is not an error. */
    #[DataProvider('adapters')]
    public function testAHashFieldCanBeRemoved(string $kind): void
    {
        // Arrange
        $adapter = $this->adapter($kind);
        $adapter->hashSet('h', 'keep', 'a');
        $adapter->hashSet('h', 'drop', 'b');

        // Act
        $adapter->hashDelete('h', 'drop');
        $adapter->hashDelete('h', 'never-existed');
        $adapter->hashDelete('no-such-hash', 'drop');

        // Assert
        $all = $adapter->hashGetAll('h');
        $this->assertArrayHasKey('keep', $all);
        $this->assertArrayNotHasKey('drop', $all);
        $this->assertSame([], $adapter->hashGetAll('no-such-hash'));
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    /**
     * `listPush()` prepends and returns the new length — Redis `LPUSH`.
     *
     * The order is the contract, not an implementation detail: a caller keeping "the last ten
     * things that happened" pushes and trims to `[0, 9]`, and an implementation that appended
     * would trim away the ten it just recorded and keep the ten oldest.
     */
    #[DataProvider('adapters')]
    public function testListPushPrependsAndReturnsTheNewLength(string $kind): void
    {
        // Arrange
        $adapter = $this->adapter($kind);

        // Act
        $first  = $adapter->listPush('l', 'oldest');
        $second = $adapter->listPush('l', 'newest');

        // Assert
        $this->assertSame(1, (int) $first);
        $this->assertSame(2, (int) $second);
        $this->assertSame(['newest', 'oldest'], $adapter->listRange('l', 0, -1));
    }

    /**
     * `listRange()` and `listTrim()` are inclusive and take negative indices.
     *
     * `LRANGE key 0 -1` is "everything" in every Redis example ever written, and `[0, 9]` is
     * "the ten most recent". An exclusive `$stop`, or one that treated `-1` as an error, would
     * be off by one everywhere and silently.
     */
    #[DataProvider('adapters')]
    public function testRangesAreInclusiveAndAcceptNegativeIndices(string $kind): void
    {
        // Arrange
        $adapter = $this->adapter($kind);
        foreach (['e', 'd', 'c', 'b', 'a'] as $value) {
            $adapter->listPush('l', $value);        // → a b c d e
        }

        // Act & Assert
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $adapter->listRange('l', 0, -1));
        $this->assertSame(['a', 'b'], $adapter->listRange('l', 0, 1));
        $this->assertSame(['d', 'e'], $adapter->listRange('l', -2, -1));
        $this->assertSame(['c'], $adapter->listRange('l', 2, 2));
        $this->assertSame([], $adapter->listRange('l', 3, 1), 'a backwards range is empty');
        $this->assertSame([], $adapter->listRange('l', 9, 20), 'a range past the end is empty');
        $this->assertSame([], $adapter->listRange('no-such-list', 0, -1));

        $adapter->listTrim('l', 0, 2);
        $this->assertSame(['a', 'b', 'c'], $adapter->listRange('l', 0, -1));

        $adapter->listTrim('no-such-list', 0, 2);   // must not create one
        $this->assertSame([], $adapter->listRange('no-such-list', 0, -1));
    }

    /** A range asking for more than the list holds returns what there is. */
    #[DataProvider('adapters')]
    public function testARangeWiderThanTheListReturnsWhatThereIs(string $kind): void
    {
        // Arrange
        $adapter = $this->adapter($kind);
        $adapter->listPush('l', 'only');

        // Act & Assert
        $this->assertSame(['only'], $adapter->listRange('l', 0, 99));
    }

    // ── Counters ──────────────────────────────────────────────────────────────

    /**
     * Counting up and down, from nothing, returns the new total each time.
     *
     * A counter that has never been written reads as `0` rather than absent, because every caller
     * of this API — a rate limiter, an attempt count — wants the arithmetic to work on the first
     * call without a "create it first" step.
     */
    #[DataProvider('adapters')]
    public function testCountersStartAtZeroAndReturnTheNewTotal(string $kind): void
    {
        // Arrange
        $adapter = $this->adapter($kind);

        // Act & Assert
        $this->assertSame(0, (int) $adapter->counter('c'), 'an unwritten counter is not zero');
        $this->assertSame(1, (int) $adapter->increment('c'));
        $this->assertSame(4, (int) $adapter->increment('c', 3));
        $this->assertSame(4, (int) $adapter->counter('c'));
        $this->assertSame(3, (int) $adapter->decrement('c'));
        $this->assertSame(0, (int) $adapter->decrement('c', 3));
        $this->assertSame(
            -2,
            (int) $adapter->decrement('c', 2),
            'a counter that goes below zero must say so rather than clamping'
        );
    }

    /**
     * A TTL takes effect, on every adapter, from one wait.
     *
     * Three claims that all need the clock to move, so they share a single `sleep()` rather than
     * taking one each: a counter given a window forgets, a hash given a TTL expires, and
     * `expire()` puts a TTL on something already stored. Twelve assertions across three adapters
     * for two seconds of suite time; one test per claim per adapter cost twelve seconds for the
     * same facts.
     *
     * The counter is the one that matters most. On the File adapter `counter()` read with a
     * timeout of `0`, the expiry check was `$timeout > 0`, and so nothing was checked at all: the
     * count from the first request stayed readable for as long as the file survived, and a
     * rate-limit window never closed. That is a security failure rather than a correctness one,
     * which is why it is asserted on every adapter rather than on whichever is convenient.
     *
     * A clock seam in three adapters would buy the two seconds back. It would also mean the thing
     * under test is no longer the clock.
     */
    public function testATtlTakesEffectOnEveryAdapter(): void
    {
        // Arrange — arm every adapter before waiting once.
        $adapters = [];
        foreach (array_keys(self::adapters()) as $kind) {
            $adapter = $this->adapter($kind);

            $adapter->increment('window', 1, 1);
            $adapter->hashSet('h', 'field', 'value', 1);
            $adapter->save('here', 'value', 0);
            $adapter->expire('here', 1);
            $adapter->expire('not-here', 1);

            $this->assertSame(1, (int) $adapter->counter('window'), $kind . ': it did not count');
            $adapters[$kind] = $adapter;
        }

        // Act
        sleep(2);

        // Assert
        foreach ($adapters as $kind => $adapter) {
            $this->assertSame(
                0,
                (int) $adapter->counter('window'),
                $kind . ': the counter outlived its TTL, so a rate-limit window never closes'
            );
            $this->assertNull(
                $adapter->hashGet('h', 'field'),
                $kind . ': a hash outlived the TTL it was given'
            );
            $this->assertFalse($adapter->load('here', 0), $kind . ': expire() did not take');
            $this->assertFalse($adapter->load('not-here', 0), $kind . ': expire() created a key');
        }
    }

    /**
     * A structure saved with no TTL is still there later.
     *
     * The other direction of the same fault, and the one that reads as data loss: a hash written
     * to live indefinitely became unreadable an hour after it was written, because the reader's
     * default timeout was being taken for the entry's expiry. Asserted by reaching past the clock
     * — the entry's recorded write time is moved into the past, which is what an hour looks like
     * to the code that decides.
     */
    public function testAPermanentStructureOutlivesTheReadersDefaultTimeout(): void
    {
        // Arrange — the File adapter is where this was wrong; the others never had the argument.
        $adapter = new FileAdapter($this->dir, '');
        $adapter->hashSet('h', 'field', 'value');
        $adapter->listPush('l', 'entry');

        // Act — every entry now claims to have been written two hours ago.
        $this->rewriteAges($this->dir, 7200);

        // Assert
        $this->assertSame('value', $adapter->hashGet('h', 'field'), 'a permanent hash expired');
        $this->assertSame(['entry'], $adapter->listRange('l', 0, -1), 'a permanent list expired');
    }

    /**
     * A reader may still demand something fresher than the entry's own TTL.
     *
     * `Cache::load($id, $category, $timeout)` has always meant "give me this only if it is
     * younger than $timeout", and making the stored TTL authoritative must not take that away —
     * a caller that needs five-minute-old data from an entry saved for a day still gets to say so.
     */
    public function testAReaderCanStillAskForSomethingFresherThanTheStoredTtl(): void
    {
        // Arrange
        $adapter = new FileAdapter($this->dir, '');
        $adapter->save('daily', 'value', 86400);

        // Act
        $this->rewriteAges($this->dir, 600);

        // Assert
        $this->assertSame('value', $adapter->load('daily', 0), 'the entry is within its own TTL');
        $this->assertFalse(
            $adapter->load('daily', 60),
            'a reader asking for something under a minute old was given ten-minute-old data'
        );
    }

    // ── swap and expire ───────────────────────────────────────────────────────

    // ── What a bare adapter promises ──────────────────────────────────────────

    /**
     * An adapter that implements nothing says so loudly rather than lying.
     *
     * `load()`, `save()` and `delete()` raise `BadMethodCallException` in the base class instead
     * of returning `false`/`null`. A silent `null` from an unimplemented adapter is a cache that
     * appears to work and never stores anything, which is the failure that takes a day to find.
     */
    public function testABareAdapterRefusesRatherThanPretending(): void
    {
        // Arrange
        $bare = new class ('') extends AbstractAdapter {
        };

        // Act & Assert
        foreach (['load' => ['k'], 'save' => ['k', 'v'], 'delete' => ['k']] as $method => $args) {
            try {
                $bare->$method(...$args);
                $this->fail($method . '() answered instead of refusing');
            } catch (\BadMethodCallException $exception) {
                $this->assertStringContainsString($method, $exception->getMessage());
            }
        }
    }

    /**
     * With caching off, `load()` and `test()` answer without reaching the backend.
     *
     * `load()` returning `null` before the unimplemented-method exception is the check that
     * matters: a disabled cache must be a no-op for every adapter, including one that could not
     * have served the request anyway.
     */
    public function testADisabledAdapterIsANoOpRatherThanAnError(): void
    {
        // Arrange
        $bare = new class ('') extends AbstractAdapter {
        };
        $bare->setCaching(false);

        // Act & Assert
        $this->assertFalse($bare->isCachingEnabled());
        $this->assertNull($bare->load('anything'));
        $this->assertFalse($bare->test(), 'a disabled cache reported a working round trip');
    }

    /**
     * `test()` is a real round trip, and reports the step that failed.
     *
     * It is what the health check and the cache screen call, so "working" has to mean written,
     * read back identical, and removed — not "the connection opened".
     */
    #[DataProvider('adapters')]
    public function testTestIsARealRoundTrip(string $kind): void
    {
        // Arrange
        $adapter = $this->adapter($kind);

        // Act & Assert
        $this->assertTrue($adapter->test(), 'a working adapter failed its own round trip');
    }

    /** An adapter whose save silently fails must not report a successful round trip. */
    public function testTestFailsWhenNothingIsActuallyStored(): void
    {
        // Arrange — the shape of a misconfigured backend: writes accepted, nothing kept.
        $blackhole = new class ('') extends AbstractAdapter {
            public function save($key, $data, $timeout = 3600)
            {
                return true;
            }

            public function load($key, $timeout = null)
            {
                return null;
            }

            public function delete($key)
            {
                return true;
            }
        };

        // Act & Assert
        $this->assertFalse($blackhole->test(), 'a cache that stores nothing reported working');
    }

    /**
     * The two capability questions default to "no", which is what makes them worth asking.
     *
     * `keys()` answers `[]` both for "nothing matched" and for "I cannot look", and every adapter
     * inherits a working-looking `increment()` whether or not it is atomic. A caller that asked
     * `method_exists()` would be told the File adapter counts atomically and can enumerate.
     */
    public function testCapabilitiesDefaultToNo(): void
    {
        // Arrange
        $bare = new class ('') extends AbstractAdapter {
        };

        // Act & Assert
        $this->assertFalse($bare->supportsAtomicCounter());
        $this->assertFalse($bare->supportsKeyEnumeration());
        $this->assertSame([], $bare->keys('*'));
        $this->assertFalse($bare->clear(), 'the default clear() claimed to have cleared');
        $this->assertFalse(
            $bare->flushEverything(),
            'the default flushEverything() claimed to have flushed a backend it cannot reach'
        );
        $this->assertSame([], $bare->getCategories());
        $this->assertSame([], $bare->getAllItems());
        $this->assertSame('unknown', $bare->getStats()['method'] ?? null);
        $this->assertTrue($bare->connect(), 'an adapter with nothing to connect to is connected');
    }

    // ── Key composition ───────────────────────────────────────────────────────

    /**
     * A key is prefix, category and extension, with the parts this class owns cleaned.
     *
     * The **id is not** cleaned here, and that is deliberate rather than an oversight: on a
     * server-backed adapter any byte is a legal key, and narrowing what an id may contain would
     * change every existing key for no gain. What a slash in an id means is a question for the
     * adapter that turns a key into a path — see
     * {@see testAKeyWithASeparatorCannotEscapeTheCacheDirectory}.
     */
    public function testAKeyIsComposedAndSanitised(): void
    {
        // Arrange
        $bare = new class ('my site') extends AbstractAdapter {
        };

        // Act
        $key = $bare->generateKey('42', 'user list', 'cache');

        // Assert
        $this->assertSame('my_site_user_list_42.cache', $key);
        $this->assertSame('my site', $bare->getPrefix());
        $this->assertSame(
            '42.cache',
            (new class ('') extends AbstractAdapter {
            })->generateKey('42'),
            'with no prefix and no category a key is just the id and the extension'
        );
    }

    /** Setting the prefix and the category is chainable, because callers chain them. */
    public function testPrefixAndCategoryAreChainable(): void
    {
        // Arrange
        $bare = new class ('') extends AbstractAdapter {
        };

        // Act
        $returned = $bare->setPrefix('site')->setCategory('users')->setCaching(true);

        // Assert
        $this->assertSame($bare, $returned);
        $this->assertSame('site', $bare->getPrefix());
        $this->assertSame('site_users_1.cache', $bare->generateKey('1', 'users'));
    }

    /** An empty category hashes to nothing, so it adds no segment to the key. */
    public function testAnEmptyCategoryAddsNothing(): void
    {
        // Arrange
        $bare = new class ('') extends AbstractAdapter {
        };

        // Act & Assert
        $this->assertSame('', $bare->categoryHash(''));
        $this->assertSame('a_b', $bare->categoryHash('a b'));
        $this->assertSame('ab', $bare->categoryHash('a/b'));
    }

    /**
     * A key containing a separator or a parent reference stays inside the cache directory.
     *
     * `getFilePath()` concatenated the key onto the directory unchanged, and neither
     * `generateKey()` nor `Cache::_generateCacheName()` cleans the id — both interpolate it as
     * given. So `$cache->load('user_' . $somethingFromTheRequest)` put whatever that was into a
     * path: `a/b` writes silently outside its own category directory, where `clear('a')` will
     * never find it again, and `../../x` writes outside the cache directory altogether.
     *
     * Fixed in the adapter rather than in the key builder, because this is the only place a key
     * becomes a path — on Redis a slash is just a character.
     */
    public function testAKeyWithASeparatorCannotEscapeTheCacheDirectory(): void
    {
        // Arrange — a cache directory with a sibling the adapter must not be able to reach.
        $root = $this->dir . DIRECTORY_SEPARATOR . 'root';
        $cache = $root . DIRECTORY_SEPARATOR . 'cache';
        mkdir($cache, 0777, true);
        $adapter = new FileAdapter($cache, '');

        // Act
        $this->assertTrue($adapter->save('../../escaped', 'gotcha', 0));
        $this->assertTrue($adapter->save('cat/sub/entry', 'sneaky', 0));

        // Assert — everything written is under the cache directory.
        $written = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile()) {
                $written[] = (string) $file->getRealPath();
            }
        }

        $this->assertNotSame([], $written, 'nothing was written at all');
        foreach ($written as $path) {
            $this->assertStringStartsWith(
                (string) realpath($cache),
                $path,
                'a cache key wrote outside the cache directory: ' . $path
            );
        }

        // And the entry is still readable by the key it was written with.
        $this->assertSame('gotcha', $adapter->load('../../escaped', 0));
        $this->assertSame('sneaky', $adapter->load('cat/sub/entry', 0));
        $this->assertTrue($adapter->delete('../../escaped'), 'the same key does not delete it');
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * Move every cache entry under $dir back in time by $seconds.
     *
     * Both the recorded write time inside the entry and the file's mtime, because the two answer
     * different questions — the entry's own TTL is measured from the first, and a reader's
     * maximum age from whichever is available.
     */
    private function rewriteAges(string $dir, int $seconds): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $entry = @unserialize((string) file_get_contents($file->getPathname()));

            if (is_array($entry) && isset($entry['time'])) {
                $entry['time'] = (int) $entry['time'] - $seconds;
                file_put_contents($file->getPathname(), serialize($entry));
            }

            touch($file->getPathname(), time() - $seconds);
        }
    }
}
