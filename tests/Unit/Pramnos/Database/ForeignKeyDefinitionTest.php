<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\ForeignKeyDefinition;

/**
 * Unit tests for Pramnos\Database\ForeignKeyDefinition.
 *
 * ForeignKeyDefinition is a fluent descriptor returned by Blueprint::foreign().
 * Every modifier stores the value on the public property, normalises the case
 * for action strings, and returns $this for chaining.
 *
 * Tests verify:
 *   - Constructor sets $column and defaults (no referenced table/column, RESTRICT actions).
 *   - references() / on() / onDelete() / onUpdate() store values and return $this.
 *   - onDelete() / onUpdate() uppercase their argument.
 *   - constraintName() overrides the auto-generated name.
 *   - Cascade shortcuts (cascadeOnDelete, cascadeOnUpdate, nullOnDelete, noActionOnDelete)
 *     set the correct action strings.
 *   - Full fluent chain example works.
 */
#[CoversClass(ForeignKeyDefinition::class)]
class ForeignKeyDefinitionTest extends TestCase
{
    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * Constructor stores the local column name and initialises sensible defaults.
     */
    public function testConstructorStoresColumnAndDefaultValues(): void
    {
        // Arrange / Act
        $fk = new ForeignKeyDefinition('user_id');

        // Assert
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('', $fk->referencedTable);
        $this->assertSame('', $fk->referencedColumn);
        $this->assertSame('RESTRICT', $fk->onDelete);
        $this->assertSame('RESTRICT', $fk->onUpdate);
        $this->assertNull($fk->constraintName);
    }

    // =========================================================================
    // references() / on()
    // =========================================================================

    /**
     * references() stores the referenced column name and returns $this.
     */
    public function testReferencesSetsColumnAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('user_id');

        // Act
        $result = $fk->references('id');

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('id', $fk->referencedColumn);
    }

    /**
     * on() stores the referenced table name and returns $this.
     */
    public function testOnSetsTableAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('user_id');

        // Act
        $result = $fk->on('users');

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('users', $fk->referencedTable);
    }

    // =========================================================================
    // onDelete() / onUpdate()
    // =========================================================================

    /**
     * onDelete() uppercases the action string and returns $this.
     */
    public function testOnDeleteUppercasesActionAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('post_id');

        // Act
        $result = $fk->onDelete('cascade');

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('CASCADE', $fk->onDelete);
    }

    /**
     * onUpdate() uppercases the action string and returns $this.
     */
    public function testOnUpdateUppercasesActionAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('post_id');

        // Act
        $result = $fk->onUpdate('set null');

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('SET NULL', $fk->onUpdate);
    }

    // =========================================================================
    // constraintName()
    // =========================================================================

    /**
     * constraintName() overrides the auto-generated name.
     */
    public function testConstraintNameSetsNameAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('user_id');

        // Act
        $result = $fk->constraintName('fk_posts_user_id');

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('fk_posts_user_id', $fk->constraintName);
    }

    // =========================================================================
    // Cascade shortcuts
    // =========================================================================

    /**
     * cascadeOnDelete() sets onDelete = 'CASCADE'.
     */
    public function testCascadeOnDeleteSetsCascadeAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('category_id');

        // Act
        $result = $fk->cascadeOnDelete();

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('CASCADE', $fk->onDelete);
    }

    /**
     * cascadeOnUpdate() sets onUpdate = 'CASCADE'.
     */
    public function testCascadeOnUpdateSetsCascadeAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('category_id');

        // Act
        $result = $fk->cascadeOnUpdate();

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('CASCADE', $fk->onUpdate);
    }

    /**
     * nullOnDelete() sets onDelete = 'SET NULL'.
     */
    public function testNullOnDeleteSetsSetNullAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('manager_id');

        // Act
        $result = $fk->nullOnDelete();

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('SET NULL', $fk->onDelete);
    }

    /**
     * noActionOnDelete() sets onDelete = 'NO ACTION'.
     */
    public function testNoActionOnDeleteSetsNoActionAndReturnsSelf(): void
    {
        // Arrange
        $fk = new ForeignKeyDefinition('dept_id');

        // Act
        $result = $fk->noActionOnDelete();

        // Assert
        $this->assertSame($fk, $result);
        $this->assertSame('NO ACTION', $fk->onDelete);
    }

    // =========================================================================
    // Fluent chaining
    // =========================================================================

    /**
     * A full chain specifying the complete foreign key definition works as
     * expected and all values are stored correctly.
     */
    public function testFluentChainingAppliesAllSetters(): void
    {
        // Arrange / Act
        $fk = (new ForeignKeyDefinition('user_id'))
            ->references('id')
            ->on('users')
            ->cascadeOnDelete()
            ->cascadeOnUpdate()
            ->constraintName('fk_posts_user_id');

        // Assert
        $this->assertSame('user_id',         $fk->column);
        $this->assertSame('id',              $fk->referencedColumn);
        $this->assertSame('users',           $fk->referencedTable);
        $this->assertSame('CASCADE',         $fk->onDelete);
        $this->assertSame('CASCADE',         $fk->onUpdate);
        $this->assertSame('fk_posts_user_id', $fk->constraintName);
    }
}
