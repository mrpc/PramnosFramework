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
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'prepareQuery'])
            ->getMock();

        $db->method('prepareQuery')->willReturnCallback(
            static fn(string $sql, ...$args): string => $sql . '|' . implode('|', $args)
        );

        $db->method('query')->willReturnCallback(function (string $sql) {
            $this->queries++;

            // The bulk read: every row at once.
            if (!str_contains($sql, 'where')) {
                return $this->fakeResult(array_map(
                    static fn($k, $v) => ['setting' => $k, 'value' => $v],
                    array_keys($this->rows),
                    $this->rows
                ));
            }

            // A per-key read: the key is the last thing prepareQuery appended.
            $key  = substr($sql, strrpos($sql, '|') + 1);
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
        // Arrange
        $ttls = [];
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'prepareQuery'])
            ->getMock();
        $db->method('prepareQuery')->willReturnCallback(
            static fn(string $sql, ...$args): string => $sql . '|' . implode('|', $args)
        );
        $db->method('query')->willReturnCallback(
            function (string $sql, $cache = false, $ttl = 0) use (&$ttls) {
                $ttls[] = $ttl;
                return $this->fakeResult([]);
            }
        );
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
