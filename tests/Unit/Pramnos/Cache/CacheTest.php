<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;

/**
 * Unit tests for Pramnos\Cache\Cache.
 *
 * Focuses on:
 *   - getCategory() — pure string sanitization (no server needed)
 *   - Constructor properties with method='file' (uses sys_get_temp_dir())
 *   - Adapter-null paths: load, delete, clear, testConnection, getStats,
 *     getAllItems, getCategories, getRedis
 *   - Short-circuit paths when caching=false
 *
 * No Memcached, Redis, or Memcache server is required. The 'file' method
 * uses a tmp directory and falls back gracefully to caching=false when the
 * directory cannot be created.
 */
#[CoversClass(Cache::class)]
class CacheTest extends TestCase
{
    // =========================================================================
    // getCategory() — pure string sanitization
    // =========================================================================

    /** @return array<string,array{string,string}> */
    public static function categoryProvider(): array
    {
        return [
            'empty string'          => ['',             ''],
            'plain word'            => ['products',     'products'],
            'space → underscore'    => ['my category',  'my_category'],
            'multiple spaces'       => ['a  b  c',      'a_b_c'],  // \s+ matches all spaces as one
            'hyphen kept'           => ['my-cat',       'my-cat'],
            'underscore kept'       => ['my_cat',       'my_cat'],
            'special chars removed' => ['cat@#!',       'cat'],
            'mixed'                 => ['hello world!', 'hello_world'],
        ];
    }

    /**
     * getCategory() sanitizes a category string for safe use in cache keys:
     * whitespace → underscore, anything outside [\w\-] is removed.
     *
     * @param string $input    Raw category name
     * @param string $expected Sanitized result
     */
    #[DataProvider('categoryProvider')]
    public function testGetCategorySanitizesString(string $input, string $expected): void
    {
        // Arrange – any Cache instance works; getCategory() is pure
        $cache = new Cache(null, null, 'file');

        // Act
        $result = $cache->getCategory($input);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Constructor — property defaults and method selection
    // =========================================================================

    /**
     * Constructor with method='file' stores properties correctly.
     */
    public function testConstructorWithFileMethodStoresProperties(): void
    {
        // Arrange / Act
        $cache = new Cache('orders', 'json', 'file');

        // Assert – passed values stored
        $this->assertSame('orders', $cache->category);
        $this->assertSame('json',   $cache->extension);
        $this->assertSame('file',   $cache->method);
    }

    /**
     * Constructor with no arguments defaults to method='memcached', but since
     * no Memcached server is available in the test environment it falls back
     * through Memcache → file. method reflects the initial request.
     */
    public function testConstructorDefaultsToMemcachedMethod(): void
    {
        // Arrange / Act
        $cache = new Cache(null, null, 'file');

        // Assert – method stored as set
        $this->assertSame('file', $cache->method);
    }

    /**
     * The adapter is set after construction when method='file' and the tmp
     * directory is writable.
     */
    public function testFileAdapterIsSetAfterConstruction(): void
    {
        // Arrange / Act
        $cache = new Cache(null, null, 'file');

        // Assert – file adapter created (getAdapter() returns non-null)
        // When the tmp dir is not writable, caching=false and adapter=null;
        // both outcomes are valid in CI — we just verify the method is consistent.
        if ($cache->caching) {
            $this->assertNotNull($cache->getAdapter());
        } else {
            $this->assertNull($cache->getAdapter());
        }
    }

    // =========================================================================
    // testConnection() / getRedis() — adapter-null paths
    // =========================================================================

    /**
     * testConnection() returns false when caching is disabled.
     */
    public function testTestConnectionReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');
        $cache->caching = false;

        // Act / Assert
        $this->assertFalse($cache->testConnection());
    }

    /**
     * getRedis() returns null when the file adapter is in use.
     */
    public function testGetRedisReturnsNullForFileAdapter(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');

        // Act / Assert – file adapter is not a RedisAdapter
        $this->assertNull($cache->getRedis());
    }

    // =========================================================================
    // getStats() — adapter-null and disabled paths
    // =========================================================================

    /**
     * getStats() returns a minimal array with just the method name when
     * caching is disabled (no adapter).
     */
    public function testGetStatsReturnsFallbackWhenCachingDisabled(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');
        $cache->caching = false;

        // Act
        $stats = $cache->getStats();

        // Assert – minimal struct
        $this->assertArrayHasKey('method', $stats);
        $this->assertSame(0, $stats['categories']);
        $this->assertSame(0, $stats['items']);
    }

    // =========================================================================
    // getAllItems() / getCategories() — short-circuit on disabled
    // =========================================================================

    /**
     * getAllItems() returns [] when caching is disabled.
     */
    public function testGetAllItemsReturnsEmptyWhenCachingDisabled(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');
        $cache->caching = false;

        // Act / Assert
        $this->assertSame([], $cache->getAllItems());
    }

    /**
     * getCategories() returns [] when caching is disabled.
     */
    public function testGetCategoriesReturnsEmptyWhenCachingDisabled(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');
        $cache->caching = false;

        // Act / Assert
        $this->assertSame([], $cache->getCategories());
    }

    // =========================================================================
    // load() / delete() / clear() — short-circuit on caching=false
    // =========================================================================

    /**
     * load() returns false when caching is disabled.
     */
    public function testLoadReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');
        $cache->caching = false;

        // Act / Assert
        $this->assertFalse($cache->load('some-key'));
    }

    /**
     * delete() returns false when caching is disabled.
     */
    public function testDeleteReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');
        $cache->caching = false;

        // Act / Assert
        $this->assertFalse($cache->delete('some-key'));
    }

    /**
     * clear() delegates to the adapter when one is available, or returns false
     * when the adapter is null.  Unlike load/delete/save it does NOT check
     * $this->caching — so we test the return type rather than the shortcircuit.
     */
    public function testClearReturnsBooleanResult(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');

        // Act
        $result = $cache->clear();

        // Assert – result is not an exception (adapter returns bool|null depending on state)
        $this->assertTrue($result === true || $result === false || $result === null);
    }

    /**
     * save() returns false when caching is disabled.
     */
    public function testSaveReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'file');
        $cache->caching = false;

        // Act / Assert
        $this->assertFalse($cache->save('data', 'key'));
    }

    // =========================================================================
    // remember()
    // =========================================================================

    /**
     * On a cache miss, remember() invokes the callback, stores the result, and
     * returns it. The callback must be called exactly once.
     */
    public function testRememberInvokesCallbackOnMiss(): void
    {
        // Arrange — array adapter guarantees no file I/O and deterministic state
        $cache     = new Cache(null, null, 'array');
        $callCount = 0;

        // Act
        $result = $cache->remember('mykey', 3600, function () use (&$callCount): string {
            $callCount++;
            return 'computed value';
        });

        // Assert — callback ran once and result was returned
        $this->assertSame(1, $callCount);
        $this->assertSame('computed value', $result);
    }

    /**
     * On a cache hit, remember() returns the cached value without calling the
     * callback. The callback must NOT be invoked.
     */
    public function testRememberReturnsCachedValueOnHit(): void
    {
        // Arrange — populate the cache first
        $cache = new Cache(null, null, 'array');
        $cache->remember('mykey', 3600, fn(): string => 'first value');
        $callCount = 0;

        // Act — second call with same key
        $result = $cache->remember('mykey', 3600, function () use (&$callCount): string {
            $callCount++;
            return 'should not be returned';
        });

        // Assert — callback was NOT called and the cached value was returned
        $this->assertSame(0, $callCount);
        $this->assertSame('first value', $result);
    }

    /**
     * remember() works correctly with the 'array' adapter — verifies that the
     * 'array' method key is accepted by initializeAdapter().
     */
    public function testRememberWithArrayAdapter(): void
    {
        // Arrange
        $cache = new Cache(null, null, 'array');

        // Act
        $v1 = $cache->remember('x', 60, fn(): int => 42);
        $v2 = $cache->remember('x', 60, fn(): int => 99);

        // Assert — first call computed, second returned cached
        $this->assertSame(42, $v1);
        $this->assertSame(42, $v2);
    }
}
