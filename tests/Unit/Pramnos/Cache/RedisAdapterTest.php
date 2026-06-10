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

    /**
     * save() with timeout=0 uses the `set` (no-expiry) path instead of `setex`.
     * Data saved with no TTL must survive past what a normal TTL would allow.
     * This covers lines 167-170 of RedisAdapter::save() which call $this->redis->set().
     */
    #[Group('redis')]
    public function testSaveWithZeroTimeoutUsesSetWithoutExpiry(): void
    {
        // Arrange
        $adapter = $this->makeConnectedAdapter('test_notimeout_');
        $key     = 'rdt_notimeout_' . uniqid();

        // Act — save with timeout=0 (no TTL)
        $saved = $adapter->save($key, 'no-expiry-value', 0);

        // Assert — data is stored and retrievable
        // load() with timeout=0 also skips the time check, so it returns data directly
        $loaded = $adapter->load($key, 0);

        // Cleanup
        $adapter->delete($key);

        $this->assertTrue($saved, 'save() with timeout=0 must return true');
        $this->assertSame('no-expiry-value', $loaded,
            'save() with timeout=0 must store data retrievable by load()');
    }

    /**
     * load() returns false for an entry whose stored time is older than the timeout.
     * This covers lines 132-135 of RedisAdapter::load(): the expiry check and
     * the del() + return false branch.
     */
    #[Group('redis')]
    public function testLoadReturnsFalseForExpiredEntry(): void
    {
        // Arrange — write a serialized entry with a timestamp 7200 seconds in the past
        $adapter = $this->makeConnectedAdapter('test_expired_');
        $key     = 'rdt_expired_' . uniqid();
        $redis   = $adapter->getConnection();

        // Store an entry whose 'time' is 2 hours ago so any positive timeout will expire it
        $entry = ['data' => 'old-data', 'time' => time() - 7200];
        // Use a long Redis TTL so it is still physically present in Redis
        $redis->setex($key, 3600, serialize($entry));

        // Act — load with a 60s timeout; entry is 7200s old so it should be expired
        $result = $adapter->load($key, 60);

        // Assert — expired entry must be removed and false returned
        $this->assertFalse($result,
            'load() must return false and delete an entry whose age exceeds the timeout');

        // The key must also be gone from Redis (the del() inside load() ran)
        $this->assertFalse($redis->get($key),
            'load() must call del() on an expired key');
    }

    /**
     * load() with timeout=0 skips the expiry check entirely and returns data
     * even for old entries.  This covers the `$timeout > 0` false branch on line 131.
     */
    #[Group('redis')]
    public function testLoadWithZeroTimeoutSkipsExpiryCheck(): void
    {
        // Arrange — write an entry timestamped far in the past
        $adapter = $this->makeConnectedAdapter('test_notimeout2_');
        $key     = 'rdt_skip_exp_' . uniqid();
        $redis   = $adapter->getConnection();

        $entry = ['data' => 'ancient-data', 'time' => time() - 99999];
        $redis->setex($key, 3600, serialize($entry));

        // Act — load with timeout=0 bypasses the age check
        $result = $adapter->load($key, 0);

        // Cleanup
        $adapter->delete($key);

        // Assert — data is returned without the expiry guard firing
        $this->assertSame('ancient-data', $result,
            'load() with timeout=0 must return data ignoring the stored timestamp');
    }

    /**
     * clear() with a category deletes only keys matching the category pattern.
     * This covers lines 219-237 of RedisAdapter::clear() — the category branch
     * that calls keys() and del() with a pattern.
     */
    #[Group('redis')]
    public function testClearCategoryDeletesOnlyMatchingKeys(): void
    {
        // Arrange — two keys in the target category, one in another category
        $adapter = $this->makeConnectedAdapter('cattest_');
        $adapter->clear(); // start clean

        $keyA = 'mycat_item_a';
        $keyB = 'mycat_item_b';
        $keyC = 'othercat_item_c';

        $adapter->save($keyA, 'data-a', 0);
        $adapter->save($keyB, 'data-b', 0);
        $adapter->save($keyC, 'data-c', 0);

        // Act — clear only the 'mycat' category
        $result = $adapter->clear('mycat');

        // Assert — clear succeeds
        $this->assertTrue($result, 'clear(category) must return true when connected');

        // Keys outside the category must be untouched
        $this->assertNotFalse(
            $adapter->load($keyC, 0),
            'clear(category) must NOT delete keys in other categories'
        );

        // Cleanup
        $adapter->clear();
    }

    /**
     * getCategories() returns an array of category names when tags data exists.
     * This covers lines 252-258 of RedisAdapter::getCategories().
     *
     * Since the adapter does not write the tags key itself (that is a Cache
     * layer concern), we inject it directly via the Redis connection.
     */
    #[Group('redis')]
    public function testGetCategoriesReturnsCategoryNamesWhenTagsExist(): void
    {
        // Arrange — write tags JSON directly into Redis under the prefix+tagsKey
        $adapter = $this->makeConnectedAdapter('tagstest_');
        $redis   = $adapter->getConnection();

        // Reflect to read the tagsKey value from the abstract parent
        $ref     = new \ReflectionProperty($adapter, 'tagsKey');
        $tagsKey = 'tagstest_' . $ref->getValue($adapter);

        $tagsData = json_encode(['products' => 1, 'users' => 1]);
        $redis->set($tagsKey, $tagsData);

        // Act
        $categories = $adapter->getCategories();

        // Cleanup
        $redis->del($tagsKey);

        // Assert — both category names are present
        $this->assertContains('products', $categories,
            'getCategories() must include all keys from the stored tags JSON');
        $this->assertContains('users', $categories);
    }

    /**
     * getStats() counts categories from the tags key and items from dbSize().
     * This covers lines 280-293 of RedisAdapter::getStats().
     */
    #[Group('redis')]
    public function testGetStatsCountsCategoriesAndItems(): void
    {
        // Arrange — isolated prefix to avoid interference from parallel tests
        $adapter = $this->makeConnectedAdapter('statstest_');
        $adapter->clear(); // start empty

        // Write the tags key with two known categories
        $redis   = $adapter->getConnection();
        $ref     = new \ReflectionProperty($adapter, 'tagsKey');
        $tagsKey = 'statstest_' . $ref->getValue($adapter);
        $redis->set($tagsKey, json_encode(['catA' => 1, 'catB' => 1]));

        // Write a couple of data keys
        $adapter->save('stats_key_1', 'val1', 0);
        $adapter->save('stats_key_2', 'val2', 0);

        // Act
        $stats = $adapter->getStats();

        // Cleanup
        $adapter->clear();

        // Assert
        $this->assertSame('redis', $stats['method']);
        $this->assertSame(2, $stats['categories'],
            'getStats() must count categories from the tags JSON');
        $this->assertGreaterThanOrEqual(2, $stats['items'],
            'getStats() must count at least the two stored items');
    }

    /**
     * getAllItems() returns an array of item descriptors for stored keys.
     * This covers lines 312-355 of RedisAdapter::getAllItems() — the full
     * keys-scan + per-key deserialization loop.
     */
    #[Group('redis')]
    public function testGetAllItemsReturnsItemDescriptors(): void
    {
        // Arrange — isolated prefix
        $adapter = $this->makeConnectedAdapter('itemstest_');
        $adapter->clear();

        $adapter->save('itemstest_key_one', ['x' => 1], 0);
        $adapter->save('itemstest_key_two', 'hello', 0);

        // Act
        $items = $adapter->getAllItems();

        // Cleanup
        $adapter->clear();

        // Assert — at least the two stored items are returned
        $this->assertIsArray($items, 'getAllItems() must return an array');
        $this->assertGreaterThanOrEqual(2, count($items),
            'getAllItems() must include all stored keys');

        // Each descriptor must have the required fields
        foreach ($items as $item) {
            $this->assertArrayHasKey('key', $item);
            $this->assertArrayHasKey('size', $item);
            $this->assertArrayHasKey('created_time', $item);
            $this->assertArrayHasKey('ttl', $item);
            $this->assertArrayHasKey('type', $item);
        }
    }

    /**
     * getAllItems() with a category filter returns only keys matching that category.
     * This covers the `$category !== ''` branch (lines 313-320) in getAllItems().
     */
    #[Group('redis')]
    public function testGetAllItemsWithCategoryFiltersKeys(): void
    {
        // Arrange
        $adapter = $this->makeConnectedAdapter('catfilter_');
        $adapter->clear();

        // Store keys in two different "categories" (emulated by key prefix)
        $adapter->save('alpha_key_1', 'a1', 0);
        $adapter->save('alpha_key_2', 'a2', 0);
        $adapter->save('beta_key_1',  'b1', 0);

        // Act — filter to 'alpha' category
        $items = $adapter->getAllItems('alpha', 100);

        // Cleanup
        $adapter->clear();

        // Assert — only alpha keys returned (key names contain 'alpha')
        $this->assertIsArray($items);
        foreach ($items as $item) {
            $this->assertStringContainsString('alpha', $item['key'],
                'getAllItems(category) must only return keys matching the category pattern');
        }
    }

    /**
     * connect() with a wrong password sets connected=false (auth failure path).
     * This covers lines 84-86 of RedisAdapter::connect(): the auth() branch.
     *
     * Note: Most Docker Redis instances have no auth — we probe for auth support
     * by checking whether auth() rejects a bad password. If the server accepts
     * any password (no-auth mode), we skip this test gracefully.
     */
    #[Group('redis')]
    public function testConnectWithWrongPasswordSetsConnectedFalse(): void
    {
        // Arrange — use a known-bad password
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('Redis PHP extension not installed');
        }

        $adapter = new RedisAdapter(self::$redisHost, self::$redisPort, 0, 'definitely_wrong_password_xyz', '');

        // Act — connect; if the server has no auth requirement it will return true
        $result = $adapter->connect();

        if ($result === true) {
            // Server accepted the wrong password — no-auth mode, cannot test this path
            $this->markTestSkipped(
                'Redis server is running without password authentication; auth failure path cannot be exercised'
            );
        }

        // Assert — bad password caused connect() to return false
        $this->assertFalse($result,
            'connect() must return false when Redis auth() rejects the password');
        $this->assertFalse($adapter->load('any_key', 60),
            'load() must return false after a failed auth connect');
    }
}
