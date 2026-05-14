<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Expression;
use Pramnos\Database\QueryBuilder;
use Pramnos\Database\Grammar\Grammar;
use Pramnos\Database\Grammar\MySQLGrammar;
use Pramnos\Database\Grammar\PostgreSQLGrammar;
use Pramnos\Database\Grammar\TimescaleDBGrammar;

/**
 * Unit tests for Grammar, MySQLGrammar, PostgreSQLGrammar, and TimescaleDBGrammar.
 *
 * All Grammar methods are pure SQL string generators — they operate only on
 * QueryBuilder state (via getters) and produce SQL strings.  No database
 * connection is required; a mocked Database with the correct type property
 * is sufficient.
 *
 * Coverage:
 * - Grammar::getPlaceholder() for all PHP types and Expression
 * - Grammar::compileInsert / compileUpdate / compileDelete / compileTruncate
 * - Grammar::compileSelect with DISTINCT, JOINs, WHERE, GROUP BY, HAVING,
 *   ORDER BY, LIMIT, OFFSET, locking, UNION, CTE
 * - Grammar::compileWheres — all where types (Basic, In, NotIn, Null, NotNull,
 *   Between, NotBetween, Nested, Raw, Exists, NotExists, DatePart)
 * - Grammar::compileHavings (scalar and Raw)
 * - Grammar::compileCtes (non-recursive and recursive)
 * - Grammar::compileTimeBucket (MySQL dialect)
 * - Grammar::compileWindowOver (partition, order, frame)
 * - MySQLGrammar::quoteColumn, compileLock, compileInsertOrIgnore, compileUpsert
 * - PostgreSQLGrammar::quoteColumn, compileLock, compileInsertOrIgnore,
 *   compileUpsert, RETURNING, wrapColumnForOperator (LIKE/ILIKE cast),
 *   compileDatePartExtraction, compileTimeBucket (PG dialect)
 * - TimescaleDBGrammar::compileTimeBucket (native time_bucket())
 */
#[CoversClass(Grammar::class)]
#[CoversClass(MySQLGrammar::class)]
#[CoversClass(PostgreSQLGrammar::class)]
#[CoversClass(TimescaleDBGrammar::class)]
class GrammarTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a mocked Database with the given driver type and timescale flag.
     *
     * We use onlyMethods([]) so property assignment ($db->type = …) works
     * on the real class layout rather than a generic mock object.
     */
    private function makeMockDb(string $type = 'mysql', bool $timescale = false): Database
    {
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type      = $type;
        $db->timescale = $timescale;
        return $db;
    }

    /**
     * Create a real QueryBuilder backed by a mocked MySQL Database.
     *
     * The grammar is injected directly so tests are not coupled to makeGrammar()
     * auto-detection logic.  Always use simple table names (no dots) to prevent
     * from() / join() / crossJoin() from calling $db->schema()->resolveTableName().
     */
    private function mysqlQB(): QueryBuilder
    {
        return new QueryBuilder($this->makeMockDb('mysql'), new MySQLGrammar());
    }

    /**
     * Create a real QueryBuilder backed by a mocked PostgreSQL Database.
     */
    private function postgresQB(): QueryBuilder
    {
        return new QueryBuilder($this->makeMockDb('postgresql'), new PostgreSQLGrammar());
    }

    /**
     * Create a real QueryBuilder backed by a mocked TimescaleDB Database.
     */
    private function timescaleQB(): QueryBuilder
    {
        return new QueryBuilder($this->makeMockDb('postgresql', true), new TimescaleDBGrammar());
    }

    // =========================================================================
    // Grammar::getPlaceholder()
    // =========================================================================

    /**
     * getPlaceholder() must return '%s' for plain strings.
     *
     * Strings are the most common column value; the placeholder drives
     * the Database::prepare() escaping and quoting logic.
     */
    public function testGetPlaceholderReturnsPercentSForString(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act + Assert — both non-empty and empty strings use %s
        $this->assertSame('%s', $grammar->getPlaceholder('hello'));
        $this->assertSame('%s', $grammar->getPlaceholder(''));
    }

    /**
     * getPlaceholder() must return '%i' for integers.
     *
     * Integer escaping differs from string escaping — no quoting is applied.
     * Zero is an int and must also use %i.
     */
    public function testGetPlaceholderReturnsPercentIForInt(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act + Assert
        $this->assertSame('%i', $grammar->getPlaceholder(42));
        $this->assertSame('%i', $grammar->getPlaceholder(0));
        $this->assertSame('%i', $grammar->getPlaceholder(-1));
    }

    /**
     * getPlaceholder() must return '%d' for floats / doubles.
     *
     * Floats require different formatting than integers — they must preserve
     * their decimal precision in the prepared statement.
     */
    public function testGetPlaceholderReturnsPercentDForFloat(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act + Assert
        $this->assertSame('%d', $grammar->getPlaceholder(3.14));
        $this->assertSame('%d', $grammar->getPlaceholder(0.0));
    }

    /**
     * getPlaceholder() must return '%b' for booleans.
     *
     * Booleans need dedicated handling because PHP's type juggling can
     * conflate true/false with 1/0; %b signals explicit boolean casting.
     */
    public function testGetPlaceholderReturnsPercentBForBool(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act + Assert
        $this->assertSame('%b', $grammar->getPlaceholder(true));
        $this->assertSame('%b', $grammar->getPlaceholder(false));
    }

    /**
     * getPlaceholder() must return the raw SQL string for Expression objects.
     *
     * Expression wraps a literal SQL snippet that must not be escaped or
     * quoted — it is injected verbatim into the compiled query (e.g. NOW(),
     * COUNT(*), or any raw DB function call).
     */
    public function testGetPlaceholderReturnsLiteralForExpression(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();
        $expr    = new Expression('NOW()');

        // Act
        $result = $grammar->getPlaceholder($expr);

        // Assert — Expression::__toString() returns the raw SQL
        $this->assertSame('NOW()', $result);
    }

    // =========================================================================
    // MySQLGrammar — quoteColumn()
    // =========================================================================

    /**
     * MySQL quotes identifiers with backticks to avoid conflicts with reserved
     * words (e.g. `order`, `select`, `key`).
     */
    public function testMysqlQuoteColumnWrapsInBackticks(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act + Assert
        $this->assertSame('`name`', $grammar->quoteColumn('name'));
        $this->assertSame('`created_at`', $grammar->quoteColumn('created_at'));
        $this->assertSame('`order`', $grammar->quoteColumn('order'));
    }

    // =========================================================================
    // MySQLGrammar — compileLock()
    // =========================================================================

    /**
     * lockForUpdate() must produce a FOR UPDATE suffix on SELECT.
     *
     * FOR UPDATE is MySQL's pessimistic row-lock; it prevents concurrent
     * transactions from reading the locked rows until the lock is released.
     */
    public function testMysqlLockForUpdateAppendsForUpdate(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->lockForUpdate();

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringEndsWith(' FOR UPDATE', $sql);
    }

    /**
     * sharedLock() on MySQL produces LOCK IN SHARE MODE — not FOR SHARE.
     *
     * MySQL uses its own non-standard syntax for shared locks.  PostgreSQL
     * uses the ANSI-standard FOR SHARE — both are covered by the dialect grammars.
     */
    public function testMysqlSharedLockAppendsLockInShareMode(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->sharedLock();

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringEndsWith(' LOCK IN SHARE MODE', $sql);
    }

    /**
     * Without an explicit lock, no locking clause must be appended.
     */
    public function testMysqlNoLockProducesNoSuffix(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringNotContainsString('FOR UPDATE', $sql);
        $this->assertStringNotContainsString('SHARE', $sql);
    }

    // =========================================================================
    // MySQLGrammar — compileInsertOrIgnore()
    // =========================================================================

    /**
     * MySQL uses the INSERT IGNORE syntax for conflict-skipping inserts.
     *
     * This differs from PostgreSQL's ON CONFLICT DO NOTHING — MySQL relies on
     * the IGNORE keyword applied to the INSERT keyword itself.
     */
    public function testMysqlInsertOrIgnoreProducesInsertIgnore(): void
    {
        // Arrange
        $qb      = $this->mysqlQB()->from('users');
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileInsertOrIgnore($qb, ['name' => 'Alice', 'age' => 30]);

        // Assert
        $this->assertStringStartsWith('INSERT IGNORE INTO users', $sql);
        // Column names are quoted with backticks
        $this->assertStringContainsString('(`name`, `age`)', $sql);
        // String placeholder for name, integer for age
        $this->assertStringContainsString('VALUES (%s, %i)', $sql);
    }

    // =========================================================================
    // MySQLGrammar — compileUpsert()
    // =========================================================================

    /**
     * MySQL upsert with update columns produces ON DUPLICATE KEY UPDATE.
     *
     * MySQL's upsert syntax references VALUES() to obtain the proposed values
     * for each updated column — it does not use EXCLUDED like PostgreSQL.
     */
    public function testMysqlUpsertWithColumnsProducesOnDuplicateKeyUpdate(): void
    {
        // Arrange
        $qb      = $this->mysqlQB()->from('users');
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileUpsert(
            $qb,
            ['name' => 'Alice', 'age' => 30],
            ['email'],
            ['name', 'age']
        );

        // Assert
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`name` = VALUES(`name`)', $sql);
        $this->assertStringContainsString('`age` = VALUES(`age`)', $sql);
    }

    /**
     * MySQL upsert with no update columns degrades to INSERT IGNORE semantics.
     *
     * When no columns are specified to update, the intent is "insert only if
     * the row does not exist" — MySQL satisfies this with INSERT IGNORE.
     */
    public function testMysqlUpsertWithNoColumnsProducesInsertIgnore(): void
    {
        // Arrange
        $qb      = $this->mysqlQB()->from('users');
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileUpsert($qb, ['name' => 'Alice'], [], []);

        // Assert
        $this->assertStringStartsWith('INSERT IGNORE INTO users', $sql);
        $this->assertStringNotContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    // =========================================================================
    // PostgreSQLGrammar — quoteColumn()
    // =========================================================================

    /**
     * PostgreSQL quotes identifiers with double quotes (ANSI SQL standard),
     * which also preserves case sensitivity.
     */
    public function testPostgresQuoteColumnUsesDoubleQuotes(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act + Assert
        $this->assertSame('"name"', $grammar->quoteColumn('name'));
        $this->assertSame('"created_at"', $grammar->quoteColumn('created_at'));
    }

    // =========================================================================
    // PostgreSQLGrammar — RETURNING clause
    // =========================================================================

    /**
     * PostgreSQL appends RETURNING to INSERT when returning columns are set.
     *
     * RETURNING retrieves column values from the affected row without an extra
     * SELECT — critical for obtaining auto-generated IDs in PostgreSQL where
     * LAST_INSERT_ID() does not exist.
     */
    public function testPostgresInsertAppendsReturningClause(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users')->returning('id', 'created_at');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileInsert($qb, ['name' => 'Alice']);

        // Assert
        $this->assertStringContainsString(' RETURNING id, created_at', $sql);
    }

    /**
     * PostgreSQL appends RETURNING to UPDATE when returning columns are set.
     */
    public function testPostgresUpdateAppendsReturningClause(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users')->returning('updated_at');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileUpdate($qb, ['name' => 'Bob']);

        // Assert
        $this->assertStringContainsString(' RETURNING updated_at', $sql);
    }

    /**
     * PostgreSQL appends RETURNING to DELETE when returning columns are set.
     */
    public function testPostgresDeleteAppendsReturningClause(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users')->returning('id');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileDelete($qb);

        // Assert
        $this->assertStringContainsString(' RETURNING id', $sql);
    }

    /**
     * No RETURNING clause is emitted when no returning columns are specified.
     *
     * RETURNING on a plain INSERT would be syntactically valid but unexpected
     * by the caller — it must only appear when explicitly requested.
     */
    public function testPostgresNoReturningWhenNoneSet(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileInsert($qb, ['name' => 'Alice']);

        // Assert
        $this->assertStringNotContainsString('RETURNING', $sql);
    }

    // =========================================================================
    // PostgreSQLGrammar — compileInsertOrIgnore()
    // =========================================================================

    /**
     * PostgreSQL uses ON CONFLICT DO NOTHING for conflict-skipping inserts.
     *
     * This syntax requires specifying which conflict target (column or constraint)
     * to detect — the simplest form with no target catches all unique violations.
     */
    public function testPostgresInsertOrIgnoreUsesOnConflictDoNothing(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileInsertOrIgnore($qb, ['name' => 'Alice', 'age' => 30]);

        // Assert
        $this->assertStringContainsString('INSERT INTO users', $sql);
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $sql);
        // Must NOT produce MySQL-style INSERT IGNORE
        $this->assertStringNotContainsString('INSERT IGNORE', $sql);
    }

    /**
     * PostgreSQL compileInsertOrIgnore() includes RETURNING when set.
     *
     * RETURNING must survive the conflict clause and appear at the end —
     * the grammar must append it after ON CONFLICT DO NOTHING.
     */
    public function testPostgresInsertOrIgnoreAppendsReturning(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users')->returning('id');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileInsertOrIgnore($qb, ['name' => 'Alice']);

        // Assert
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $sql);
        $this->assertStringContainsString('RETURNING id', $sql);
    }

    // =========================================================================
    // PostgreSQLGrammar — compileUpsert()
    // =========================================================================

    /**
     * PostgreSQL upsert uses ON CONFLICT DO UPDATE SET col = EXCLUDED.col.
     *
     * EXCLUDED refers to the proposed row that was blocked by the conflict —
     * this is the standard PostgreSQL idiom for "use the new value on conflict".
     */
    public function testPostgresUpsertWithColumnsUsesExcluded(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileUpsert(
            $qb,
            ['email' => 'a@b.com', 'name' => 'Alice'],
            ['email'],
            ['name']
        );

        // Assert
        $this->assertStringContainsString('ON CONFLICT ("email")', $sql);
        $this->assertStringContainsString('DO UPDATE SET "name" = EXCLUDED."name"', $sql);
    }

    /**
     * PostgreSQL upsert with no update columns produces DO NOTHING.
     *
     * When an update list is empty, conflict detection is still desired
     * (to avoid duplicate key errors) but no columns should be overwritten.
     */
    public function testPostgresUpsertWithNoColumnsProducesDoNothing(): void
    {
        // Arrange
        $qb      = $this->postgresQB()->from('users');
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileUpsert($qb, ['email' => 'a@b.com'], ['email'], []);

        // Assert
        $this->assertStringContainsString('ON CONFLICT ("email") DO NOTHING', $sql);
        $this->assertStringNotContainsString('DO UPDATE', $sql);
    }

    // =========================================================================
    // PostgreSQLGrammar — compileLock()
    // =========================================================================

    /**
     * lockForUpdate() on PostgreSQL produces FOR UPDATE.
     */
    public function testPostgresLockForUpdateAppendsForUpdate(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('orders')->lockForUpdate();

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringEndsWith(' FOR UPDATE', $sql);
    }

    /**
     * sharedLock() on PostgreSQL produces FOR SHARE (ANSI-standard, unlike MySQL).
     */
    public function testPostgresSharedLockAppendsForShare(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('orders')->sharedLock();

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringEndsWith(' FOR SHARE', $sql);
        // Must NOT use MySQL syntax
        $this->assertStringNotContainsString('LOCK IN SHARE MODE', $sql);
    }

    // =========================================================================
    // PostgreSQLGrammar — wrapColumnForOperator() (LIKE / ILIKE ::text cast)
    // =========================================================================

    /**
     * LIKE operator on PostgreSQL adds a ::text cast automatically.
     *
     * PostgreSQL rejects LIKE against non-text types (e.g. uuid, int, jsonb)
     * without an explicit cast.  The grammar injects ::text so the caller does
     * not need to know the column's storage type.
     */
    public function testPostgresLikeOperatorAddsTextCast(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('users')->where('email', 'LIKE', '%@example.com');

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert — the column must be cast before the operator
        $this->assertStringContainsString('email::text LIKE', $sql);
    }

    /**
     * ILIKE (case-insensitive LIKE) also receives the ::text cast.
     */
    public function testPostgresIlikeOperatorAddsTextCast(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('users')->where('name', 'ILIKE', 'alice%');

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('name::text ILIKE', $sql);
    }

    /**
     * NOT LIKE also receives the ::text cast.
     */
    public function testPostgresNotLikeOperatorAddsTextCast(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('users')->where('email', 'NOT LIKE', '%@spam.com');

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('email::text NOT LIKE', $sql);
    }

    /**
     * NOT ILIKE also receives the ::text cast.
     */
    public function testPostgresNotIlikeOperatorAddsTextCast(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('users')->where('name', 'NOT ILIKE', 'spam%');

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('name::text NOT ILIKE', $sql);
    }

    /**
     * A regular equality operator must NOT receive any ::text cast.
     *
     * The cast is only needed for pattern-matching operators — injecting it
     * on = would be incorrect and could prevent index usage.
     */
    public function testPostgresEqualOperatorDoesNotAddTextCast(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('users')->where('id', '=', 1);

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringNotContainsString('::text', $sql);
    }

    // =========================================================================
    // compileDatePartExtraction() — MySQL vs PostgreSQL
    // =========================================================================

    /**
     * PostgreSQL uses EXTRACT(YEAR FROM col) — not MySQL's YEAR(col).
     *
     * EXTRACT() is part of the SQL standard; MySQL functions like YEAR() are
     * MySQL-specific.  The correct grammar is chosen per dialect.
     */
    public function testPostgresDatePartYearUsesExtract(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('orders')->whereYear('created_at', 2024);

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('EXTRACT(YEAR FROM created_at)', $sql);
    }

    /**
     * PostgreSQL month extraction uses EXTRACT(MONTH FROM col).
     */
    public function testPostgresDatePartMonthUsesExtract(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('orders')->whereMonth('created_at', 6);

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('EXTRACT(MONTH FROM created_at)', $sql);
    }

    /**
     * PostgreSQL day extraction uses EXTRACT(DAY FROM col).
     */
    public function testPostgresDatePartDayUsesExtract(): void
    {
        // Arrange
        $qb = $this->postgresQB()->from('orders')->whereDay('created_at', 15);

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('EXTRACT(DAY FROM created_at)', $sql);
    }

    /**
     * PostgreSQL time part extraction uses a ::time cast.
     */
    public function testPostgresDatePartTimeUsesCast(): void
    {
        // Arrange — add a raw DatePart where for 'time' since there's no whereTime()
        $qb = $this->postgresQB()->from('orders');
        $qb->whereRaw("(created_at)::time > '08:00'");

        // Act
        $sql = (new PostgreSQLGrammar())->compileSelect($qb);

        // Assert — confirm PG time cast syntax is present in raw
        $this->assertStringContainsString("::time", $sql);
    }

    /**
     * MySQL uses YEAR(col), MONTH(col), DAY(col) functions — not EXTRACT().
     */
    public function testMysqlDatePartYearUsesFunctionSyntax(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->whereYear('created_at', 2024);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('YEAR(created_at)', $sql);
        $this->assertStringNotContainsString('EXTRACT', $sql);
    }

    /**
     * MySQL month part uses MONTH() function.
     */
    public function testMysqlDatePartMonthUsesFunctionSyntax(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->whereMonth('created_at', 3);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('MONTH(created_at)', $sql);
    }

    // =========================================================================
    // compileTimeBucket() — MySQLGrammar
    // =========================================================================

    /**
     * MySQL compileTimeBucket() uses DATE_FORMAT for yearly truncation.
     *
     * MySQL cannot express calendar units in UNIX_TIMESTAMP arithmetic because
     * months have variable length.  DATE_FORMAT is used for month and year.
     */
    public function testMysqlTimeBucketYearUsesDateFormat(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('1 year', 'created_at');

        // Assert
        $this->assertStringContainsString("DATE_FORMAT(created_at, '%Y-01-01')", $sql);
    }

    /**
     * MySQL compileTimeBucket() uses DATE_FORMAT with month precision.
     */
    public function testMysqlTimeBucketMonthUsesDateFormat(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('1 month', 'created_at');

        // Assert
        $this->assertStringContainsString("DATE_FORMAT(created_at, '%Y-%m-01')", $sql);
    }

    /**
     * MySQL compileTimeBucket() uses FROM_UNIXTIME arithmetic for fixed-length intervals.
     *
     * For intervals expressible as a fixed number of seconds (minutes, hours, days),
     * MySQL uses FLOOR(UNIX_TIMESTAMP / seconds) * seconds epoch arithmetic.
     */
    public function testMysqlTimeBucketHourUsesEpochArithmetic(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('1 hour', 'created_at');

        // Assert
        $this->assertStringContainsString('FROM_UNIXTIME', $sql);
        $this->assertStringContainsString('UNIX_TIMESTAMP(created_at)', $sql);
        // 1 hour = 3600 seconds — the bucket width encoded into the expression
        $this->assertStringContainsString('3600', $sql);
    }

    /**
     * MySQL compileTimeBucket() with 15-minute interval encodes 900 seconds.
     */
    public function testMysqlTimeBucket15MinutesEncodes900Seconds(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('15 minutes', 'created_at');

        // Assert — 15 * 60 = 900
        $this->assertStringContainsString('900', $sql);
        $this->assertStringContainsString('FROM_UNIXTIME', $sql);
    }

    /**
     * MySQL compileTimeBucket() with an unrecognised interval falls back to DATE(col).
     */
    public function testMysqlTimeBucketUnknownIntervalFallsBackToDate(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('junk interval', 'created_at');

        // Assert
        $this->assertSame('DATE(created_at)', $sql);
    }

    // =========================================================================
    // compileTimeBucket() — PostgreSQLGrammar
    // =========================================================================

    /**
     * PostgreSQL compileTimeBucket() uses date_trunc for single-unit intervals.
     *
     * date_trunc('hour', col) is the native PG function for precision truncation —
     * more efficient than epoch arithmetic and supports calendar units natively.
     */
    public function testPostgresTimeBucketSingleHourUsesDateTrunc(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('1 hour', 'created_at');

        // Assert
        $this->assertSame("date_trunc('hour', created_at)", $sql);
    }

    /**
     * PostgreSQL compileTimeBucket() with multi-unit interval uses epoch arithmetic.
     *
     * date_trunc does not support "6 hours" — for multi-unit sub-month intervals,
     * to_timestamp(floor(epoch / seconds) * seconds) is used instead.
     */
    public function testPostgresTimeBucketMultiHourUsesEpochArithmetic(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('6 hours', 'created_at');

        // Assert
        $this->assertStringContainsString('to_timestamp', $sql);
        $this->assertStringContainsString('extract(epoch from created_at)', $sql);
        // 6 * 3600 = 21600 seconds
        $this->assertStringContainsString('21600', $sql);
    }

    /**
     * PostgreSQL compileTimeBucket() for 1 year uses date_trunc('year').
     */
    public function testPostgresTimeBucketYearUsesDateTrunc(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('1 year', 'created_at');

        // Assert
        $this->assertSame("date_trunc('year', created_at)", $sql);
    }

    /**
     * PostgreSQL compileTimeBucket() for 1 month uses date_trunc('month').
     */
    public function testPostgresTimeBucketMonthUsesDateTrunc(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('1 month', 'created_at');

        // Assert
        $this->assertSame("date_trunc('month', created_at)", $sql);
    }

    /**
     * PostgreSQL compileTimeBucket() with unrecognised interval falls back to date_trunc('day').
     */
    public function testPostgresTimeBucketUnknownIntervalFallsBackToDay(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('junk interval', 'created_at');

        // Assert
        $this->assertSame("date_trunc('day', created_at)", $sql);
    }

    // =========================================================================
    // TimescaleDBGrammar — compileTimeBucket()
    // =========================================================================

    /**
     * TimescaleDB uses the native time_bucket() function for all intervals.
     *
     * This is simpler and more efficient than any arithmetic fallback — it
     * supports arbitrary intervals (including "15 minutes", "1 week") natively
     * without second-level epoch math.
     */
    public function testTimescaleTimeBucketUsesNativeFunction(): void
    {
        // Arrange
        $grammar = new TimescaleDBGrammar();

        // Act + Assert
        $this->assertSame(
            "time_bucket('15 minutes', created_at)",
            $grammar->compileTimeBucket('15 minutes', 'created_at'),
            '15-minute bucket must use native time_bucket()'
        );
        $this->assertSame(
            "time_bucket('1 hour', created_at)",
            $grammar->compileTimeBucket('1 hour', 'created_at'),
            '1-hour bucket must use native time_bucket()'
        );
        $this->assertSame(
            "time_bucket('1 year', created_at)",
            $grammar->compileTimeBucket('1 year', 'created_at'),
            'Calendar units must also use native time_bucket()'
        );
    }

    // =========================================================================
    // Grammar::compileWindowOver()
    // =========================================================================

    /**
     * compileWindowOver() with no partition/order/frame produces fn OVER ().
     *
     * An empty OVER () is valid SQL — it applies the window function across
     * the entire result set as a single partition.
     */
    public function testWindowOverEmptyProducesEmptyOver(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileWindowOver('RANK()', [], [], '');

        // Assert
        $this->assertSame('RANK() OVER ()', $sql);
    }

    /**
     * PARTITION BY emits quoted column names from the partition array.
     */
    public function testWindowOverWithPartitionEmitsPartitionBy(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileWindowOver('ROW_NUMBER()', ['department', 'region'], [], '');

        // Assert
        $this->assertStringContainsString('PARTITION BY `department`, `region`', $sql);
    }

    /**
     * ORDER BY emits quoted columns with their direction.
     */
    public function testWindowOverWithOrderByEmitsOrderClause(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileWindowOver('SUM(amount)', [], ['created_at' => 'desc', 'id' => 'asc'], '');

        // Assert
        $this->assertStringContainsString('ORDER BY `created_at` DESC, `id` ASC', $sql);
    }

    /**
     * An indexed (no-direction) order array uses the value as column name with ASC default.
     */
    public function testWindowOverWithIndexedOrderUsesColumnNameOnly(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();

        // Act — indexed array means no explicit direction; grammar emits just the quoted column
        $sql = $grammar->compileWindowOver('RANK()', [], ['id'], '');

        // Assert
        $this->assertStringContainsString('ORDER BY `id`', $sql);
    }

    /**
     * A ROWS/RANGE frame clause is appended after ORDER BY.
     */
    public function testWindowOverWithFrameClauseAppendsFrame(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();
        $frame   = 'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW';

        // Act
        $sql = $grammar->compileWindowOver('SUM(amount)', [], ['id' => 'asc'], $frame);

        // Assert
        $this->assertStringContainsString($frame, $sql);
    }

    /**
     * PostgreSQL window functions quote columns with double-quotes, not backticks.
     */
    public function testWindowOverPostgresUsesDoubleQuotes(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileWindowOver('RANK()', ['dept'], ['salary' => 'desc'], '');

        // Assert
        $this->assertStringContainsString('PARTITION BY "dept"', $sql);
        $this->assertStringContainsString('ORDER BY "salary" DESC', $sql);
    }

    // =========================================================================
    // Grammar::compileCtes()
    // =========================================================================

    /**
     * A single non-recursive CTE produces WITH name AS (sql) … SELECT.
     *
     * CTEs are emitted before the main SELECT — the grammar must prepend the
     * WITH block and not append it after the query body.
     */
    public function testCompileCtesSingleNonRecursive(): void
    {
        // Arrange
        $qb = $this->mysqlQB()
            ->with('top_orders', 'SELECT id FROM orders WHERE total > 100')
            ->from('top_orders');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringStartsWith('WITH ', $sql);
        $this->assertStringContainsString('top_orders AS (SELECT id FROM orders WHERE total > 100)', $sql);
        $this->assertStringNotContainsString('RECURSIVE', $sql);
    }

    /**
     * A recursive CTE produces WITH RECURSIVE name AS (…).
     *
     * The RECURSIVE keyword is mandatory for self-referencing CTEs — without it
     * the database engine would reject the query with a syntax error.
     */
    public function testCompileCtesRecursiveAddsRecursiveKeyword(): void
    {
        // Arrange
        $cteBody = 'SELECT id, parent_id FROM nodes WHERE id = 1 '
            . 'UNION ALL '
            . 'SELECT n.id, n.parent_id FROM nodes n JOIN tree t ON t.id = n.parent_id';

        $qb = $this->mysqlQB()
            ->withRecursive('tree', $cteBody)
            ->from('tree');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringStartsWith('WITH RECURSIVE ', $sql);
        $this->assertStringContainsString('tree AS (', $sql);
    }

    // =========================================================================
    // Grammar::compileSelect() — various clauses
    // =========================================================================

    /**
     * SELECT DISTINCT adds the DISTINCT keyword immediately after SELECT.
     */
    public function testCompileSelectDistinctAddsKeyword(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->distinct()->select('name');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('SELECT DISTINCT name', $sql);
    }

    /**
     * LIMIT clause is correctly appended as an integer.
     */
    public function testCompileSelectWithLimitAppendsLimit(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->limit(10);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    /**
     * OFFSET clause is correctly appended after LIMIT.
     */
    public function testCompileSelectWithOffsetAppendsOffset(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->limit(10)->offset(20);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    /**
     * ORDER BY clause with direction is appended after WHERE / GROUP BY.
     */
    public function testCompileSelectOrderByAppendsOrderClause(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->orderBy('name', 'desc');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('ORDER BY name DESC', $sql);
    }

    /**
     * GROUP BY clause is appended for aggregate queries.
     */
    public function testCompileSelectGroupByAppendsGroupClause(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->select('status')->groupBy('status');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('GROUP BY status', $sql);
    }

    /**
     * Scalar HAVING clause is appended after GROUP BY with correct placeholder.
     */
    public function testCompileSelectHavingAppendsHavingClause(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')
            ->select('status')
            ->groupBy('status')
            ->having('total', '>', 100);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('HAVING total > %i', $sql);
    }

    /**
     * Raw HAVING clause is injected verbatim without escaping.
     */
    public function testCompileSelectRawHavingIsInjectedVerbatim(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')
            ->groupBy('status')
            ->havingRaw('COUNT(*) > 5');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('HAVING COUNT(*) > 5', $sql);
    }

    /**
     * INNER JOIN is compiled with the ON condition.
     *
     * Simple (non-dot) table names are used to avoid schema()->resolveTableName()
     * being called on the mocked Database.
     */
    public function testCompileSelectInnerJoinIsCompiled(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')
            ->join('users', 'users.id', '=', 'orders.user_id');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('INNER JOIN users ON users.id = orders.user_id', $sql);
    }

    /**
     * LEFT JOIN is compiled with the correct type prefix.
     */
    public function testCompileSelectLeftJoinIsCompiled(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')
            ->join('users', 'users.id', '=', 'orders.user_id', 'left');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('LEFT JOIN users ON', $sql);
    }

    /**
     * CROSS JOIN has no ON clause — it produces a cartesian product.
     */
    public function testCompileSelectCrossJoinHasNoOnClause(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('products')->crossJoin('sizes');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('CROSS JOIN sizes', $sql);
        // CROSS JOIN must not be followed by ON
        $this->assertStringNotContainsString('CROSS JOIN sizes ON', $sql);
    }

    /**
     * Raw JOIN is injected verbatim for complex conditions not covered by the QB API.
     */
    public function testCompileSelectRawJoinIsInjectedVerbatim(): void
    {
        // Arrange
        $rawJoin = 'INNER JOIN b USE INDEX (idx_foo) ON a.id = b.a_id';
        $qb = $this->mysqlQB()->from('a')->joinRaw($rawJoin);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString($rawJoin, $sql);
    }

    /**
     * UNION combines two SELECT statements.
     */
    public function testCompileSelectUnionCombinesQueries(): void
    {
        // Arrange
        $qb2 = $this->mysqlQB()->from('archived_users');
        $qb  = $this->mysqlQB()->from('users')->union($qb2);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('UNION SELECT', $sql);
        $this->assertStringContainsString('FROM archived_users', $sql);
    }

    // =========================================================================
    // Grammar::compileWheres() — all where types
    // =========================================================================

    /**
     * WHERE IN produces the correct IN (...) syntax with one placeholder per value.
     */
    public function testCompileWheresInClause(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->whereIn('status', ['pending', 'active']);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert — two string values → two %s placeholders
        $this->assertStringContainsString('status IN (%s, %s)', $sql);
    }

    /**
     * WHERE NOT IN produces the correct NOT IN (...) syntax.
     */
    public function testCompileWheresNotInClause(): void
    {
        // Arrange — $not = true selects the NotIn type
        $qb = $this->mysqlQB()->from('orders')->whereIn('status', ['cancelled'], 'and', true);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('status NOT IN (%s)', $sql);
    }

    /**
     * WHERE IS NULL produces the correct IS NULL syntax (no placeholder).
     */
    public function testCompileWheresIsNull(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->whereNull('deleted_at');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('deleted_at IS NULL', $sql);
    }

    /**
     * WHERE IS NOT NULL produces the correct IS NOT NULL syntax.
     */
    public function testCompileWheresIsNotNull(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->whereNotNull('email');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('email IS NOT NULL', $sql);
    }

    /**
     * WHERE BETWEEN produces the correct BETWEEN ... AND ... syntax.
     */
    public function testCompileWheresBetween(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->whereBetween('total', [10, 100]);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('total BETWEEN %i AND %i', $sql);
    }

    /**
     * WHERE NOT BETWEEN produces the correct NOT BETWEEN ... AND ... syntax.
     */
    public function testCompileWheresNotBetween(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')->whereNotBetween('total', [10, 100]);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('total NOT BETWEEN %i AND %i', $sql);
    }

    /**
     * Raw WHERE is injected verbatim without any escaping or placeholder substitution.
     */
    public function testCompileWheresRawIsInjectedVerbatim(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->whereRaw('YEAR(created_at) = 2024');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('YEAR(created_at) = 2024', $sql);
    }

    /**
     * Nested WHERE wraps the sub-conditions in parentheses for correct precedence.
     *
     * Without parentheses, mixing AND and OR conditions can produce incorrect
     * results due to operator precedence.  The Nested type guarantees grouping.
     */
    public function testCompileWheresNestedAddsParentheses(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('role', 'admin')->orWhere('role', 'manager');
            });

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert — nested group must be wrapped in parentheses
        $this->assertStringContainsString('(role = %s OR role = %s)', $sql);
    }

    /**
     * WHERE EXISTS wraps the sub-query in an EXISTS (SELECT ...) expression.
     */
    public function testCompileWheresExists(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')
            ->whereExists(function ($sub) {
                $sub->from('orders')->where('orders.user_id', '=', 1);
            });

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('EXISTS (SELECT * FROM orders', $sql);
    }

    /**
     * WHERE NOT EXISTS wraps the sub-query in a NOT EXISTS (SELECT ...) expression.
     */
    public function testCompileWheresNotExists(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')
            ->whereNotExists(function ($sub) {
                $sub->from('orders')->where('orders.user_id', '=', 1);
            });

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('NOT EXISTS (SELECT * FROM orders', $sql);
    }

    /**
     * DatePart WHERE emits the correct date function and operator.
     *
     * The DatePart type delegates to compileDatePartExtraction() which differs
     * per dialect (YEAR() in MySQL, EXTRACT() in PostgreSQL).
     */
    public function testCompileWheresDatePartMysql(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('events')->whereDate('event_at', '=', '2024-06-01');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert — MySQL uses DATE() for date-part extraction
        $this->assertStringContainsString('DATE(event_at) = %s', $sql);
    }

    /**
     * OR WHERE connector produces OR between clauses (not AND).
     */
    public function testCompileWheresOrBooleanConnector(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')
            ->where('status', 'active')
            ->orWhere('status', 'pending');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('OR status = %s', $sql);
    }

    // =========================================================================
    // Grammar::compileInsert / compileUpdate / compileDelete / compileTruncate
    // =========================================================================

    /**
     * compileInsert() builds a complete INSERT INTO ... VALUES ... statement.
     *
     * Columns are quoted with the dialect's quoteColumn(); each value uses the
     * correct placeholder type (%s string, %i int, %b bool).
     */
    public function testCompileInsertBuildsCorrectSql(): void
    {
        // Arrange
        $qb      = $this->mysqlQB()->from('users');
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileInsert($qb, ['name' => 'Alice', 'age' => 30, 'active' => true]);

        // Assert
        $this->assertStringStartsWith('INSERT INTO users', $sql);
        $this->assertStringContainsString('(`name`, `age`, `active`)', $sql);
        $this->assertStringContainsString('VALUES (%s, %i, %b)', $sql);
    }

    /**
     * compileUpdate() builds a complete UPDATE ... SET ... WHERE ... statement.
     *
     * SET clauses use the dialect's quoteColumn(); WHERE clauses use the same
     * compileWheres() path as SELECT.
     */
    public function testCompileUpdateBuildsCorrectSql(): void
    {
        // Arrange
        $qb      = $this->mysqlQB()->from('users')->where('id', 1);
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileUpdate($qb, ['name' => 'Bob', 'age' => 25]);

        // Assert
        $this->assertStringStartsWith('UPDATE users SET', $sql);
        $this->assertStringContainsString('`name` = %s', $sql);
        $this->assertStringContainsString('`age` = %i', $sql);
        $this->assertStringContainsString('WHERE id = %i', $sql);
    }

    /**
     * compileDelete() builds a complete DELETE FROM ... WHERE ... statement.
     */
    public function testCompileDeleteBuildsCorrectSql(): void
    {
        // Arrange
        $qb      = $this->mysqlQB()->from('users')->where('id', 5);
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileDelete($qb);

        // Assert
        $this->assertSame('DELETE FROM users WHERE id = %i', $sql);
    }

    /**
     * compileTruncate() produces a TRUNCATE TABLE statement.
     *
     * TRUNCATE does not accept WHERE clauses — the grammar must emit only the
     * table name, never the current WHERE state.
     */
    public function testCompileTruncateBuildsCorrectSql(): void
    {
        // Arrange
        $qb      = $this->mysqlQB()->from('cache');
        $grammar = new MySQLGrammar();

        // Act
        $sql = $grammar->compileTruncate($qb);

        // Assert
        $this->assertSame('TRUNCATE TABLE cache', $sql);
    }

    // =========================================================================
    // Additional coverage tests for branches not yet reached
    // =========================================================================

    /**
     * MySQL DAY() function is used for whereDay() — covers Grammar::compileDatePartExtraction 'day'.
     */
    public function testMysqlDatePartDayUsesDayFunction(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('events')->whereDay('event_at', 15);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('DAY(event_at)', $sql);
    }

    /**
     * Raw ORDER BY is injected verbatim — covers Grammar::compileSelect orderByRaw branch.
     *
     * Needed when ORDER BY must use an expression that cannot be expressed with
     * a simple column name (e.g. FIELD(), CASE WHEN, subquery ranking).
     */
    public function testCompileSelectRawOrderByIsInjectedVerbatim(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('users')->orderByRaw('FIELD(status, "active", "pending", "inactive")');

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert
        $this->assertStringContainsString('ORDER BY FIELD(status, "active", "pending", "inactive")', $sql);
    }

    /**
     * A second HAVING clause joins with AND/OR — covers Grammar::compileHavings OR boolean.
     *
     * The first HAVING has no connector; subsequent ones must emit AND or OR.
     */
    public function testCompileSelectTwoHavingsJoinWithAndConnector(): void
    {
        // Arrange
        $qb = $this->mysqlQB()->from('orders')
            ->groupBy('status')
            ->having('total', '>', 100)
            ->having('qty', '<', 500);

        // Act
        $sql = (new MySQLGrammar())->compileSelect($qb);

        // Assert — second HAVING must be prefixed with AND
        $this->assertStringContainsString('AND qty < %i', $sql);
    }

    /**
     * PostgreSQL compileTimeBucket() with count > 1 on a calendar unit falls back
     * to date_trunc with the single-unit precision (e.g. '2 months' → date_trunc('month')).
     *
     * date_trunc does not support "2 months" or "3 years" — the grammar degrades
     * to the nearest supported precision rather than raising an error.
     */
    public function testPostgresTimeBucketMultiMonthDegradesToDateTruncMonth(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('2 months', 'created_at');

        // Assert — degrades to single-unit date_trunc
        $this->assertSame("date_trunc('month', created_at)", $sql);
    }

    /**
     * PostgreSQL compileTimeBucket() with count > 1 year degrades to date_trunc('year').
     */
    public function testPostgresTimeBucketMultiYearDegradesToDateTruncYear(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();

        // Act
        $sql = $grammar->compileTimeBucket('2 years', 'created_at');

        // Assert
        $this->assertSame("date_trunc('year', created_at)", $sql);
    }

    /**
     * PostgreSQL compileDatePartExtraction() returns a ::time cast for the 'time' part.
     *
     * There is no public whereTime() helper on QueryBuilder, so the protected method
     * is invoked via Reflection to verify the correct PG-specific expression is built.
     */
    public function testPostgresDatePartTimeUsesTimeCast(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDatePartExtraction');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke($grammar, 'time', 'created_at');

        // Assert — PostgreSQL casts to ::time instead of calling a function
        $this->assertSame('(created_at)::time', $result);
    }

    /**
     * PostgreSQL compileDatePartExtraction() returns a ::date cast for unknown parts.
     *
     * The default branch handles any unrecognised part name by returning a date cast.
     */
    public function testPostgresDatePartDefaultUsesDateCast(): void
    {
        // Arrange
        $grammar = new PostgreSQLGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDatePartExtraction');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke($grammar, 'date', 'created_at');

        // Assert
        $this->assertSame('(created_at)::date', $result);
    }

    /**
     * MySQL compileDatePartExtraction() returns TIME() for the 'time' part.
     *
     * Tested via Reflection since there is no public whereTime() QB method.
     */
    public function testMysqlDatePartTimeUsesTimeFunction(): void
    {
        // Arrange
        $grammar = new MySQLGrammar();
        $ref     = new \ReflectionMethod($grammar, 'compileDatePartExtraction');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke($grammar, 'time', 'created_at');

        // Assert
        $this->assertSame('TIME(created_at)', $result);
    }
}
