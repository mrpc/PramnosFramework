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

    // ── getCategories ─────────────────────────────────────────────────────────

    /**
     * getCategories() with an empty prefix returns the first segment of every
     * stored key as a category label. Keys are split on '_'; the prefix segment
     * before the first underscore identifies the category.
     */
    public function testGetCategoriesReturnsAllCategoryPrefixes(): void
    {
        // Arrange — two keys in two distinct categories
        $this->adapter->save('posts_123', 'post data', 3600);
        $this->adapter->save('users_456', 'user data', 3600);

        // Act
        $categories = $this->adapter->getCategories('');

        // Assert — both category labels are present (order is unspecified)
        $this->assertIsArray($categories);
        $this->assertContains('posts', $categories,
            'getCategories() must extract the "posts" prefix from "posts_123"');
        $this->assertContains('users', $categories,
            'getCategories() must extract the "users" prefix from "users_456"');
    }

    /**
     * getCategories() with a non-empty prefix skips keys that do not start with
     * that prefix, honouring the str_starts_with() guard inside the method.
     */
    public function testGetCategoriesWithPrefixFiltersKeys(): void
    {
        // Arrange — one key matching the prefix, one that does not
        $this->adapter->save('app_posts_1', 'a', 3600);
        $this->adapter->save('other_thing', 'b', 3600);

        // Act — only keys starting with 'app_' should contribute categories
        $categories = $this->adapter->getCategories('app_');

        // Assert — 'app_posts_1' starts with 'app_' → its prefix 'app' is returned;
        // 'other_thing' does NOT start with 'app_' → its prefix must be absent.
        $this->assertContains('app', $categories,
            '"app_posts_1" starts with prefix "app_", so "app" must appear');
        $this->assertNotContains('other', $categories,
            '"other_thing" does not start with prefix "app_", so "other" must be excluded');
    }

    // ── getAllItems — category filter & limit ─────────────────────────────────

    /**
     * getAllItems() with a non-empty $category skips entries whose key does not
     * contain the category substring. Only matching items are returned.
     */
    public function testGetAllItemsWithCategoryFilterSkipsNonMatchingKeys(): void
    {
        // Arrange
        $this->adapter->save('posts_1', 'post', 3600);
        $this->adapter->save('users_1', 'user', 3600);

        // Act — only 'posts' category
        $items = $this->adapter->getAllItems('posts');

        // Assert — exactly the posts entry is returned, users entry is skipped
        $this->assertCount(1, $items,
            'getAllItems("posts") must skip the "users_1" key');
        $this->assertStringContainsString('posts', $items[0]['key']);
    }

    /**
     * getAllItems() stops after $limit results, covering the break inside the
     * counting loop. Verifies that excess items are excluded from the result.
     */
    public function testGetAllItemsLimitCutsOffResults(): void
    {
        // Arrange — three items, limit is 2
        $this->adapter->save('item_a', 'a', 3600);
        $this->adapter->save('item_b', 'b', 3600);
        $this->adapter->save('item_c', 'c', 3600);

        // Act
        $items = $this->adapter->getAllItems('', 2);

        // Assert — at most 2 items returned despite 3 being in store
        $this->assertCount(2, $items,
            'getAllItems() must respect the $limit parameter and stop after 2 items');
    }

    // ── pruneExpired (via getStats) ───────────────────────────────────────────

    /**
     * getStats() calls pruneExpired() before counting; entries that have already
     * expired are removed from the store and excluded from the item count.
     * This covers the unset() branch inside pruneExpired() that is not reachable
     * through load() alone.
     */
    public function testGetStatsExcludesExpiredItems(): void
    {
        // Arrange — save one item, then backdate its expiry via reflection
        $this->adapter->save('stale', 'old value', 3600);
        $reflection = new \ReflectionProperty(ArrayAdapter::class, 'store');
        $store = $reflection->getValue($this->adapter);
        $store['stale'] = ['data' => 'old value', 'expires' => time() - 1];
        $reflection->setValue($this->adapter, $store);

        // Act — getStats() triggers pruneExpired() internally
        $stats = $this->adapter->getStats();

        // Assert — the expired entry was pruned; item count must be 0
        $this->assertSame(0, $stats['items'],
            'pruneExpired() must remove the expired entry before getStats() counts items');
    }
}
