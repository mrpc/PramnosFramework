<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\AbstractAdapter;

/**
 * Unit tests for Pramnos\Cache\Adapter\AbstractAdapter.
 *
 * AbstractAdapter provides shared infrastructure for all cache adapters:
 *   - Prefix management (setPrefix/getPrefix)
 *   - Caching on/off toggle (setCaching/isCachingEnabled)
 *   - Key generation (generateKey) with optional prefix and category
 *   - Category hashing (categoryHash) — whitespace/special-char sanitization
 *   - Safe default implementations for getStats, getCategories, getAllItems,
 *     connect, clear
 *   - Short-circuit in load() when caching is disabled
 *
 * The class is abstract. Tests use a minimal anonymous concrete subclass that
 * also exposes the protected sanitizeName() helper.
 */
#[CoversClass(AbstractAdapter::class)]
class AbstractAdapterTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Minimal concrete subclass — AbstractAdapter provides all interface
     * method bodies (throwing BadMethodCallException for unimplemented ones),
     * so no additional methods need to be added.
     * exposeSanitizeName() exposes the protected sanitizeName() for testing.
     */
    private function makeAdapter(string $prefix = ''): AbstractAdapter
    {
        return new class($prefix) extends AbstractAdapter {
            public function exposeSanitizeName(string $name): string
            {
                return $this->sanitizeName($name);
            }
        };
    }

    // =========================================================================
    // Constructor / getPrefix()
    // =========================================================================

    /**
     * Constructor sets the supplied prefix string.
     */
    public function testConstructorSetsPrefix(): void
    {
        // Arrange / Act
        $adapter = $this->makeAdapter('myapp');

        // Assert
        $this->assertSame('myapp', $adapter->getPrefix());
    }

    /**
     * Constructor with no argument leaves prefix as an empty string.
     */
    public function testConstructorDefaultPrefixIsEmpty(): void
    {
        // Arrange / Act
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertSame('', $adapter->getPrefix());
    }

    // =========================================================================
    // setPrefix() / getPrefix()
    // =========================================================================

    /**
     * setPrefix() updates the prefix and returns $this for fluent chaining.
     */
    public function testSetPrefixUpdatesAndReturnsSelf(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $result = $adapter->setPrefix('new_prefix');

        // Assert — fluent return and value updated
        $this->assertSame($adapter, $result);
        $this->assertSame('new_prefix', $adapter->getPrefix());
    }

    // =========================================================================
    // setCaching() / isCachingEnabled()
    // =========================================================================

    /**
     * Caching is enabled by default (all adapters start in usable state).
     */
    public function testCachingIsEnabledByDefault(): void
    {
        // Arrange / Act
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertTrue($adapter->isCachingEnabled());
    }

    /**
     * setCaching(false) disables caching and returns $this.
     */
    public function testSetCachingFalseDisablesCaching(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $result = $adapter->setCaching(false);

        // Assert — fluent return and caching off
        $this->assertSame($adapter, $result);
        $this->assertFalse($adapter->isCachingEnabled());
    }

    /**
     * setCaching() casts the argument to bool (0 disables, 1 enables).
     */
    public function testSetCachingCastsToBool(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act — integer 0 is falsy
        $adapter->setCaching(0);

        // Assert
        $this->assertFalse($adapter->isCachingEnabled());
    }

    /**
     * setCaching(true) re-enables caching after it was disabled.
     */
    public function testSetCachingCanReenableCaching(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->setCaching(false);

        // Act
        $adapter->setCaching(true);

        // Assert
        $this->assertTrue($adapter->isCachingEnabled());
    }

    // =========================================================================
    // sanitizeName() — accessed via expose helper
    // =========================================================================

    /**
     * sanitizeName() replaces spaces with underscores.
     */
    public function testSanitizeNameReplacesSpacesWithUnderscore(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert — single space becomes underscore
        $this->assertSame('my_file', $adapter->exposeSanitizeName('my file'));
    }

    /**
     * sanitizeName() removes characters outside [\w_.-].
     */
    public function testSanitizeNameRemovesSpecialChars(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert — @ and ! are stripped; alphanumeric chars kept
        $this->assertSame('myfile', $adapter->exposeSanitizeName('my@file!'));
    }

    /**
     * sanitizeName() collapses consecutive dots to a single dot.
     */
    public function testSanitizeNameCollapsesMultipleDots(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert — '..' normalized to '.'
        $this->assertSame('a.b', $adapter->exposeSanitizeName('a..b'));
    }

    // =========================================================================
    // generateKey()
    // =========================================================================

    /**
     * Without a prefix or category, generateKey() produces "{id}.{extension}".
     */
    public function testGenerateKeyWithoutPrefixOrCategory(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $key = $adapter->generateKey('my-id', '', 'cache');

        // Assert
        $this->assertSame('my-id.cache', $key);
    }

    /**
     * With a prefix set, the key starts with "{sanitized_prefix}_".
     */
    public function testGenerateKeyWithPrefix(): void
    {
        // Arrange
        $adapter = $this->makeAdapter('app');

        // Act
        $key = $adapter->generateKey('item1', '', 'cache');

        // Assert
        $this->assertStringStartsWith('app_', $key);
        $this->assertStringEndsWith('item1.cache', $key);
    }

    /**
     * With a non-empty category, generateKey() inserts the category hash
     * between the prefix and the id.
     */
    public function testGenerateKeyWithCategory(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $key = $adapter->generateKey('item1', 'products', 'cache');

        // Assert — category name appears in the key (unhashed in default impl)
        $this->assertStringContainsString('products', $key);
        $this->assertStringEndsWith('item1.cache', $key);
    }

    /**
     * With both prefix and category the key has the form:
     * "{prefix}_{category_hash}_{id}.{ext}".
     */
    public function testGenerateKeyWithPrefixAndCategory(): void
    {
        // Arrange
        $adapter = $this->makeAdapter('store');

        // Act
        $key = $adapter->generateKey('id42', 'orders', 'json');

        // Assert — all three components present
        $this->assertStringStartsWith('store_', $key);
        $this->assertStringContainsString('orders', $key);
        $this->assertStringEndsWith('id42.json', $key);
    }

    // =========================================================================
    // categoryHash()
    // =========================================================================

    /**
     * categoryHash('') returns '' — empty category has no hash.
     */
    public function testCategoryHashReturnsEmptyStringForEmptyInput(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertSame('', $adapter->categoryHash(''));
    }

    /**
     * categoryHash() replaces whitespace sequences with underscores.
     */
    public function testCategoryHashReplacesSpaces(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertSame('my_category', $adapter->categoryHash('my category'));
    }

    /**
     * categoryHash() strips characters outside [\w-] (e.g. @, !).
     */
    public function testCategoryHashRemovesSpecialChars(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert — @ is stripped; hyphens and word chars kept
        $this->assertSame('catname', $adapter->categoryHash('cat@name'));
    }

    /**
     * categoryHash() preserves hyphens, which are valid in category names.
     */
    public function testCategoryHashPreservesHyphens(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertSame('my-category', $adapter->categoryHash('my-category'));
    }

    // =========================================================================
    // Default safe implementations
    // =========================================================================

    /**
     * connect() returns true by default — concrete adapters override this.
     */
    public function testConnectReturnsTrueByDefault(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert — default is "connected" so base tests succeed
        $this->assertTrue($adapter->connect());
    }

    /**
     * clear() returns false by default — no backend to clear without override.
     */
    public function testClearReturnsFalseByDefault(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertFalse($adapter->clear());
    }

    /**
     * getCategories() returns [] by default.
     */
    public function testGetCategoriesReturnsEmptyArrayByDefault(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertSame([], $adapter->getCategories());
    }

    /**
     * getAllItems() returns [] by default.
     */
    public function testGetAllItemsReturnsEmptyArrayByDefault(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertSame([], $adapter->getAllItems());
    }

    /**
     * getStats() returns a minimal array with 'method', 'categories', 'items'
     * keys, even when no backend is connected.
     */
    public function testGetStatsReturnsMinimalStructure(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act
        $stats = $adapter->getStats();

        // Assert — all three required keys are present
        $this->assertArrayHasKey('method',     $stats);
        $this->assertArrayHasKey('categories', $stats);
        $this->assertArrayHasKey('items',      $stats);
        $this->assertSame(0, $stats['categories']);
        $this->assertSame(0, $stats['items']);
    }

    // =========================================================================
    // load() — short-circuit and throw paths
    // =========================================================================

    /**
     * load() returns null immediately when caching is disabled, without
     * attempting to reach the (unimplemented) backend.
     */
    public function testLoadReturnsNullWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->setCaching(false);

        // Act
        $result = $adapter->load('any-key');

        // Assert — short-circuit path, no exception
        $this->assertNull($result);
    }

    /**
     * load() throws BadMethodCallException when caching is enabled and the
     * concrete class has not overridden it.
     */
    public function testLoadThrowsBadMethodCallExceptionWhenNotOverridden(): void
    {
        // Arrange — caching enabled (default), no concrete load() override
        $adapter = $this->makeAdapter();

        // Act / Assert — default load() is a deliberate "not implemented" guard
        $this->expectException(\BadMethodCallException::class);
        $adapter->load('some-key');
    }

    // =========================================================================
    // test() — short-circuit path
    // =========================================================================

    /**
     * test() returns false immediately when caching is disabled, without
     * invoking save() or load().
     */
    public function testTestReturnsFalseWhenCachingDisabled(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();
        $adapter->setCaching(false);

        // Assert
        $this->assertFalse($adapter->test());
    }

    // =========================================================================
    // save() / delete() — default throw paths
    // =========================================================================

    /**
     * save() throws BadMethodCallException in the base class — concrete adapters
     * must override it.  The exception signals a programming error (misconfigured
     * adapter) rather than a runtime failure.
     */
    public function testSaveThrowsBadMethodCallExceptionWhenNotOverridden(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act / Assert
        $this->expectException(\BadMethodCallException::class);
        $adapter->save('my-key', 'my-value');
    }

    /**
     * delete() throws BadMethodCallException in the base class for the same reason.
     */
    public function testDeleteThrowsBadMethodCallExceptionWhenNotOverridden(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Act / Assert
        $this->expectException(\BadMethodCallException::class);
        $adapter->delete('my-key');
    }

    // =========================================================================
    // generateKey() — edge cases
    // =========================================================================

    /**
     * generateKey() without a prefix keeps the key clean — no leading underscore.
     */
    public function testGenerateKeyWithoutPrefixHasNoLeadingUnderscore(): void
    {
        // Arrange
        $adapter = $this->makeAdapter(); // no prefix

        // Act
        $key = $adapter->generateKey('item99', '', 'cache');

        // Assert — the key starts directly with the id
        $this->assertStringStartsWith('item99', $key);
    }

    /**
     * generateKey() with a prefix that has special chars sanitises the prefix.
     */
    public function testGenerateKeyWithSpecialCharPrefixIsSanitised(): void
    {
        // Arrange — prefix with a space (should be replaced with '_')
        $adapter = $this->makeAdapter('my app');

        // Act
        $key = $adapter->generateKey('id1', '', 'cache');

        // Assert — space in prefix becomes underscore
        $this->assertStringContainsString('my_app_', $key);
    }

    /**
     * getCategories() with a prefix argument still returns an empty array in the
     * default implementation.
     */
    public function testGetCategoriesWithPrefixReturnsEmptyArray(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert — default impl ignores the $prefix param
        $this->assertSame([], $adapter->getCategories('some_prefix'));
    }

    /**
     * getAllItems() with a category + limit still returns an empty array by default.
     */
    public function testGetAllItemsWithCategoryAndLimitReturnsEmptyArray(): void
    {
        // Arrange
        $adapter = $this->makeAdapter();

        // Assert
        $this->assertSame([], $adapter->getAllItems('cat', 10));
    }

    // =========================================================================
    // test() — success and failure paths
    // =========================================================================

    /**
     * Helper: build a concrete adapter whose save/load/delete work correctly.
     * This allows us to exercise the test() method's happy path.
     */
    private function makeWorkingAdapter(string $prefix = ''): AbstractAdapter
    {
        return new class($prefix) extends AbstractAdapter {
            private array $store = [];

            public function save($key, $data, $timeout = 3600): bool
            {
                $this->store[$key] = $data;
                return true;
            }

            public function load($key, $timeout = null): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function delete($key): bool
            {
                unset($this->store[$key]);
                return true;
            }
        };
    }

    /**
     * test() must return true when save/load/delete all succeed — the cache
     * connection is healthy. Covers the full success path of test()
     * (lines 144-161 in AbstractAdapter).
     */
    public function testTestReturnsTrueWhenCacheIsFullyFunctional(): void
    {
        // Arrange — working adapter with save/load/delete implemented
        $adapter = $this->makeWorkingAdapter();

        // Act
        $result = $adapter->test();

        // Assert — happy path returns true
        $this->assertTrue($result, 'test() must return true when the cache is functional');
    }

    /**
     * test() must return false when save() succeeds but load() returns the wrong
     * value (e.g., cache is write-only or the key expired instantly).
     * Covers the `if ($loadedValue !== $testValue) return false` branch.
     */
    public function testTestReturnsFalseWhenLoadReturnsWrongValue(): void
    {
        // Arrange — adapter where load() always returns something different
        $adapter = new class extends AbstractAdapter {
            public function save($key, $data, $timeout = 3600): bool { return true; }
            public function load($key, $timeout = null): mixed { return 'WRONG_VALUE'; }
            public function delete($key): bool { return true; }
        };

        // Act
        $result = $adapter->test();

        // Assert — load returned wrong value → test fails
        $this->assertFalse($result,
            'test() must return false when load() does not return the saved value');
    }

    /**
     * test() must return false immediately when save() returns false.
     * Covers the `if (!$saveResult) return false` branch.
     */
    public function testTestReturnsFalseWhenSaveFails(): void
    {
        // Arrange — adapter where save() always fails
        $adapter = new class extends AbstractAdapter {
            public function save($key, $data, $timeout = 3600): bool { return false; }
            public function load($key, $timeout = null): mixed { return null; }
            public function delete($key): bool { return true; }
        };

        // Act
        $result = $adapter->test();

        // Assert — save returned false → test must short-circuit and return false
        $this->assertFalse($result,
            'test() must return false immediately when save() reports failure');
    }
}
