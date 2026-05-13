<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Cache\SimpleCache;
use Pramnos\Cache\SimpleCacheInvalidArgumentException;

/**
 * Unit tests for Pramnos\Cache\SimpleCache and SimpleCacheInvalidArgumentException.
 *
 * SimpleCache is a PSR-16 (CacheInterface) wrapper around the framework's
 * native Cache class.  Tests use a PHPUnit mock for Cache so no real backend
 * (file, Memcached, Redis) is required.
 *
 * Tests verify:
 *   - get(): delegates to Cache::load(), returns default when load() returns false/null.
 *   - set(): delegates to Cache::save(), always returns true.
 *   - delete(): delegates to Cache::delete(), always returns true.
 *   - clear(): delegates to Cache::clear(), always returns true.
 *   - has(): returns true when Cache::load() returns a truthy value, false otherwise.
 *   - getMultiple(): aggregates get() for multiple keys.
 *   - setMultiple(): aggregates set() for multiple key–value pairs.
 *   - deleteMultiple(): aggregates delete() for multiple keys.
 *   - validateKey(): throws SimpleCacheInvalidArgumentException for empty keys and
 *     for keys containing PSR-16 reserved characters {, }, (, ), /, \, @, :.
 *   - normalizeTtl(): null is accepted; DateInterval is converted to seconds.
 *   - SimpleCacheInvalidArgumentException: is-a InvalidArgumentException + PSR interface.
 */
#[CoversClass(SimpleCache::class)]
#[CoversClass(SimpleCacheInvalidArgumentException::class)]
class SimpleCacheTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Returns a PHPUnit mock of Cache with the constructor suppressed so no
     * real backend initialisation happens.
     *
     * @return Cache&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockCache(): Cache
    {
        return $this->createMock(Cache::class);
    }

    // =========================================================================
    // get()
    // =========================================================================

    /**
     * get() calls Cache::load() and returns the loaded value when it is not
     * null or false.
     */
    public function testGetReturnsCachedValue(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturn('cached_value');
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->get('mykey');

        // Assert
        $this->assertSame('cached_value', $result);
    }

    /**
     * get() returns the $default when Cache::load() returns false (cache miss).
     */
    public function testGetReturnsDefaultOnCacheMiss(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturn(false);
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->get('missing', 'fallback');

        // Assert
        $this->assertSame('fallback', $result);
    }

    /**
     * get() returns the $default when Cache::load() returns null.
     */
    public function testGetReturnsDefaultWhenLoadReturnsNull(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturn(null);
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->get('key', 'default');

        // Assert
        $this->assertSame('default', $result);
    }

    /**
     * get() returns null as the default default (when not specified).
     */
    public function testGetDefaultDefaultIsNull(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturn(false);
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->get('x');

        // Assert — no explicit default → null
        $this->assertNull($result);
    }

    // =========================================================================
    // set()
    // =========================================================================

    /**
     * set() calls Cache::save() with the value and key, returns true.
     */
    public function testSetDelegatesToSaveAndReturnsTrue(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->expects($this->once())->method('save')->with('hello', 'greet');
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->set('greet', 'hello');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * set() with a DateInterval TTL converts correctly (no exception thrown).
     */
    public function testSetWithDateIntervalTtlAccepted(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('save')->willReturn(true);
        $simple = new SimpleCache($cache);

        $interval = new \DateInterval('PT30M'); // 30 minutes

        // Act — should not throw
        $result = $simple->set('key', 'val', $interval);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * set() with null TTL is accepted (use driver default).
     */
    public function testSetWithNullTtlAccepted(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('save')->willReturn(true);
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->set('k', 'v', null);

        // Assert
        $this->assertTrue($result);
    }

    // =========================================================================
    // delete()
    // =========================================================================

    /**
     * delete() calls Cache::delete() and returns true.
     */
    public function testDeleteDelegatesToDeleteAndReturnsTrue(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->expects($this->once())->method('delete')->with('del_key');
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->delete('del_key');

        // Assert
        $this->assertTrue($result);
    }

    // =========================================================================
    // clear()
    // =========================================================================

    /**
     * clear() calls Cache::clear() and returns true.
     */
    public function testClearDelegatesToClearAndReturnsTrue(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->expects($this->once())->method('clear');
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->clear();

        // Assert
        $this->assertTrue($result);
    }

    // =========================================================================
    // has()
    // =========================================================================

    /**
     * has() returns true when Cache::load() returns a non-false/non-null value.
     */
    public function testHasReturnsTrueWhenEntryExists(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturn('something');
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->has('key');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * has() returns false when Cache::load() returns false (cache miss).
     */
    public function testHasReturnsFalseOnCacheMiss(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturn(false);
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->has('missing');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * has() returns false when Cache::load() returns null.
     */
    public function testHasReturnsFalseWhenLoadReturnsNull(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturn(null);
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->has('key');

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // getMultiple()
    // =========================================================================

    /**
     * getMultiple() returns a map of key → value for each key in the iterable,
     * using the default for any missed entries.
     */
    public function testGetMultipleReturnsMapOfValues(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->method('load')->willReturnMap([
            ['alpha', 'value_alpha'],
            ['beta',  false],
        ]);
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->getMultiple(['alpha', 'beta'], 'default');

        // Assert
        $this->assertSame('value_alpha', $result['alpha']);
        $this->assertSame('default',     $result['beta']);
    }

    // =========================================================================
    // setMultiple()
    // =========================================================================

    /**
     * setMultiple() calls set() for each key–value pair and returns true.
     */
    public function testSetMultipleCallsSaveForEachPair(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->expects($this->exactly(2))->method('save');
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->setMultiple(['k1' => 'v1', 'k2' => 'v2']);

        // Assert
        $this->assertTrue($result);
    }

    // =========================================================================
    // deleteMultiple()
    // =========================================================================

    /**
     * deleteMultiple() calls delete() for each key and returns true.
     */
    public function testDeleteMultipleCallsDeleteForEachKey(): void
    {
        // Arrange
        $cache = $this->mockCache();
        $cache->expects($this->exactly(3))->method('delete');
        $simple = new SimpleCache($cache);

        // Act
        $result = $simple->deleteMultiple(['a', 'b', 'c']);

        // Assert
        $this->assertTrue($result);
    }

    // =========================================================================
    // validateKey() — exception paths
    // =========================================================================

    /**
     * get() throws SimpleCacheInvalidArgumentException when the key is empty.
     */
    public function testGetThrowsForEmptyKey(): void
    {
        // Arrange
        $simple = new SimpleCache($this->mockCache());

        // Assert
        $this->expectException(SimpleCacheInvalidArgumentException::class);

        // Act
        $simple->get('');
    }

    /**
     * set() throws SimpleCacheInvalidArgumentException when the key contains a
     * PSR-16 reserved character '{'.
     */
    public function testSetThrowsForKeyWithReservedCharOpenBrace(): void
    {
        // Arrange
        $simple = new SimpleCache($this->mockCache());

        // Assert
        $this->expectException(SimpleCacheInvalidArgumentException::class);

        // Act
        $simple->set('bad{key}', 'value');
    }

    /**
     * has() throws SimpleCacheInvalidArgumentException for a key that contains
     * the reserved character '@'.
     */
    public function testHasThrowsForKeyWithAtSign(): void
    {
        // Arrange
        $simple = new SimpleCache($this->mockCache());

        // Assert
        $this->expectException(SimpleCacheInvalidArgumentException::class);

        // Act
        $simple->has('user@host');
    }

    /**
     * delete() throws SimpleCacheInvalidArgumentException for a key that
     * contains the reserved colon character ':'.
     */
    public function testDeleteThrowsForKeyWithColon(): void
    {
        // Arrange
        $simple = new SimpleCache($this->mockCache());

        // Assert
        $this->expectException(SimpleCacheInvalidArgumentException::class);

        // Act
        $simple->delete('key:name');
    }

    // =========================================================================
    // SimpleCacheInvalidArgumentException
    // =========================================================================

    /**
     * SimpleCacheInvalidArgumentException extends \InvalidArgumentException so
     * callers catching \InvalidArgumentException also catch it.
     */
    public function testExceptionExtendsInvalidArgumentException(): void
    {
        // Arrange / Act
        $e = new SimpleCacheInvalidArgumentException('oops');

        // Assert — inheritance chain
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    /**
     * SimpleCacheInvalidArgumentException implements the PSR-16
     * Psr\SimpleCache\InvalidArgumentException interface.
     */
    public function testExceptionImplementsPsr16Interface(): void
    {
        // Arrange / Act
        $e = new SimpleCacheInvalidArgumentException('bad key');

        // Assert — PSR-16 contract
        $this->assertInstanceOf(\Psr\SimpleCache\InvalidArgumentException::class, $e);
    }

    /**
     * The exception message passed to the constructor is accessible via getMessage().
     */
    public function testExceptionPreservesMessage(): void
    {
        // Arrange / Act
        $e = new SimpleCacheInvalidArgumentException('Cache key must not be empty');

        // Assert
        $this->assertSame('Cache key must not be empty', $e->getMessage());
    }
}
