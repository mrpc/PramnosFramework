<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Exposes the statement description, which is protected.
 */
class StatementDescriber extends Database
{
    /** No connection: describing a statement touches none. */
    public function __construct()
    {
    }

    /**
     * @param  mixed $sql
     * @param  array<int, mixed> $arguments
     */
    public function describe($sql, array $arguments): string
    {
        return $this->describeStatement($sql, null, $arguments);
    }
}

/**
 * What the query log shows for a prepared statement.
 *
 * The toolbar reads the in-memory query log, and prepared statements were not
 * in it at all — which is most of what a modern application runs, since
 * everything the query builder produces goes through that path. The ones that
 * did appear showed their template: `WHERE userid = %i`, with no way to see
 * which user, compare two runs, or paste the statement into a client.
 *
 * Nothing here is ever executed. The description exists to be read, so the
 * tests are about readability and about not lying — a value that is quoted
 * wrongly in a log is a value somebody will mis-copy.
 */
#[CoversClass(Database::class)]
class QueryLogInterpolationTest extends TestCase
{
    /**
     * Placeholders are replaced by the values that were bound.
     *
     * The whole point: a statement in the log should say what it did.
     */
    public function testPlaceholdersAreReplacedByTheirValues(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $described = $describer->describe(
            'SELECT * FROM users WHERE userid = %i AND status = %s',
            [42, 'active']
        );

        // Assert
        $this->assertSame(
            "SELECT * FROM users WHERE userid = 42 AND status = 'active'",
            $described
        );
    }

    /**
     * The driver's own placeholders are understood too.
     *
     * By the time a statement is executed it carries `?` or `$1` rather than
     * the caller's `%s`, and the log has to be readable either way.
     */
    public function testDriverPlaceholdersAreUnderstood(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $question = $describer->describe('SELECT * FROM t WHERE a = ? AND b = ?', [1, 'x']);
        $numbered = $describer->describe('SELECT * FROM t WHERE a = $1 AND b = $2', [1, 'x']);

        // Assert
        $this->assertSame("SELECT * FROM t WHERE a = 1 AND b = 'x'", $question);
        $this->assertSame("SELECT * FROM t WHERE a = 1 AND b = 'x'", $numbered);
    }

    /**
     * Numbered placeholders name their own parameter, in any order.
     *
     * PostgreSQL allows `$1` to appear twice, or after `$2`; consuming
     * parameters positionally would put the wrong value in the log and send
     * somebody looking for a bug that is not there.
     */
    public function testNumberedPlaceholdersAreNotPositional(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $described = $describer->describe(
            'SELECT * FROM t WHERE a = $2 AND b = $1 AND c = $2',
            ['first', 'second']
        );

        // Assert
        $this->assertSame(
            "SELECT * FROM t WHERE a = 'second' AND b = 'first' AND c = 'second'",
            $described
        );
    }

    /**
     * A percent sign inside a quoted string is not a placeholder.
     *
     * `LIKE '%foo%'` is a literal, and treating it as two placeholders would
     * consume parameters and shift every later value by two.
     */
    public function testAPercentInsideAStringIsNotAPlaceholder(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $described = $describer->describe(
            "SELECT * FROM t WHERE name LIKE '%value%' AND id = %d",
            [7]
        );

        // Assert
        $this->assertSame(
            "SELECT * FROM t WHERE name LIKE '%value%' AND id = 7",
            $described
        );
    }

    /**
     * Values are quoted so the statement can be pasted into a client.
     */
    public function testValuesAreQuotedForReading(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $described = $describer->describe(
            'INSERT INTO t (a, b, c, d) VALUES (?, ?, ?, ?)',
            ["o'brien", null, true, 3.5]
        );

        // Assert — the apostrophe is doubled, as SQL wants it
        $this->assertSame(
            "INSERT INTO t (a, b, c, d) VALUES ('o''brien', NULL, true, 3.5)",
            $described
        );
    }

    /**
     * A very long parameter is cut short.
     *
     * One bound value can hold an entire request body — the audit log binds
     * exactly that. A log entry the width of a POST body is a log entry nobody
     * reads.
     */
    public function testALongValueIsTruncated(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $described = $describer->describe('INSERT INTO t (a) VALUES (?)', [str_repeat('x', 5000)]);

        // Assert
        $this->assertLessThan(300, strlen($described));
        $this->assertStringContainsString('…', $described, 'and it says it was cut');
    }

    /**
     * A statement with no parameters comes back unchanged.
     */
    public function testAStatementWithNoParametersIsUntouched(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act & Assert
        $this->assertSame(
            'SELECT COUNT(*) FROM users',
            $describer->describe('SELECT COUNT(*) FROM users', [])
        );
    }

    /**
     * More placeholders than parameters does not raise.
     *
     * The log must survive a caller that got its own arguments wrong — the
     * statement will fail on its own, and an instrumentation error on top of it
     * only hides the real one.
     */
    public function testMorePlaceholdersThanValuesIsSurvivable(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $described = $describer->describe('SELECT * FROM t WHERE a = ? AND b = ?', [1]);

        // Assert
        $this->assertStringContainsString('NULL', $described);
    }

    /**
     * A statement that was not passed as a string is still described.
     */
    public function testAnUnknownStatementIsLabelledRatherThanEmpty(): void
    {
        // Arrange
        $describer = new StatementDescriber();

        // Act
        $described = $describer->describe(new \stdClass(), [1]);

        // Assert
        $this->assertSame('(prepared statement)', $described);
    }
}
