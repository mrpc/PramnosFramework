<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\MemcachedAdapter;

/**
 * Unit tests for Pramnos\Cache\Adapter\MemcachedAdapter.
 *
 * The Memcached PHP extension is not installed in the test Docker image, so
 * connect() always returns false.  This lets us exercise all of the adapter's
 * guard-clause paths (load/save/delete/clear/getCategories/getStats/getAllItems
 * all short-circuit when not connected) as well as the pure-logic helpers
 * (categoryHash, constructor field assignment, getConnection).
 *
 * The "connected" path is intentionally out of scope here — it would require
 * the extension and a running Memcached server.
 */
#[CoversClass(MemcachedAdapter::class)]
class MemcachedAdapterTest extends TestCase
{
    // =========================================================================
    // Constructor / field assignment
    // =========================================================================

    /**
     * Constructor stores host, port, persistentId, and prefix via parent.
     * The getPrefix() helper from AbstractAdapter verifies the prefix is passed
     * through the constructor chain.
     */
    public function testConstructorStoresAllFields(): void
    {
        // Arrange / Act
        $adapter = new MemcachedAdapter('myhost', 12345, 'pid1', 'myprefix_');

        // Assert — prefix round-trips through parent::__construct
        $this->assertSame('myprefix_', $adapter->getPrefix());
    }

    /**
     * Default constructor arguments leave host=localhost, port=11211, and
     * prefix empty.
     */
    public function testDefaultConstructorValuesAreCorrect(): void
    {
        // Arrange / Act
        $adapter = new MemcachedAdapter();

        // Assert
        $this->assertSame('', $adapter->getPrefix());
    }

    // =========================================================================
    // connect()
    // =========================================================================

    /**
     * connect() returns false when the \Memcached class is not available.
     * In the CI/Docker environment, the Memcached PHP extension is absent, so
     * this branch is exercised on every run.
     */
    public function testConnectReturnsFalseWhenMemcachedExtensionNotAvailable(): void
    {
        // Precondition — verify extension is really absent so the test is meaningful
        if (class_exists('\Memcached')) {
            $this->markTestSkipped('Memcached extension is installed — this test targets the absent-extension path');
        }

        // Arrange / Act
        $adapter = new MemcachedAdapter();
        $result  = $adapter->connect();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * getConnection() returns null before connect() is called (or after a failed
     * connect() when the extension is absent).
     */
    public function testGetConnectionReturnsNullWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();
        $adapter->connect(); // will fail silently when extension absent

        // Act / Assert
        $this->assertNull($adapter->getConnection());
    }

    // =========================================================================
    // load() — not connected
    // =========================================================================

    /**
     * load() returns false immediately when not connected, without touching
     * any Memcached state.
     */
    public function testLoadReturnsFalseWhenNotConnected(): void
    {
        // Arrange — adapter not connected (no connect() call)
        $adapter = new MemcachedAdapter();

        // Act
        $result = $adapter->load('some_key', 3600);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * load() also returns false when caching is disabled, regardless of
     * connection state.
     */
    public function testLoadReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();
        $adapter->setCaching(false);

        // Act
        $result = $adapter->load('key');

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // save() — not connected
    // =========================================================================

    /**
     * save() returns false when not connected.
     */
    public function testSaveReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

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
        $adapter = new MemcachedAdapter();
        $adapter->setCaching(false);

        // Act
        $result = $adapter->save('key', 'value');

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // delete() — not connected
    // =========================================================================

    /**
     * delete() returns false when not connected.
     */
    public function testDeleteReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

        // Act
        $result = $adapter->delete('key');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * delete() returns false when caching is disabled.
     */
    public function testDeleteReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();
        $adapter->setCaching(false);

        // Act
        $result = $adapter->delete('key');

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // clear() — not connected
    // =========================================================================

    /**
     * clear() (no category) returns false when not connected.
     */
    public function testClearAllReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

        // Act
        $result = $adapter->clear();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * clear() with a specific category also returns false when not connected.
     */
    public function testClearCategoryReturnsFalseWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

        // Act
        $result = $adapter->clear('products');

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // getCategories() — not connected
    // =========================================================================

    /**
     * getCategories() returns an empty array when not connected.
     */
    public function testGetCategoriesReturnsEmptyArrayWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

        // Act
        $result = $adapter->getCategories();

        // Assert
        $this->assertSame([], $result);
    }

    // =========================================================================
    // getStats() — not connected
    // =========================================================================

    /**
     * getStats() returns a base stats array with method='memcached' and zero
     * counts when not connected.  The method must not attempt any Memcached
     * calls in this path.
     */
    public function testGetStatsReturnsBaseStatsWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

        // Act
        $stats = $adapter->getStats();

        // Assert — shape and default values
        $this->assertSame('memcached', $stats['method']);
        $this->assertSame(0, $stats['categories']);
        $this->assertSame(0, $stats['items']);
    }

    // =========================================================================
    // getAllItems() — not connected
    // =========================================================================

    /**
     * getAllItems() returns an empty array when not connected.
     */
    public function testGetAllItemsReturnsEmptyArrayWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

        // Act
        $result = $adapter->getAllItems();

        // Assert
        $this->assertSame([], $result);
    }

    /**
     * getAllItems() with a category filter also returns [] when not connected.
     */
    public function testGetAllItemsWithCategoryReturnsEmptyWhenNotConnected(): void
    {
        // Arrange
        $adapter = new MemcachedAdapter();

        // Act
        $result = $adapter->getAllItems('products', 50);

        // Assert
        $this->assertSame([], $result);
    }

    // =========================================================================
    // categoryHash() — pure sanitization logic (no connection needed)
    // =========================================================================

    /**
     * categoryHash() returns an empty string for an empty category, which acts
     * as a "no category" sentinel in the key-generation path.
     */
    public function testCategoryHashReturnsEmptyStringForEmptyCategory(): void
    {
        // Arrange / Act
        $result = (new MemcachedAdapter())->categoryHash('');

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * categoryHash() replaces whitespace with underscores.
     */
    public function testCategoryHashReplacesSpacesWithUnderscores(): void
    {
        // Arrange / Act
        $result = (new MemcachedAdapter())->categoryHash('my category');

        // Assert
        $this->assertSame('my_category', $result);
    }

    /**
     * categoryHash() strips characters that are not alphanumeric, underscore,
     * or hyphen.  Special characters like '!', '@', '.' are removed.
     */
    public function testCategoryHashStripsSpecialChars(): void
    {
        // Arrange / Act
        $result = (new MemcachedAdapter())->categoryHash('cat!@#.name');

        // Assert — only word chars and hyphens survive
        $this->assertMatchesRegularExpression('/^[\w\-]*$/', $result);
        $this->assertStringNotContainsString('!', $result);
        $this->assertStringNotContainsString('@', $result);
        $this->assertStringNotContainsString('.', $result);
    }

    /**
     * categoryHash() leaves hyphenated and underscored names unchanged.
     */
    public function testCategoryHashPreservesHyphensAndUnderscores(): void
    {
        // Arrange / Act
        $result = (new MemcachedAdapter())->categoryHash('my-category_name');

        // Assert
        $this->assertSame('my-category_name', $result);
    }
}
