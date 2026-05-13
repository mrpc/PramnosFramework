<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Orm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Orm\Collection;

/**
 * Unit tests for Pramnos\Application\Orm\Collection.
 *
 * Collection wraps an array of items (typically OrmModel objects) and exposes
 * functional helpers (filter, map, pluck, groupBy, sortBy, each).  All methods
 * are pure — they return new Collection instances rather than mutating $this.
 * Tests use anonymous stdClass objects and arrays to keep the fixture light and
 * independent of any ORM infrastructure.
 */
#[CoversClass(Collection::class)]
class CollectionTest extends TestCase
{
    // =========================================================================
    // Helpers — lightweight fixtures
    // =========================================================================

    /** Build a stdClass item from an associative array (mimics ORM model public props). */
    private static function item(array $props): object
    {
        return (object) $props;
    }

    // =========================================================================
    // count / isEmpty
    // =========================================================================

    /**
     * An empty Collection has count() === 0 and isEmpty() === true.
     */
    public function testEmptyCollectionHasCountZeroAndIsEmpty(): void
    {
        // Arrange / Act
        $col = new Collection();

        // Assert
        $this->assertSame(0, $col->count());
        $this->assertTrue($col->isEmpty());
    }

    /**
     * A Collection initialised with items reflects the correct count and is
     * not empty.
     */
    public function testCountAndIsEmptyReflectItemsProvided(): void
    {
        // Arrange / Act
        $col = new Collection([self::item(['id' => 1]), self::item(['id' => 2])]);

        // Assert
        $this->assertSame(2, $col->count());
        $this->assertFalse($col->isEmpty());
    }

    /**
     * count() complies with the Countable interface so PHP's count() global
     * function delegates to it correctly.
     */
    public function testCountableInterfaceWorks(): void
    {
        // Arrange
        $col = new Collection([1, 2, 3]);

        // Assert – PHP count() function uses Countable::count()
        $this->assertSame(3, count($col));
    }

    // =========================================================================
    // first / last
    // =========================================================================

    /**
     * first() returns the first item of the collection.
     */
    public function testFirstReturnsFirstItem(): void
    {
        // Arrange
        $a = self::item(['id' => 10]);
        $b = self::item(['id' => 20]);
        $col = new Collection([$a, $b]);

        // Act / Assert
        $this->assertSame($a, $col->first());
    }

    /**
     * first() on an empty Collection returns null rather than throwing.
     */
    public function testFirstOnEmptyCollectionReturnsNull(): void
    {
        // Arrange / Act
        $result = (new Collection())->first();

        // Assert
        $this->assertNull($result);
    }

    /**
     * last() returns the last item of the collection.
     */
    public function testLastReturnsLastItem(): void
    {
        // Arrange
        $a = self::item(['id' => 1]);
        $b = self::item(['id' => 99]);
        $col = new Collection([$a, $b]);

        // Act / Assert
        $this->assertSame($b, $col->last());
    }

    /**
     * last() on an empty Collection returns null rather than throwing.
     */
    public function testLastOnEmptyCollectionReturnsNull(): void
    {
        // Arrange / Act
        $result = (new Collection())->last();

        // Assert
        $this->assertNull($result);
    }

    // =========================================================================
    // contains
    // =========================================================================

    /**
     * contains() returns true when the callback matches at least one item.
     */
    public function testContainsReturnsTrueWhenCallbackMatches(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['role' => 'admin']),
            self::item(['role' => 'user']),
        ]);

        // Act / Assert
        $this->assertTrue($col->contains(fn($item) => $item->role === 'admin'));
    }

    /**
     * contains() returns false when no item matches the callback.
     */
    public function testContainsReturnsFalseWhenNoItemMatches(): void
    {
        // Arrange
        $col = new Collection([self::item(['role' => 'user'])]);

        // Act / Assert
        $this->assertFalse($col->contains(fn($item) => $item->role === 'admin'));
    }

    // =========================================================================
    // filter
    // =========================================================================

    /**
     * filter() returns a new Collection containing only items for which the
     * callback returns true; the original collection is not modified.
     */
    public function testFilterReturnsMatchingItemsInNewCollection(): void
    {
        // Arrange
        $a = self::item(['active' => true]);
        $b = self::item(['active' => false]);
        $c = self::item(['active' => true]);
        $original = new Collection([$a, $b, $c]);

        // Act
        $filtered = $original->filter(fn($item) => $item->active === true);

        // Assert – filtered has 2 items
        $this->assertSame(2, $filtered->count());
        // Assert – original is untouched
        $this->assertSame(3, $original->count());
        // Assert – filtered items are the active ones
        $this->assertContains($a, $filtered->all());
        $this->assertContains($c, $filtered->all());
    }

    /**
     * filter() on a collection where no item matches returns an empty collection.
     */
    public function testFilterWithNoMatchReturnsEmptyCollection(): void
    {
        // Arrange
        $col = new Collection([self::item(['x' => 1]), self::item(['x' => 2])]);

        // Act
        $result = $col->filter(fn($item) => $item->x > 100);

        // Assert
        $this->assertTrue($result->isEmpty());
    }

    // =========================================================================
    // map
    // =========================================================================

    /**
     * map() returns a new Collection whose items are the result of applying the
     * callback to each item in the original collection.
     */
    public function testMapTransformsEachItem(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['value' => 1]),
            self::item(['value' => 2]),
            self::item(['value' => 3]),
        ]);

        // Act – double each value
        $doubled = $col->map(fn($item) => $item->value * 2);

        // Assert
        $this->assertSame([2, 4, 6], $doubled->all());
    }

    /**
     * map() returns a distinct Collection instance — immutability is preserved.
     */
    public function testMapReturnsNewCollectionInstance(): void
    {
        // Arrange
        $col = new Collection([1, 2]);

        // Act
        $mapped = $col->map(fn($x) => $x);

        // Assert – different object
        $this->assertNotSame($col, $mapped);
    }

    // =========================================================================
    // pluck
    // =========================================================================

    /**
     * pluck() extracts a named property from each object item and returns them
     * in a new Collection.
     */
    public function testPluckExtractsObjectProperty(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['name' => 'Alice', 'age' => 30]),
            self::item(['name' => 'Bob',   'age' => 25]),
        ]);

        // Act
        $names = $col->pluck('name');

        // Assert
        $this->assertSame(['Alice', 'Bob'], $names->all());
    }

    /**
     * pluck() also works when items are associative arrays.
     */
    public function testPluckExtractsArrayKey(): void
    {
        // Arrange
        $col = new Collection([
            ['id' => 1, 'label' => 'Foo'],
            ['id' => 2, 'label' => 'Bar'],
        ]);

        // Act
        $ids = $col->pluck('id');

        // Assert
        $this->assertSame([1, 2], $ids->all());
    }

    /**
     * pluck() returns null for items that do not have the requested key.
     */
    public function testPluckReturnsNullForMissingKey(): void
    {
        // Arrange
        $col = new Collection([self::item(['name' => 'Alice'])]);

        // Act
        $result = $col->pluck('nonexistent');

        // Assert – null for the missing property
        $this->assertSame([null], $result->all());
    }

    // =========================================================================
    // groupBy
    // =========================================================================

    /**
     * groupBy() partitions items by the value of a property and returns an
     * array of Collections keyed by group value.
     */
    public function testGroupByPartitionsItemsByProperty(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['status' => 'active',   'name' => 'Alice']),
            self::item(['status' => 'inactive', 'name' => 'Bob']),
            self::item(['status' => 'active',   'name' => 'Carol']),
        ]);

        // Act
        $groups = $col->groupBy('status');

        // Assert – two groups
        $this->assertArrayHasKey('active', $groups);
        $this->assertArrayHasKey('inactive', $groups);
        $this->assertSame(2, $groups['active']->count());
        $this->assertSame(1, $groups['inactive']->count());
    }

    /**
     * Each value in the groupBy() result is a Collection instance.
     */
    public function testGroupByReturnsCollectionInstances(): void
    {
        // Arrange
        $col = new Collection([self::item(['type' => 'a'])]);

        // Act
        $groups = $col->groupBy('type');

        // Assert
        $this->assertInstanceOf(Collection::class, $groups['a']);
    }

    // =========================================================================
    // sortBy
    // =========================================================================

    /**
     * sortBy() returns a new Collection sorted ascending by a property value.
     */
    public function testSortByAscendingOrder(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['score' => 30]),
            self::item(['score' => 10]),
            self::item(['score' => 20]),
        ]);

        // Act
        $sorted = $col->sortBy('score');

        // Assert – scores are in ascending order
        $scores = $sorted->pluck('score')->all();
        $this->assertSame([10, 20, 30], $scores);
    }

    /**
     * sortBy() with $descending=true returns a descending-ordered Collection.
     */
    public function testSortByDescendingOrder(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['score' => 10]),
            self::item(['score' => 30]),
            self::item(['score' => 20]),
        ]);

        // Act
        $sorted = $col->sortBy('score', descending: true);

        // Assert
        $scores = $sorted->pluck('score')->all();
        $this->assertSame([30, 20, 10], $scores);
    }

    /**
     * sortBy() does not mutate the original collection.
     */
    public function testSortByDoesNotMutateOriginal(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['n' => 3]),
            self::item(['n' => 1]),
        ]);
        $originalFirst = $col->first()->n;

        // Act – sort ascending
        $col->sortBy('n');

        // Assert – original first item is unchanged
        $this->assertSame($originalFirst, $col->first()->n);
    }

    // =========================================================================
    // each
    // =========================================================================

    /**
     * each() iterates over all items calling the callback, and returns $this
     * for fluent chaining.
     */
    public function testEachInvokesCallbackForEachItemAndReturnsSelf(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['val' => 1]),
            self::item(['val' => 2]),
            self::item(['val' => 3]),
        ]);
        $seen = [];

        // Act
        $returned = $col->each(function ($item) use (&$seen) {
            $seen[] = $item->val;
        });

        // Assert – callback was called for every item
        $this->assertSame([1, 2, 3], $seen);
        // Assert – fluent interface (returns same instance)
        $this->assertSame($col, $returned);
    }

    // =========================================================================
    // all / toArray / jsonSerialize
    // =========================================================================

    /**
     * all() returns the raw underlying array (not a copy; same references).
     */
    public function testAllReturnsTheRawItemsArray(): void
    {
        // Arrange
        $items = [self::item(['id' => 1]), self::item(['id' => 2])];
        $col   = new Collection($items);

        // Act
        $result = $col->all();

        // Assert – same content, same references
        $this->assertCount(2, $result);
        $this->assertSame($items[0], $result[0]);
    }

    /**
     * toArray() converts each item that has a toArray() method, and falls back
     * to casting to array for plain objects and arrays.
     */
    public function testToArrayCastsObjectsToArrays(): void
    {
        // Arrange – stdClass is cast to array
        $col = new Collection([self::item(['x' => 1, 'y' => 2])]);

        // Act
        $result = $col->toArray();

        // Assert
        $this->assertSame([['x' => 1, 'y' => 2]], $result);
    }

    /**
     * toArray() delegates to toArray() when the item supports it.
     */
    public function testToArrayDelegatesToItemToArrayMethod(): void
    {
        // Arrange – anonymous class with its own toArray()
        $item = new class {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };
        $col = new Collection([$item]);

        // Act
        $result = $col->toArray();

        // Assert
        $this->assertSame([['key' => 'value']], $result);
    }

    /**
     * jsonSerialize() returns the same structure as toArray(), allowing
     * json_encode() to produce the expected JSON array.
     */
    public function testJsonSerializeProducesExpectedJson(): void
    {
        // Arrange
        $col = new Collection([self::item(['id' => 1]), self::item(['id' => 2])]);

        // Act
        $json = json_encode($col);

        // Assert
        $this->assertSame('[{"id":1},{"id":2}]', $json);
    }

    // =========================================================================
    // getIterator (IteratorAggregate)
    // =========================================================================

    /**
     * getIterator() returns an ArrayIterator that a foreach loop can traverse,
     * fulfilling the IteratorAggregate contract.
     */
    public function testGetIteratorAllowsForeachTraversal(): void
    {
        // Arrange
        $col = new Collection([
            self::item(['id' => 1]),
            self::item(['id' => 2]),
        ]);
        $ids = [];

        // Act – use in foreach (triggers getIterator)
        foreach ($col as $item) {
            $ids[] = $item->id;
        }

        // Assert
        $this->assertSame([1, 2], $ids);
    }

    // =========================================================================
    // Immutability / index re-keying
    // =========================================================================

    /**
     * The constructor re-keys the array to sequential integers so that
     * Collections built from sparse/associative arrays still iterate cleanly.
     */
    public function testConstructorRekeysAssociativeInput(): void
    {
        // Arrange – associative array input
        $col = new Collection(['a' => 'apple', 'b' => 'banana']);

        // Act
        $all = $col->all();

        // Assert – keys are 0, 1 (not 'a', 'b')
        $this->assertSame([0, 1], array_keys($all));
    }

    /**
     * filter() re-keys the result so no gaps appear in the returned
     * Collection's underlying array (array_values is applied).
     */
    public function testFilterRekeysResultArray(): void
    {
        // Arrange – [0, 1, 2]; filter keeps index 1 and 2
        $col = new Collection([10, 20, 30]);
        $filtered = $col->filter(fn($v) => $v >= 20);

        // Act
        $keys = array_keys($filtered->all());

        // Assert – keys are 0, 1 (not 1, 2)
        $this->assertSame([0, 1], $keys);
    }
}
