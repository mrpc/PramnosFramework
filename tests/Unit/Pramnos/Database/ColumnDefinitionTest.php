<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\ColumnDefinition;
use Pramnos\Database\Expression;

/**
 * Unit tests for Pramnos\Database\ColumnDefinition.
 *
 * ColumnDefinition is a fluent descriptor returned by SchemaBuilder column
 * methods.  Each modifier stores a value in the $attributes bag and returns
 * $this for chaining.  The get() / has() accessors read from that bag.
 *
 * Tests verify:
 *   - Constructor stores name, type, and optional initial attributes.
 *   - Every modifier (nullable, default, unsigned, autoIncrement, primary,
 *     unique, after, first, comment, check, storedAs, virtualAs, charset,
 *     collation) sets the expected attribute and returns $this.
 *   - useCurrent() delegates to default(Expression('CURRENT_TIMESTAMP')).
 *   - get() returns the stored value or the given default when missing.
 *   - has() returns true only for explicitly set attributes (even if null/false).
 *   - Modifiers can be chained fluently.
 */
#[CoversClass(ColumnDefinition::class)]
class ColumnDefinitionTest extends TestCase
{
    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * Constructor stores name, type, and any initial attributes.
     */
    public function testConstructorStoresNameTypeAndAttributes(): void
    {
        // Arrange / Act
        $col = new ColumnDefinition('age', 'integer', ['unsigned' => true]);

        // Assert
        $this->assertSame('age', $col->name);
        $this->assertSame('integer', $col->type);
        $this->assertTrue($col->attributes['unsigned']);
    }

    /**
     * Constructor with no attributes argument leaves the bag empty.
     */
    public function testConstructorDefaultsToEmptyAttributeBag(): void
    {
        // Arrange / Act
        $col = new ColumnDefinition('name', 'string');

        // Assert
        $this->assertSame([], $col->attributes);
    }

    // =========================================================================
    // Nullability
    // =========================================================================

    /**
     * nullable() sets attributes['nullable'] = true and returns $this.
     */
    public function testNullableSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('bio', 'text');

        // Act
        $result = $col->nullable();

        // Assert
        $this->assertSame($col, $result);
        $this->assertTrue($col->attributes['nullable']);
    }

    /**
     * nullable(false) sets attributes['nullable'] = false.
     */
    public function testNullableWithFalseArgumentStoredCorrectly(): void
    {
        // Arrange
        $col = new ColumnDefinition('name', 'string');

        // Act
        $col->nullable(false);

        // Assert
        $this->assertFalse($col->attributes['nullable']);
    }

    // =========================================================================
    // default() / useCurrent()
    // =========================================================================

    /**
     * default() stores both the value and the hasDefault flag.
     */
    public function testDefaultStoresValueAndHasDefaultFlag(): void
    {
        // Arrange
        $col = new ColumnDefinition('status', 'string');

        // Act
        $result = $col->default('active');

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame('active', $col->attributes['default']);
        $this->assertTrue($col->attributes['hasDefault']);
    }

    /**
     * default(null) is valid — hasDefault is still set.
     */
    public function testDefaultWithNullValueSetsHasDefault(): void
    {
        // Arrange
        $col = new ColumnDefinition('deleted_at', 'datetime');

        // Act
        $col->default(null);

        // Assert
        $this->assertNull($col->attributes['default']);
        $this->assertTrue($col->attributes['hasDefault']);
    }

    /**
     * useCurrent() stores an Expression as the default value.
     */
    public function testUseCurrentStoresExpression(): void
    {
        // Arrange
        $col = new ColumnDefinition('created_at', 'datetime');

        // Act
        $result = $col->useCurrent();

        // Assert — returns $this; default is an Expression
        $this->assertSame($col, $result);
        $this->assertInstanceOf(Expression::class, $col->attributes['default']);
    }

    // =========================================================================
    // Numeric modifiers
    // =========================================================================

    /**
     * unsigned() sets attributes['unsigned'] = true and returns $this.
     */
    public function testUnsignedSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('count', 'integer');

        // Act
        $result = $col->unsigned();

        // Assert
        $this->assertSame($col, $result);
        $this->assertTrue($col->attributes['unsigned']);
    }

    /**
     * autoIncrement() sets attributes['autoIncrement'] = true and returns $this.
     */
    public function testAutoIncrementSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('id', 'integer');

        // Act
        $result = $col->autoIncrement();

        // Assert
        $this->assertSame($col, $result);
        $this->assertTrue($col->attributes['autoIncrement']);
    }

    // =========================================================================
    // Key / constraint modifiers
    // =========================================================================

    /**
     * primary() marks the column as a primary key component.
     */
    public function testPrimarySetsPrimaryAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('id', 'integer');

        // Act
        $result = $col->primary();

        // Assert
        $this->assertSame($col, $result);
        $this->assertTrue($col->attributes['primary']);
    }

    /**
     * unique() marks the column with a UNIQUE constraint.
     */
    public function testUniqueSetsUniqueAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('email', 'string');

        // Act
        $result = $col->unique();

        // Assert
        $this->assertSame($col, $result);
        $this->assertTrue($col->attributes['unique']);
    }

    // =========================================================================
    // Positioning (MySQL)
    // =========================================================================

    /**
     * after() stores the target column name in attributes['after'].
     */
    public function testAfterStoresColumnNameAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('middle_name', 'string');

        // Act
        $result = $col->after('first_name');

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame('first_name', $col->attributes['after']);
    }

    /**
     * first() stores attributes['first'] = true.
     */
    public function testFirstSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('sort_key', 'integer');

        // Act
        $result = $col->first();

        // Assert
        $this->assertSame($col, $result);
        $this->assertTrue($col->attributes['first']);
    }

    // =========================================================================
    // Metadata
    // =========================================================================

    /**
     * comment() stores the comment text.
     */
    public function testCommentStoresTextAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('score', 'integer');

        // Act
        $result = $col->comment('Player score');

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame('Player score', $col->attributes['comment']);
    }

    // =========================================================================
    // Check constraint
    // =========================================================================

    /**
     * check() stores the SQL expression string.
     */
    public function testCheckStoresExpressionAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('age', 'integer');

        // Act
        $result = $col->check('age >= 0');

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame('age >= 0', $col->attributes['check']);
    }

    // =========================================================================
    // Computed columns
    // =========================================================================

    /**
     * storedAs() stores the SQL expression for a stored generated column.
     */
    public function testStoredAsSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('full_name', 'string');

        // Act
        $result = $col->storedAs("CONCAT(first, ' ', last)");

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame("CONCAT(first, ' ', last)", $col->attributes['storedAs']);
    }

    /**
     * virtualAs() stores the SQL expression for a virtual generated column.
     */
    public function testVirtualAsSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('initials', 'string');

        // Act
        $result = $col->virtualAs('LEFT(name, 1)');

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame('LEFT(name, 1)', $col->attributes['virtualAs']);
    }

    // =========================================================================
    // Character set (MySQL)
    // =========================================================================

    /**
     * charset() stores the character set name.
     */
    public function testCharsetSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('body', 'text');

        // Act
        $result = $col->charset('utf8mb4');

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame('utf8mb4', $col->attributes['charset']);
    }

    /**
     * collation() stores the collation name.
     */
    public function testCollationSetsAttributeAndReturnsSelf(): void
    {
        // Arrange
        $col = new ColumnDefinition('title', 'string');

        // Act
        $result = $col->collation('utf8mb4_unicode_ci');

        // Assert
        $this->assertSame($col, $result);
        $this->assertSame('utf8mb4_unicode_ci', $col->attributes['collation']);
    }

    // =========================================================================
    // get() / has()
    // =========================================================================

    /**
     * get() returns the stored attribute value.
     */
    public function testGetReturnsStoredAttributeValue(): void
    {
        // Arrange
        $col = new ColumnDefinition('n', 'integer', ['unsigned' => true]);

        // Assert
        $this->assertTrue($col->get('unsigned'));
    }

    /**
     * get() returns the given default when the attribute is absent.
     */
    public function testGetReturnsDefaultWhenAttributeNotPresent(): void
    {
        // Arrange
        $col = new ColumnDefinition('n', 'integer');

        // Assert — default is null when not specified
        $this->assertNull($col->get('nonexistent'));

        // Assert — caller-supplied default
        $this->assertSame('fallback', $col->get('missing', 'fallback'));
    }

    /**
     * has() returns true for an explicitly set attribute, even if the value is
     * false or null — this distinguishes "set to null" from "never set".
     */
    public function testHasReturnsTrueForExplicitlySetAttribute(): void
    {
        // Arrange — nullable=false, default=null
        $col = new ColumnDefinition('x', 'string');
        $col->nullable(false);
        $col->default(null);

        // Assert — both set, even though values are falsy
        $this->assertTrue($col->has('nullable'));
        $this->assertTrue($col->has('default'));
    }

    /**
     * has() returns false when the attribute has never been set.
     */
    public function testHasReturnsFalseForAbsentAttribute(): void
    {
        // Arrange
        $col = new ColumnDefinition('y', 'string');

        // Assert
        $this->assertFalse($col->has('unsigned'));
    }

    // =========================================================================
    // Fluent chaining
    // =========================================================================

    /**
     * Multiple modifiers can be chained in a single expression and all take effect.
     */
    public function testFluentChainingAppliesAllModifiers(): void
    {
        // Arrange / Act — chain several modifiers
        $col = (new ColumnDefinition('bio', 'text'))
            ->nullable()
            ->default('')
            ->comment('User bio text')
            ->charset('utf8mb4');

        // Assert — all modifiers applied
        $this->assertTrue($col->attributes['nullable']);
        $this->assertSame('', $col->attributes['default']);
        $this->assertSame('User bio text', $col->attributes['comment']);
        $this->assertSame('utf8mb4', $col->attributes['charset']);
    }
}
