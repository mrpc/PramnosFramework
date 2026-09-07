<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * `cacheflush($category)` has to remove what the SQL cache wrote under it.
 *
 * Reported from production twice — a `sitename` corrected by a migration that kept
 * announcing the old value on the live admin until `cache:clear` was run by hand, and a
 * settings screen that saved correctly and redrew itself from the pre-save snapshot, so a
 * field that had just been filled in came back empty.
 *
 * Measured there across three separate processes, so nothing was an in-memory artefact:
 *
 * ```
 * 1. write a new setting, then force-read it   → value-1788743887
 * 2. read it through the cached path           → []            ← empty
 * 3. force-read it again                       → value-1788743887
 * ```
 *
 * The cached path did not return a *stale* value; it returned **nothing** — the bulk blob
 * was built before the key existed and the write did not evict it. The five-minute TTL is
 * what made it hard to see: it heals itself, so it reads as an intermittent bug in the
 * screen rather than an eviction that never happened.
 *
 * These tests drive `Cache` exactly as `Database::cacheStore()` and
 * `Database::cacheflush()` drive it — same category, same `sql` extension, same
 * assignment of `prefix` after `getInstance()` — because the shape of the key is the whole
 * question.
 */
#[CoversClass(Cache::class)]
#[CoversClass(RedisAdapter::class)]
class SettingsCacheEvictionTest extends TestCase
{
    private const PREFIX = 'evictprobe';

    protected function setUp(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not installed here.');
        }

        $probe = new \Redis();

        if (!@$probe->connect('redis', 6379, 1)) {
            $this->markTestSkipped('No redis to connect to.');
        }

        $probe->close();
        \Pramnos\Redis\ConnectionManager::resetPool();
    }

    protected function tearDown(): void
    {
        // Leave nothing behind for the next test in this database.
        $redis = new \Redis();

        if (@$redis->connect('redis', 6379, 1)) {
            $keys = $redis->keys(self::PREFIX . '*');

            if (is_array($keys) && $keys !== []) {
                $redis->del($keys);
            }

            $redis->close();
        }

        \Pramnos\Redis\ConnectionManager::resetPool();
        parent::tearDown();
    }

    /**
     * A cache built the way `Database::cacheStore()` builds one.
     *
     * Not `Cache::getInstance()`: that is a per-category singleton shared with the rest of
     * the suite, and what is under test is the key shape, which depends on the prefix being
     * assigned after construction — exactly as the database does it.
     */
    private function cache(string $category): Cache
    {
        $cache = new Cache($category, 'sql', 'redis', array(
            'hostname' => 'redis',
            'port'     => 6379,
            'database' => 0,
            'password' => null,
            'prefix'   => self::PREFIX,
        ));

        $cache->category = $category;
        $cache->prefix   = self::PREFIX;

        if (!$cache->caching || $cache->getAdapter() === null) {
            $this->markTestSkipped('This build could not reach redis through Cache.');
        }

        return $cache;
    }

    /**
     * The whole of the report: a write, a flush, and a read that must miss.
     *
     * `md5($query)` is the id the SQL cache uses, and `settings` the category
     * `Settings::invalidateCache()` flushes. If this passes and production still fails, the
     * cause is not the key shape.
     */
    public function testFlushingACategoryRemovesWhatTheSqlCacheWrote(): void
    {
        // Arrange — as Database::cacheStore() does it
        $cache = $this->cache('settings');
        $id    = md5('SELECT setting, value FROM settings');

        $this->assertTrue($cache->save('the-bulk-blob', $id), 'the fixture could not write');
        $this->assertSame('the-bulk-blob', $cache->load($id), 'the fixture could not read back');

        // Act — as Database::cacheflush('settings') does it
        $cache->clear('settings');

        // Assert
        $this->assertFalse(
            $cache->load($id),
            'the settings cache survived its own invalidation'
        );
    }

    /**
     * A category flush does not walk the keyspace. Not once, not per save.
     *
     * The urgent half of the second filing, and the assertion that matters most here.
     * `clear($category)` used to fall back to `SCAN … MATCH` whenever a per-category marker
     * was absent — and `Model::save()` clears a **key** as though it were a category, so a
     * marker was never there and the fallback ran on **every save**. At 288,706 keys and
     * `COUNT 200` that is about 1,443 round trips, twice per save, and each fallback then
     * wrote a permanent marker for a category with no members: 95% of that keyspace was
     * markers left by sweeps that found nothing, each one making the next sweep slower.
     *
     * What it looked like on the workers: `do_poll` in 148 of 150 samples at 1.6% CPU,
     * twenty redis clients concurrently in `SCAN`, and throughput **falling** as workers
     * were added.
     *
     * Measured with redis's own `commandstats`, because the cost is not visible from PHP —
     * which is exactly why it took four hours and four wrong answers to find.
     */
    public function testFlushingACategoryDoesNotScanTheKeyspace(): void
    {
        // Arrange
        $cache = $this->cache('settings');
        $cache->save('the-bulk-blob', md5('SELECT setting, value FROM settings'));

        $before = $this->scanCalls();

        // Act — a category flush, and the key-as-category one `Model::save()` also does
        $cache->clear('settings');
        $cache->clear('4711-users');

        // Assert
        $this->assertSame(
            $before,
            $this->scanCalls(),
            'invalidating a category walked the keyspace'
        );
    }

    /**
     * And nothing writes a permanent marker key any more.
     *
     * The other half of the same defect: the sweep wrote `catindexed:<name>` with no TTL
     * every time it ran, including for a category that never had a member. One installation
     * reached 275,000 of them. Asserted after the flush, because that was where they were
     * written.
     */
    public function testNoPermanentMarkerKeysAreLeftBehind(): void
    {
        // Arrange
        $cache = $this->cache('settings');
        $cache->save('the-bulk-blob', md5('SELECT setting, value FROM settings'));

        // Act
        $cache->clear('settings');
        $cache->clear('4711-users');

        // Assert
        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $markers = (array) $redis->keys(self::PREFIX . 'catindexed:*');
        $redis->close();

        $this->assertSame(array(), $markers, 'a marker with no TTL was written');
    }

    /**
     * The dashboard listing reads the same index the flush does.
     *
     * They were two answers to one question and they disagreed: the listing matched
     * `<prefix><category>_*`, and `Cache` writes `<prefix>_<category>_<id>…` — the underscore
     * after the prefix was missing, so every category read as empty on the screen while the
     * flush was emptying it. One source of truth now.
     */
    public function testTheListingAndTheFlushAgreeOnWhatIsInACategory(): void
    {
        // Arrange
        $cache = $this->cache('settings');
        $id    = md5('SELECT setting, value FROM settings');
        $cache->save('the-bulk-blob', $id);

        $adapter = $cache->getAdapter();

        // Act + Assert — listed while it is there
        $listed = $adapter->getAllItems('settings');
        $this->assertNotSame(array(), $listed, 'a stored entry was not listed');

        // and gone from the listing once flushed
        $cache->clear('settings');

        $this->assertSame(array(), $adapter->getAllItems('settings'));
    }

    /**
     * How many `SCAN` calls this redis has served, from its own counters.
     */
    private function scanCalls(): int
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379, 1);
        $stats = (array) $redis->info('commandstats');
        $redis->close();

        $calls = 0;

        foreach ($stats as $command => $line) {
            if ($command === 'cmdstat_scan' || $command === 'cmdstat_keys') {
                preg_match('/calls=(\d+)/', is_array($line) ? implode(',', $line) : (string) $line, $m);
                $calls += (int) ($m[1] ?? 0);
            }
        }

        return $calls;
    }

    /**
     * Flushing one category leaves another alone.
     *
     * The control, and it is the reason the pattern cannot simply be widened to the whole
     * prefix: `Model` clears on every write, so a category flush that took the neighbours
     * with it would empty the cache on every save.
     */
    public function testFlushingOneCategoryLeavesTheOthers(): void
    {
        // Arrange
        $settings = $this->cache('settings');
        $rows     = $this->cache('rows');

        $settings->save('mine', 'probe-id');
        $rows->save('theirs', 'probe-id');

        // Act
        $settings->clear('settings');

        // Assert
        $this->assertFalse($settings->load('probe-id'));
        $this->assertSame('theirs', $rows->load('probe-id'), 'a neighbour category was cleared');
    }
}
