<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pramnos\Cache\Cache;
use Pramnos\Cache\SimpleCache;
use Pramnos\Cache\SimpleCacheInvalidArgumentException;

/**
 * Characterization tests for SimpleCache — the PSR-16 adapter.
 *
 * These tests lock the PSR-16 contract of SimpleCache before any refactoring:
 * - Implements Psr\SimpleCache\CacheInterface.
 * - get/set/delete/has round-trip correctly.
 * - get() returns $default for missing keys.
 * - clear() removes all stored entries.
 * - getMultiple/setMultiple/deleteMultiple batch operations.
 * - Key validation: empty keys and reserved-char keys throw
 *   SimpleCacheInvalidArgumentException.
 * - TTL normalisation: int, null, and DateInterval are all accepted by set().
 *
 * All tests use a file-based Cache instance writing to /tmp to avoid
 * requiring a running database server.
 */
#[CoversClass(SimpleCache::class)]
#[CoversClass(SimpleCacheInvalidArgumentException::class)]
class SimpleCacheCharacterizationTest extends TestCase
{
    private SimpleCache $cache;

    protected function setUp(): void
    {
        // Arrange — create a fresh Cache instance per test using a unique prefix
        // so runs don't bleed into each other, even if tearDown skips cleanup.
        $prefix      = 'sc_test_' . uniqid();
        $innerCache  = new Cache(null, null, 'file', ['prefix' => $prefix]);
        $this->cache = new SimpleCache($innerCache);
    }

    protected function tearDown(): void
    {
        // Best-effort: clear all entries stored during this test
        $this->cache->clear();
    }

    // -------------------------------------------------------------------------

    /**
     * SimpleCache must satisfy the Psr\SimpleCache\CacheInterface type so that
     * any PSR-16 aware library can accept it by type.
     */
    public function testImplementsPsrSimpleCacheInterface(): void
    {
        // Assert
        $this->assertInstanceOf(CacheInterface::class, $this->cache);
    }

    /**
     * set() / get() round-trip: a stored scalar value must be retrievable
     * with the same key.  This is the fundamental PSR-16 contract.
     */
    public function testSetAndGetRoundTrip(): void
    {
        // Act
        $this->cache->set('greeting', 'hello world');

        // Assert
        $this->assertSame('hello world', $this->cache->get('greeting'));
    }

    /**
     * get() must return $default (null by default) when the key does not
     * exist in the cache — not throw an exception.
     */
    public function testGetReturnDefaultForMissingKey(): void
    {
        // Act
        $result = $this->cache->get('does_not_exist', 'fallback');

        // Assert
        $this->assertSame('fallback', $result);
    }

    /**
     * delete() must remove a previously stored key so that the next get()
     * returns the default value.
     */
    public function testDeleteRemovesKey(): void
    {
        // Arrange
        $this->cache->set('to_delete', 'value');

        // Act
        $this->cache->delete('to_delete');

        // Assert — key is gone
        $this->assertNull($this->cache->get('to_delete'));
    }

    /**
     * has() must return true immediately after set() and false after delete().
     * This mirrors the behaviour of isset() for caches.
     */
    public function testHasReturnsTrueAfterSetAndFalseAfterDelete(): void
    {
        // Arrange
        $this->cache->set('presence', 'yes');

        // Act / Assert
        $this->assertTrue($this->cache->has('presence'));

        $this->cache->delete('presence');
        $this->assertFalse($this->cache->has('presence'));
    }

    /**
     * clear() must wipe all entries so that any subsequent has() returns false
     * and get() returns the default.
     */
    public function testClearRemovesAllEntries(): void
    {
        // Arrange
        $this->cache->set('key1', 'v1');
        $this->cache->set('key2', 'v2');

        // Act
        $this->cache->clear();

        // Assert
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    /**
     * setMultiple() / getMultiple() must set and retrieve all key-value pairs
     * atomically from the caller's perspective.
     */
    public function testSetMultipleAndGetMultipleRoundTrip(): void
    {
        // Arrange
        $data = ['alpha' => 1, 'beta' => 2, 'gamma' => 3];

        // Act
        $this->cache->setMultiple($data);
        $result = $this->cache->getMultiple(['alpha', 'beta', 'gamma']);

        // Assert
        $result = iterator_to_array($result);
        $this->assertSame(1, $result['alpha']);
        $this->assertSame(2, $result['beta']);
        $this->assertSame(3, $result['gamma']);
    }

    /**
     * deleteMultiple() must remove every listed key; unlisted keys remain.
     */
    public function testDeleteMultipleRemovesOnlyListedKeys(): void
    {
        // Arrange
        $this->cache->setMultiple(['x' => 'X', 'y' => 'Y', 'z' => 'Z']);

        // Act
        $this->cache->deleteMultiple(['x', 'z']);

        // Assert — x and z gone, y intact
        $this->assertFalse($this->cache->has('x'));
        $this->assertFalse($this->cache->has('z'));
        $this->assertTrue($this->cache->has('y'));
    }

    /**
     * An empty-string key must throw SimpleCacheInvalidArgumentException.
     * PSR-16 §4: "A cache key MUST be a non-empty string."
     */
    public function testEmptyKeyThrowsInvalidArgumentException(): void
    {
        // Assert
        $this->expectException(SimpleCacheInvalidArgumentException::class);

        // Act
        $this->cache->get('');
    }

    /**
     * Keys containing any PSR-16 reserved character ({, }, (, ), /, \, @, :)
     * must throw SimpleCacheInvalidArgumentException.
     */
    public function testReservedCharacterInKeyThrowsInvalidArgumentException(): void
    {
        // Assert
        $this->expectException(SimpleCacheInvalidArgumentException::class);

        // Act — '{' is a reserved character per PSR-16
        $this->cache->set('bad{key}', 'value');
    }

    /**
     * set() with an integer TTL must be accepted without throwing.
     * The PSR-16 spec allows int for TTL.
     */
    public function testSetWithIntegerTtlDoesNotThrow(): void
    {
        // Act — must not throw
        $this->cache->set('ttl_int_key', 'value', 3600);

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * set() with null TTL (use-driver-default) must be accepted without throwing.
     */
    public function testSetWithNullTtlDoesNotThrow(): void
    {
        // Act — must not throw
        $this->cache->set('ttl_null_key', 'value', null);

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * set() with a DateInterval TTL must be accepted without throwing.
     * The PSR-16 spec allows DateInterval for TTL.
     */
    public function testSetWithDateIntervalTtlDoesNotThrow(): void
    {
        // Arrange
        $ttl = new \DateInterval('PT1H'); // 1 hour

        // Act — must not throw
        $this->cache->set('ttl_interval_key', 'value', $ttl);

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * A complex value (array with nested data) must survive a round-trip
     * through set()/get() without data corruption.
     */
    public function testArrayValueRoundTrip(): void
    {
        // Arrange
        $data = ['user' => ['id' => 42, 'roles' => ['admin', 'editor']]];

        // Act
        $this->cache->set('complex_key', $data);
        $retrieved = $this->cache->get('complex_key');

        // Assert
        $this->assertSame($data, $retrieved);
    }
}
