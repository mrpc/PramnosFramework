<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * Bindings in a raw fragment, and where they land.
 *
 * Reported from a consuming application, and the cost was in the failure mode
 * rather than the failure: a query mixing `where()` with a `whereRaw()` carrying
 * `?` placeholders returned **`false`** from `first()` — no exception, nothing in
 * the log — and the only symptom was
 * `Attempt to read property "fields" on false` pointing at the *consumer*, several
 * lines from the cause. They rewrote the query as a prepared statement rather than
 * spend longer on it.
 *
 * The cause is that this builder does not use `?`. It emits the framework's own
 * typed placeholders (`%s`, `%i`, `%d`, `%b`), which `Database::prepare()`
 * substitutes positionally. A raw fragment was emitted **verbatim**, so a `?` in it
 * stayed a literal `?` in the SQL while its value was appended to the binding
 * list — one more value than the statement had placeholders, and every value after
 * the fragment shifted by one.
 *
 * So `?` is now translated to the placeholder each binding's type calls for, at the
 * position the `?` occupied. And a fragment whose placeholder count does not match
 * its bindings throws where the mistake is — at build time, in the caller's own
 * file — instead of turning into a false three layers away.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderRawBindingTest extends TestCase
{
    /**
     * A builder with a mocked connection: nothing here executes SQL.
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
     * The reported query: two `where()` calls and a raw subquery with one `?`.
     *
     * Every placeholder must appear in clause order, and there must be exactly as
     * many as there are bindings — that pairing is the whole bug.
     */
    public function testARawFragmentsPlaceholderIsEmittedWhereItWasWritten(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('chat_messages')
            ->where('created_at', '>=', '2026-01-01')
            ->where('created_at', '<=', '2026-02-01')
            ->whereRaw('channel_id IN (SELECT id FROM channels WHERE station_id = ?)', [42]);

        $sql      = $qb->toSql();
        $bindings = $qb->getBindings();

        // Assert — the raw `?` became a typed placeholder in its own position...
        $this->assertStringContainsString('station_id = %i', $sql);
        $this->assertStringNotContainsString('?', $sql, 'a literal ? cannot be bound to anything');
        // ...and the three values line up with the three placeholders, in order
        $this->assertSame(['2026-01-01', '2026-02-01', 42], $bindings);
        $this->assertSame(3, $this->countPlaceholders($sql));
    }

    /**
     * Each `?` takes the placeholder its own binding's type needs.
     *
     * The typed placeholders are not decoration: `%i` and `%s` quote and cast
     * differently, and a string bound through `%i` becomes 0 rather than an error.
     */
    public function testEachPlaceholderFollowsItsBindingsType(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')
            ->whereRaw('a = ? AND b = ? AND c = ? AND d = ?', [7, 'x', 1.5, true]);

        // Assert
        $this->assertStringContainsString('a = %i AND b = %s AND c = %d AND d = %b', $qb->toSql());
        $this->assertSame([7, 'x', 1.5, true], $qb->getBindings());
    }

    /**
     * A raw fragment written the framework's own way is left exactly as it is.
     *
     * Existing code does this — `whereRaw('status = %i', [1])` — and it worked,
     * so it has to keep working byte for byte.
     */
    public function testAFragmentAlreadyUsingFrameworkPlaceholdersIsUntouched(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')->whereRaw('status = %i AND name = %s', [1, 'a']);

        // Assert
        $this->assertStringContainsString('status = %i AND name = %s', $qb->toSql());
        $this->assertSame([1, 'a'], $qb->getBindings());
    }

    /**
     * A fragment with no bindings is left alone, `?` and all.
     *
     * `whereRaw('enabled = TRUE')` and `whereRaw('(next_run IS NULL OR next_run <=
     * NOW())')` are used across the framework itself. And a `?` with no bindings is
     * not a placeholder the builder can fill — it may be a JSON operator, a
     * literal, or the caller's mistake, and rewriting it would be a guess.
     */
    public function testAFragmentWithoutBindingsIsNotRewritten(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')->whereRaw('enabled = TRUE');

        // Assert
        $this->assertStringContainsString('enabled = TRUE', $qb->toSql());
        $this->assertSame([], $qb->getBindings());
    }

    /**
     * More bindings than placeholders is refused, at the call.
     *
     * This is the loud half of the fix. The alternative is what happened: the
     * extra value shifts every later binding, the statement fails, `first()`
     * returns false, and the error surfaces as a property read on false in code
     * that did nothing wrong.
     */
    public function testTooManyBindingsThrowsWhereTheMistakeIs(): void
    {
        // Arrange
        $qb = $this->makeQB()->from('t');

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2 binding(s)');
        $qb->whereRaw('a = ?', [1, 2]);
    }

    /**
     * Fewer bindings than placeholders is refused too.
     */
    public function testTooFewBindingsThrows(): void
    {
        // Arrange
        $qb = $this->makeQB()->from('t');

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('placeholder');
        $qb->whereRaw('a = ? AND b = ?', [1]);
    }

    /**
     * `orWhereRaw()` behaves the same way, because it is the same method.
     *
     * Named in the report as worth checking, and it was: it forwards to
     * `whereRaw()`, so the fix reaches it — but that is worth an assertion rather
     * than an assumption.
     */
    public function testOrWhereRawTranslatesItsPlaceholdersToo(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')
            ->where('a', 1)
            ->orWhereRaw('b = ?', ['x']);

        // Assert
        $this->assertStringContainsString('OR b = %s', $qb->toSql());
        $this->assertSame([1, 'x'], $qb->getBindings());
    }

    /**
     * `havingRaw()` shares the behaviour, and its bindings share the ordering.
     *
     * Also named in the report. A HAVING is compiled after the WHERE, and its
     * bindings are a separate bucket merged in that order — so a `?` there was
     * wrong in exactly the same way.
     */
    public function testHavingRawTranslatesItsPlaceholders(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')
            ->where('a', 1)
            ->groupBy('b')
            ->havingRaw('SUM(c) > ?', [10]);

        // Assert
        $sql = $qb->toSql();
        $this->assertStringContainsString('SUM(c) > %i', $sql);
        $this->assertStringNotContainsString('?', $sql);
        // WHERE's binding first, HAVING's second — the order the SQL reads in
        $this->assertSame([1, 10], $qb->getBindings());
    }

    /**
     * A `?` inside a quoted string is not a placeholder.
     *
     * `WHERE note LIKE '%?%'` and `WHERE label = 'why?'` are legal SQL that means
     * what it says. Rewriting the `?` inside the literal would change the query,
     * which is worse than the bug this fix is for.
     */
    public function testAQuestionMarkInsideAStringLiteralIsLeftAlone(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')
            ->whereRaw("label = 'why?' AND owner = ?", ['me']);

        // Assert
        $sql = $qb->toSql();
        $this->assertStringContainsString("label = 'why?'", $sql);
        $this->assertStringContainsString('owner = %s', $sql);
        $this->assertSame(['me'], $qb->getBindings());
    }

    /**
     * A null binding becomes a string placeholder rather than breaking the count.
     *
     * `%s` with null writes NULL through the driver's own escaping, which is what
     * the typed placeholders do for `where('x', null)` as well.
     */
    public function testANullBindingStillGetsAPlaceholder(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')->whereRaw('a = ?', [null]);

        // Assert
        $this->assertStringContainsString('a = %s', $qb->toSql());
        $this->assertSame([null], $qb->getBindings());
    }

    /**
     * An escaped quote does not end the literal it is inside.
     *
     * `'it''s'` is one string containing an apostrophe. Reading the doubled quote
     * as a close and a re-open would put the rest of the fragment in the wrong
     * state, and a `?` after it would be rewritten inside a string — or not
     * rewritten at all, which is the bug this whole fix is about.
     */
    public function testADoubledQuoteInsideALiteralIsNotAClose(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')
            ->whereRaw("label = 'it''s a ?' AND owner = ?", ['me']);

        // Assert — the `?` inside the literal survives, the one outside is bound
        $sql = $qb->toSql();
        $this->assertStringContainsString("label = 'it''s a ?'", $sql);
        $this->assertStringContainsString('owner = %s', $sql);
        $this->assertSame(['me'], $qb->getBindings());
    }

    /**
     * `orHavingRaw()` exists and joins with OR.
     *
     * Added alongside `orWhereRaw()` for the same reason: every other having
     * clause has an `or` form, and passing `'or'` as a third positional argument
     * reads as an internal detail because it is one.
     */
    public function testOrHavingRawJoinsWithOr(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')
            ->groupBy('b')
            ->havingRaw('SUM(c) > ?', [1])
            ->orHavingRaw('MIN(c) < ?', [0]);

        // Assert
        $sql = $qb->toSql();
        $this->assertStringContainsString('SUM(c) > %i OR MIN(c) < %i', $sql);
        $this->assertSame([1, 0], $qb->getBindings());
    }

    /**
     * An empty raw fragment adds nothing, whatever else is passed with it.
     *
     * The guard predates this change and has to keep working: a caller building a
     * fragment conditionally ends up passing `''` and expects a no-op rather than
     * an exception about placeholder counts.
     */
    public function testAnEmptyFragmentIsStillANoOp(): void
    {
        // Arrange & Act
        $qb = $this->makeQB()->from('t')->whereRaw('', ['ignored']);

        // Assert
        $this->assertStringNotContainsString('WHERE', $qb->toSql());
        $this->assertSame([], $qb->getBindings());
    }

    /**
     * How many framework placeholders a statement carries.
     *
     * `%%` is an escaped percent and not a placeholder, so it must not be counted —
     * the whole point of the assertion is that the count matches the bindings.
     *
     * @param  string $sql
     * @return int
     */
    private function countPlaceholders(string $sql): int
    {
        preg_match_all('/%[sidb]/', preg_replace('/%%/', '', $sql) ?? '', $matches);

        return count($matches[0]);
    }
}
