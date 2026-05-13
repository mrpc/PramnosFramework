<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Orm\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Orm\Concerns\HasTimestamps;

/**
 * Unit tests for Pramnos\Application\Orm\Concerns\HasTimestamps.
 *
 * HasTimestamps provides automatic `created_at` / `updated_at` management
 * through touchTimestamps() and can be disabled via withoutTimestamps().
 *
 * Tests verify:
 *   - touchTimestamps(true) sets both created_at and updated_at on INSERT.
 *   - touchTimestamps(true) does NOT overwrite a pre-existing created_at.
 *   - touchTimestamps(false) sets only updated_at on UPDATE.
 *   - When $timestamps=false (after withoutTimestamps()), nothing is set.
 *   - withoutTimestamps() sets $timestamps=false and returns $this.
 *   - Custom column names are respected.
 */
#[CoversClass(HasTimestamps::class)]
class HasTimestampsTest extends TestCase
{
    // =========================================================================
    // Fixture
    // =========================================================================

    /** Build a minimal object that uses the trait, with touch exposed publicly. */
    private function makeModel(?string $createdCol = null, ?string $updatedCol = null): object
    {
        return new class($createdCol, $updatedCol) {
            use HasTimestamps;

            // Declare all timestamp column names used by the trait so PHP 8.4
            // does not warn about dynamic property creation.
            public ?string $created_at  = null;
            public ?string $updated_at  = null;
            public ?string $created_on  = null;
            public ?string $modified_on = null;

            public function __construct(?string $createdCol, ?string $updatedCol)
            {
                if ($createdCol !== null) {
                    $this->createdAtColumn = $createdCol;
                }
                if ($updatedCol !== null) {
                    $this->updatedAtColumn = $updatedCol;
                }
            }

            public function touch(bool $isNew): void
            {
                $this->touchTimestamps($isNew);
            }
        };
    }

    // =========================================================================
    // withoutTimestamps()
    // =========================================================================

    /**
     * withoutTimestamps() sets $timestamps to false and returns $this for
     * fluent chaining, so the caller can disable auto-timestamping inline.
     */
    public function testWithoutTimestampsSetsFlagAndReturnsSelf(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->withoutTimestamps();

        // Assert — fluent interface
        $this->assertSame($m, $result);

        // Assert — after disabling, touch sets nothing
        $m->touch(true);
        $this->assertFalse(isset($m->created_at));
        $this->assertFalse(isset($m->updated_at));
    }

    // =========================================================================
    // touchTimestamps() — INSERT ($isNew=true)
    // =========================================================================

    /**
     * On INSERT (isNew=true), both created_at and updated_at are set to the
     * current timestamp when neither is already set.
     */
    public function testTouchTimestampsOnInsertSetsBothColumns(): void
    {
        // Arrange
        $m = $this->makeModel();
        $before = time();

        // Act
        $m->touch(true);

        // Assert — both columns populated
        $this->assertNotEmpty($m->created_at);
        $this->assertNotEmpty($m->updated_at);

        // Assert — values are valid date strings (Y-m-d H:i:s)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $m->created_at);
    }

    /**
     * On INSERT, if created_at is already set to a non-empty value, it must
     * NOT be overwritten — this preserves explicit creation dates.
     */
    public function testTouchTimestampsOnInsertDoesNotOverwriteExistingCreatedAt(): void
    {
        // Arrange
        $m = $this->makeModel();
        $m->created_at = '2020-01-01 00:00:00';

        // Act
        $m->touch(true);

        // Assert — pre-existing created_at preserved
        $this->assertSame('2020-01-01 00:00:00', $m->created_at);

        // Assert — updated_at still set
        $this->assertNotEmpty($m->updated_at);
    }

    // =========================================================================
    // touchTimestamps() — UPDATE ($isNew=false)
    // =========================================================================

    /**
     * On UPDATE (isNew=false), only updated_at is set; created_at is untouched.
     */
    public function testTouchTimestampsOnUpdateSetsOnlyUpdatedAt(): void
    {
        // Arrange
        $m = $this->makeModel();
        $m->created_at = '2020-01-01 00:00:00';

        // Act
        $m->touch(false);

        // Assert — created_at unchanged
        $this->assertSame('2020-01-01 00:00:00', $m->created_at);

        // Assert — updated_at set
        $this->assertNotEmpty($m->updated_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $m->updated_at);
    }

    // =========================================================================
    // Custom column names
    // =========================================================================

    /**
     * When the model declares custom column names (createdAtColumn / updatedAtColumn),
     * touchTimestamps() writes to those columns instead of the defaults.
     */
    public function testTouchTimestampsRespectsCustomColumnNames(): void
    {
        // Arrange — custom column names
        $m = $this->makeModel('created_on', 'modified_on');

        // Act
        $m->touch(true);

        // Assert — custom columns populated
        $this->assertNotEmpty($m->created_on);
        $this->assertNotEmpty($m->modified_on);

        // Assert — default columns NOT set (avoids polluting the model)
        $this->assertFalse(isset($m->created_at));
        $this->assertFalse(isset($m->updated_at));
    }

    /**
     * When updatedAtColumn is empty string, updated_at is not touched.
     */
    public function testTouchTimestampsSkipsUpdatedAtWhenColumnIsEmpty(): void
    {
        // Arrange
        $m = $this->makeModel(null, '');

        // Act
        $m->touch(false);

        // Assert — nothing written
        $this->assertFalse(isset($m->updated_at));
    }
}
