<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * Clearing one cache category costs the size of the category, not of the
 * database.
 *
 * ## What changed and why
 *
 * `RedisAdapter::clear($category)` used to delete by pattern —
 * `SCAN … MATCH prefix_category_*`. That reads as a narrow operation and is
 * not one: **`MATCH` filters what `SCAN` returns, not what it traverses.**
 * Every call walks the entire keyspace, so clearing one category costs exactly
 * what clearing all of them costs, and the cost grows with everything else
 * sharing the Redis database — sessions, rate limiters, another application.
 *
 * Measured across keyspace sizes, category held at 40 keys:
 *
 * | keyspace | `SCAN` + `MATCH` | `SMEMBERS` + `DEL` |
 * | --- | --- | --- |
 * | 1,000 | 0.6 ms | 0.29 ms |
 * | 10,000 | 1.2 ms | 0.70 ms |
 * | 100,000 | 15.8 ms | 0.27 ms |
 * | 500,000 | **128.7 ms** | **0.85 ms** |
 *
 * One is linear in the size of the database, the other is flat. `Model` clears
 * on every write, so the left column was the price of a save.
 *
 * ## What these tests pin
 *
 * The mechanism, structurally, rather than the timings — a timing assertion in
 * CI is a flake waiting to happen, and the numbers above are in the docblock
 * where they can be re-run by hand. What matters is that the clear reads the
 * category's own set, that an installation with no sets still gets its old keys
 * removed exactly once, and that a category name with an underscore survives
 * the round trip.
 *
 * Requires a live Redis, as the other `#[Group('redis')]` tests do: `REDIS_HOST`
 * or `pramnos_redis`, port 6379. Database **9** is used and flushed, to stay off
 * whatever the rest of the suite is doing.
 */
#[CoversClass(RedisAdapter::class)]
#[Group('redis')]
class RedisCategoryIndexTest extends TestCase
{
    private const DB     = 9;
    private const PREFIX = 'rcit_';

    private \Redis $redis;

    protected function setUp(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }

        $host  = getenv('REDIS_HOST') ?: 'pramnos_redis';
        $redis = new \Redis();

        try {
            if (!@$redis->connect($host, 6379, 1.0)) {
                $this->markTestSkipped('No Redis server at ' . $host . ':6379.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('No Redis server at ' . $host . ':6379.');
        }

        $redis->select(self::DB);
        $redis->flushDb();
        $this->redis = $redis;
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->flushDb();
        }
    }

    /** A connected adapter bound to the scratch database. */
    private function adapter(string $category = ''): RedisAdapter
    {
        $adapter = new RedisAdapter(
            getenv('REDIS_HOST') ?: 'pramnos_redis', 6379, self::DB, null, self::PREFIX
        );
        $adapter->connect();

        if ($category !== '') {
            $adapter->setCategory($category);
        }

        return $adapter;
    }

    /** The key an entry is stored under, as `Cache` composes them. */
    private function key(string $category, string $id): string
    {
        return self::PREFIX . $category . '_' . $id;
    }

    // ── The behaviour that has to hold ──────────────────────────────────────

    /**
     * Clearing a category removes its entries and leaves every other category
     * alone.
     *
     * The contract, unchanged by the redesign — asserted first because a faster
     * invalidation that invalidates the wrong things is not an improvement.
     */
    public function testClearingACategoryRemovesOnlyThatCategory(): void
    {
        // Arrange
        $mails = $this->adapter('mails');
        $mails->save($this->key('mails', 'a'), 'A', 600);
        $mails->save($this->key('mails', 'b'), 'B', 600);

        $users = $this->adapter('users');
        $users->save($this->key('users', 'c'), 'C', 600);

        // Act
        $this->assertTrue($mails->clear('mails'));

        // Assert
        $this->assertFalse($mails->load($this->key('mails', 'a'), 600));
        $this->assertFalse($mails->load($this->key('mails', 'b'), 600));
        $this->assertSame(
            'C', $users->load($this->key('users', 'c'), 600),
            'an unrelated category must survive'
        );
    }

    /**
     * Every saved key is recorded in its category's set.
     *
     * The mechanism itself. Without this the clear has nothing to read and
     * silently removes nothing — a cache that reports a successful
     * invalidation and keeps serving the old rows.
     */
    public function testSavingAKeyRecordsItInTheCategoryIndex(): void
    {
        // Arrange
        $adapter = $this->adapter('mails');

        // Act
        $adapter->save($this->key('mails', 'a'), 'A', 600);
        $adapter->save($this->key('mails', 'b'), 'B', 600);

        // Assert
        $members = $this->redis->sMembers(self::PREFIX . 'catindex:mails');
        sort($members);
        $this->assertSame(
            [$this->key('mails', 'a'), $this->key('mails', 'b')], $members
        );
    }

    /**
     * The clear really does read the index rather than search for keys.
     *
     * Proved without a stopwatch: a key that matches the old pattern exactly,
     * but was written behind the adapter's back and so is in no set, survives
     * the clear. Under the pattern scan it would have been deleted.
     *
     * This is also the honest statement of what the redesign gives up — a key
     * put into the category's namespace by something other than this adapter is
     * no longer swept — and it is why the crossover below exists.
     *
     * The reversal that reddens this: restore `deleteByPattern()` in
     * `clearCategory()`.
     */
    public function testTheClearReadsTheIndexRatherThanScanning(): void
    {
        // Arrange — one indexed key, and one written directly to Redis.
        $adapter = $this->adapter('mails');
        $adapter->save($this->key('mails', 'indexed'), 'A', 600);

        $stranger = $this->key('mails', 'written_behind_our_back');
        $this->redis->set($stranger, 'x');

        // Act
        $adapter->clear('mails');

        // Assert
        $this->assertFalse($adapter->load($this->key('mails', 'indexed'), 600));
        $this->assertSame(
            1, $this->redis->exists($stranger),
            'a key that is in no index is not searched for'
        );
    }

    // ── Crossing over from an installation with no indexes ──────────────────

    /**
     * The first clear on an existing installation still finds the old keys.
     *
     * The upgrade case, and the one that would fail silently: keys written
     * before this code existed are in no set, so an index-only clear would
     * leave them — including entries saved with **no expiry**, which would then
     * serve stale rows for ever.
     *
     * With no marker present, the first clear falls back to the old scan. Every
     * pre-upgrade key goes, once.
     *
     * The reversal that reddens this: delete the `exists($marker)` branch in
     * `clearCategory()`.
     */
    public function testTheFirstClearSweepsKeysWrittenBeforeTheIndexExisted(): void
    {
        // Arrange — exactly the state an upgraded installation is in: keys in
        // the category's namespace, no set, no marker. One of them immortal.
        $this->redis->set($this->key('mails', 'legacy_a'), 'A');
        $this->redis->setex($this->key('mails', 'legacy_b'), 600, 'B');

        $adapter = $this->adapter('mails');

        // Act
        $this->assertTrue($adapter->clear('mails'));

        // Assert
        $this->assertSame(0, $this->redis->exists($this->key('mails', 'legacy_a')));
        $this->assertSame(0, $this->redis->exists($this->key('mails', 'legacy_b')));
    }

    /**
     * And it only does that once.
     *
     * The marker is what makes the crossover a one-off. Deciding by "is the set
     * empty?" instead would scan every time a category with nothing in it was
     * cleared — which is the common case, and the one a test suite hits
     * repeatedly.
     */
    public function testTheCrossoverScanHappensOnlyOnce(): void
    {
        // Arrange
        $this->redis->set($this->key('mails', 'legacy'), 'A');
        $adapter = $this->adapter('mails');
        $adapter->clear('mails');

        // Act — a second stranger appears after the crossover.
        $stranger = $this->key('mails', 'after_crossover');
        $this->redis->set($stranger, 'x');
        $adapter->clear('mails');

        // Assert — untouched, because the second clear did not scan.
        $this->assertSame(1, $this->redis->exists($stranger));
    }

    /**
     * A category that has never been written is marked on its first clear, and
     * does not scan again.
     */
    public function testAnEmptyCategoryIsMarkedOnFirstClear(): void
    {
        // Arrange
        $adapter = $this->adapter('never_used');

        // Act
        $this->assertTrue($adapter->clear('never_used'));

        // Assert
        $this->assertSame(
            1, $this->redis->exists(self::PREFIX . 'catindexed:never_used')
        );
    }

    // ── Names ──────────────────────────────────────────────────────────────

    /**
     * The index and the clear agree on how a category name is spelled.
     *
     * These were two copies of the same sanitising expression. A name the two
     * spelled differently would be indexed under one key and cleared under
     * another — invalidation that reports success and does nothing, which is
     * exactly the failure this class exists to make impossible.
     *
     * An underscore is the case worth naming: `\w` includes it, so `user_list`
     * must survive intact. Category names have already gone wrong here once.
     *
     * @return array<string,array{string}>
     */
    public static function categoryNameProvider(): array
    {
        return [
            'plain'          => ['mails'],
            'an underscore'  => ['user_list'],
            'two underscores' => ['api_rate_limits'],
            'a hyphen'       => ['user-tokens'],
            'a space'        => ['user list'],
            'punctuation'    => ['user.list!'],
            'mixed case'     => ['UserList'],
        ];
    }

    #[DataProvider('categoryNameProvider')]
    public function testACategoryIsClearedUnderTheNameItWasIndexedWith(
        string $category
    ): void {
        // Arrange
        $adapter = $this->adapter($category);
        $key     = self::PREFIX . preg_replace('/[^\w\-]/', '', str_replace(' ', '_', $category)) . '_row';
        $adapter->save($key, 'value', 600);
        $this->assertSame('value', $adapter->load($key, 600), 'the fixture must be readable');

        // Act
        $adapter->clear($category);

        // Assert
        $this->assertFalse($adapter->load($key, 600));
    }

    // ── The set's own lifetime ──────────────────────────────────────────────

    /**
     * The set outlives the newest entry it holds.
     *
     * It has to: a set that expired while its members were still alive would
     * leave them unclearable — the same stale-for-ever failure as the upgrade
     * case, arriving later and without an upgrade to blame.
     *
     * Each save pushes the expiry forward, so the set is always at least an hour
     * past the newest entry.
     */
    public function testTheIndexOutlivesItsNewestEntry(): void
    {
        // Arrange
        $adapter = $this->adapter('mails');

        // Act
        $adapter->save($this->key('mails', 'a'), 'A', 600);

        // Assert
        $ttl = $this->redis->ttl(self::PREFIX . 'catindex:mails');
        $this->assertGreaterThan(
            600, $ttl, 'the set must outlive the entry it holds'
        );
    }

    /**
     * An entry with no expiry makes the set permanent.
     *
     * Otherwise the set would expire and leave behind a key that never does —
     * exactly the immortal-stale-key case, created by the fix meant to prevent
     * it.
     */
    public function testAnEntryWithNoExpiryMakesTheIndexPermanent(): void
    {
        // Arrange
        $adapter = $this->adapter('mails');
        $adapter->save($this->key('mails', 'a'), 'A', 600);
        $this->assertGreaterThan(0, $this->redis->ttl(self::PREFIX . 'catindex:mails'));

        // Act — an immortal entry joins the category.
        $adapter->save($this->key('mails', 'forever'), 'B', 0);

        // Assert — -1 is "exists, no expiry".
        $this->assertSame(-1, $this->redis->ttl(self::PREFIX . 'catindex:mails'));
    }

    /**
     * The index is removed with the category it indexes.
     *
     * A set left behind after a clear would hand the next clear a list of keys
     * that are already gone — harmless, but it would grow for ever in a
     * category that is cleared often.
     */
    public function testClearingRemovesTheIndexItself(): void
    {
        // Arrange
        $adapter = $this->adapter('mails');
        $adapter->save($this->key('mails', 'a'), 'A', 600);

        // Act
        $adapter->clear('mails');

        // Assert
        $this->assertSame(0, $this->redis->exists(self::PREFIX . 'catindex:mails'));
    }

    // ── Clearing everything ────────────────────────────────────────────────

    /**
     * A full clear takes the indexes and markers with it.
     *
     * They are prefixed like everything else, so the prefix-scoped clear that
     * `cache:clear` runs removes them too. If it did not, a category cleared
     * this way would keep a marker saying "indexed" while its set was gone, and
     * its surviving keys would never be found again.
     */
    public function testClearingEverythingRemovesTheIndexesToo(): void
    {
        // Arrange
        $adapter = $this->adapter('mails');
        $adapter->save($this->key('mails', 'a'), 'A', 600);

        // Act
        $adapter->clear('');

        // Assert
        $this->assertSame(0, $this->redis->exists(self::PREFIX . 'catindex:mails'));
        $this->assertSame(0, $this->redis->exists(self::PREFIX . 'catindexed:mails'));
        $this->assertSame(0, $this->redis->exists($this->key('mails', 'a')));
    }

    /**
     * A save with no category set writes no index.
     *
     * `FlatCache` uses the adapter directly and owns its own key namespace; it
     * has no categories, and must not accumulate sets for one.
     */
    public function testASaveWithNoCategoryWritesNoIndex(): void
    {
        // Arrange
        $adapter = $this->adapter();

        // Act
        $adapter->save(self::PREFIX . 'flat:key', 'value', 600);

        // Assert — one key, the entry itself.
        $this->assertSame(
            [self::PREFIX . 'flat:key'], $this->redis->keys(self::PREFIX . '*')
        );
    }
}
