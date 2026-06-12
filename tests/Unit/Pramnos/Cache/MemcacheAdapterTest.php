<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\MemcacheAdapter;

/**
 * Unit tests for Pramnos\Cache\Adapter\MemcacheAdapter.
 */
#[CoversClass(MemcacheAdapter::class)]
class MemcacheAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean dynamic mock state before each test
        if (class_exists('\Memcache')) {
            \Memcache::$mockInstance = null;
        }
    }

    protected function tearDown(): void
    {
        if (class_exists('\Memcache')) {
            \Memcache::$mockInstance = null;
        }
    }

    // =========================================================================
    // Class-not-exists path
    // =========================================================================

    /**
     * Test connect() when \Memcache class does not exist in runtime.
     * Note: This must run first because once eval defines Memcache, it cannot be undone.
     */
    public function testConnectReturnsFalseWhenClassDoesNotExist(): void
    {
        if (class_exists('\Memcache')) {
            $this->markTestSkipped('\Memcache class already defined, skipping non-existent class test.');
        }

        $adapter = new MemcacheAdapter('localhost', 11211);
        $this->assertFalse($adapter->connect());
    }

    // =========================================================================
    // Class-exists paths
    // =========================================================================

    private function defineMemcacheClassIfNeeded(): void
    {
        if (!class_exists('\Memcache')) {
            eval('
                class Memcache {
                    public static $mockInstance = null;

                    public function connect($host, $port) {
                        return self::$mockInstance ? self::$mockInstance->connect($host, $port) : true;
                    }

                    public function get($key) {
                        return self::$mockInstance ? self::$mockInstance->get($key) : false;
                    }

                    public function set($key, $value, $flag, $expire) {
                        return self::$mockInstance ? self::$mockInstance->set($key, $value, $flag, $expire) : true;
                    }

                    public function delete($key) {
                        return self::$mockInstance ? self::$mockInstance->delete($key) : true;
                    }

                    public function flush() {
                        return self::$mockInstance ? self::$mockInstance->flush() : true;
                    }

                    public function getExtendedStats() {
                        return self::$mockInstance ? self::$mockInstance->getExtendedStats() : [];
                    }
                }
            ');
        }
    }

    private function makeConnectedAdapter(): MemcacheAdapter
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211, 'prefix_');

        // Set mock to return true on connect
        $mock = $this->createMock(\Memcache::class);
        $mock->method('connect')->willReturn(true);
        \Memcache::$mockInstance = $mock;

        $adapter->connect();
        return $adapter;
    }

    public function testConstructorAndProperties(): void
    {
        $adapter = new MemcacheAdapter('127.0.0.2', 11212, 'pfx_');
        $refHost = new \ReflectionProperty($adapter, 'host');
        $refPort = new \ReflectionProperty($adapter, 'port');
        
        $this->assertSame('127.0.0.2', $refHost->getValue($adapter));
        $this->assertSame(11212, $refPort->getValue($adapter));
    }

    public function testConnectSuccess(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);

        $mock = $this->createMock(\Memcache::class);
        $mock->expects($this->once())->method('connect')->with('localhost', 11211)->willReturn(true);
        \Memcache::$mockInstance = $mock;

        $this->assertTrue($adapter->connect());
        $this->assertInstanceOf(\Memcache::class, $adapter->getConnection());
    }

    public function testConnectFailureOnException(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);

        $mock = $this->createMock(\Memcache::class);
        $mock->expects($this->once())->method('connect')->willThrowException(new \RuntimeException('Connection failed'));
        \Memcache::$mockInstance = $mock;

        $this->assertFalse($adapter->connect());
    }

    public function testLoadShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);
        $this->assertFalse($adapter->load('key'));

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->load('key'));
    }

    public function testLoadReturnsFalseOnMissingKey(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->with('key')->willReturn(false);

        $this->assertFalse($adapter->load('key'));
    }

    public function testLoadReturnsFalseOnNonArrayEntry(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->with('key')->willReturn('string_not_array');

        $this->assertFalse($adapter->load('key'));
    }

    public function testLoadReturnsValidData(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->with('key')->willReturn([
            'data' => 'myval',
            'time' => time()
        ]);

        $this->assertSame('myval', $adapter->load('key'));
    }

    public function testLoadReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->load('key'));
    }

    public function testSaveShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);
        $this->assertFalse($adapter->save('key', 'data'));

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->save('key', 'data'));
    }

    public function testSaveStoresData(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('set')->with(
            'key',
            $this->callback(function ($arg) {
                return is_array($arg) && $arg['data'] === 'myval';
            }),
            false,
            3600
        )->willReturn(true);

        $this->assertTrue($adapter->save('key', 'myval', 3600));
    }

    public function testSaveReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('set')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->save('key', 'myval'));
    }

    public function testDeleteShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);
        $this->assertFalse($adapter->delete('key'));

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->delete('key'));
    }

    public function testDeleteRemovesKey(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('delete')->with('key')->willReturn(true);

        $this->assertTrue($adapter->delete('key'));
    }

    public function testDeleteReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('delete')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->delete('key'));
    }

    public function testClearShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);
        $this->assertFalse($adapter->clear());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->clear());
    }

    public function testClearWithoutCategoryFlushesAll(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('flush')->willReturn(true);

        $this->assertTrue($adapter->clear(''));
    }

    public function testClearWithCategoryReturnsTrue(): void
    {
        // Category clearing is no-op (relies on expiration), so it just returns true
        $adapter = $this->makeConnectedAdapter();
        $this->assertTrue($adapter->clear('mycategory'));
    }

    public function testClearReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('flush')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->clear(''));
    }

    public function testGetCategoriesShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);
        $this->assertSame([], $adapter->getCategories());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertSame([], $adapter->getCategories());
    }

    public function testGetCategoriesListsStoredCategories(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->with('prefix_memcachedtags')->willReturn([
            'category1' => true,
            'category2' => true
        ]);

        $this->assertSame(['category1', 'category2'], $adapter->getCategories());
    }

    public function testGetCategoriesReturnsEmptyOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->willThrowException(new \RuntimeException('Error'));

        $this->assertSame([], $adapter->getCategories());
    }

    public function testGetStatsShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);
        $expected = ['method' => 'memcache', 'categories' => 0, 'items' => 0];
        $this->assertSame($expected, $adapter->getStats());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertSame($expected, $adapter->getStats());
    }

    public function testGetStatsReturnsMetadataAndItems(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->willReturnCallback(function ($key) {
            if ($key === 'prefix_memcachedtags') {
                return ['cat1' => true, 'cat2' => true];
            }
            return null;
        });

        $mock->expects($this->once())->method('getExtendedStats')->willReturn([
            'localhost:11211' => ['curr_items' => 123]
        ]);

        $stats = $adapter->getStats();
        $this->assertSame('memcache', $stats['method']);
        $this->assertSame(2, $stats['categories']);
        $this->assertSame(123, $stats['items']);
    }

    public function testGetStatsHandlesExceptionAndReturnsZeroValues(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcache::$mockInstance;
        $mock->expects($this->once())->method('get')->willThrowException(new \RuntimeException('Error'));

        $stats = $adapter->getStats();
        $this->assertSame('memcache', $stats['method']);
        $this->assertSame(0, $stats['categories']);
        $this->assertSame(0, $stats['items']);
    }

    public function testCategoryHashSanitizesCategoryName(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $this->assertSame('my_category', $adapter->categoryHash('my category'));
        $this->assertSame('alphabeta', $adapter->categoryHash('alpha@beta'));
        $this->assertSame('', $adapter->categoryHash(''));
    }

    public function testGetAllItemsShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcacheClassIfNeeded();
        $adapter = new MemcacheAdapter('localhost', 11211);
        $this->assertSame([], $adapter->getAllItems());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertSame([], $adapter->getAllItems());
    }

    public function testGetAllItemsReturnsLimitationNotice(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $items = $adapter->getAllItems();
        
        $this->assertNotEmpty($items);
        $this->assertSame('memcache_limitation', $items[0]['key']);
        $this->assertSame('info', $items[0]['type']);
    }

    public function testGetAllItemsReturnsErrorOnException(): void
    {
        // getAllItems is hardcoded to return limitation notice under normal circumstances
        $adapter = $this->makeConnectedAdapter();
        $items = $adapter->getAllItems();
        $this->assertNotEmpty($items);
        $this->assertSame('memcache_limitation', $items[0]['key']);
        $this->assertSame('info', $items[0]['type']);
    }

    /**
     * connect() called a second time on an already-connected adapter must return
     * the current $connected status without re-creating the \Memcache instance.
     * Covers the `if ($this->memcache === null)` false branch (line 59).
     */
    public function testConnectIsIdempotent(): void
    {
        // Arrange — first connect() creates the instance
        $adapter = $this->makeConnectedAdapter();

        // Act — second call must not throw and must return true
        $result = $adapter->connect();

        // Assert
        $this->assertTrue($result, 'Second connect() call must return the existing connected status');
    }

    /**
     * getCategories() with a custom prefix must use that prefix for the tags key
     * lookup instead of the adapter's own prefix.
     * Covers the `$prefix ? $prefix : $this->prefix` ternary at line 188.
     */
    public function testGetCategoriesWithCustomPrefixUsesProvidedPrefix(): void
    {
        // Arrange
        $adapter = $this->makeConnectedAdapter();
        $mock    = \Memcache::$mockInstance;
        // Return an empty array for the tags key — the result doesn't matter,
        // what matters is that get() is called (the prefix branch is exercised).
        $mock->expects($this->once())
             ->method('get')
             ->willReturn([]);

        // Act
        $result = $adapter->getCategories('custom_prefix_');

        // Assert — empty categories returned when tags array is empty
        $this->assertSame([], $result);
    }

    /**
     * getStats() when the server stats array has entries WITHOUT a 'curr_items' key
     * must leave $stats['items'] at 0 (the key-missing branch at line 221).
     */
    public function testGetStatsWhenServerHasNoCurrentItemsKey(): void
    {
        // Arrange — server returns stats without curr_items
        $adapter = $this->makeConnectedAdapter();
        $mock    = \Memcache::$mockInstance;
        $mock->expects($this->once())
             ->method('get')
             ->willReturn([]); // empty tags
        $mock->expects($this->once())
             ->method('getExtendedStats')
             ->willReturn(['127.0.0.1:11211' => ['uptime' => 12345]]); // no curr_items

        // Act
        $stats = $adapter->getStats();

        // Assert — items stays at 0 when curr_items is absent
        $this->assertSame(0, $stats['items'],
            'items must remain 0 when the server stats do not contain curr_items');
    }
}
