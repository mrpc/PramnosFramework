<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\MemcachedAdapter;

/**
 * Unit tests for Pramnos\Cache\Adapter\MemcachedAdapter.
 */
#[CoversClass(MemcachedAdapter::class)]
class MemcachedAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean dynamic mock state before each test
        if (class_exists('\Memcached')) {
            \Memcached::$mockInstance = null;
        }
    }

    protected function tearDown(): void
    {
        if (class_exists('\Memcached')) {
            \Memcached::$mockInstance = null;
        }
    }

    // =========================================================================
    // Class-not-exists path
    // =========================================================================

    /**
     * Test connect() when \Memcached class does not exist in runtime.
     * Note: This must run first because once eval defines Memcached, it cannot be undone.
     */
    public function testConnectReturnsFalseWhenClassDoesNotExist(): void
    {
        if (class_exists('\Memcached')) {
            $this->markTestSkipped('\Memcached class already defined, skipping non-existent class test.');
        }

        $adapter = new MemcachedAdapter('localhost', 11211);
        $this->assertFalse($adapter->connect());
    }

    // =========================================================================
    // Class-exists paths
    // =========================================================================

    private function defineMemcachedClassIfNeeded(): void
    {
        if (!class_exists('\Memcached')) {
            eval('
                class Memcached {
                    public const RES_SUCCESS = 0;
                    public const RES_NOTFOUND = 16;

                    public static $mockInstance = null;

                    private $resultCode = 0;
                    private $persistentId = "";

                    public function __construct($persistentId = "") {
                        $this->persistentId = $persistentId;
                    }

                    public function getServerList() {
                        return self::$mockInstance ? self::$mockInstance->getServerList() : [];
                    }

                    public function addServer($host, $port) {
                        return self::$mockInstance ? self::$mockInstance->addServer($host, $port) : true;
                    }

                    public function get($key) {
                        return self::$mockInstance ? self::$mockInstance->get($key) : false;
                    }

                    public function set($key, $value, $expire) {
                        return self::$mockInstance ? self::$mockInstance->set($key, $value, $expire) : true;
                    }

                    public function delete($key) {
                        return self::$mockInstance ? self::$mockInstance->delete($key) : true;
                    }

                    public function flush() {
                        return self::$mockInstance ? self::$mockInstance->flush() : true;
                    }

                    public function getResultCode() {
                        return self::$mockInstance ? self::$mockInstance->getResultCode() : $this->resultCode;
                    }

                    public function setResultCode($code) {
                        $this->resultCode = $code;
                    }

                    public function getStats() {
                        return self::$mockInstance ? self::$mockInstance->getStats() : [];
                    }

                    public function getAllKeys() {
                        return self::$mockInstance ? self::$mockInstance->getAllKeys() : false;
                    }
                }
            ');
        }
    }

    private function makeConnectedAdapter(): MemcachedAdapter
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211, 'pers_id', 'prefix_');

        // Set mock to return true on connect
        $mock = $this->createMock(\Memcached::class);
        $mock->method('getServerList')->willReturn([]);
        $mock->method('addServer')->willReturn(true);
        \Memcached::$mockInstance = $mock;

        $adapter->connect();
        return $adapter;
    }

    public function testConstructorAndProperties(): void
    {
        $adapter = new MemcachedAdapter('127.0.0.3', 11213, 'pid_123', 'pfx_');
        $refHost = new \ReflectionProperty($adapter, 'host');
        $refPort = new \ReflectionProperty($adapter, 'port');
        $refPid = new \ReflectionProperty($adapter, 'persistentId');
        
        $this->assertSame('127.0.0.3', $refHost->getValue($adapter));
        $this->assertSame(11213, $refPort->getValue($adapter));
        $this->assertSame('pid_123', $refPid->getValue($adapter));
    }

    public function testConnectUsesExistingServerList(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);

        $mock = $this->createMock(\Memcached::class);
        $mock->expects($this->once())->method('getServerList')->willReturn([
            ['host' => 'localhost', 'port' => 11211]
        ]);
        $mock->expects($this->never())->method('addServer');
        \Memcached::$mockInstance = $mock;

        $this->assertTrue($adapter->connect());
    }

    public function testConnectAddsServerIfEmpty(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);

        $mock = $this->createMock(\Memcached::class);
        $mock->expects($this->once())->method('getServerList')->willReturn([]);
        $mock->expects($this->once())->method('addServer')->with('localhost', 11211)->willReturn(true);
        \Memcached::$mockInstance = $mock;

        $this->assertTrue($adapter->connect());
        $this->assertInstanceOf(\Memcached::class, $adapter->getConnection());
    }

    public function testConnectFailureOnException(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);

        $mock = $this->createMock(\Memcached::class);
        $mock->expects($this->once())->method('getServerList')->willReturn([]);
        $mock->expects($this->once())->method('addServer')->willThrowException(new \RuntimeException('Connection error'));
        \Memcached::$mockInstance = $mock;

        $this->assertFalse($adapter->connect());
    }

    public function testLoadShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);
        $this->assertFalse($adapter->load('key'));

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->load('key'));
    }

    public function testLoadReturnsFalseOnMissingKeyAndResultCodeNotSuccess(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->with('key')->willReturn(false);
        $mock->expects($this->once())->method('getResultCode')->willReturn(16); // RES_NOTFOUND

        $this->assertFalse($adapter->load('key'));
    }

    public function testLoadReturnsFalseOnNonArrayEntry(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->with('load_key')->willReturn('string_not_array');
        $mock->expects($this->never())->method('getResultCode');

        $this->assertFalse($adapter->load('load_key'));
    }

    public function testLoadHandlesExpiredKeyByDeletingIt(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->with('exp_key')->willReturn([
            'data' => 'val',
            'time' => time() - 1000
        ]);
        $mock->expects($this->never())->method('getResultCode');
        $mock->expects($this->once())->method('delete')->with('exp_key')->willReturn(true);

        $this->assertFalse($adapter->load('exp_key', 500));
    }

    public function testLoadReturnsValidData(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->with('valid_key')->willReturn([
            'data' => 'myval',
            'time' => time()
        ]);
        $mock->expects($this->never())->method('getResultCode');

        $this->assertSame('myval', $adapter->load('valid_key'));
    }

    public function testLoadReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->load('key'));
    }

    public function testSaveShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);
        $this->assertFalse($adapter->save('key', 'data'));

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->save('key', 'data'));
    }

    public function testSaveReturnsFalseOnFailure(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('set')->willReturn(false);
        $mock->expects($this->never())->method('getResultCode');

        $this->assertFalse($adapter->save('key', 'val'));
    }

    public function testSaveStoresDataAndTracksCategoryKey(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;

        // Expectations for set (first for the item, second for the category key tracking list)
        $mock->expects($this->exactly(2))->method('set')->willReturnCallback(function ($key, $val, $exp) {
            return true;
        });

        // get() is called to get the existing index keys for the category tracking
        $mock->expects($this->once())->method('get')->with('prefix_category_keys_cat')->willReturn(['prefix_cat_item1']);
        $mock->expects($this->once())->method('getResultCode')->willReturn(0); // RES_SUCCESS

        $this->assertTrue($adapter->save('prefix_cat-item2', 'val'));
    }

    public function testSaveReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('set')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->save('key', 'myval'));
    }

    public function testDeleteShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);
        $this->assertFalse($adapter->delete('key'));

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->delete('key'));
    }

    public function testDeleteReturnsFalseOnFailureCodeNotNotFound(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('delete')->willReturn(false);
        $mock->expects($this->once())->method('getResultCode')->willReturn(1); // not SUCCESS or NOTFOUND

        $this->assertFalse($adapter->delete('key'));
    }

    public function testDeleteRemovesKeyAndUpdatesCategoryIndex(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;

        // delete is called twice: once for the key, and once for the index since it will become empty!
        $mock->expects($this->exactly(2))->method('delete')->willReturnCallback(function ($key) {
            return true;
        });
        $mock->expects($this->once())->method('getResultCode')->willReturn(0); // RES_SUCCESS
        $mock->expects($this->once())->method('get')->with('prefix_category_keys_cat')->willReturn(['prefix_cat_item1']);

        $this->assertTrue($adapter->delete('prefix_cat_item1'));
    }

    public function testDeleteRemovesKeyAndUpdatesCategoryIndexMultiple(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;

        // delete is called once for the key. set is called for the updated index!
        $mock->expects($this->once())->method('delete')->with('prefix_cat_item1')->willReturn(true);
        $mock->expects($this->once())->method('set')->with('prefix_category_keys_cat', ['prefix_cat_item2'], 0)->willReturn(true);
        $mock->expects($this->once())->method('getResultCode')->willReturn(0); // RES_SUCCESS
        $mock->expects($this->once())->method('get')->with('prefix_category_keys_cat')->willReturn(['prefix_cat_item1', 'prefix_cat_item2']);

        $this->assertTrue($adapter->delete('prefix_cat_item1'));
    }

    public function testDeleteReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('delete')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->delete('key'));
    }

    public function testClearShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);
        $this->assertFalse($adapter->clear());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertFalse($adapter->clear());
    }

    public function testClearWithoutCategoryFlushesAll(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('flush')->willReturn(true);

        $this->assertTrue($adapter->clear(''));
    }

    public function testClearWithoutCategoryReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('flush')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->clear(''));
    }

    public function testClearWithCategoryRemovesKeysInIndex(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;

        $mock->expects($this->once())->method('get')->with('prefix_category_keys_cat')->willReturn(['prefix_cat_k1', 'prefix_cat_k2']);
        
        // Deletes k1, k2, and the index key category_keys_cat
        $mock->expects($this->exactly(3))->method('delete')->willReturn(true);

        $this->assertTrue($adapter->clear('cat'));
    }

    public function testClearWithCategoryReturnsFalseOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->willThrowException(new \RuntimeException('Error'));

        $this->assertFalse($adapter->clear('cat'));
    }

    public function testGetCategoriesShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);
        $this->assertSame([], $adapter->getCategories());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertSame([], $adapter->getCategories());
    }

    public function testGetCategoriesListsStoredCategories(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->with('prefix_memcachedtags')->willReturn([
            'cat1' => true,
            'cat2' => true
        ]);

        $this->assertSame(['cat1', 'cat2'], $adapter->getCategories());
    }

    public function testGetCategoriesReturnsEmptyOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->willThrowException(new \RuntimeException('Error'));

        $this->assertSame([], $adapter->getCategories());
    }

    public function testGetStatsShortCircuitsWhenNotConnectedOrCachingDisabled(): void
    {
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);
        $expected = ['method' => 'memcached', 'categories' => 0, 'items' => 0];
        $this->assertSame($expected, $adapter->getStats());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertSame($expected, $adapter->getStats());
    }

    public function testGetStatsReturnsMetadataAndItems(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->with('prefix_memcachedtags')->willReturn([
            'cat1' => true,
            'cat2' => true
        ]);

        $mock->expects($this->once())->method('getStats')->willReturn([
            'localhost:11211' => ['curr_items' => 456]
        ]);

        $stats = $adapter->getStats();
        $this->assertSame('memcached', $stats['method']);
        $this->assertSame(2, $stats['categories']);
        $this->assertSame(456, $stats['items']);
    }

    public function testGetStatsHandlesExceptionAndReturnsZeroValues(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('get')->willThrowException(new \RuntimeException('Error'));

        $stats = $adapter->getStats();
        $this->assertSame('memcached', $stats['method']);
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
        $this->defineMemcachedClassIfNeeded();
        $adapter = new MemcachedAdapter('localhost', 11211);
        $this->assertSame([], $adapter->getAllItems());

        $adapter = $this->makeConnectedAdapter();
        $adapter->setCaching(false);
        $this->assertSame([], $adapter->getAllItems());
    }

    public function testGetAllItemsReturnsLimitationNoticeWhenGetAllKeysFails(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('getAllKeys')->willReturn(false);

        $items = $adapter->getAllItems();
        $this->assertNotEmpty($items);
        $this->assertSame('memcached_limitation', $items[0]['key']);
        $this->assertSame('info', $items[0]['type']);
    }

    public function testGetAllItemsReturnsMetadataOfKeys(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('getAllKeys')->willReturn([
            'prefix_key1',
            'prefix_memcachedtags', // tagsKey should be filtered out
            'other_prefix_key2',   // other prefix should be filtered out
            'prefix_key3'
        ]);

        $mock->expects($this->exactly(2))->method('get')->willReturnCallback(function ($key) {
            if ($key === 'prefix_key1') {
                return ['data' => 'val1', 'time' => 1714680000];
            }
            if ($key === 'prefix_key3') {
                return ['data' => 100, 'time' => 1714680100];
            }
            return false;
        });

        $items = $adapter->getAllItems('', 10);
        $this->assertCount(2, $items);
        $this->assertSame('key1', $items[0]['key']);
        $this->assertSame('string', $items[0]['type']);
        $this->assertSame('key3', $items[1]['key']);
        $this->assertSame('integer', $items[1]['type']);
    }

    public function testGetAllItemsReturnsErrorOnException(): void
    {
        $adapter = $this->makeConnectedAdapter();
        $mock = \Memcached::$mockInstance;
        $mock->expects($this->once())->method('getAllKeys')->willThrowException(new \RuntimeException('Error'));

        $items = $adapter->getAllItems();
        $this->assertNotEmpty($items);
        $this->assertSame('memcached_error', $items[0]['key']);
        $this->assertSame('error', $items[0]['type']);
    }
}
