<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * What reading a setting costs, in queries.
 *
 * The bulk load has been there for a while: one query fetches every row, and
 * later reads are served from memory. What it could not do is answer for a key
 * that is *not* in the table — those fell through to a per-key `SELECT … LIMIT
 * 1` that found nothing and recorded nothing, so the next read repeated it.
 *
 * That is the shape worth pinning down with a query counter rather than a
 * behavioural assertion: the values returned were always right. The cost was
 * wrong, and it grew with how often an absent setting was consulted — which is
 * precisely the thing a caller has no reason to think about. Two such lookups
 * on a page render is what made it visible.
 */
#[CoversClass(Settings::class)]
class SettingsMissCachingTest extends TestCase
{
    /** @var int How many queries the fake database has been asked to run */
    private int $queries = 0;

    /** @var array<string, string> The rows the fake settings table contains */
    private array $rows = ['sitename' => 'Test Site', 'theme' => 'default'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = 0;
        $this->resetSettings();
        $this->installDatabase();
    }

    protected function tearDown(): void
    {
        $this->resetSettings();
        parent::tearDown();
    }

    /**
     * Wipe the static store so each test starts from an empty request.
     */
    private function resetSettings(): void
    {
        $ref = new \ReflectionClass(Settings::class);
        $ref->getProperty('settings')->setValue(null, []);
        $ref->getProperty('loaded')->setValue(null, false);
        $ref->getProperty('bulkLoaded')->setValue(null, false);
        $ref->getProperty('database')->setValue(null, null);
    }

    /**
     * Install a database that counts queries and serves {@see $rows}.
     */
    private function installDatabase(): void
    {
        // Settings goes through the query builder, so the methods to intercept
        // are the ones the builder calls — execute() and the SQL cache — rather
        // than query()/prepareQuery(). The builder itself is real: what these
        // tests count is how many statements reach the database, and a fake
        // builder would be counting its own behaviour instead.
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute', 'cacheRead', 'cacheStore', 'shouldCacheResult', 'logCacheHit'])
            ->getMock();

        $db->type   = 'mysql';
        $db->prefix = '';

        // No SQL cache in these tests: a cache hit would hide the very thing
        // being counted.
        $db->method('cacheRead')->willReturn(false);
        $db->method('shouldCacheResult')->willReturn(false);

        $db->method('execute')->willReturnCallback(function (string $sql, ...$bindings) {
            $this->queries++;

            // The bulk read has no WHERE; a per-key read binds the key.
            if (!str_contains(strtolower($sql), 'where')) {
                return $this->fakeResult(array_map(
                    static fn($k, $v) => ['setting' => $k, 'value' => $v],
                    array_keys($this->rows),
                    $this->rows
                ));
            }

            $key  = (string) ($bindings[0] ?? '');
            $rows = isset($this->rows[$key])
                ? [['value' => $this->rows[$key]]]
                : [];

            return $this->fakeResult($rows);
        });

        (new \ReflectionClass(Settings::class))
            ->getProperty('database')->setValue(null, $db);
    }

    /**
     * A stand-in for a query result, with the two shapes Settings reads.
     *
     * @param  list<array<string, mixed>> $rows
     */
    private function fakeResult(array $rows): object
    {
        return new class ($rows) {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
                $this->numRows = count($rows);
                $this->fields  = $rows[0] ?? [];
            }

            /** @var int */
            public $numRows;

            /** @var array<string, mixed> */
            public $fields;

            // Written back by QueryBuilder::get() when it caches a result.
            // Declared rather than created on the fly: PHP 8.2 deprecates
            // dynamic properties, and the deprecation notice is noise in a test
            // that is counting queries.

            /** @var list<array<string, mixed>>|null */
            public $result = null;

            /** @var bool */
            public $isCached = false;

            /** @var int */
            public $cursor = 0;

            /** @var bool */
            public $eof = true;

            /** @return list<array<string, mixed>> */
            public function fetchAll(): array
            {
                return $this->rows;
            }
        };
    }

    /**
     * Reading many present settings costs exactly one query.
     *
     * The existing bulk-load contract, asserted so the change below cannot
     * quietly undo it.
     */
    public function testReadingPresentSettingsCostsOneQuery(): void
    {
        // Act
        $first  = Settings::getSetting('sitename');
        $second = Settings::getSetting('theme');
        $third  = Settings::getSetting('sitename');

        // Assert
        $this->assertSame('Test Site', $first);
        $this->assertSame('default', $second);
        $this->assertSame('Test Site', $third);
        $this->assertSame(1, $this->queries, 'One bulk read serves them all');
    }

    /**
     * A setting that does not exist costs nothing beyond the bulk read.
     *
     * The fix. The bulk load read every row, so a key missing afterwards is
     * missing — asking the database again cannot change that, and asking it on
     * every read is how two absent settings turned into two queries on every
     * request for ever.
     */
    public function testAMissingSettingIsNotQueriedAgain(): void
    {
        // Act — the same absent key, read five times
        for ($i = 0; $i < 5; $i++) {
            $value = Settings::getSetting('theme_default_widgets');
        }

        // Assert
        $this->assertFalse($value, 'It still reports absence');
        $this->assertSame(1, $this->queries, 'Only the bulk read ever ran');
    }

    /**
     * Several different missing settings still cost nothing extra.
     */
    public function testSeveralMissingSettingsCostNothingExtra(): void
    {
        // Act
        Settings::getSetting('theme_default_settings');
        Settings::getSetting('theme_default_widgets');
        Settings::getSetting('something_else_entirely');

        // Assert
        $this->assertSame(1, $this->queries);
    }

    /**
     * A missing setting still returns the caller's default.
     *
     * The saving must not come at the cost of the contract: absent means the
     * default, not false, and not an empty string.
     */
    public function testAMissingSettingStillReturnsTheDefault(): void
    {
        // Act
        $value = Settings::getSetting('not_there', 'fallback');

        // Assert
        $this->assertSame('fallback', $value);
    }

    /**
     * A forced read still goes to the database.
     *
     * `$force` exists for the caller who knows the value may have changed
     * underneath them — a worker between jobs, a test. Short-circuiting that
     * would turn an escape hatch into a lie.
     */
    public function testAForcedReadStillHitsTheDatabase(): void
    {
        // Arrange — prime the store
        Settings::getSetting('sitename');
        $afterBulk = $this->queries;

        // Act
        $value = Settings::getSetting('sitename', false, true);

        // Assert
        $this->assertSame('Test Site', $value);
        $this->assertSame($afterBulk + 1, $this->queries, 'The forced read ran');
    }

    /**
     * A value written this request is read from memory, not from the database.
     */
    public function testAValueSetInMemoryIsNotReRead(): void
    {
        // Arrange
        Settings::setSetting('runtime_only', 'yes', false);

        // Act
        $value = Settings::getSetting('runtime_only');

        // Assert
        $this->assertSame('yes', $value);
        $this->assertSame(0, $this->queries, 'It never needed the database at all');
    }

    /**
     * Both reads share one cache lifetime.
     *
     * They used to be 300 and 600 seconds for the same data, so a per-key entry
     * could outlive the bulk one and keep answering with an older value for the
     * rest of its window.
     */
    public function testBothReadsUseTheSameCacheLifetime(): void
    {
        // Arrange — the TTL now reaches the SQL cache through the query
        // builder, so it is cacheRead() that reports which lifetime was asked
        // for. Every read misses, so both statements actually run.
        $ttls = [];
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute', 'cacheRead', 'cacheStore', 'shouldCacheResult', 'logCacheHit'])
            ->getMock();

        $db->type   = 'mysql';
        $db->prefix = '';

        $db->method('cacheRead')->willReturn(false);
        $db->method('shouldCacheResult')->willReturn(true);
        $db->method('cacheStore')->willReturnCallback(
            function ($key, $data, $category = '', $ttl = 0) use (&$ttls): bool {
                $ttls[] = (int) $ttl;
                return true;
            }
        );
        $db->method('execute')->willReturnCallback(fn(): object => $this->fakeResult([]));

        (new \ReflectionClass(Settings::class))
            ->getProperty('database')->setValue(null, $db);

        // Act — the bulk read, then a forced per-key read
        Settings::getSetting('anything');
        Settings::getSetting('anything', false, true);

        // Assert
        $this->assertCount(2, $ttls);
        $this->assertSame($ttls[0], $ttls[1], 'One lifetime for one kind of data');
        $this->assertSame(Settings::CACHE_TTL, $ttls[0]);
    }
}
