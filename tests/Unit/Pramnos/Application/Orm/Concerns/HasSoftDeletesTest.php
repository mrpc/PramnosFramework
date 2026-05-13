<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Orm\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Orm\Concerns\HasSoftDeletes;

/**
 * Unit tests for Pramnos\Application\Orm\Concerns\HasSoftDeletes.
 *
 * HasSoftDeletes provides soft-delete behaviour: instead of hard-deleting a row,
 * it sets a `deleted_at` timestamp and filters those records from queries.
 *
 * Tests verify:
 *   - withTrashed() / onlyTrashed() toggle the query-scope flags and return $this.
 *   - trashed() returns true only when $softDelete=true AND deleted_at is set.
 *   - softDelete() sets deleted_at when $softDelete=true; does nothing otherwise.
 *   - restore() clears deleted_at when $softDelete=true; does nothing otherwise.
 *   - buildSoftDeleteFilter() returns the correct SQL fragment for each flag combo.
 *   - mergeSoftDeleteFilter() combines user filter with soft-delete clause.
 *
 * The _save() method that softDelete() / restore() call is stubbed to prevent
 * any real database calls.
 */
#[CoversClass(HasSoftDeletes::class)]
class HasSoftDeletesTest extends TestCase
{
    // =========================================================================
    // Fixture
    // =========================================================================

    /**
     * Build a minimal object that uses the trait.
     *
     * @param bool   $enabled    Whether soft-delete is on.
     * @param string $deletedCol The deleted_at column name.
     */
    private function makeModel(bool $enabled = false, string $deletedCol = 'deleted_at'): object
    {
        return new class($enabled, $deletedCol) {
            use HasSoftDeletes;

            public int    $saveCallCount = 0;
            public ?string $deleted_at   = null;
            public ?string $removed_at   = null;

            public function __construct(bool $enabled, string $deletedCol)
            {
                $this->softDelete       = $enabled;
                $this->deletedAtColumn  = $deletedCol;
            }

            /** Stub — prevents real DB calls, tracks invocations. */
            public function _save(): void
            {
                $this->saveCallCount++;
            }

            // Expose protected methods for assertion
            public function buildFilter(): string
            {
                return $this->buildSoftDeleteFilter();
            }

            public function mergeFilter(?string $filter): string
            {
                return $this->mergeSoftDeleteFilter($filter);
            }

            public function getWithTrashedFlag(): bool
            {
                return $this->withTrashedFlag;
            }

            public function getOnlyTrashedFlag(): bool
            {
                return $this->onlyTrashedFlag;
            }
        };
    }

    // =========================================================================
    // withTrashed() / onlyTrashed()
    // =========================================================================

    /**
     * withTrashed() sets withTrashedFlag=true, clears onlyTrashedFlag, and
     * returns $this for fluent chaining.
     */
    public function testWithTrashedSetsFlagAndReturnsSelf(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->withTrashed();

        // Assert — fluent
        $this->assertSame($m, $result);
        // Assert — flags
        $this->assertTrue($m->getWithTrashedFlag());
        $this->assertFalse($m->getOnlyTrashedFlag());
    }

    /**
     * onlyTrashed() sets onlyTrashedFlag=true, clears withTrashedFlag, and
     * returns $this.
     */
    public function testOnlyTrashedSetsFlagAndReturnsSelf(): void
    {
        // Arrange
        $m = $this->makeModel();
        $m->withTrashed(); // set withTrashedFlag first to verify it is cleared

        // Act
        $result = $m->onlyTrashed();

        // Assert — fluent
        $this->assertSame($m, $result);
        // Assert — flags
        $this->assertTrue($m->getOnlyTrashedFlag());
        $this->assertFalse($m->getWithTrashedFlag());
    }

    // =========================================================================
    // trashed()
    // =========================================================================

    /**
     * trashed() returns false when $softDelete=false, regardless of deleted_at.
     */
    public function testTrashedReturnsFalseWhenSoftDeleteDisabled(): void
    {
        // Arrange — soft-delete off
        $m = $this->makeModel(false);
        $m->deleted_at = '2024-01-01 00:00:00';

        // Assert — soft-delete flag is off so trashed() is always false
        $this->assertFalse($m->trashed());
    }

    /**
     * trashed() returns false when $softDelete=true but deleted_at is null/empty.
     */
    public function testTrashedReturnsFalseWhenDeletedAtNotSet(): void
    {
        // Arrange — soft-delete on, no deleted_at
        $m = $this->makeModel(true);

        // Assert
        $this->assertFalse($m->trashed());
    }

    /**
     * trashed() returns true when $softDelete=true AND deleted_at has a value.
     */
    public function testTrashedReturnsTrueWhenSoftDeletedAtIsSet(): void
    {
        // Arrange
        $m = $this->makeModel(true);
        $m->deleted_at = '2024-06-15 10:00:00';

        // Assert
        $this->assertTrue($m->trashed());
    }

    // =========================================================================
    // softDelete()
    // =========================================================================

    /**
     * softDelete() does nothing and returns $this when $softDelete=false.
     */
    public function testSoftDeleteDoesNothingWhenDisabled(): void
    {
        // Arrange
        $m = $this->makeModel(false);

        // Act
        $result = $m->softDelete();

        // Assert — fluent; _save() NOT called
        $this->assertSame($m, $result);
        $this->assertSame(0, $m->saveCallCount);
        $this->assertFalse(isset($m->deleted_at));
    }

    /**
     * softDelete() sets deleted_at to the current datetime and calls _save()
     * when $softDelete=true.
     */
    public function testSoftDeleteSetsDeletedAtAndCallsSave(): void
    {
        // Arrange
        $m = $this->makeModel(true);

        // Act
        $result = $m->softDelete();

        // Assert — fluent
        $this->assertSame($m, $result);

        // Assert — deleted_at is set to a valid timestamp
        $this->assertNotEmpty($m->deleted_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $m->deleted_at);

        // Assert — _save() called
        $this->assertSame(1, $m->saveCallCount);
    }

    // =========================================================================
    // restore()
    // =========================================================================

    /**
     * restore() does nothing and returns $this when $softDelete=false.
     */
    public function testRestoreDoesNothingWhenDisabled(): void
    {
        // Arrange
        $m = $this->makeModel(false);
        $m->deleted_at = '2024-01-01 00:00:00';

        // Act
        $result = $m->restore();

        // Assert — unchanged; _save() NOT called
        $this->assertSame($m, $result);
        $this->assertSame(0, $m->saveCallCount);
        $this->assertSame('2024-01-01 00:00:00', $m->deleted_at);
    }

    /**
     * restore() clears deleted_at to null and calls _save() when $softDelete=true.
     */
    public function testRestoreClearsDeletedAtAndCallsSave(): void
    {
        // Arrange
        $m = $this->makeModel(true);
        $m->deleted_at = '2024-06-15 10:00:00';

        // Act
        $result = $m->restore();

        // Assert — fluent
        $this->assertSame($m, $result);

        // Assert — deleted_at cleared
        $this->assertNull($m->deleted_at);

        // Assert — _save() called
        $this->assertSame(1, $m->saveCallCount);
    }

    // =========================================================================
    // buildSoftDeleteFilter()
    // =========================================================================

    /**
     * buildSoftDeleteFilter() returns '' when $softDelete=false (feature off).
     */
    public function testBuildSoftDeleteFilterReturnsEmptyWhenDisabled(): void
    {
        // Arrange
        $m = $this->makeModel(false);

        // Assert
        $this->assertSame('', $m->buildFilter());
    }

    /**
     * When only $withTrashedFlag is true, all records are included → '' returned.
     */
    public function testBuildSoftDeleteFilterReturnsEmptyWithWithTrashed(): void
    {
        // Arrange
        $m = $this->makeModel(true);
        $m->withTrashed();

        // Assert — include all records; no filter clause
        $this->assertSame('', $m->buildFilter());
    }

    /**
     * When $onlyTrashedFlag is true, filter returns "deleted_at IS NOT NULL".
     */
    public function testBuildSoftDeleteFilterReturnsIsNotNullForOnlyTrashed(): void
    {
        // Arrange
        $m = $this->makeModel(true);
        $m->onlyTrashed();

        // Assert
        $this->assertSame('deleted_at IS NOT NULL', $m->buildFilter());
    }

    /**
     * Default (no flags set): filter returns "deleted_at IS NULL" to exclude
     * soft-deleted records.
     */
    public function testBuildSoftDeleteFilterReturnsIsNullByDefault(): void
    {
        // Arrange — soft-delete on, no flag set
        $m = $this->makeModel(true);

        // Assert
        $this->assertSame('deleted_at IS NULL', $m->buildFilter());
    }

    /**
     * Custom deletedAtColumn is used in the filter clause.
     */
    public function testBuildSoftDeleteFilterUsesCustomColumnName(): void
    {
        // Arrange
        $m = $this->makeModel(true, 'removed_at');

        // Assert
        $this->assertSame('removed_at IS NULL', $m->buildFilter());
    }

    // =========================================================================
    // mergeSoftDeleteFilter()
    // =========================================================================

    /**
     * mergeSoftDeleteFilter() returns the user filter unchanged when soft-delete
     * is disabled (buildSoftDeleteFilter returns '').
     */
    public function testMergeSoftDeleteFilterReturnsUserFilterWhenDisabled(): void
    {
        // Arrange
        $m = $this->makeModel(false);

        // Assert
        $this->assertSame('active = 1', $m->mergeFilter('active = 1'));
    }

    /**
     * mergeSoftDeleteFilter() returns an empty string when both the user filter
     * and the soft-delete filter are empty/null.
     */
    public function testMergeSoftDeleteFilterReturnsEmptyWhenBothEmpty(): void
    {
        // Arrange — soft-delete on but withTrashed → soft filter = ''
        $m = $this->makeModel(true);
        $m->withTrashed();

        // Assert
        $this->assertSame('', $m->mergeFilter(null));
    }

    /**
     * mergeSoftDeleteFilter() returns just the soft-delete clause when no user
     * filter is provided.
     */
    public function testMergeSoftDeleteFilterReturnsSoftClauseAloneWhenNoUserFilter(): void
    {
        // Arrange
        $m = $this->makeModel(true);

        // Assert — no user filter; only soft-delete clause
        $this->assertSame('deleted_at IS NULL', $m->mergeFilter(null));
        $this->assertSame('deleted_at IS NULL', $m->mergeFilter(''));
    }

    /**
     * mergeSoftDeleteFilter() combines both filters with AND when both are non-empty.
     */
    public function testMergeSoftDeleteFilterCombinesBothFilters(): void
    {
        // Arrange
        $m = $this->makeModel(true);

        // Act
        $result = $m->mergeFilter('active = 1');

        // Assert — combined with AND
        $this->assertSame('(active = 1) AND (deleted_at IS NULL)', $result);
    }
}
