<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\Token;

/**
 * The deduplicated URL registry, and the cache in front of it.
 *
 * `Token::urlId()` is called from the spool drain, which is a long-running process — so the cache
 * is the point rather than an optimisation: a site serves a few hundred distinct URLs, a worker
 * learns them in its first minutes, and after that every token action resolves from memory instead
 * of a round trip.
 *
 * Which makes the bound on it the interesting part, and it had never run. A site that generates
 * URLs without limit — an id in the path, a search term in the query string — would otherwise grow
 * the cache without limit too, in a process that never restarts. When it fills, the **oldest half**
 * is dropped rather than all of it: clearing outright would make a worker re-resolve the URLs it is
 * busiest with, over and over, exactly when it is busiest.
 *
 * Runs on every backend: {@see UrlRegistryCachePostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB, where the insert's returned id comes from a sequence rather than
 * `LAST_INSERT_ID()`.
 */
#[CoversClass(Token::class)]
class UrlRegistryCacheTest extends BaseTestCase
{
    private $db;

    /** A URL nothing else will have registered. */
    private string $url = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $app = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();

        try {
            if (!$this->db->connected) {
                $this->db->connect();
            }
        } catch (\Throwable $exception) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        $app->database = $this->db;

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUrlsTable::class,
        ], $this->db);

        $this->url = '/tests/url-registry/' . getmypid() . '/' . microtime(true);
        $this->clearCache();
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->db->connected && $this->url !== '') {
            $this->db->query(
                'DELETE FROM ' . $this->db->prefix . 'urls WHERE hash = '
                . (int) crc32($this->url)
            );
        }
        $this->clearCache();
        parent::tearDown();
    }

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** Empties the static cache, which is process-wide and shared with every other test. */
    private function clearCache(): void
    {
        $property = new \ReflectionProperty(Token::class, 'urlIdCache');
        $property->setValue(null, []);
    }

    /** @return array<string, int> */
    private function cache(): array
    {
        return (new \ReflectionProperty(Token::class, 'urlIdCache'))->getValue();
    }

    /**
     * A URL nobody has logged before is inserted, and its id comes back.
     *
     * Once per distinct URL for the life of the installation, which is what makes the registry a
     * registry rather than a log.
     */
    public function testAnUnknownUrlIsRegisteredAndItsIdReturned(): void
    {
        // Act
        $id = Token::urlId($this->url);

        // Assert
        $this->assertGreaterThan(0, $id, 'the URL was not registered');

        $row = $this->db->query(
            'SELECT urlid FROM ' . $this->db->prefix . 'urls WHERE hash = ' . (int) crc32($this->url)
        );
        $this->assertSame(1, (int) $row->numRows, 'the registry should hold exactly one row for it');
        $this->assertSame($id, (int) $row->fields['urlid']);
    }

    /**
     * The same URL twice gives the same id, and the second call does not query.
     *
     * Asserted on the cache rather than on a query count, because what the cache promises is that
     * the answer is in memory: an entry for this hash means the next call is a lookup and not a
     * round trip.
     */
    public function testTheSameUrlResolvesToTheSameIdFromMemory(): void
    {
        // Act
        $first = Token::urlId($this->url);

        $this->assertArrayHasKey((string) crc32($this->url), $this->cache(), 'the id was not cached');

        $second = Token::urlId($this->url);

        // Assert
        $this->assertSame($first, $second);
    }

    /**
     * A URL already in the registry is found rather than inserted again.
     *
     * The path a second worker takes on a URL the first one registered — the cache is per process,
     * the registry is not.
     */
    public function testAUrlAlreadyInTheRegistryIsFoundNotDuplicated(): void
    {
        // Arrange — register it, then forget it locally, as a second process would
        $first = Token::urlId($this->url);
        $this->clearCache();

        // Act
        $second = Token::urlId($this->url);

        // Assert
        $this->assertSame($first, $second, 'a second process got a different id for the same URL');

        $row = $this->db->query(
            'SELECT COUNT(*) AS c FROM ' . $this->db->prefix . 'urls WHERE hash = '
            . (int) crc32($this->url)
        );
        $this->assertSame(1, (int) $row->fields['c'], 'the URL was registered twice');
    }

    /**
     * A full cache drops its oldest half and keeps the newest.
     *
     * The claim the comment makes, asserted: the entries that survive are the ones added most
     * recently, which on a worker are the URLs it is currently busy with. Dropping the newest — or
     * clearing everything — would make the next minute of work re-resolve exactly what it had just
     * learned.
     *
     * Driven through `rememberUrlId()` rather than `urlId()`: filling the cache means two thousand
     * entries, and two thousand round trips to prove an `array_slice` would be a slow test of
     * nothing.
     */
    public function testAFullCacheDropsItsOldestHalf(): void
    {
        // Arrange
        $limit    = Token::URL_CACHE_LIMIT;
        $remember = new \ReflectionMethod(Token::class, 'rememberUrlId');

        for ($i = 1; $i <= $limit; $i++) {
            $remember->invoke(null, 'hash-' . $i, $i);
        }

        $this->assertCount($limit, $this->cache(), 'the cache did not fill');

        // Act — one more, which is what trips the eviction
        $remember->invoke(null, 'hash-overflow', 999999);

        // Assert
        $cache = $this->cache();

        $this->assertLessThan($limit, count($cache), 'the cache grew past its limit');
        $this->assertSame(
            (int) ($limit / 2) + 1,
            count($cache),
            'half the cache plus the new entry is what should remain'
        );
        $this->assertArrayHasKey('hash-overflow', $cache, 'the entry that tripped the eviction was dropped');
        $this->assertArrayHasKey(
            'hash-' . $limit,
            $cache,
            'the most recently used URL was evicted, which is the opposite of the intent'
        );
        $this->assertArrayNotHasKey('hash-1', $cache, 'the oldest entry survived');
    }

    /**
     * An id already in the cache is returned as it is, not re-resolved.
     *
     * `rememberUrlId()` returns the id it was given so callers can `return` it directly, and this
     * is the assertion that keeps that contract — a version that returned the array, or nothing,
     * would make `urlId()` answer `0` for every URL it had just registered.
     */
    public function testRememberingAnIdReturnsIt(): void
    {
        // Act
        $returned = (new \ReflectionMethod(Token::class, 'rememberUrlId'))
            ->invoke(null, 'some-hash', 4242);

        // Assert
        $this->assertSame(4242, $returned);
        $this->assertSame(4242, $this->cache()['some-hash']);
    }

    /**
     * A registry that cannot be reached resolves to `0`, and the drain carries on.
     *
     * `0` is the "no url" value the action row takes, so a spool drain whose `urls` table is
     * missing or unreachable still writes its token actions — with no URL attached — instead of
     * abandoning the batch. Losing the URL of an action is a gap in a report; losing the batch is
     * a gap in the audit trail.
     */
    public function testAnUnreachableRegistryResolvesToZero(): void
    {
        // Arrange — a database whose query builder raises
        $saved     = &\Pramnos\Database\Database::getInstance();
        $original  = $saved;
        $saved     = new class extends \Pramnos\Database\Database {
            public $type      = 'mysql';
            public $prefix    = '';
            public $connected = true;

            public function __construct() {}

            public function queryBuilder()
            {
                throw new \RuntimeException('the registry is not there');
            }
        };

        try {
            // Act
            $id = Token::urlId('/somewhere/nothing-can-resolve');

            // Assert
            $this->assertSame(0, $id);
        } finally {
            $restore = &\Pramnos\Database\Database::getInstance();
            $restore = $original;
        }
    }
}
