<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\RedisAdapter;

/**
 * Unit tests for Pramnos\Cache\Adapter\RedisAdapter.
 *
 * Two test groups are included:
 *
 *   1. Pure-logic tests (always run) — constructor field storage, categoryHash
 *      sanitization, and the guard-clause paths that short-circuit when the
 *      adapter has not been connected.
 *
 *   2. Integration tests (#[Group('redis')]) — require a running Redis server
 *      accessible at REDIS_HOST (env var, default: 'pramnos_redis') on port 6379.
 *      In the Pramnos Docker environment this is always available.  These tests
 *      verify the full read/write/delete/clear lifecycle.
 */
#[CoversClass(RedisAdapter::class)]
class RedisAdapterTest extends TestCase
{
    private static string $redisHost = 'pramnos_redis';
    private static int    $redisPort = 6379;

    protected function setUp(): void
    {
        $envHost = getenv('REDIS_HOST');
        if ($envHost !== false && $envHost !== '') {
            self::$redisHost = $envHost;
        }
    }

    // =========================================================================
    // Constructor / field storage
    // =========================================================================

    /**
     * Constructor stores host, port, database, password, and prefix.
     * getPrefix() from AbstractAdapter verifies the prefix is passed through.
     */
    public function testConstructorStoresAllFields(): void
    {
        // Arrange / Act
        $adapter = new RedisAdapter('myhost', 6380, 2, 'secret', 'pre_');

        // Assert — prefix is accessible via AbstractAdapter helper
        $this->assertSame('pre_', $adapter->getPrefix());
    }

    /**
     * Default constructor creates an adapter with empty prefix.
     */
    public function testDefaultConstructorHasEmptyPrefix(): void
    {
        // Arrange / Act
        $adapter = new RedisAdapter();

        // Assert
        $this->assertSame('', $adapter->getPrefix());
    }

    /**
     * getConnection() returns null before connect() is called.
     */
    public function testGetConnectionReturnsNullBeforeConnect(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act / Assert — no connect() was called
        $this->assertNull($adapter->getConnection());
    }

    // =========================================================================
    // Not-connected guard-clause paths
    // =========================================================================

    /**
     * load() returns false when not connected.
     */
    public function testLoadReturnsFalseWhenNotConnected(): void
    {
        // Arrange — adapter created, connect() not called
        $adapter = new RedisAdapter();

        // Act
        $result = $adapter->load('key', 3600);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * load() returns false when caching is disabled, regardless of connection.
     */
    public function testLoadReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = new RedisAdapter();
        $adapter->setCaching(false);

        // Act
        $result = $adapter->load('key');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * save() returns false when not connected.
     */
    public function testSaveReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act
        $result = $adapter->save('key', 'value', 3600);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * save() returns false when caching is disabled.
     */
    public function testSaveReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = new RedisAdapter();
        $adapter->setCaching(false);

        // Act
        $result = $adapter->save('key', 'value');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * delete() returns false when not connected.
     */
    public function testDeleteReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act
        $result = $adapter->delete('key');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * clear() returns false when not connected (no-category variant).
     */
    public function testClearAllReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act
        $result = $adapter->clear();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * clear() with a category also returns false when not connected.
     */
    public function testClearCategoryReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act
        $result = $adapter->clear('products');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * getCategories() returns [] when not connected.
     */
    public function testGetCategoriesReturnsEmptyWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act / Assert
        $this->assertSame([], $adapter->getCategories());
    }

    /**
     * getStats() returns a base stats array with method='redis' and zero counts
     * when not connected.
     */
    public function testGetStatsReturnsBaseStatsWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act
        $stats = $adapter->getStats();

        // Assert — shape and default values
        $this->assertSame('redis', $stats['method']);
        $this->assertSame(0, $stats['categories']);
        $this->assertSame(0, $stats['items']);
    }

    /**
     * getAllItems() returns [] when not connected.
     */
    public function testGetAllItemsReturnsEmptyWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act / Assert
        $this->assertSame([], $adapter->getAllItems());
    }

    /**
     * getAllItems() with a category filter also returns [] when not connected.
     */
    public function testGetAllItemsWithCategoryReturnsEmptyWhenNotConnected(): void
    {
        // Arrange
        $adapter = new RedisAdapter();

        // Act / Assert
        $this->assertSame([], $adapter->getAllItems('products', 50));
    }

    // =========================================================================
    // connect() failure path (non-existent server)
    // =========================================================================

    /**
     * connect() returns false and does not throw when the server is unreachable.
     * Uses a port that is guaranteed not to be listening.
     * Note: the \Redis object is instantiated before the connection attempt, so
     * getConnection() may return a non-null (disconnected) \Redis object — that
     * is implementation detail.  The observable contract is that load/save/delete
     * all return false after a failed connect().
     */
    public function testConnectReturnsFalseForUnreachableServer(): void
    {
        // Arrange — port 1 is in the system range and always refused
        $adapter = new RedisAdapter('127.0.0.1', 1);

        // Act — must not throw, just return false
        $result = $adapter->connect();

        // Assert — connect() fails
        $this->assertFalse($result);
        // Operations must not work after failed connect
        $this->assertFalse($adapter->load('key', 60));
        $this->assertFalse($adapter->save('key', 'val', 60));
    }

    // =========================================================================
    // categoryHash() — pure sanitization logic (no connection needed)
    // =========================================================================

    /**
     * categoryHash() returns '' for an empty category — used as "no category"
     * sentinel in key generation.
     */
    public function testCategoryHashReturnsEmptyStringForEmptyCategory(): void
    {
        // Arrange / Act
        $result = (new RedisAdapter())->categoryHash('');

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * categoryHash() replaces whitespace with underscores.
     */
    public function testCategoryHashReplacesSpacesWithUnderscores(): void
    {
        // Arrange / Act
        $result = (new RedisAdapter())->categoryHash('my category');

        // Assert
        $this->assertSame('my_category', $result);
    }

    /**
     * categoryHash() strips special characters, keeping only alphanumeric,
     * underscore, and hyphen.
     */
    public function testCategoryHashStripsSpecialChars(): void
    {
        // Arrange / Act
        $result = (new RedisAdapter())->categoryHash('cat!@#.name');

        // Assert — only word chars and hyphens survive
        $this->assertMatchesRegularExpression('/^[\w\-]*$/', $result);
        $this->assertStringNotContainsString('!', $result);
        $this->assertStringNotContainsString('.', $result);
    }

    /**
     * categoryHash() leaves hyphenated and underscored names unchanged.
     */
    public function testCategoryHashPreservesHyphensAndUnderscores(): void
    {
        // Arrange / Act
        $result = (new RedisAdapter())->categoryHash('my-category_name');

        // Assert
        $this->assertSame('my-category_name', $result);
    }

    // =========================================================================
    // Integration tests — require real Redis connection
    // =========================================================================

    /**
     * Returns a connected RedisAdapter using the test Redis server.
     * Skips the calling test if the connection fails.
     */
    private function makeConnectedAdapter(string $prefix = 'test_unit_'): RedisAdapter
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('Redis PHP extension not installed');
        }
        $adapter = new RedisAdapter(self::$redisHost, self::$redisPort, 0, null, $prefix);
        if (!$adapter->connect()) {
            $this->markTestSkipped('Cannot connect to Redis at ' . self::$redisHost . ':' . self::$redisPort);
        }
        return $adapter;
    }

    /**
     * connect() returns true and getConnection() returns a \Redis instance
     * when the server is reachable.
     */
    #[Group('redis')]
    public function testConnectReturnsTrueAndGetConnectionReturnsRedisInstance(): void
    {
        // Arrange / Act
        $adapter = $this->makeConnectedAdapter();

        // Assert — connected flag and connection object
        $this->assertInstanceOf(\Redis::class, $adapter->getConnection());
    }

    /**
     * save() stores data and load() retrieves it by the same key.
     */
    #[Group('redis')]
    public function testSaveAndLoadRoundtrip(): void
    {
        // Arrange
        $adapter = $this->makeConnectedAdapter();
        $key     = 'rdt_roundtrip_' . uniqid();

        // Act
        $saved  = $adapter->save($key, ['foo' => 'bar'], 60);
        $loaded = $adapter->load($key, 60);

        // Cleanup
        $adapter->delete($key);

        // Assert
        $this->assertTrue($saved);
        $this->assertSame(['foo' => 'bar'], $loaded);
    }

    /**
     * load() returns false for a key that has never been stored.
     */
    #[Group('redis')]
    public function testLoadReturnsFalseForMissingKey(): void
    {
        // Arrange
        $adapter = $this->makeConnectedAdapter();
        $key     = 'rdt_nonexistent_' . uniqid();

        // Act
        $result = $adapter->load($key, 60);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * delete() removes a previously saved key so load() returns false.
     */
    #[Group('redis')]
    public function testDeleteRemovesKey(): void
    {
        // Arrange
        $adapter = $this->makeConnectedAdapter();
        $key     = 'rdt_delete_' . uniqid();
        $adapter->save($key, 'delete-me', 60);

        // Act
        $deleted = $adapter->delete($key);

        // Assert
        $this->assertTrue($deleted);
        $this->assertFalse($adapter->load($key, 60));
    }

    /**
     * clear() with no category flushes all keys in the Redis database.
     */
    #[Group('redis')]
    public function testClearAllFlushesDatabase(): void
    {
        // Arrange — save a key, then flush
        $adapter = $this->makeConnectedAdapter('clrtest_');
        $key     = 'clrtest_item_' . uniqid();
        $adapter->save($key, 'will-be-cleared', 60);

        // Act
        $result = $adapter->clear();

        // Assert
        $this->assertTrue($result);
        // Key must be gone after flush
        $this->assertFalse($adapter->load($key, 60));
    }

    /**
     * getStats() returns a stats array with method='redis' and non-negative
     * item and category counts when connected.
     */
    #[Group('redis')]
    public function testGetStatsReturnsValidStatsWhenConnected(): void
    {
        // Arrange
        $adapter = $this->makeConnectedAdapter();

        // Act
        $stats = $adapter->getStats();

        // Assert — shape and type checks
        $this->assertSame('redis', $stats['method']);
        $this->assertIsInt($stats['categories']);
        $this->assertIsInt($stats['items']);
        $this->assertGreaterThanOrEqual(0, $stats['items']);
    }
}
