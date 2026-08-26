<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\QueryBuilder;

/**
 * Reading a column whose name has upper-case letters.
 *
 * WHAT: that a bare identifier carrying an upper-case letter is quoted in a
 *       SELECT, a WHERE, a GROUP BY, an ORDER BY and a HAVING — and that
 *       everything else is emitted exactly as it was.
 * WHY:  PostgreSQL folds an unquoted identifier to lower case. `compileInsert()`
 *       and `compileUpdate()` have always quoted; the read paths never did. A
 *       column named `parentToken` could therefore be *written* and not *read* —
 *       `SELECT … parentToken` asked for `parenttoken`, PostgreSQL said it did not
 *       exist, the builder swallowed the error and returned nothing.
 *
 *       Real behaviour was built on top of that silence: a logout that revoked no
 *       tokens, an introspection that reported every live token as dead. Neither
 *       raised anything, because "the query returned no rows" and "the query
 *       failed" arrive at the caller identically.
 *
 * The predicate is deliberately narrow, and the tests for what is **not** quoted
 * matter as much as the ones for what is: an all-lower-case identifier is
 * unaffected by folding, so leaving it alone means no existing generated SQL
 * changes anywhere.
 */
class CaseSensitiveColumnTest extends TestCase
{
    /** A builder over the PostgreSQL grammar, without a connection. */
    private function postgres(): QueryBuilder
    {
        return $this->builderFor(new \Pramnos\Database\Grammar\PostgreSQLGrammar());
    }

    /** A builder over the MySQL grammar, without a connection. */
    private function mysql(): QueryBuilder
    {
        return $this->builderFor(new \Pramnos\Database\Grammar\MySQLGrammar());
    }

    private function builderFor(object $grammar): QueryBuilder
    {
        $rc      = new \ReflectionClass(QueryBuilder::class);
        $builder = $rc->newInstanceWithoutConstructor();

        // `toSql()` substitutes the table prefix, so it needs something that has
        // one. A stub is enough: nothing here executes a query.
        $stubDb = new class {
            public string $prefix = '';
        };

        foreach (['grammar' => $grammar, 'db' => $stubDb] as $name => $value) {
            $property = $rc->getProperty($name);
            $property->setValue($builder, $value);
        }

        return $builder;
    }

    /** The SQL a builder compiles, without executing it. */
    private function sql(QueryBuilder $builder): string
    {
        return $builder->toSql();
    }

    /**
     * A camelCase column is quoted in the select list.
     *
     * The case the bug was found through: `parentToken` links a refresh token to
     * its access token, and no read path could see it.
     */
    public function testACamelCaseColumnIsQuotedInASelect(): void
    {
        // Act
        $sql = $this->sql(
            $this->postgres()->table('usertokens')->select(['tokenid', 'parentToken'])
        );

        // Assert
        $this->assertStringContainsString('"parentToken"', $sql);
        $this->assertStringNotContainsString(' parentToken', $sql);
    }

    /** And in a where clause, which is how it is matched. */
    public function testACamelCaseColumnIsQuotedInAWhere(): void
    {
        // Act
        $sql = $this->sql(
            $this->postgres()->table('usertokens')->where('parentToken', 5)
        );

        // Assert
        $this->assertStringContainsString('"parentToken"', $sql);
    }

    /** MySQL gets its own quoting character, so the same code works on both. */
    public function testMySqlUsesItsOwnQuoting(): void
    {
        // Act
        $sql = $this->sql(
            $this->mysql()->table('usertokens')->select(['parentToken'])
        );

        // Assert
        $this->assertStringContainsString('`parentToken`', $sql);
    }

    /**
     * An all-lower-case column is untouched.
     *
     * The assertion that makes this change safe to ship: folding cannot affect it,
     * so quoting it would only churn every piece of generated SQL in the framework
     * and every test that asserts on one.
     */
    public function testALowerCaseColumnIsNotQuoted(): void
    {
        // Act
        $sql = $this->sql(
            $this->postgres()->table('usertokens')->select(['tokenid', 'userid'])->where('status', 1)
        );

        // Assert
        $this->assertStringNotContainsString('"', $sql);
    }

    /**
     * Anything that is not a bare identifier is left exactly as written.
     *
     * These are expressions, qualified names and stars. Quoting one would turn
     * working SQL into a syntax error, so the predicate refuses them by shape
     * rather than by trying to parse them.
     *
     * @param string $column A select-list entry that must not be quoted
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expressionsThatMustNotBeQuoted')]
    public function testAnExpressionIsLeftAlone(string $column): void
    {
        // Act
        $sql = $this->sql($this->postgres()->table('usertokens')->select([$column]));

        // Assert
        $this->assertStringContainsString($column, $sql, $column . ' must survive verbatim');
    }

    /** @return array<string, array{0: string}> */
    public static function expressionsThatMustNotBeQuoted(): array
    {
        return [
            'star'            => ['*'],
            'qualified'       => ['ut.parentToken'],
            'qualified star'  => ['ut.*'],
            'aggregate'       => ['MAX(ut.lastused) AS last_used'],
            'count'           => ['COUNT(*) AS total'],
            'alias'           => ['a.apikey AS client_id'],
            'already quoted'  => ['"parentToken"'],
            'comma list'      => ['ut.*, u.username'],
        ];
    }

    /** A camelCase column is quoted in GROUP BY and ORDER BY too. */
    public function testGroupingAndOrderingQuoteItAsWell(): void
    {
        // Act
        $grouped = $this->sql(
            $this->postgres()->table('usertokens')->select(['parentToken'])->groupBy(['parentToken'])
        );
        $ordered = $this->sql(
            $this->postgres()->table('usertokens')->orderBy('parentToken', 'desc')
        );

        // Assert
        $this->assertStringContainsString('GROUP BY "parentToken"', $grouped);
        $this->assertStringContainsString('ORDER BY "parentToken" DESC', $ordered);
    }

    /** And in the null checks, which name a column without an operator. */
    public function testNullChecksQuoteItAsWell(): void
    {
        // Act
        $isNull    = $this->sql($this->postgres()->table('usertokens')->whereNull('parentToken'));
        $isNotNull = $this->sql($this->postgres()->table('usertokens')->whereNotNull('parentToken'));

        // Assert
        $this->assertStringContainsString('"parentToken" IS NULL', $isNull);
        $this->assertStringContainsString('"parentToken" IS NOT NULL', $isNotNull);
    }

    /** And in an IN clause. */
    public function testAnInClauseQuotesItAsWell(): void
    {
        // Act
        $sql = $this->sql(
            $this->postgres()->table('usertokens')->whereIn('parentToken', [1, 2, 3])
        );

        // Assert
        $this->assertStringContainsString('"parentToken" IN', $sql);
    }
}
