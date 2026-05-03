<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\AbstractAdapter;
use Pramnos\Cache\Adapter\FileAdapter;

/**
 * Characterization tests for the Cache subsystem (FileAdapter / AbstractAdapter).
 *
 * Locks: connect, save/load/delete, timeout/expiry, caching flag,
 * prefix management, and generateKey shape contracts.
 *
 * All tests run against a temporary directory; no DB or network needed.
 */
#[CoversClass(FileAdapter::class)]
#[CoversClass(AbstractAdapter::class)]
class FileAdapterCharacterizationTest extends TestCase
{
    private string $cacheDir;
    private FileAdapter $adapter;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/pramnos_cache_test_' . uniqid();
        mkdir($this->cacheDir, 0777, true);
        // No prefix so key assertions stay simple
        $this->adapter = new FileAdapter($this->cacheDir, '');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($dir);
    }

    // -----------------------------------------------------------------------
    // connect
    // -----------------------------------------------------------------------

    /**
     * connect() returns true for an existing, writable directory.
     */
    public function testConnectReturnsTrueForValidDirectory(): void
    {
        $result = $this->adapter->connect();
        $this->assertTrue($result);
    }

    /**
     * connect() returns false when no cache directory is configured.
     */
    public function testConnectReturnsFalseForEmptyDirectory(): void
    {
        $adapter = new FileAdapter('');
        $this->assertFalse($adapter->connect());
    }

    /**
     * connect() creates the directory automatically if it does not yet exist.
     */
    public function testConnectCreatesDirectoryIfMissing(): void
    {
        // Arrange
        $newDir = $this->cacheDir . '/new_subdir_' . uniqid();
        $adapter = new FileAdapter($newDir);

        // Act
        $result = $adapter->connect();

        // Assert
        $this->assertTrue($result);
        $this->assertDirectoryExists($newDir);
    }

    // -----------------------------------------------------------------------
    // save / load
    // -----------------------------------------------------------------------

    /**
     * save() returns true and load() retrieves the same data (array round-trip).
     */
    public function testSaveAndLoadRoundTripArray(): void
    {
        // Arrange
        $key  = 'test_item_' . uniqid();
        $data = ['id' => 42, 'name' => 'pramnos'];

        // Act
        $saved  = $this->adapter->save($key, $data, 3600);
        $loaded = $this->adapter->load($key, 3600);

        // Assert
        $this->assertTrue($saved);
        $this->assertSame($data, $loaded);
    }

    /**
     * save() and load() round-trip a string correctly.
     */
    public function testSaveAndLoadString(): void
    {
        $key = 'str_key_' . uniqid();
        $this->adapter->save($key, 'hello world', 3600);
        $this->assertSame('hello world', $this->adapter->load($key, 3600));
    }

    /**
     * Saving null cannot be round-tripped: isset($entry['data']) is false for
     * null, so load() returns false. This characterises the current
     * known-limited behaviour.
     */
    public function testSaveNullReturnsFalseOnLoad(): void
    {
        $key = 'null_key_' . uniqid();
        $this->adapter->save($key, null, 3600);
        $this->assertFalse($this->adapter->load($key, 3600));
    }

    /**
     * load() returns false for a non-existent key.
     */
    public function testLoadReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->adapter->load('definitely_missing_key_xyz', 3600));
    }

    /**
     * load() with timeout=0 treats the cache as eternal (never expires).
     */
    public function testLoadWithTimeoutZeroNeverExpires(): void
    {
        $key = 'eternal_' . uniqid();
        $this->adapter->save($key, 'immortal', 0);
        $this->assertSame('immortal', $this->adapter->load($key, 0));
    }

    /**
     * load() returns false when the file's mtime is older than the timeout.
     * We reach the protected getFilePath() via Reflection to touch the mtime.
     */
    public function testLoadReturnsFalseForExpiredCache(): void
    {
        // Arrange
        $key = 'expired_' . uniqid();
        $this->adapter->save($key, 'old data', 1);

        $method = new \ReflectionMethod($this->adapter, 'getFilePath');
        $method->setAccessible(true);
        $filePath = $method->invoke($this->adapter, $key, false);
        $this->assertIsString($filePath);
        $this->assertFileExists($filePath);
        touch($filePath, time() - 10); // age the file

        // Act & Assert
        $this->assertFalse($this->adapter->load($key, 1));
    }

    // -----------------------------------------------------------------------
    // delete
    // -----------------------------------------------------------------------

    /**
     * delete() removes the cache entry; subsequent load() returns false.
     */
    public function testDeleteRemovesEntry(): void
    {
        $key = 'delete_me_' . uniqid();
        $this->adapter->save($key, 'to be deleted', 3600);

        $this->assertTrue($this->adapter->delete($key));
        $this->assertFalse($this->adapter->load($key, 3600));
    }

    /**
     * delete() returns true even when the key does not exist (idempotent).
     */
    public function testDeleteIsIdempotentForMissingKey(): void
    {
        $this->assertTrue($this->adapter->delete('ghost_key_' . uniqid()));
    }

    // -----------------------------------------------------------------------
    // setCaching / isCachingEnabled (AbstractAdapter)
    // -----------------------------------------------------------------------

    /**
     * setCaching(false) disables all operations: save() and load() both return false.
     */
    public function testDisabledCachingCausesSaveAndLoadToReturnFalse(): void
    {
        $key = 'disabled_' . uniqid();
        $this->adapter->setCaching(false);
        $this->assertFalse($this->adapter->save($key, 'value', 3600));
        $this->assertFalse($this->adapter->load($key, 3600));
    }

    /**
     * setCaching() returns self for fluent chaining; isCachingEnabled() reflects the change.
     */
    public function testSetCachingReturnsSelfAndUpdatesState(): void
    {
        $result = $this->adapter->setCaching(false);
        $this->assertSame($this->adapter, $result);
        $this->assertFalse($this->adapter->isCachingEnabled());

        $this->adapter->setCaching(true);
        $this->assertTrue($this->adapter->isCachingEnabled());
    }

    // -----------------------------------------------------------------------
    // setPrefix / getPrefix (AbstractAdapter)
    // -----------------------------------------------------------------------

    /**
     * setPrefix/getPrefix round-trip correctly; setPrefix returns self.
     */
    public function testSetAndGetPrefix(): void
    {
        $result = $this->adapter->setPrefix('myprefix');
        $this->assertSame($this->adapter, $result);
        $this->assertSame('myprefix', $this->adapter->getPrefix());
    }

    // -----------------------------------------------------------------------
    // generateKey (AbstractAdapter)
    // -----------------------------------------------------------------------

    /**
     * generateKey() with no category or prefix produces "id.extension".
     */
    public function testGenerateKeyWithNoCategory(): void
    {
        // Adapter was constructed with empty prefix
        $key = $this->adapter->generateKey('myitem', '', 'cache');
        $this->assertSame('myitem.cache', $key);
    }

    /**
     * generateKey() with a prefix prepends "prefix_" to the key.
     */
    public function testGenerateKeyWithPrefixOnly(): void
    {
        $adapter = new FileAdapter($this->cacheDir, 'pfx');
        $key = $adapter->generateKey('myitem', '', 'cache');
        $this->assertSame('pfx_myitem.cache', $key);
    }

    /**
     * generateKey() with prefix and category produces a key that starts with
     * the prefix and ends with "id.extension".
     */
    public function testGenerateKeyWithCategoryAndPrefix(): void
    {
        $adapter = new FileAdapter($this->cacheDir, 'pfx');
        $key = $adapter->generateKey('myitem', 'cats', 'cache');
        $this->assertStringStartsWith('pfx_', $key);
        $this->assertStringEndsWith('myitem.cache', $key);
    }

    // -----------------------------------------------------------------------
    // getCategories
    // -----------------------------------------------------------------------

    /**
     * getCategories() returns an empty array when the cache dir has no sub-directories.
     */
    public function testGetCategoriesEmptyInitially(): void
    {
        $categories = $this->adapter->getCategories();
        $this->assertIsArray($categories);
        $this->assertEmpty($categories);
    }

    /**
     * getCategories() lists sub-directories created when saving a categorized key.
     * A key "catA_item" causes FileAdapter to create a "catA/" subdirectory.
     */
    public function testGetCategoriesReflectsSavedCategoryFolders(): void
    {
        // Arrange – save a key whose first segment is the category
        $this->adapter->save('catA_item.cache', 'data', 3600);

        // Act
        $categories = $this->adapter->getCategories();

        // Assert
        $this->assertContains('catA', $categories);
    }
}
