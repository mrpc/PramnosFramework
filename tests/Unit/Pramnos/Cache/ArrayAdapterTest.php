<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for ArrayAdapter — the in-memory cache adapter.
 *
 * ArrayAdapter is the testing-friendly adapter: no APCu, no Redis, no file
 * system. Tests verify:
 *   - connect() always returns true.
 *   - save() + load() round-trips all PHP value types (string, int, array, null).
 *   - Expired entries are not returned (lazy expiry on load).
 *   - Entries with ttl=0 never expire.
 *   - delete() removes an entry and returns true; a second delete returns false.
 *   - clear('') wipes the entire store.
 *   - clear($category) removes only matching keys.
 *   - categoryHash() returns a stable string; reset=true gives a new value.
 *   - test() returns true.
 *   - getStats() includes item count.
 *   - getAllItems() returns a list with metadata.
 *   - Prefix isolation: two adapters with different prefixes don't share data.
 */
#[CoversClass(ArrayAdapter::class)]
class ArrayAdapterTest extends TestCase
{
    private ArrayAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ArrayAdapter();
    }

    // ── Connection ────────────────────────────────────────────────────────────

    /**
     * connect() requires no external resource; it must always succeed.
     */
    public function testConnectAlwaysReturnsTrue(): void
    {
        // Act + Assert
        $this->assertTrue($this->adapter->connect());
    }

    /**
     * test() mirrors connect() — no backend, always available.
     */
    public function testTestAlwaysReturnsTrue(): void
    {
        // Act + Assert
        $this->assertTrue($this->adapter->test());
    }

    // ── Save / Load round-trips ───────────────────────────────────────────────

    /**
     * A string value saved under a key is returned unchanged by load().
     */
    public function testSaveAndLoadString(): void
    {
        // Arrange + Act
        $this->adapter->save('mykey', 'hello world', 3600);

        // Assert
        $this->assertSame('hello world', $this->adapter->load('mykey'));
    }

    /**
     * Integer values survive the round-trip without type coercion.
     */
    public function testSaveAndLoadInteger(): void
    {
        // Arrange + Act
        $this->adapter->save('intkey', 42, 3600);

        // Assert
        $this->assertSame(42, $this->adapter->load('intkey'));
    }

    /**
     * Arrays (nested data) are stored and retrieved correctly.
     */
    public function testSaveAndLoadArray(): void
    {
        // Arrange
        $data = ['a' => 1, 'b' => [2, 3]];

        // Act
        $this->adapter->save('arrkey', $data, 3600);

        // Assert
        $this->assertSame($data, $this->adapter->load('arrkey'));
    }

    /**
     * Storing null is valid; load() returns null (not false) for a cached null.
     */
    public function testSaveAndLoadNull(): void
    {
        // Arrange + Act
        $this->adapter->save('nullkey', null, 3600);

        // Assert — null is a valid cached value, distinct from false (miss)
        $this->assertNull($this->adapter->load('nullkey'));
    }

    // ── Expiry ────────────────────────────────────────────────────────────────

    /**
     * load() returns false for a key that was never set.
     */
    public function testLoadReturnsFalseForMissingKey(): void
    {
        // Act + Assert
        $this->assertFalse($this->adapter->load('nonexistent'));
    }

    /**
     * An entry with ttl=1 is no longer accessible after it expires.
     * We mock time indirectly by saving with ttl=1 and then triggering
     * expiry by calling a method that prunes: use a negative-ttl trick via
     * reflection to force expiry, or just save with ttl=1 and verify load()
     * behaviour using a fresh adapter with an overridden clock.
     *
     * Since ArrayAdapter uses time() internally, we store with a deliberately
     * past expiry timestamp via the store property using reflection.
     */
    public function testExpiredEntryReturnsFalse(): void
    {
        // Arrange — write an entry whose expires timestamp is already in the past
        $this->adapter->save('expkey', 'value', 3600);
        $reflection = new \ReflectionProperty(ArrayAdapter::class, 'store');
        // Overwrite the entry with an already-expired timestamp
        $store = $reflection->getValue($this->adapter);
        $store['expkey'] = ['data' => 'value', 'expires' => time() - 1];
        $reflection->setValue($this->adapter, $store);

        // Act + Assert — load() should prune the entry and return false
        $this->assertFalse($this->adapter->load('expkey'));
    }

    /**
     * An entry saved with ttl=0 never expires regardless of elapsed time.
     */
    public function testZeroTtlNeverExpires(): void
    {
        // Arrange — save with ttl=0 (no expiry)
        $this->adapter->save('forever', 'eternal', 0);
        $reflection = new \ReflectionProperty(ArrayAdapter::class, 'store');
        $store = $reflection->getValue($this->adapter);

        // Assert — expires field is 0 (never)
        $this->assertSame(0, $store['forever']['expires']);

        // Act + Assert — load() still returns the value
        $this->assertSame('eternal', $this->adapter->load('forever'));
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * delete() removes the entry; subsequent load() returns false.
     */
    public function testDeleteRemovesEntry(): void
    {
        // Arrange
        $this->adapter->save('delkey', 'bye', 3600);

        // Act
        $result = $this->adapter->delete('delkey');

        // Assert — delete reported success and the entry is gone
        $this->assertTrue($result);
        $this->assertFalse($this->adapter->load('delkey'));
    }

    /**
     * delete() on a non-existent key returns false without error.
     */
    public function testDeleteNonExistentKeyReturnsFalse(): void
    {
        // Act + Assert
        $this->assertFalse($this->adapter->delete('ghost'));
    }

    // ── Clear ─────────────────────────────────────────────────────────────────

    /**
     * clear('') wipes all entries.
     */
    public function testClearEmptyStringWipesAllEntries(): void
    {
        // Arrange
        $this->adapter->save('k1', 'v1', 3600);
        $this->adapter->save('k2', 'v2', 3600);

        // Act
        $this->adapter->clear('');

        // Assert
        $this->assertFalse($this->adapter->load('k1'));
        $this->assertFalse($this->adapter->load('k2'));
    }

    /**
     * clear($category) removes only keys that contain the category substring.
     */
    public function testClearCategoryRemovesOnlyMatchingKeys(): void
    {
        // Arrange
        $this->adapter->save('posts_123', 'post data', 3600);
        $this->adapter->save('users_456', 'user data', 3600);

        // Act — clear only the 'posts' category
        $this->adapter->clear('posts');

        // Assert — posts entry gone, users entry still present
        $this->assertFalse($this->adapter->load('posts_123'));
        $this->assertSame('user data', $this->adapter->load('users_456'));
    }

    // ── Category hash ─────────────────────────────────────────────────────────

    /**
     * categoryHash() returns a stable non-empty string for the same inputs.
     */
    public function testCategoryHashIsStable(): void
    {
        // Act
        $h1 = $this->adapter->categoryHash('posts', 'myapp_');
        $h2 = $this->adapter->categoryHash('posts', 'myapp_');

        // Assert — same result for same inputs
        $this->assertSame($h1, $h2);
        $this->assertNotEmpty($h1);
    }

    /**
     * categoryHash() with reset=true returns a different hash than the previous one.
     */
    public function testCategoryHashResetChangesHash(): void
    {
        // Arrange — record the initial hash
        $original = $this->adapter->categoryHash('posts');

        // Act — force a reset
        $fresh = $this->adapter->categoryHash('posts', '', true);

        // Assert — the reset hash differs from the stored one
        $this->assertNotEquals($original, $fresh);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * getStats() returns an array with at least an 'items' key reflecting the
     * current (non-expired) item count.
     */
    public function testGetStatsReturnsItemCount(): void
    {
        // Arrange
        $this->adapter->save('s1', 'a', 3600);
        $this->adapter->save('s2', 'b', 3600);

        // Act
        $stats = $this->adapter->getStats();

        // Assert
        $this->assertArrayHasKey('items', $stats);
        $this->assertSame(2, $stats['items']);
    }

    // ── getAllItems ───────────────────────────────────────────────────────────

    /**
     * getAllItems() returns an array whose entries each carry 'key' and 'size'
     * metadata fields.
     */
    public function testGetAllItemsReturnsMetadata(): void
    {
        // Arrange
        $this->adapter->save('item1', 'data1', 3600);

        // Act
        $items = $this->adapter->getAllItems();

        // Assert — at least one item with required metadata keys
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('key', $items[0]);
        $this->assertArrayHasKey('size', $items[0]);
    }

    // ── Prefix isolation ──────────────────────────────────────────────────────

    /**
     * Two ArrayAdapter instances with different prefixes store independent data.
     * Saving under 'key' in adapter A must not affect adapter B's 'key'.
     */
    public function testPrefixIsolation(): void
    {
        // Arrange
        $adapterA = new ArrayAdapter('appA_');
        $adapterB = new ArrayAdapter('appB_');

        // Act — save to A only
        $adapterA->save('shared', 'from-A', 3600);

        // Assert — B has no entry for 'shared'
        $this->assertFalse($adapterB->load('shared'));
        // Assert — A still has its entry
        $this->assertSame('from-A', $adapterA->load('shared'));
    }
}
