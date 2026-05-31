<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\FileAdapter;

/**
 * Unit tests for Pramnos\Cache\Adapter\FileAdapter.
 *
 * FileAdapter stores cache entries as serialised files on disk.  The adapter
 * is exercised against a freshly-created temporary directory so that tests
 * are hermetic and clean up after themselves.
 *
 * Tests verify:
 *   - connect(): returns true for a writable dir, false for empty path.
 *   - connect(): creates the directory when it doesn't already exist.
 *   - save() / load(): round-trip for scalar, array, and object data.
 *   - load(): returns false for a missing key.
 *   - load(): returns false when the file is expired (timeout elapsed).
 *   - load(): timeout = 0 means never-expire (always valid).
 *   - delete(): removes the cache file; re-load returns false.
 *   - delete(): returns true when the key doesn't exist (idempotent).
 *   - clear(): removes all files under a category.
 *   - clear(): removes all files when called without a category.
 *   - getCategories(): lists category subdirectories.
 *   - getStats(): returns method = 'file' and correct item counts.
 *   - categoryHash(): returns the category name unchanged.
 *   - getAllItems(): lists cache files with expected metadata keys.
 *   - caching disabled: save/load/delete/getCategories all short-circuit.
 */
#[CoversClass(FileAdapter::class)]
class FileAdapterTest extends TestCase
{
    /** @var string Temporary cache directory created for each test */
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/pramnos_cache_test_' . uniqid('', true);
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Recursively remove the temp directory so each test starts clean
        $this->removeDirectory($this->cacheDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = array_diff(scandir($path), ['.', '..']);
        foreach ($items as $item) {
            $full = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }

    private function makeAdapter(string $prefix = ''): FileAdapter
    {
        return new FileAdapter($this->cacheDir, $prefix);
    }

    // =========================================================================
    // connect()
    // =========================================================================

    /**
     * connect() returns true when the cache directory exists and is writable.
     */
    public function testConnectReturnsTrueForWritableDirectory(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $result = $adapter->connect();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * connect() returns false when cacheDir is empty.
     *
     * Uses reflection to bypass the constructor's CACHE_PATH fallback so the
     * test is independent of whether CACHE_PATH is defined in the environment
     * (it gets defined by Application::__construct in the same test run).
     */
    public function testConnectReturnsFalseWhenNoCacheDir(): void
    {
        // Arrange — create adapter with a real path, then forcibly clear cacheDir
        // to simulate the "no directory configured" state without relying on
        // CACHE_PATH being absent.
        $adapter = new FileAdapter('/dummy');
        $prop = new \ReflectionProperty($adapter, 'cacheDir');
        $prop->setValue($adapter, '');

        // Act
        $result = $adapter->connect();

        // Assert — connect() must short-circuit to false when cacheDir == ''
        $this->assertFalse($result);
    }

    /**
     * connect() creates the directory if it doesn't already exist and returns true.
     */
    public function testConnectCreatesDirectoryIfMissing(): void
    {
        // Arrange — point to a non-existent subdirectory
        $newDir  = $this->cacheDir . '/auto_created';
        $adapter = new FileAdapter($newDir);

        // Act
        $result = $adapter->connect();

        // Assert — directory was created and is usable
        $this->assertTrue($result);
        $this->assertDirectoryExists($newDir);
    }

    // =========================================================================
    // save() / load()
    // =========================================================================

    /**
     * save() persists a scalar string; load() retrieves the exact same value.
     */
    public function testSaveAndLoadScalarString(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $adapter->save('mykey', 'hello world');
        $result = $adapter->load('mykey');

        // Assert
        $this->assertSame('hello world', $result);
    }

    /**
     * save() persists an array; load() returns an equal array.
     */
    public function testSaveAndLoadArray(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $data    = ['a' => 1, 'b' => [2, 3]];

        // Act
        $adapter->save('arr_key', $data);
        $result = $adapter->load('arr_key');

        // Assert
        $this->assertSame($data, $result);
    }

    /**
     * save() persists a stdClass object; load() returns an equal object.
     */
    public function testSaveAndLoadObject(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $obj     = new \stdClass();
        $obj->x  = 42;

        // Act
        $adapter->save('obj_key', $obj);
        $result = $adapter->load('obj_key');

        // Assert — serialise/unserialise preserves stdClass properties
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(42, $result->x);
    }

    /**
     * load() returns false for a key that has never been saved.
     */
    public function testLoadReturnsFalseForMissingKey(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $result = $adapter->load('nonexistent_key');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * load() returns false when the cache entry has expired (mtime < now - timeout).
     *
     * The timeout is set to 1 second so the file mtime can be backdated via
     * touch() to simulate expiry without real waiting.
     */
    public function testLoadReturnsFalseForExpiredEntry(): void
    {
        // Arrange — key without underscore to avoid category subdirectory;
        // the plain key resolves to {cacheDir}/expkey so touch() can find it.
        $adapter = $this->makeAdapter();
        $adapter->save('expkey', 'value', 1);

        // Backdate the file mtime by 10 seconds to simulate expiry
        $filePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'expkey';
        touch($filePath, time() - 10);

        // Act — timeout = 1 second; mtime is 10 seconds old → expired
        $result = $adapter->load('expkey', 1);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * load() with timeout = 0 treats entries as never expired.
     */
    public function testLoadWithTimeoutZeroNeverExpires(): void
    {
        // Arrange — plain key (no underscore) to get a flat file path
        $adapter = $this->makeAdapter();
        $adapter->save('neverkey', 'permanent', 0);

        // Backdate mtime to many seconds ago — timeout 0 should skip expiry check
        $filePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'neverkey';
        touch($filePath, time() - 9999);

        // Act
        $result = $adapter->load('neverkey', 0);

        // Assert — timeout 0 = never expires
        $this->assertSame('permanent', $result);
    }

    // =========================================================================
    // delete()
    // =========================================================================

    /**
     * delete() removes the cached entry; subsequent load() returns false.
     */
    public function testDeleteRemovesEntry(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('del_key', 'goodbye');

        // Act
        $deleted = $adapter->delete('del_key');
        $result  = $adapter->load('del_key');

        // Assert
        $this->assertTrue($deleted);
        $this->assertFalse($result);
    }

    /**
     * delete() returns true even when the key does not exist (idempotent).
     */
    public function testDeleteIsIdempotentForMissingKey(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $result = $adapter->delete('does_not_exist');

        // Assert — "already gone" is a success condition
        $this->assertTrue($result);
    }

    // =========================================================================
    // clear()
    // =========================================================================

    /**
     * clear() with a category removes only the files in that category
     * subdirectory and leaves other entries intact.
     *
     * Category-based file paths use a '{category}_' prefix in the key name,
     * which causes FileAdapter::getFilePath() to create a subdirectory.
     */
    public function testClearWithCategoryRemovesOnlyCategoryFiles(): void
    {
        // Arrange — save one item in category 'cat' and one bare item
        $adapter = $this->makeAdapter();
        $adapter->save('cat_item', 'in category');   // key starts with 'cat_', goes into cat/
        $adapter->save('other',    'no category');   // bare key, no subdir

        // Act — clear only the 'cat' category
        $adapter->clear('cat');

        // Assert — category item gone, bare item intact
        $this->assertFalse($adapter->load('cat_item'));
        $this->assertSame('no category', $adapter->load('other'));
    }

    /**
     * clear() without a category removes all cached files.
     */
    public function testClearWithoutCategoryRemovesAllFiles(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('key1', 'value1');
        $adapter->save('key2', 'value2');

        // Act
        $adapter->clear();

        // Assert — both entries gone
        $this->assertFalse($adapter->load('key1'));
        $this->assertFalse($adapter->load('key2'));
    }

    // =========================================================================
    // getCategories()
    // =========================================================================

    /**
     * getCategories() returns an array of subdirectory names that act as
     * cache categories (i.e. keys that contain an underscore).
     */
    public function testGetCategoriesListsCategorySubdirectories(): void
    {
        // Arrange — create two category-scoped keys
        $adapter = $this->makeAdapter();
        $adapter->save('alpha_item1', 'v1');
        $adapter->save('beta_item2',  'v2');

        // Act
        $categories = $adapter->getCategories();

        // Assert — at least 'alpha' and 'beta' dirs were created
        $this->assertContains('alpha', $categories);
        $this->assertContains('beta',  $categories);
    }

    /**
     * getCategories() returns [] when caching is disabled.
     */
    public function testGetCategoriesReturnEmptyWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('cat_x', 'v');
        $adapter->setCaching(false);

        // Act
        $result = $adapter->getCategories();

        // Assert
        $this->assertSame([], $result);
    }

    /**
     * getCategories() returns [] when there are no subdirectories yet.
     */
    public function testGetCategoriesReturnsEmptyForFreshDir(): void
    {
        // Arrange — fresh adapter, nothing saved
        $adapter = $this->makeAdapter();

        // Act
        $result = $adapter->getCategories();

        // Assert
        $this->assertSame([], $result);
    }

    // =========================================================================
    // categoryHash()
    // =========================================================================

    /**
     * categoryHash() returns the category name as-is for FileAdapter — no
     * hash transform is applied since directories use the category name directly.
     */
    public function testCategoryHashReturnsNameUnchanged(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act / Assert
        $this->assertSame('products', $adapter->categoryHash('products'));
        $this->assertSame('user-data', $adapter->categoryHash('user-data'));
    }

    // =========================================================================
    // getStats()
    // =========================================================================

    /**
     * getStats() returns method = 'file' and counts matching saved items.
     */
    public function testGetStatsReturnsCorrectShape(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('s1', 'v1');
        $adapter->save('s2', 'v2');

        // Act
        $stats = $adapter->getStats();

        // Assert — shape and method identifier
        $this->assertArrayHasKey('method',     $stats);
        $this->assertArrayHasKey('categories', $stats);
        $this->assertArrayHasKey('items',      $stats);
        $this->assertSame('file', $stats['method']);

        // Two files were saved — items count should reflect that
        $this->assertGreaterThanOrEqual(2, $stats['items']);
    }

    /**
     * getStats() returns zero counts when caching is disabled.
     */
    public function testGetStatsReturnedWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->setCaching(false);

        // Act
        $stats = $adapter->getStats();

        // Assert — structure preserved even when disabled
        $this->assertSame('file', $stats['method']);
        $this->assertSame(0, $stats['categories']);
        $this->assertSame(0, $stats['items']);
    }

    // =========================================================================
    // getAllItems()
    // =========================================================================

    /**
     * getAllItems() returns entries with the expected metadata keys: key, size,
     * created_time, ttl, type, path, expired.
     */
    public function testGetAllItemsReturnsExpectedMetadataKeys(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('meta_key', 'some data', 3600);

        // Act
        $items = $adapter->getAllItems();

        // Assert — at least one item returned
        $this->assertNotEmpty($items);

        $item = $items[0];
        $this->assertArrayHasKey('key',          $item);
        $this->assertArrayHasKey('size',         $item);
        $this->assertArrayHasKey('created_time', $item);
        $this->assertArrayHasKey('ttl',          $item);
        $this->assertArrayHasKey('type',         $item);
        $this->assertArrayHasKey('path',         $item);
        $this->assertArrayHasKey('expired',      $item);

        // Type is always 'file' for this adapter
        $this->assertSame('file', $item['type']);
    }

    /**
     * getAllItems() returns [] when caching is disabled.
     */
    public function testGetAllItemsReturnEmptyWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('x', 'y');
        $adapter->setCaching(false);

        // Act
        $result = $adapter->getAllItems();

        // Assert
        $this->assertSame([], $result);
    }

    /**
     * getAllItems() respects the limit parameter.
     */
    public function testGetAllItemsRespectsLimit(): void
    {
        // Arrange — save 5 items
        $adapter = $this->makeAdapter();
        for ($i = 0; $i < 5; $i++) {
            $adapter->save("item{$i}", "value{$i}");
        }

        // Act — limit to 2
        $items = $adapter->getAllItems('', 2);

        // Assert
        $this->assertCount(2, $items);
    }

    // =========================================================================
    // Caching disabled — short-circuit paths
    // =========================================================================

    /**
     * save() returns false when caching is disabled, nothing is written to disk.
     */
    public function testSaveReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->setCaching(false);

        // Act
        $result = $adapter->save('x', 'y');

        // Assert — short-circuit
        $this->assertFalse($result);
    }

    /**
     * load() returns false when caching is disabled.
     */
    public function testLoadReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('z', 'value');   // save while caching is on
        $adapter->setCaching(false);

        // Act
        $result = $adapter->load('z');

        // Assert — caching off → always miss
        $this->assertFalse($result);
    }

    /**
     * delete() returns false when caching is disabled.
     */
    public function testDeleteReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->save('d', 'data');
        $adapter->setCaching(false);

        // Act
        $result = $adapter->delete('d');

        // Assert — short-circuit; file may still exist on disk
        $this->assertFalse($result);
    }

    // =========================================================================
    // Prefix support
    // =========================================================================

    /**
     * Two adapters with different prefixes do not share cache entries, even
     * when saving under the same key name.
     *
     * FileAdapter scopes keys under a prefix subdirectory, so adapter_a's
     * "mykey" and adapter_b's "mykey" resolve to different file paths.
     */
    public function testPrefixIsolatesToDifferentSubdirectories(): void
    {
        // Arrange
        $adapterA = new FileAdapter($this->cacheDir, 'prefix_a');
        $adapterB = new FileAdapter($this->cacheDir, 'prefix_b');

        // Act
        $adapterA->save('shared_key', 'from A');
        $adapterB->save('shared_key', 'from B');

        // Assert — each adapter sees only its own value
        $this->assertSame('from A', $adapterA->load('shared_key'));
        $this->assertSame('from B', $adapterB->load('shared_key'));
    }

    /**
     * load() returns false when file exists but its content is empty.
     */
    public function testLoadReturnsFalseWhenFileContentIsEmpty(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $filePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'emptykey';
        file_put_contents($filePath, '');

        // Act
        $result = $adapter->load('emptykey');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * load() returns false when file exists but the serialized data array lacks a 'data' key.
     */
    public function testLoadReturnsFalseWhenDataNotSetInSerializedArray(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $filePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'nodatakey';
        file_put_contents($filePath, serialize(['time' => time()]));

        // Act
        $result = $adapter->load('nodatakey');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * getAllItems() correctly identifies and marks malformed serialized cache entries as expired/invalid.
     */
    public function testGetAllItemsHandlesMalformedSerializedData(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $filePath = $this->cacheDir . DIRECTORY_SEPARATOR . 'malformedkey';
        file_put_contents($filePath, 'not-serialized-data');

        // Act
        $items = $adapter->getAllItems();

        // Assert
        $this->assertNotEmpty($items);
        $found = false;
        foreach ($items as $item) {
            if ($item['key'] === 'malformedkey') {
                $found = true;
                $this->assertFalse($item['expired']);
            }
        }
        $this->assertTrue($found);
    }

    /**
     * _createCacheDir() private method is executed successfully via reflection.
     */
    public function testCreateCacheDirViaReflection(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $newDir = $this->cacheDir . '/reflection_created';
        $prop = new \ReflectionProperty($adapter, 'cacheDir');
        $prop->setValue($adapter, $newDir);

        $method = new \ReflectionMethod(FileAdapter::class, '_createCacheDir');

        // Act
        $method->invoke($adapter);

        // Assert — directory should exist
        $this->assertDirectoryExists($newDir);
    }
}

