<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Expression;

/**
 * Unit tests for Pramnos\Database\Expression.
 *
 * Expression is a value object that wraps a raw SQL fragment so that the query
 * builder embeds it verbatim rather than binding it as a parameter.  It prevents
 * accidental quoting of things like 'NOW()', 'COUNT(*)', or column references
 * that must appear as bare SQL in the generated query.
 */
#[CoversClass(Expression::class)]
class ExpressionTest extends TestCase
{
    // =========================================================================
    // getValue
    // =========================================================================

    /**
     * getValue() returns the exact value passed to the constructor, preserving
     * the raw SQL fragment without modification.
     */
    public function testGetValueReturnsConstructorArgument(): void
    {
        // Arrange / Act
        $expr = new Expression('NOW()');

        // Assert
        $this->assertSame('NOW()', $expr->getValue());
    }

    /**
     * getValue() also works correctly with an integer argument — the Expression
     * constructor accepts mixed, so numeric literals are a valid use case
     * (e.g. embedding a hard-coded 0 or 1 in a CASE expression).
     */
    public function testGetValueWorksWithIntegerArgument(): void
    {
        // Arrange / Act
        $expr = new Expression(42);

        // Assert
        $this->assertSame(42, $expr->getValue());
    }

    /**
     * getValue() preserves a complex SQL string (multi-word fragment with
     * function calls) exactly as provided — no normalisation is applied.
     */
    public function testGetValuePreservesComplexSqlFragment(): void
    {
        // Arrange – a realistic subquery fragment
        $sql  = 'COALESCE(user_count, 0)';
        $expr = new Expression($sql);

        // Act / Assert
        $this->assertSame($sql, $expr->getValue());
    }

    // =========================================================================
    // __toString
    // =========================================================================

    /**
     * __toString() returns the same string as getValue() when the stored value
     * is a string, making the object usable anywhere a string is expected.
     */
    public function testToStringReturnsSameAsGetValue(): void
    {
        // Arrange
        $expr = new Expression('COUNT(*)');

        // Act
        $string = (string) $expr;

        // Assert
        $this->assertSame($expr->getValue(), $string);
    }

    /**
     * __toString() casts a numeric value to string, so an integer Expression
     * like Expression(0) produces '0' when string-cast.
     */
    public function testToStringCastsIntegerValueToString(): void
    {
        // Arrange
        $expr = new Expression(0);

        // Act
        $string = (string) $expr;

        // Assert
        $this->assertSame('0', $string);
    }

    /**
     * PHP string interpolation triggers __toString(), so an Expression can be
     * embedded directly in a double-quoted string — useful in the query builder
     * when building raw SQL fragments.
     */
    public function testExpressionCanBeEmbeddedInStringInterpolation(): void
    {
        // Arrange
        $expr = new Expression('NOW()');

        // Act – embed in an interpolated string
        $query = "SELECT {$expr} AS current_time";

        // Assert
        $this->assertSame('SELECT NOW() AS current_time', $query);
    }
}
