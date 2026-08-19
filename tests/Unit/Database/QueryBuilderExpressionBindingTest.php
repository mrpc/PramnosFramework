<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Expression;
use Pramnos\Database\QueryBuilder;

/**
 * A raw fragment is emitted, not bound.
 *
 * `Expression` exists to put SQL where a value would go: `getPlaceholder()` returns
 * the fragment itself instead of `%s`, so `where('lockoutuntil', '>', $qb->raw('NOW()'))`
 * compiles to `lockoutuntil > NOW()` — a statement with **no** placeholders. The
 * builder bound the Expression object anyway, so that statement was executed with one
 * value.
 *
 * Both drivers refuse it, which is worth stating because the first guess was that only
 * PostgreSQL would:
 *
 * ```
 * PostgreSQL: bind message supplies 1 parameters, but prepared statement "…" requires 0
 * MySQL:      mysqli_stmt::bind_param(): Argument #1 ($types) must not be empty
 * ```
 *
 * Found in the DevPanel's login-lockout panel, which was empty on every PostgreSQL
 * installation for this reason, with the exception swallowed into a "could not load"
 * line. The `insert()`, `update()` and `upsert()` paths had each filtered Expressions
 * out at their own call site; `where()`, `having()` and `whereBetween()` had not — so
 * the same fragment was safe in one clause and fatal in another. The filter now lives
 * in `addBinding()`, which is the one place every clause goes through.
 *
 * The invariant each test asserts is the same one: **a compiled statement has exactly
 * as many bindings as placeholders.**
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderExpressionBindingTest extends TestCase
{
    /**
     * A builder with a mocked connection: nothing here executes SQL.
     *
     * @param string $dbType Dialect to compile for
     * @return QueryBuilder
     */
    private function makeQB(string $dbType = 'mysql'): QueryBuilder
    {
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = $dbType;
        $db->prefix = '';

        return new QueryBuilder($db);
    }

    /**
     * Counts the framework's typed placeholders in a compiled statement.
     *
     * @param string $sql Compiled SQL
     * @return int
     */
    private function countPlaceholders(string $sql): int
    {
        preg_match_all('/%[sidb]/', preg_replace('/%%/', '', $sql) ?? '', $matches);

        return count($matches[0]);
    }

    /**
     * The reported query: a `where` comparing a column to `NOW()`.
     *
     * The exact shape that produced the PostgreSQL error, reduced to the two facts
     * that matter — the fragment reaches the SQL, and nothing is bound for it.
     *
     * @return void
     */
    public function testARawValueInWhereIsNotBound(): void
    {
        // Arrange + Act
        $qb = $this->makeQB()->from('loginlockouts')
            ->where('lockoutuntil', '>', new Expression('NOW()'));

        $sql = $qb->toSql();

        // Assert
        $this->assertStringContainsString('lockoutuntil > NOW()', $sql);
        $this->assertSame([], $qb->getBindings());
        $this->assertSame(0, $this->countPlaceholders($sql));
    }

    /**
     * A scalar next to a fragment still binds, and stays in its own position.
     *
     * The fix must not swing the other way: the surplus binding was the bug, a
     * missing one would be the same bug with the sign flipped, and a *shifted* one
     * silently answers the wrong question.
     *
     * @return void
     */
    public function testScalarsAroundAFragmentKeepTheirPositions(): void
    {
        // Arrange + Act
        $qb = $this->makeQB()->from('loginlockouts')
            ->where('locktype', 'ip')
            ->where('lockoutuntil', '>', new Expression('NOW()'))
            ->where('failedattempts', '>=', 3);

        $sql      = $qb->toSql();
        $bindings = $qb->getBindings();

        // Assert — two values, two placeholders, in the order they were written
        $this->assertSame(['ip', 3], $bindings);
        $this->assertSame(2, $this->countPlaceholders($sql));
        // The fragment sits between them, with no placeholder of its own
        $this->assertMatchesRegularExpression(
            '/locktype = %s.*lockoutuntil > NOW\(\).*failedattempts >= %i/s',
            $sql
        );
    }

    /**
     * `raw()` is the public way to build one, and behaves the same.
     *
     * @return void
     */
    public function testTheRawHelperProducesAnUnboundFragment(): void
    {
        // Arrange
        $qb = $this->makeQB();

        // Act
        $qb->from('t')->where('created', '>', $qb->raw('CURRENT_DATE - 7'));

        // Assert
        $this->assertSame([], $qb->getBindings());
        $this->assertSame(0, $this->countPlaceholders($qb->toSql()));
    }

    /**
     * `having()` binds through the same method, and had the same defect.
     *
     * @return void
     */
    public function testARawValueInHavingIsNotBound(): void
    {
        // Arrange + Act
        $qb = $this->makeQB()->from('orders')
            ->groupBy('customer')
            ->having('total', '>', new Expression('AVG(total)'));

        $sql = $qb->toSql();

        // Assert
        $this->assertStringContainsString('AVG(total)', $sql);
        $this->assertSame([], $qb->getBindings());
        $this->assertSame(0, $this->countPlaceholders($sql));
    }

    /**
     * `whereBetween()` binds both endpoints, and either one may be a fragment.
     *
     * A half-raw range — a literal lower bound and `NOW()` as the upper — is the
     * common case, and the one that would leave the bindings off by exactly one.
     *
     * @return void
     */
    public function testAHalfRawBetweenBindsOnlyItsScalarEndpoint(): void
    {
        // Arrange + Act
        $qb = $this->makeQB()->from('events')
            ->whereBetween('happened', ['2026-01-01', new Expression('NOW()')]);

        $sql = $qb->toSql();

        // Assert
        $this->assertSame(['2026-01-01'], $qb->getBindings());
        $this->assertSame(1, $this->countPlaceholders($sql));
        $this->assertStringContainsString('BETWEEN %s AND NOW()', $sql);
    }

    /**
     * `whereIn()` takes a list, and the filter has to reach inside it.
     *
     * The array branch of `addBinding()` is a separate path from the scalar one; a
     * fix applied to only one of them leaves half the clauses broken.
     *
     * @return void
     */
    public function testAFragmentInsideAWhereInListIsNotBound(): void
    {
        // Arrange + Act
        $qb = $this->makeQB()->from('t')
            ->whereIn('id', [1, new Expression('(SELECT MAX(id) FROM t)'), 3]);

        $sql = $qb->toSql();

        // Assert — two scalars bound, two placeholders, the subquery inlined
        $this->assertSame([1, 3], $qb->getBindings());
        $this->assertSame(2, $this->countPlaceholders($sql));
        $this->assertStringContainsString('(SELECT MAX(id) FROM t)', $sql);
    }

    /**
     * The same statement compiled for PostgreSQL, where the surplus was fatal.
     *
     * PostgreSQL is the dialect that counts parameters, so it is the one that has to
     * be asserted rather than assumed to follow MySQL.
     *
     * @return void
     */
    public function testTheInvariantHoldsOnPostgreSQL(): void
    {
        // Arrange + Act
        $qb = $this->makeQB('postgresql')->from('authserver.loginlockouts')
            ->select(['displayvalue'])
            ->where('lockoutuntil', '>', new Expression('NOW()'))
            ->orderBy('lockoutuntil', 'desc')
            ->limit(20);

        $sql = $qb->toSql();

        // Assert
        $this->assertSame([], $qb->getBindings());
        $this->assertSame(0, $this->countPlaceholders($sql));
    }
}
