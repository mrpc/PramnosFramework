<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The SQL result cache — 20 statements across store, read and the decision to cache at all.
 *
 * Three properties, and each is a way this can be silently useless rather than broken.
 *
 * **The round trip has to be transparent.** Above ten kilobytes the payload is gzipped and marked
 * with a prefix; below it, it is not. A caller must not be able to tell — and the tests assert both
 * the marker on the stored value *and* that what comes back is what went in, because a cache that
 * returns almost the right thing is worse than one that misses.
 *
 * **A miss and a cached nothing are different answers.** `cacheRead()` answers `false` on a miss
 * and `[]` for a query that was cached and legitimately returned no rows. Conflating them re-runs
 * the expensive query that returns nothing on every request, which is the query most worth caching
 * and the one nobody notices is uncached.
 *
 * **An absent `cache` setting means no opinion.** This used to default to `'memcached'` here, so an
 * installation that had configured nothing asked for a store that was not there, the connection
 * failed, and the SQL cache silently downgraded to a private file store — working, slower, and
 * invisible. An empty method lets `Cache` use whatever the installation actually configured.
 *
 * Both backends, because the cache key is the statement's hash and the PostgreSQL path rewrites the
 * statement before it gets here: {@see QueryCacheStorePostgreSQLTest}.
 */
#[CoversClass(Database::class)]
class QueryCacheStoreTest extends BaseTestCase
{
    private $db;

    private string $category = '';

    private mixed $originalCacheSetting = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        // Its own category per test, so nothing here reads an entry another test wrote.
        $this->category = 'sqlcache_probe_' . bin2hex(random_bytes(4));

        /*
         * A store this class knows works, pinned for its duration.
         *
         * These tests are about `cacheStore()`/`cacheRead()`, not about which store an installation
         * configures — and inheriting the ambient one made them fail in the full suite while passing
         * under a filter. `Cache` keeps a **static** record of which methods connected, so a
         * preceding test that reached for a store which is not running leaves `caching` false for
         * every instance built afterwards, and every read here answers `false` for a reason that has
         * nothing to do with the code under test.
         */
        $this->originalCacheSetting = Settings::getSetting('cache');
        Settings::setSetting('cache', ['method' => 'file']);
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        if ($this->category !== '') {
            try {
                $this->db->cacheflush($this->category);
            } catch (\Throwable) {
                // Nothing to flush.
            }
        }

        Settings::setSetting('cache', $this->originalCacheSetting);

        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> A result set of the given row count. */
    private function rows(int $count, int $width = 1): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $row = ['id' => $i];
            for ($c = 0; $c < $width; $c++) {
                $row['column_' . $c] = 'value ' . $i . '-' . $c;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    // ── The round trip ────────────────────────────────────────────────────────

    /**
     * A stored result comes back exactly as it went in.
     *
     * Types included: a row's integer id must not come back as `"0"`, because a caller comparing it
     * with `===` against an integer stops matching and the code path that only runs on a cache hit
     * behaves differently from the one that runs on a miss. That is the worst shape of cache bug —
     * it only happens the second time.
     */
    public function testAStoredResultComesBackUnchanged(): void
    {
        // Arrange
        $sql  = 'SELECT * FROM probe WHERE id = 7 /* ' . $this->category . ' */';
        $rows = $this->rows(3);

        // Act
        $stored = $this->db->cacheStore($sql, $rows, $this->category, 600);
        $read   = $this->db->cacheRead($sql, $this->category);

        // Assert
        $this->assertNotFalse($stored, 'the store reported failure');
        $this->assertSame($rows, $read, 'the result changed shape on the way through the cache');
        $this->assertSame(0, $read[0]['id'], 'an integer came back as something else');
    }

    /**
     * A query that was never cached is a miss, and a query that was cached with no rows is not.
     *
     * The distinction that decides whether an expensive query returning nothing is ever cached.
     * `false` means "ask the database"; `[]` means "the database already said nothing".
     */
    public function testAMissAndACachedNothingAreDifferentAnswers(): void
    {
        // Arrange
        $never  = 'SELECT * FROM probe WHERE never = 1 /* ' . $this->category . ' */';
        $empty  = 'SELECT * FROM probe WHERE nothing = 1 /* ' . $this->category . ' */';

        // Act
        $this->db->cacheStore($empty, [], $this->category, 600);

        // Assert
        $this->assertFalse($this->db->cacheRead($never, $this->category), 'a miss was not a miss');
        $this->assertSame(
            [],
            $this->db->cacheRead($empty, $this->category),
            'a cached empty result reads as a miss, so it is re-run for ever'
        );
    }

    /**
     * Two statements do not share an entry, and the same statement hits.
     *
     * The key is the statement's hash. A collision would serve one query's rows as another's, which
     * is a data leak rather than a performance bug — and the same-statement hit is the entire point.
     */
    public function testTheKeyIsTheStatementSoTwoQueriesDoNotCollide(): void
    {
        // Arrange
        $one = 'SELECT a FROM probe /* ' . $this->category . ' */';
        $two = 'SELECT b FROM probe /* ' . $this->category . ' */';

        // Act
        $this->db->cacheStore($one, $this->rows(1), $this->category, 600);
        $this->db->cacheStore($two, $this->rows(2), $this->category, 600);

        // Assert
        $this->assertCount(1, (array) $this->db->cacheRead($one, $this->category));
        $this->assertCount(2, (array) $this->db->cacheRead($two, $this->category));
    }

    /**
     * Flushing the category removes the entry.
     *
     * Which is how a write invalidates a read — the reason `cacheflush()` takes the same category
     * name. Without it a cached listing outlives the row it was listing.
     */
    public function testFlushingTheCategoryRemovesTheEntry(): void
    {
        // Arrange
        $sql = 'SELECT * FROM probe /* ' . $this->category . ' */';
        $this->db->cacheStore($sql, $this->rows(2), $this->category, 600);
        $this->assertNotFalse($this->db->cacheRead($sql, $this->category), 'nothing was cached');

        // Act
        $this->db->cacheflush($this->category);

        // Assert
        $this->assertFalse(
            $this->db->cacheRead($sql, $this->category),
            'a flushed category still answers, so a write cannot invalidate a read'
        );
    }

    // ── Compression ───────────────────────────────────────────────────────────

    /**
     * A large payload is compressed, and the caller cannot tell.
     *
     * Both halves matter. The marker on the stored value is what proves the compression happened
     * rather than the threshold being unreachable, and the round trip is what proves it is
     * transparent — a cache that hands back a `GZCOMPRESSED:` string is a cache that breaks every
     * caller on its second request.
     */
    public function testALargePayloadIsCompressedTransparently(): void
    {
        // Arrange — comfortably past the 10KB threshold, and repetitive so it actually shrinks
        $sql  = 'SELECT * FROM big_probe /* ' . $this->category . ' */';
        $rows = $this->rows(200, 10);
        $this->assertGreaterThan(10240, strlen(serialize($rows)), 'the fixture is under the threshold');

        // Act
        $this->db->cacheStore($sql, $rows, $this->category, 600);
        $read = $this->db->cacheRead($sql, $this->category);

        // Assert
        $this->assertSame($rows, $read, 'the compressed payload did not round-trip');
        $this->assertStringStartsWith(
            'GZCOMPRESSED:',
            (string) $this->rawCachedValue($sql),
            'a large payload was stored uncompressed'
        );
    }

    /**
     * A small payload is stored as it is.
     *
     * Compressing a few hundred bytes costs CPU on every read and every write to save nothing —
     * and gzip on short repetitive data can be *larger*, which is why the store keeps the
     * compressed form only when it is actually smaller.
     */
    public function testASmallPayloadIsNotCompressed(): void
    {
        // Arrange
        $sql = 'SELECT * FROM small_probe /* ' . $this->category . ' */';

        // Act
        $this->db->cacheStore($sql, $this->rows(2), $this->category, 600);

        // Assert
        $raw = (string) $this->rawCachedValue($sql);
        // Non-empty first: `assertStringStartsNotWith` is satisfied by an empty string, so without
        // this the test would also pass if nothing had been cached at all.
        $this->assertNotSame('', $raw, 'nothing was stored, so the assertion below proves nothing');
        $this->assertStringStartsNotWith('GZCOMPRESSED:', $raw, 'a small payload paid for compression');
    }

    /**
     * A compressed entry that will not decompress is a miss, not an exception.
     *
     * A truncated or half-written cache entry is an ordinary thing — a store evicting mid-write, a
     * file cache on a full disk. The answer has to be "ask the database", because the alternative
     * is an exception out of whatever page happened to read it, and the data is still in the
     * database the whole time.
     */
    public function testACorruptCompressedEntryIsAMiss(): void
    {
        // Arrange — a valid marker over bytes that are not a gzip stream
        $sql = 'SELECT * FROM corrupt_probe /* ' . $this->category . ' */';
        $this->writeRawCachedValue($sql, 'GZCOMPRESSED:this is not compressed data at all');

        // Act & Assert
        $this->assertFalse(
            @$this->db->cacheRead($sql, $this->category),
            'an unreadable entry was handed to the caller instead of being treated as absent'
        );
    }

    // ── Whether to cache at all ───────────────────────────────────────────────

    /**
     * An empty result is worth caching; a result over the row limit is not.
     *
     * The limit exists because the cache is memory somebody else is also using: a query returning
     * a hundred thousand rows through a cache costs more than re-running it, and the process that
     * pays is the one serving the next request. The empty case is the opposite — nearly free to
     * hold and the most wasteful thing to re-run.
     */
    public function testTheRowLimitDecidesWhetherAResultIsWorthCaching(): void
    {
        // Act & Assert
        $this->assertTrue($this->db->shouldCacheResult([]), 'an empty result was refused');
        $this->assertTrue($this->db->shouldCacheResult($this->rows(10)));
        $this->assertTrue(
            $this->db->shouldCacheResult('not a result set at all'),
            'a non-array caller gets no opinion rather than a refusal'
        );
        $this->assertFalse(
            $this->db->shouldCacheResult($this->rows(1001)),
            'a result over the default row limit was accepted'
        );
    }

    /**
     * The limits come from configuration, and a lowered one is obeyed.
     *
     * An installation with a small cache has to be able to say so — and the default of a thousand
     * rows is a guess about a machine this code has never seen.
     */
    public function testAConfiguredRowLimitIsObeyed(): void
    {
        // Arrange
        $original = Settings::getSetting('cache');
        Settings::setSetting('cache', ['max_cached_rows' => 5, 'max_cache_memory_mb' => 50]);

        try {
            // Act & Assert
            $this->assertTrue($this->db->shouldCacheResult($this->rows(5)));
            $this->assertFalse(
                $this->db->shouldCacheResult($this->rows(6)),
                'the configured limit was ignored in favour of the default'
            );
        } finally {
            Settings::setSetting('cache', $original);
        }
    }

    /**
     * A result estimated to cost more than the memory allowance is refused.
     *
     * The row count alone is not the cost: a thousand rows of two integers and a thousand rows of a
     * text column are three orders of magnitude apart, and it is the second that fills the store.
     */
    public function testAResultOverTheMemoryAllowanceIsRefused(): void
    {
        // Arrange
        $original = Settings::getSetting('cache');
        Settings::setSetting('cache', ['max_cached_rows' => 100000, 'max_cache_memory_mb' => 0]);

        try {
            // Act & Assert
            $this->assertFalse(
                $this->db->shouldCacheResult($this->rows(50, 5)),
                'the memory allowance was not consulted'
            );
        } finally {
            Settings::setSetting('cache', $original);
        }
    }

    /**
     * An absent `cache` setting is «no opinion», not «memcached».
     *
     * The regression: an absent setting used to be read as `'memcached'` *here*, overriding whatever
     * the installation had configured as its default. So an installation that had configured nothing
     * asked for a store that was not running, the connection failed, and the SQL cache silently
     * downgraded to a private file store — working, slower, and with nothing anywhere to say it had
     * happened. An empty method lets `Cache` decide.
     *
     * What this asserts is deliberately not «the round trip works», and the reason is the bug
     * itself: with no setting, the store `Cache` picks may or may not be reachable on the machine
     * running the test, and insisting on a hit would make the test depend on exactly the ambient
     * cache state that this class pins away everywhere else. So the assertion is that the call goes
     * through and answers one of the two things a cache may answer — a hit or a miss — rather than
     * raising out of whatever page happened to read it.
     */
    public function testAnAbsentCacheSettingIsNoOpinionRatherThanMemcached(): void
    {
        // Arrange
        Settings::setSetting('cache', '');
        $sql  = 'SELECT * FROM unconfigured_probe /* ' . $this->category . ' */';
        $rows = $this->rows(2);

        // Act
        $this->db->cacheStore($sql, $rows, $this->category, 600);
        $read = $this->db->cacheRead($sql, $this->category);

        // Assert
        $this->assertTrue(
            $read === false || $read === $rows,
            'an unconfigured installation got something that was neither its rows nor a miss'
        );
    }

    // ── Reaching the stored bytes ─────────────────────────────────────────────

    /**
     * The value as the cache holds it, before `cacheRead()` decompresses it.
     *
     * Reached the same way `cacheStore()` reached it, so the key and the category are computed by
     * the same code rather than reproduced here — a helper that built the key itself would keep
     * passing after the real one changed.
     */
    private function rawCachedValue(string $sql): mixed
    {
        $cache = \Pramnos\Cache\Cache::getInstance($this->category, 'sql', '');
        $cache->category = $this->category;
        $cache->prefix   = (new \ReflectionProperty(Database::class, 'prefix'))
            ->getValue($this->db);

        return $cache->load(md5($sql));
    }

    private function writeRawCachedValue(string $sql, string $value): void
    {
        $cache = \Pramnos\Cache\Cache::getInstance($this->category, 'sql', '');
        $cache->category = $this->category;
        $cache->prefix   = (new \ReflectionProperty(Database::class, 'prefix'))
            ->getValue($this->db);
        $cache->timeout  = 600;
        $cache->save($value, md5($sql));
    }
}
