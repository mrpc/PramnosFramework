<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * Unit tests for QueryBuilder SQL compilation — no database connection required.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderUnitTest extends TestCase
{
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

    private function setType(QueryBuilder $qb, string $type): void
    {
        $prop = new \ReflectionProperty(QueryBuilder::class, 'type');
        $prop->setValue($qb, $type);
    }

    // -------------------------------------------------------------------------
    // compileDelete() via toSql()
    // -------------------------------------------------------------------------

    public function testToSqlDeleteWithoutWhere(): void
    {
        $qb = $this->makeQB()->from('users');
        $this->setType($qb, 'DELETE');

        $this->assertEquals('DELETE FROM users', $qb->toSql());
    }

    public function testToSqlDeleteWithIntegerWhere(): void
    {
        $qb = $this->makeQB()->from('users')->where('id', 5);
        $this->setType($qb, 'DELETE');

        $this->assertEquals('DELETE FROM users WHERE id = %i', $qb->toSql());
    }

    public function testToSqlDeleteWithStringWhere(): void
    {
        $qb = $this->makeQB()->from('users')->where('status', 'inactive');
        $this->setType($qb, 'DELETE');

        $this->assertEquals("DELETE FROM users WHERE status = %s", $qb->toSql());
    }

    public function testToSqlDeleteWithMultipleWheres(): void
    {
        $qb = $this->makeQB()
            ->from('orders')
            ->where('status', 'cancelled')
            ->where('total', '<', 0.0);
        $this->setType($qb, 'DELETE');

        $sql = $qb->toSql();
        $this->assertStringStartsWith('DELETE FROM orders WHERE', $sql);
        $this->assertStringContainsString('status = %s', $sql);
        $this->assertStringContainsString('total < %d', $sql);
    }

    // -------------------------------------------------------------------------
    // orderByRaw() compiled in SELECT
    // -------------------------------------------------------------------------

    public function testOrderByRawAppearsInSql(): void
    {
        $qb  = $this->makeQB()->select('*')->from('products')->orderByRaw("FIELD(status, 'active', 'pending')");
        $sql = $qb->toSql();

        $this->assertStringContainsString("ORDER BY FIELD(status, 'active', 'pending')", $sql);
    }

    public function testOrderByRawCombinedWithRegularOrderBy(): void
    {
        $qb = $this->makeQB()
            ->select('*')
            ->from('products')
            ->orderByRaw('priority DESC')
            ->orderBy('name');

        $sql = $qb->toSql();
        $this->assertStringContainsString('ORDER BY priority DESC, name ASC', $sql);
    }

    // -------------------------------------------------------------------------
    // groupByRaw() compiled in SELECT
    // -------------------------------------------------------------------------

    public function testGroupByRawAppearsInSql(): void
    {
        $qb  = $this->makeQB()->select('*')->from('logs')->groupByRaw('DATE(created_at)');
        $sql = $qb->toSql();

        $this->assertStringContainsString('GROUP BY DATE(created_at)', $sql);
    }

    public function testGroupByRawCombinedWithRegularGroupBy(): void
    {
        $qb = $this->makeQB()
            ->select('*')
            ->from('sales')
            ->groupBy('region')
            ->groupByRaw('YEAR(sale_date)');

        $sql = $qb->toSql();
        $this->assertStringContainsString('GROUP BY', $sql);
        $this->assertStringContainsString('region', $sql);
        $this->assertStringContainsString('YEAR(sale_date)', $sql);
    }

    // -------------------------------------------------------------------------
    // toSql() — INSERT/UPDATE fall through to SELECT (values not stored on QB)
    // -------------------------------------------------------------------------

    public function testToSqlInsertFallsThroughToSelect(): void
    {
        $qb = $this->makeQB()->from('users');
        $this->setType($qb, 'INSERT');
        // No values stored on QB, so toSql() compiles as SELECT
        $sql = $qb->toSql();
        $this->assertStringStartsWith('SELECT', $sql);
    }

    public function testToSqlUpdateFallsThroughToSelect(): void
    {
        $qb = $this->makeQB()->from('users');
        $this->setType($qb, 'UPDATE');
        $sql = $qb->toSql();
        $this->assertStringStartsWith('SELECT', $sql);
    }

    // -------------------------------------------------------------------------
    // toSql() default path (SELECT)
    // -------------------------------------------------------------------------

    public function testToSqlDefaultIsSelect(): void
    {
        $qb  = $this->makeQB()->select('id', 'name')->from('users');
        $sql = $qb->toSql();
        $this->assertStringStartsWith('SELECT', $sql);
        $this->assertStringContainsString('FROM users', $sql);
    }

    // -------------------------------------------------------------------------
    // TimescaleDB grammar path (makeGrammar with timescale=true)
    // -------------------------------------------------------------------------

    public function testTimescaleDBGetsTimescaleDBGrammar(): void
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type      = 'postgresql';
        $db->timescale = true;
        $db->prefix    = '';

        $qb = new QueryBuilder($db);
        $this->assertInstanceOf(\Pramnos\Database\Grammar\TimescaleDBGrammar::class, $qb->getGrammar());
    }

    // -------------------------------------------------------------------------
    // whereRaw('') guard — returns $this without adding a where clause
    // -------------------------------------------------------------------------

    public function testWhereRawEmptyStringGuard(): void
    {
        $qb  = $this->makeQB()->select('*')->from('users');
        $qb->whereRaw('');
        $this->assertEmpty($qb->getWheres());
    }

    public function testWhereRawNullGuard(): void
    {
        $qb = $this->makeQB()->select('*')->from('users');
        $qb->whereRaw(null);
        $this->assertEmpty($qb->getWheres());
    }

    // -------------------------------------------------------------------------
    // having() 2-argument shorthand (having('col', value) → operator defaults to '=')
    // -------------------------------------------------------------------------

    public function testHavingTwoArgUsesEqualsOperator(): void
    {
        $qb  = $this->makeQB()->select('*')->from('items')->groupBy('type')->having('cnt', 5);
        $sql = $qb->toSql();
        $this->assertStringContainsString('HAVING cnt = %i', $sql);
    }

    // -------------------------------------------------------------------------
    // compileHavings with multiple HAVING clauses (AND boolean connector, Grammar.php line 195)
    // -------------------------------------------------------------------------

    public function testMultipleHavingsCompileWithAndConnector(): void
    {
        $qb = $this->makeQB()
            ->select('category')
            ->from('products')
            ->groupBy('category')
            ->having('cnt', '>', 2)
            ->having('total', '>', 100.0);

        $sql = $qb->toSql();
        $this->assertStringContainsString('HAVING', $sql);
        $this->assertStringContainsString('AND', $sql);
        $this->assertStringContainsString('cnt > %i', $sql);
        $this->assertStringContainsString('total > %d', $sql);
    }

    // -------------------------------------------------------------------------
    // Grammar injection via setGrammar / getGrammar
    // -------------------------------------------------------------------------

    public function testSetAndGetGrammar(): void
    {
        $qb      = $this->makeQB('mysql');
        $grammar = new \Pramnos\Database\Grammar\PostgreSQLGrammar();
        $qb->setGrammar($grammar);
        $this->assertSame($grammar, $qb->getGrammar());
    }

    public function testMySQLQBGetsMySQLGrammar(): void
    {
        $qb = $this->makeQB('mysql');
        $this->assertInstanceOf(\Pramnos\Database\Grammar\MySQLGrammar::class, $qb->getGrammar());
    }

    public function testPostgreSQLQBGetsPostgreSQLGrammar(): void
    {
        $qb = $this->makeQB('postgresql');
        $this->assertInstanceOf(\Pramnos\Database\Grammar\PostgreSQLGrammar::class, $qb->getGrammar());
    }

    // -------------------------------------------------------------------------
    // addBinding() invalid type throws InvalidArgumentException
    // -------------------------------------------------------------------------

    public function testAddBindingInvalidTypeThrows(): void
    {
        $qb     = $this->makeQB();
        $method = new \ReflectionMethod(QueryBuilder::class, 'addBinding');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($qb, 'value', 'nonexistent_type');
    }

    // -------------------------------------------------------------------------
    // get() with cache — cache HIT path (QB lines 720-740)
    // -------------------------------------------------------------------------

    private function makeCachingQB(array $cacheReadReturn): QueryBuilder
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->method('cacheRead')->willReturn($cacheReadReturn);
        return new QueryBuilder($db);
    }

    public function testGetCacheHitReturnsPopulatedResult(): void
    {
        $rows = [['name' => 'Alice', 'age' => 30]];
        $qb   = $this->makeCachingQB($rows);

        $result = $qb->select('*')->from('users')->get(true);

        $this->assertTrue($result->isCached);
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Alice', $result->fields['name']);
        $this->assertFalse($result->eof);
    }

    public function testGetCacheHitWithEmptyResultSetsEof(): void
    {
        $qb     = $this->makeCachingQB([]);
        $result = $qb->select('*')->from('users')->get(true);

        $this->assertTrue($result->isCached);
        $this->assertEquals(0, $result->numRows);
        $this->assertTrue($result->eof);
    }

    // -------------------------------------------------------------------------
    // get() with cache — cache MISS + cache WRITE path (QB lines 747-760)
    // -------------------------------------------------------------------------

    public function testGetCacheMissExecutesAndWritesCache(): void
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        // Cache miss
        $db->method('cacheRead')->willReturn(false);

        // Fake result from execute() — set up as cached so fetchAll() works
        $fakeResult           = new \Pramnos\Database\Result($db);
        $fakeResult->isCached = true;
        $fakeResult->result   = [['name' => 'Bob']];
        $fakeResult->numRows  = 1;
        $fakeResult->cursor   = -1;
        $fakeResult->fields   = ['name' => 'Bob'];
        $fakeResult->eof      = false;

        $db->method('execute')->willReturn($fakeResult);
        $db->method('shouldCacheResult')->willReturn(true);
        $db->expects($this->once())->method('cacheStore');

        $qb     = new QueryBuilder($db);
        $result = $qb->select('*')->from('users')->get(true);

        $this->assertTrue($result->isCached);
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Bob', $result->fields['name']);
    }

    public function testGetCacheMissExecutesNoWriteWhenShouldNotCache(): void
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';
        $db->method('cacheRead')->willReturn(false);

        $fakeResult           = new \Pramnos\Database\Result($db);
        $fakeResult->isCached = true;
        $fakeResult->result   = [];
        $fakeResult->numRows  = 0;
        $fakeResult->cursor   = -1;
        $fakeResult->eof      = true;

        $db->method('execute')->willReturn($fakeResult);
        $db->method('shouldCacheResult')->willReturn(false);
        $db->expects($this->never())->method('cacheStore');

        $qb     = new QueryBuilder($db);
        $result = $qb->select('*')->from('users')->get(true);

        $this->assertEquals(0, $result->numRows);
    }

    // =========================================================================
    // timeBucket() — dialect translation
    // =========================================================================

    private function makeQBForDialect(string $dbType, bool $timescale = false): QueryBuilder
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type      = $dbType;
        $db->timescale = $timescale;
        $db->prefix    = '';
        return new QueryBuilder($db);
    }

    // ---- TimescaleDB ----

    public function testTimeBucketTimescaleDBHour(): void
    {
        $qb   = $this->makeQBForDialect('postgresql', true);
        $expr = $qb->timeBucket('1 hour', 'recorded_at');
        $this->assertEquals("time_bucket('1 hour', recorded_at)", (string)$expr);
    }

    public function testTimeBucketTimescaleDBArbitraryInterval(): void
    {
        $qb   = $this->makeQBForDialect('postgresql', true);
        $expr = $qb->timeBucket('15 minutes', 'ts');
        $this->assertEquals("time_bucket('15 minutes', ts)", (string)$expr);
    }

    public function testTimeBucketTimescaleDBDay(): void
    {
        $qb   = $this->makeQBForDialect('postgresql', true);
        $expr = $qb->timeBucket('1 day', 'event_time');
        $this->assertEquals("time_bucket('1 day', event_time)", (string)$expr);
    }

    // ---- PostgreSQL ----

    public function testTimeBucketPostgreSQLHour(): void
    {
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('1 hour', 'recorded_at');
        $this->assertEquals("date_trunc('hour', recorded_at)", (string)$expr);
    }

    public function testTimeBucketPostgreSQLDay(): void
    {
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('1 day', 'event_time');
        $this->assertEquals("date_trunc('day', event_time)", (string)$expr);
    }

    public function testTimeBucketPostgreSQLMonth(): void
    {
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('1 month', 'created_at');
        $this->assertEquals("date_trunc('month', created_at)", (string)$expr);
    }

    public function testTimeBucketPostgreSQLYear(): void
    {
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('1 year', 'created_at');
        $this->assertEquals("date_trunc('year', created_at)", (string)$expr);
    }

    public function testTimeBucketPostgreSQLWeek(): void
    {
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('1 week', 'created_at');
        $this->assertEquals("date_trunc('week', created_at)", (string)$expr);
    }

    public function testTimeBucketPostgreSQLMinute(): void
    {
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('1 minute', 'ts');
        $this->assertEquals("date_trunc('minute', ts)", (string)$expr);
    }

    public function testTimeBucketPostgresQL15MinutesFallsBackToEpochArithmetic(): void
    {
        // "15 minutes" can't map to a DATE_TRUNC precision — uses epoch arithmetic
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('15 minutes', 'ts');
        $this->assertEquals('to_timestamp(floor(extract(epoch from ts) / 900) * 900)', (string)$expr);
    }

    public function testTimeBucketPostgresQL6HoursFallsBackToEpochArithmetic(): void
    {
        $qb   = $this->makeQBForDialect('postgresql');
        $expr = $qb->timeBucket('6 hours', 'ts');
        $this->assertEquals('to_timestamp(floor(extract(epoch from ts) / 21600) * 21600)', (string)$expr);
    }

    // ---- MySQL ----

    public function testTimeBucketMySQLHour(): void
    {
        $qb   = $this->makeQBForDialect('mysql');
        $expr = $qb->timeBucket('1 hour', 'recorded_at');
        $this->assertEquals('FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 3600) * 3600)', (string)$expr);
    }

    public function testTimeBucketMySQLDay(): void
    {
        $qb   = $this->makeQBForDialect('mysql');
        $expr = $qb->timeBucket('1 day', 'event_time');
        $this->assertEquals('FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(event_time) / 86400) * 86400)', (string)$expr);
    }

    public function testTimeBucketMySQL15Minutes(): void
    {
        $qb   = $this->makeQBForDialect('mysql');
        $expr = $qb->timeBucket('15 minutes', 'ts');
        $this->assertEquals('FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / 900) * 900)', (string)$expr);
    }

    public function testTimeBucketMySQLMonth(): void
    {
        $qb   = $this->makeQBForDialect('mysql');
        $expr = $qb->timeBucket('1 month', 'created_at');
        $this->assertEquals("DATE_FORMAT(created_at, '%Y-%m-01')", (string)$expr);
    }

    public function testTimeBucketMySQLYear(): void
    {
        $qb   = $this->makeQBForDialect('mysql');
        $expr = $qb->timeBucket('1 year', 'created_at');
        $this->assertEquals("DATE_FORMAT(created_at, '%Y-01-01')", (string)$expr);
    }

    public function testTimeBucketMySQLWeek(): void
    {
        $qb   = $this->makeQBForDialect('mysql');
        $expr = $qb->timeBucket('1 week', 'ts');
        $this->assertEquals('FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / 604800) * 604800)', (string)$expr);
    }

    // ---- Expression column passthrough ----

    public function testTimeBucketAcceptsExpressionAsColumn(): void
    {
        $qb  = $this->makeQBForDialect('postgresql', true);
        $col = $qb->raw('to_timestamp(raw_col)');
        $expr = $qb->timeBucket('1 hour', $col);
        $this->assertEquals("time_bucket('1 hour', to_timestamp(raw_col))", (string)$expr);
    }

    // ---- timeBucket() result usable in GROUP BY / SELECT ----

    public function testTimeBucketUsableInGroupBy(): void
    {
        $qb  = $this->makeQBForDialect('mysql');
        $bucket = $qb->timeBucket('1 hour', 'ts');

        $qb->select([$bucket, 'COUNT(*) AS cnt'])
           ->from('events')
           ->groupBy([$bucket]);

        $sql = $qb->toSql();
        $this->assertStringContainsString('FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / 3600) * 3600)', $sql);
        $this->assertStringContainsString('GROUP BY', $sql);
    }

    // =========================================================================
    // with() / withRecursive() — CTEs
    // =========================================================================

    public function testWithSimpleCte(): void
    {
        $qb = $this->makeQB()
            ->with('active_users', function (QueryBuilder $sub) {
                $sub->select('*')->from('users')->where('active', 1);
            })
            ->select('*')
            ->from('active_users');

        $sql = $qb->toSql();
        $this->assertStringStartsWith('WITH', $sql);
        $this->assertStringContainsString('active_users AS (', $sql);
        $this->assertStringContainsString('SELECT * FROM users', $sql);
        $this->assertStringContainsString('FROM active_users', $sql);
        $this->assertStringNotContainsString('RECURSIVE', $sql);
    }

    public function testWithRecursiveCte(): void
    {
        $qb = $this->makeQB()
            ->withRecursive('hierarchy', 'SELECT id, parent_id FROM categories WHERE parent_id IS NULL UNION ALL SELECT c.id, c.parent_id FROM categories c JOIN hierarchy h ON c.parent_id = h.id')
            ->select('*')
            ->from('hierarchy');

        $sql = $qb->toSql();
        $this->assertStringStartsWith('WITH RECURSIVE', $sql);
        $this->assertStringContainsString('hierarchy AS (', $sql);
    }

    public function testWithMultipleCtes(): void
    {
        $qb = $this->makeQB()
            ->with('cte1', 'SELECT 1 AS n')
            ->with('cte2', 'SELECT 2 AS n')
            ->select('*')
            ->from('cte1');

        $sql = $qb->toSql();
        $this->assertStringStartsWith('WITH', $sql);
        $this->assertStringContainsString('cte1 AS (SELECT 1 AS n)', $sql);
        $this->assertStringContainsString('cte2 AS (SELECT 2 AS n)', $sql);
        // Both CTEs present, comma-separated
        $this->assertStringContainsString('), cte2 AS (', $sql);
    }

    public function testWithCteUsingQueryBuilderInstance(): void
    {
        $sub = $this->makeQB()->select(['id', 'name'])->from('products')->where('active', 1);

        $qb = $this->makeQB()
            ->with('active_products', $sub)
            ->select('*')
            ->from('active_products');

        $sql = $qb->toSql();
        $this->assertStringContainsString('active_products AS (', $sql);
        $this->assertStringContainsString('SELECT id, name FROM products', $sql);
    }

    public function testWithNoCteProducesNoWithKeyword(): void
    {
        $qb  = $this->makeQB()->select('*')->from('users');
        $sql = $qb->toSql();
        $this->assertStringStartsWith('SELECT', $sql);
        $this->assertStringNotContainsString('WITH', $sql);
    }

    public function testWithRecursiveFlagOverridesNonRecursiveCte(): void
    {
        // If at least one CTE is recursive, the whole preamble is WITH RECURSIVE
        $qb = $this->makeQB()
            ->with('plain', 'SELECT 1')
            ->withRecursive('tree', 'SELECT id FROM nodes WHERE parent IS NULL UNION ALL SELECT n.id FROM nodes n JOIN tree t ON n.parent = t.id')
            ->select('*')
            ->from('tree');

        $sql = $qb->toSql();
        $this->assertStringStartsWith('WITH RECURSIVE', $sql);
        $this->assertStringContainsString('plain AS (SELECT 1)', $sql);
        $this->assertStringContainsString('tree AS (', $sql);
    }

    public function testGetCtesReturnsRegisteredCtes(): void
    {
        $qb = $this->makeQB()
            ->with('foo', 'SELECT 1')
            ->withRecursive('bar', 'SELECT 2');

        $ctes = $qb->getCtes();
        $this->assertCount(2, $ctes);
        $this->assertEquals('foo', $ctes[0]['name']);
        $this->assertFalse($ctes[0]['recursive']);
        $this->assertEquals('bar', $ctes[1]['name']);
        $this->assertTrue($ctes[1]['recursive']);
    }

    // -------------------------------------------------------------------------
    // count()
    // -------------------------------------------------------------------------

    /**
     * count() must issue SELECT COUNT(*) AS aggregate, preserve WHERE bindings,
     * and return the integer value from the aggregate field.
     *
     * ORDER BY and LIMIT/OFFSET are stripped because they are meaningless for
     * aggregate queries and would waste DB resources.
     */
    public function testCountGeneratesAggregateQueryStripsOrderingAndReturnsInt(): void
    {
        // Arrange
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        $capturedSql      = null;
        $capturedBindings = [];

        $fakeResult          = new \Pramnos\Database\Result($db);
        $fakeResult->fields  = ['aggregate' => 7];
        $fakeResult->numRows = 1;
        $fakeResult->eof     = true;

        $db->expects($this->once())
            ->method('execute')
            ->willReturnCallback(
                function (string $sql, ...$bindings) use ($fakeResult, &$capturedSql, &$capturedBindings) {
                    $capturedSql      = $sql;
                    $capturedBindings = $bindings;
                    return $fakeResult;
                }
            );

        $qb = (new QueryBuilder($db))
            ->from('orders')
            ->where('status', '=', 1)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->offset(5);

        // Act
        $total = $qb->count();

        // Assert — returns the integer from the aggregate field
        $this->assertSame(7, $total);
        // COUNT(*) AS aggregate must be the SELECT expression
        $this->assertStringContainsString('COUNT(*) AS aggregate', $capturedSql);
        // WHERE clause (and its bindings) must survive the clone
        $this->assertStringContainsString('WHERE', $capturedSql);
        $this->assertSame([1], $capturedBindings);
        // ORDER BY, LIMIT, OFFSET must be stripped
        $this->assertStringNotContainsString('ORDER BY', strtoupper($capturedSql));
        $this->assertStringNotContainsString('LIMIT',    strtoupper($capturedSql));
        $this->assertStringNotContainsString('OFFSET',   strtoupper($capturedSql));
    }

    /**
     * count() must not mutate the original builder.
     *
     * After calling count(), the original QB must still produce its full SELECT
     * with ORDER BY / LIMIT / OFFSET intact, so it can be used for the data fetch.
     * This is the key invariant that makes count() safe to call before get() in
     * the pagination pattern: $total = $qb->count(); $rows = $qb->get();
     */
    public function testCountDoesNotMutateOriginalBuilder(): void
    {
        // Arrange
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        $fakeResult          = new \Pramnos\Database\Result($db);
        $fakeResult->fields  = ['aggregate' => 0];
        $fakeResult->numRows = 0;
        $fakeResult->eof     = true;
        $db->method('execute')->willReturn($fakeResult);

        $qb = (new QueryBuilder($db))
            ->from('orders')
            ->where('status', '=', 1)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->offset(10);

        // Record the full SQL before the count call
        $sqlBefore = $qb->toSql();

        // Act
        $qb->count();

        // Assert — original builder is unchanged
        $sqlAfter = $qb->toSql();
        $this->assertSame($sqlBefore, $sqlAfter);
        $this->assertStringContainsString('ORDER BY', strtoupper($sqlAfter));
        $this->assertStringContainsString('LIMIT',    strtoupper($sqlAfter));
        $this->assertStringContainsString('OFFSET',   strtoupper($sqlAfter));
    }

    // =========================================================================
    // rightJoin / crossJoin
    // =========================================================================

    /**
     * rightJoin() must produce RIGHT JOIN … ON … in compiled SQL.
     * This is a convenience wrapper around join($table, ..., 'right').
     */
    public function testRightJoinCompilesCorrectly(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('orders')
            ->rightJoin('customers', 'orders.customer_id', '=', 'customers.id');

        // Act
        $sql = $qb->toSql();

        // Assert
        $this->assertStringContainsString('RIGHT JOIN customers ON', $sql);
        $this->assertStringContainsString('orders.customer_id = customers.id', $sql);
    }

    /**
     * crossJoin() must produce CROSS JOIN without any ON clause.
     * Cross joins enumerate every combination of rows from both tables.
     */
    public function testCrossJoinCompilesWithoutOnClause(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('colors')
            ->crossJoin('sizes');

        // Act
        $sql = $qb->toSql();

        // Assert — CROSS JOIN present, no ON keyword after it
        $this->assertStringContainsString('CROSS JOIN sizes', $sql);
        $this->assertStringNotContainsString('ON', $sql);
    }

    // =========================================================================
    // latest() / oldest() / forPage()
    // =========================================================================

    /**
     * latest() must add ORDER BY col DESC — default column is created_at.
     */
    public function testLatestAddsDescOrdering(): void
    {
        // Arrange / Act
        $sql = $this->makeQB()->select('*')->from('posts')->latest()->toSql();

        // Assert
        $this->assertStringContainsString('ORDER BY created_at DESC', $sql);
    }

    /**
     * latest() with explicit column must order by that column descending.
     */
    public function testLatestWithExplicitColumn(): void
    {
        $sql = $this->makeQB()->select('*')->from('events')->latest('published_at')->toSql();
        $this->assertStringContainsString('ORDER BY published_at DESC', $sql);
    }

    /**
     * oldest() must add ORDER BY col ASC — default column is created_at.
     */
    public function testOldestAddsAscOrdering(): void
    {
        $sql = $this->makeQB()->select('*')->from('posts')->oldest()->toSql();
        $this->assertStringContainsString('ORDER BY created_at ASC', $sql);
    }

    /**
     * forPage() must calculate LIMIT and OFFSET correctly for 1-based pages.
     * Page 1, 10 per page → LIMIT 10 OFFSET 0
     * Page 3, 10 per page → LIMIT 10 OFFSET 20
     */
    public function testForPageCalculatesLimitAndOffset(): void
    {
        // Arrange / Act
        $sql1 = $this->makeQB()->select('*')->from('items')->forPage(1, 10)->toSql();
        $sql3 = $this->makeQB()->select('*')->from('items')->forPage(3, 10)->toSql();

        // Assert page 1
        $this->assertStringContainsString('LIMIT 10', $sql1);
        $this->assertStringContainsString('OFFSET 0', $sql1);

        // Assert page 3
        $this->assertStringContainsString('LIMIT 10', $sql3);
        $this->assertStringContainsString('OFFSET 20', $sql3);
    }

    // =========================================================================
    // when()
    // =========================================================================

    /**
     * when() with a truthy condition must apply the callback to the builder.
     * The builder is returned unchanged when condition is false and no default.
     */
    public function testWhenTruthyAppliesCallback(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('users');
        $filterActive = true;

        // Act
        $qb->when($filterActive, fn($q) => $q->where('active', 1));
        $sql = $qb->toSql();

        // Assert — WHERE added because condition was truthy
        $this->assertStringContainsString('WHERE active = %i', $sql);
    }

    /**
     * when() with a falsy condition must skip the callback and not modify the builder.
     */
    public function testWhenFalsySkipsCallback(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('users');

        // Act
        $qb->when(false, fn($q) => $q->where('active', 1));
        $sql = $qb->toSql();

        // Assert — no WHERE added
        $this->assertStringNotContainsString('WHERE', $sql);
    }

    /**
     * when() with a falsy condition and a default callback must apply the default.
     */
    public function testWhenFalsyWithDefaultAppliesDefault(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('users');

        // Act
        $qb->when(
            false,
            fn($q) => $q->where('active', 1),
            fn($q) => $q->where('deleted', 0)
        );
        $sql = $qb->toSql();

        // Assert — default WHERE applied, not the truthy callback
        $this->assertStringContainsString('WHERE deleted = %i', $sql);
        $this->assertStringNotContainsString('active', $sql);
    }

    /**
     * when() with a Closure condition evaluates it to determine truthiness.
     */
    public function testWhenWithClosureCondition(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('users');

        // Act — Closure condition that returns truthy value
        $qb->when(fn() => 'active', fn($q, $val) => $q->where('status', $val));
        $sql = $qb->toSql();

        // Assert — WHERE uses the value returned by the condition Closure
        $this->assertStringContainsString('WHERE status = %s', $sql);
    }

    // =========================================================================
    // sum() / avg() / min() / max()
    // =========================================================================

    /**
     * sum() must issue SELECT SUM(col) AS aggregate, strip ORDER BY/LIMIT,
     * and return a float.  Must not mutate the original builder.
     */
    public function testSumGeneratesCorrectAggregateQuery(): void
    {
        // Arrange
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        $capturedSql = null;
        $fakeResult          = new \Pramnos\Database\Result($db);
        $fakeResult->fields  = ['aggregate' => '42.50'];
        $fakeResult->numRows = 1;
        $fakeResult->eof     = true;
        $db->method('execute')->willReturnCallback(
            function ($sql) use ($fakeResult, &$capturedSql) {
                $capturedSql = $sql;
                return $fakeResult;
            }
        );

        $qb = (new QueryBuilder($db))->from('orders')->where('status', 1)->orderBy('id')->limit(5);

        // Act
        $result = $qb->sum('total');

        // Assert
        $this->assertSame(42.50, $result);
        $this->assertStringContainsString('SUM(total) AS aggregate', $capturedSql);
        $this->assertStringNotContainsString('ORDER BY', strtoupper($capturedSql));
        $this->assertStringNotContainsString('LIMIT', strtoupper($capturedSql));
        // Original builder must be unchanged
        $this->assertStringContainsString('ORDER BY', strtoupper($qb->toSql()));
    }

    /**
     * avg() must issue SELECT AVG(col) AS aggregate and return a float.
     */
    public function testAvgGeneratesCorrectAggregateQuery(): void
    {
        // Arrange
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        $capturedSql = null;
        $fakeResult         = new \Pramnos\Database\Result($db);
        $fakeResult->fields = ['aggregate' => '3.14'];
        $fakeResult->numRows = 1;
        $db->method('execute')->willReturnCallback(
            function ($sql) use ($fakeResult, &$capturedSql) {
                $capturedSql = $sql;
                return $fakeResult;
            }
        );

        $qb = (new QueryBuilder($db))->from('ratings');

        // Act / Assert
        $this->assertSame(3.14, $qb->avg('score'));
        $this->assertStringContainsString('AVG(score) AS aggregate', $capturedSql);
    }

    /**
     * min() / max() must return the raw mixed value from the aggregate field.
     * When no rows match, they return null (not 0).
     */
    public function testMinReturnsAggregateValue(): void
    {
        // Arrange
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        $capturedSql = null;
        $fakeResult         = new \Pramnos\Database\Result($db);
        $fakeResult->fields = ['aggregate' => '5'];
        $fakeResult->numRows = 1;
        $db->method('execute')->willReturnCallback(
            function ($sql) use ($fakeResult, &$capturedSql) {
                $capturedSql = $sql;
                return $fakeResult;
            }
        );

        $qb = (new QueryBuilder($db))->from('bids');

        // Act / Assert
        $this->assertEquals('5', $qb->min('amount'));
        $this->assertStringContainsString('MIN(amount) AS aggregate', $capturedSql);
    }

    public function testMaxReturnsAggregateValue(): void
    {
        // Arrange
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type   = 'mysql';
        $db->prefix = '';

        $capturedSql = null;
        $fakeResult         = new \Pramnos\Database\Result($db);
        $fakeResult->fields = ['aggregate' => '999'];
        $fakeResult->numRows = 1;
        $db->method('execute')->willReturnCallback(
            function ($sql) use ($fakeResult, &$capturedSql) {
                $capturedSql = $sql;
                return $fakeResult;
            }
        );

        $qb = (new QueryBuilder($db))->from('bids');

        // Act / Assert
        $this->assertEquals('999', $qb->max('amount'));
        $this->assertStringContainsString('MAX(amount) AS aggregate', $capturedSql);
    }

    // =========================================================================
    // lockForUpdate() / sharedLock()
    // =========================================================================

    /**
     * lockForUpdate() must append FOR UPDATE to the SQL on MySQL.
     * This is used for pessimistic locking within a transaction.
     */
    public function testLockForUpdateMySQLAppendsSuffix(): void
    {
        // Arrange / Act
        $sql = $this->makeQB('mysql')->select('*')->from('products')
            ->where('id', 1)
            ->lockForUpdate()
            ->toSql();

        // Assert — FOR UPDATE appended after WHERE (and any LIMIT)
        $this->assertStringEndsWith('FOR UPDATE', trim($sql));
    }

    /**
     * sharedLock() on MySQL must produce LOCK IN SHARE MODE.
     */
    public function testSharedLockMySQLAppendsSuffix(): void
    {
        // Arrange / Act
        $sql = $this->makeQB('mysql')->select('*')->from('products')
            ->sharedLock()
            ->toSql();

        // Assert
        $this->assertStringEndsWith('LOCK IN SHARE MODE', trim($sql));
    }

    /**
     * lockForUpdate() on PostgreSQL must append FOR UPDATE.
     */
    public function testLockForUpdatePostgreSQLAppendsSuffix(): void
    {
        $sql = $this->makeQB('postgresql')->select('*')->from('products')
            ->where('id', 1)
            ->lockForUpdate()
            ->toSql();

        $this->assertStringEndsWith('FOR UPDATE', trim($sql));
    }

    /**
     * sharedLock() on PostgreSQL must append FOR SHARE (not LOCK IN SHARE MODE).
     */
    public function testSharedLockPostgreSQLAppendsSuffix(): void
    {
        $sql = $this->makeQB('postgresql')->select('*')->from('products')
            ->sharedLock()
            ->toSql();

        $this->assertStringEndsWith('FOR SHARE', trim($sql));
    }

    /**
     * Without any lock call, no locking suffix appears in the SQL.
     */
    public function testNoLockProducesNoLockingSuffix(): void
    {
        $sql = $this->makeQB()->select('*')->from('users')->toSql();
        $this->assertStringNotContainsString('FOR UPDATE', $sql);
        $this->assertStringNotContainsString('FOR SHARE', $sql);
        $this->assertStringNotContainsString('LOCK IN SHARE MODE', $sql);
    }

    // =========================================================================
    // whereExists() / whereNotExists()
    // =========================================================================

    /**
     * whereExists() must compile to WHERE EXISTS (SELECT 1 FROM …).
     * The sub-query is fully built by the callback closure.
     */
    public function testWhereExistsCompilesSubquery(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('users')
            ->whereExists(function (QueryBuilder $sub) {
                $sub->select(['1'])->from('orders')->whereRaw('orders.user_id = users.id');
            });

        // Act
        $sql = $qb->toSql();

        // Assert
        $this->assertStringContainsString('WHERE EXISTS (', $sql);
        $this->assertStringContainsString('SELECT 1 FROM orders', $sql);
    }

    /**
     * whereNotExists() must compile to WHERE NOT EXISTS (SELECT …).
     */
    public function testWhereNotExistsCompilesSubquery(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('users')
            ->whereNotExists(function (QueryBuilder $sub) {
                $sub->select(['1'])->from('bans')->whereRaw('bans.user_id = users.id');
            });

        // Act
        $sql = $qb->toSql();

        // Assert
        $this->assertStringContainsString('WHERE NOT EXISTS (', $sql);
        $this->assertStringContainsString('SELECT 1 FROM bans', $sql);
    }

    /**
     * orWhereExists() must use OR as the boolean connector.
     */
    public function testOrWhereExistsUsesOrConnector(): void
    {
        // Arrange
        $qb = $this->makeQB()->select('*')->from('users')
            ->where('active', 1)
            ->orWhereExists(function (QueryBuilder $sub) {
                $sub->select(['1'])->from('admins')->whereRaw('admins.user_id = users.id');
            });

        // Act
        $sql = $qb->toSql();

        // Assert — OR connector before EXISTS
        $this->assertStringContainsString('OR EXISTS (', $sql);
    }

    // =========================================================================
    // whereDate() / whereYear() / whereMonth() / whereDay() / whereTime()
    // =========================================================================

    /**
     * whereDate() on MySQL must use DATE(col) for the date portion comparison.
     * This allows filtering by date without caring about the time part.
     */
    public function testWhereDateMySQLUsesDateFunction(): void
    {
        // Arrange / Act
        $qb  = $this->makeQB('mysql')->select('*')->from('events')->whereDate('created_at', '2026-01-15');
        $sql = $qb->toSql();

        // Assert — DATE() function wraps the column
        $this->assertStringContainsString("WHERE DATE(created_at) = %s", $sql);
    }

    /**
     * whereDate() on PostgreSQL must cast to ::date.
     */
    public function testWhereDatePostgreSQLUsesDateCast(): void
    {
        $qb  = $this->makeQB('postgresql')->select('*')->from('events')->whereDate('created_at', '2026-01-15');
        $sql = $qb->toSql();
        $this->assertStringContainsString("WHERE (created_at)::date = %s", $sql);
    }

    /**
     * whereYear() on MySQL must use YEAR(col).
     */
    public function testWhereYearMySQLUsesYearFunction(): void
    {
        $qb  = $this->makeQB('mysql')->select('*')->from('events')->whereYear('published_at', 2026);
        $sql = $qb->toSql();
        $this->assertStringContainsString('WHERE YEAR(published_at) = %i', $sql);
    }

    /**
     * whereYear() on PostgreSQL must use EXTRACT(YEAR FROM col).
     */
    public function testWhereYearPostgreSQLUsesExtract(): void
    {
        $qb  = $this->makeQB('postgresql')->select('*')->from('events')->whereYear('published_at', 2026);
        $sql = $qb->toSql();
        $this->assertStringContainsString('WHERE EXTRACT(YEAR FROM published_at) = %i', $sql);
    }

    /**
     * whereMonth() on MySQL must use MONTH(col).
     */
    public function testWhereMonthMySQLUsesMonthFunction(): void
    {
        $qb  = $this->makeQB('mysql')->select('*')->from('events')->whereMonth('created_at', '>=', 6);
        $sql = $qb->toSql();
        $this->assertStringContainsString('WHERE MONTH(created_at) >= %i', $sql);
    }

    /**
     * whereDay() on MySQL must use DAY(col).
     */
    public function testWhereDayMySQLUsesDayFunction(): void
    {
        $qb  = $this->makeQB('mysql')->select('*')->from('events')->whereDay('created_at', 15);
        $sql = $qb->toSql();
        $this->assertStringContainsString('WHERE DAY(created_at) = %i', $sql);
    }

    /**
     * whereTime() on MySQL must use TIME(col).
     */
    public function testWhereTimeMySQLUsesTimeFunction(): void
    {
        $qb  = $this->makeQB('mysql')->select('*')->from('events')->whereTime('starts_at', '08:00:00');
        $sql = $qb->toSql();
        $this->assertStringContainsString("WHERE TIME(starts_at) = %s", $sql);
    }

    /**
     * whereTime() on PostgreSQL must cast to ::time.
     */
    public function testWhereTimePostgreSQLUsesTimeCast(): void
    {
        $qb  = $this->makeQB('postgresql')->select('*')->from('events')->whereTime('starts_at', '08:00:00');
        $sql = $qb->toSql();
        $this->assertStringContainsString("WHERE (starts_at)::time = %s", $sql);
    }

    /**
     * Date-part WHERE clauses chain with regular WHERE using AND connector.
     */
    public function testDatePartWhereChainedWithRegularWhere(): void
    {
        $qb = $this->makeQB('mysql')->select('*')->from('events')
            ->where('active', 1)
            ->whereYear('created_at', 2026);
        $sql = $qb->toSql();

        $this->assertStringContainsString('WHERE active = %i', $sql);
        $this->assertStringContainsString('AND YEAR(created_at) = %i', $sql);
    }

    // =========================================================================
    // selectSub() / fromSub() — subqueries
    // =========================================================================

    /**
     * selectSub() must wrap the sub-query in parentheses, alias it, and emit it
     * as a SELECT column.  The default '*' is replaced when no explicit select()
     * was called first.
     */
    public function testSelectSubCompilesSubqueryAsColumn(): void
    {
        // Arrange
        $qb = $this->makeQB('mysql');

        // Act
        $qb->selectSub(function (QueryBuilder $sub) {
            $sub->select('MAX(price)')->from('products');
        }, 'max_price')
           ->from('categories');

        $sql = $qb->toSql();

        // Assert — subquery wrapped in parens, aliased correctly
        $this->assertStringContainsString('(SELECT MAX(price) FROM products)', $sql);
        $this->assertStringContainsString('AS `max_price`', $sql);
    }

    /**
     * selectSub() must preserve existing columns added via select() before it is called.
     */
    public function testSelectSubPreservesExistingColumns(): void
    {
        // Arrange
        $qb = $this->makeQB('mysql');

        // Act
        $qb->select(['id', 'name'])
           ->selectSub(function (QueryBuilder $sub) {
               $sub->select('COUNT(*)')->from('orders')->whereRaw('orders.user_id = users.id');
           }, 'order_count')
           ->from('users');

        $sql = $qb->toSql();

        // Assert — original columns AND the subquery column all appear
        $this->assertStringContainsString('id', $sql);
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('(SELECT COUNT(*) FROM orders', $sql);
        $this->assertStringContainsString('AS `order_count`', $sql);
    }

    /**
     * selectSub() on PostgreSQL must use double-quote alias quoting.
     */
    public function testSelectSubPostgreSQLUsesDoubleQuoteAlias(): void
    {
        // Arrange
        $qb = $this->makeQB('postgresql');

        // Act
        $qb->selectSub(function (QueryBuilder $sub) {
            $sub->select('MAX(price)')->from('products');
        }, 'max_price')
           ->from('categories');

        $sql = $qb->toSql();

        // Assert
        $this->assertStringContainsString('AS "max_price"', $sql);
    }

    /**
     * fromSub() must set the FROM clause to a derived table wrapped in parens and aliased.
     */
    public function testFromSubCompilesSubqueryAsFromSource(): void
    {
        // Arrange
        $qb = $this->makeQB('mysql');

        // Act
        $qb->select(['category', 'total'])
           ->fromSub(function (QueryBuilder $sub) {
               $sub->select(['category', 'SUM(price) AS total'])
                   ->from('products')
                   ->groupBy('category');
           }, 'cat_totals')
           ->orderBy('total', 'desc');

        $sql = $qb->toSql();

        // Assert
        $this->assertStringContainsString('FROM (SELECT', $sql);
        $this->assertStringContainsString(') AS cat_totals', $sql);
        $this->assertStringContainsString('GROUP BY', $sql);
    }

    /**
     * fromSub() must accept a QueryBuilder instance directly, not just a Closure.
     */
    public function testFromSubAcceptsQueryBuilderDirectly(): void
    {
        // Arrange
        $outer = $this->makeQB('mysql');
        $inner = $this->makeQB('mysql');
        $inner->select(['id', 'name'])->from('products')->where('active', 1);

        // Act
        $sql = $outer->select('*')->fromSub($inner, 'active_products')->toSql();

        // Assert
        $this->assertStringContainsString('FROM (SELECT', $sql);
        $this->assertStringContainsString(') AS active_products', $sql);
    }

    /**
     * Bindings from a selectSub() subquery must appear before the outer WHERE bindings
     * so Database::prepare() maps them to the correct placeholders in left-to-right order.
     */
    public function testSelectSubBindingsAreMergedBeforeWhereBindings(): void
    {
        // Arrange
        $qb = $this->makeQB('mysql');

        // Act
        $qb->selectSub(function (QueryBuilder $sub) {
            $sub->select('COUNT(*)')->from('orders')->where('status', 'paid');
        }, 'paid_orders')
           ->from('users')
           ->where('active', 1);

        $bindings = $qb->getBindings();

        // Assert — 'paid' (subquery select) must precede 1 (outer WHERE)
        $this->assertCount(2, $bindings);
        $this->assertEquals('paid', $bindings[0]);
        $this->assertEquals(1, $bindings[1]);
    }

    /**
     * Bindings from a fromSub() subquery must appear before the outer WHERE bindings.
     * 'from' slot comes before 'where' slot in the merge order.
     */
    public function testFromSubBindingsAreMergedBeforeWhereBindings(): void
    {
        // Arrange
        $qb = $this->makeQB('mysql');

        // Act
        $qb->select('*')
           ->fromSub(function (QueryBuilder $sub) {
               $sub->select('*')->from('products')->where('category', 'fruit');
           }, 'fruits')
           ->where('price', '>', 1.00);

        $bindings = $qb->getBindings();

        // Assert — 'fruit' (subquery from) must precede 1.00 (outer WHERE)
        $this->assertCount(2, $bindings);
        $this->assertEquals('fruit', $bindings[0]);
        $this->assertEquals(1.00, $bindings[1]);
    }

    // =========================================================================
    // over() — window functions
    // =========================================================================

    /**
     * over() with no partition or order must produce 'FN OVER ()'.
     * A window function with an empty OVER clause is valid SQL (applies to
     * all rows in the partition, i.e. the entire result set).
     */
    public function testOverWithNoOptionsProducesEmptyOver(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('mysql')->over('RANK()');

        // Assert
        $this->assertStringContainsString('RANK() OVER ()', (string)$expr);
    }

    /**
     * PARTITION BY columns must be quoted with the grammar's quoting style.
     * MySQL grammar wraps columns in backticks.
     */
    public function testOverPartitionByMySQLUsesBackticks(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('mysql')->over('RANK()', partition: ['category', 'region']);

        // Assert
        $this->assertStringContainsString('PARTITION BY `category`, `region`', (string)$expr);
    }

    /**
     * PARTITION BY columns must be quoted with the grammar's quoting style.
     * PostgreSQL grammar wraps columns in double-quotes.
     */
    public function testOverPartitionByPostgreSQLUsesDoubleQuotes(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('postgresql')->over('RANK()', partition: ['category', 'region']);

        // Assert
        $this->assertStringContainsString('PARTITION BY "category", "region"', (string)$expr);
    }

    /**
     * ORDER BY with an associative array must emit the direction after each column.
     */
    public function testOverOrderByAssocCompilesWithDirection(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('postgresql')
            ->over('ROW_NUMBER()', order: ['score' => 'desc', 'id' => 'asc']);

        // Assert
        $this->assertStringContainsString('ORDER BY "score" DESC, "id" ASC', (string)$expr);
    }

    /**
     * ORDER BY with an indexed array (no direction) must default to ASC (i.e. no suffix).
     */
    public function testOverOrderByIndexedListDefaultsToNoSuffix(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('postgresql')->over('RANK()', order: ['score', 'id']);

        $sql = (string)$expr;

        // Assert — columns quoted, no DESC emitted
        $this->assertStringContainsString('ORDER BY "score", "id"', $sql);
        $this->assertStringNotContainsString('DESC', $sql);
        $this->assertStringNotContainsString('ASC', $sql);
    }

    /**
     * over() with both PARTITION BY and ORDER BY must emit both clauses in the OVER.
     */
    public function testOverWithPartitionAndOrderCompilesCorrectly(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('postgresql')
            ->over('RANK()', partition: ['category'], order: ['price' => 'desc']);

        $sql = (string)$expr;

        // Assert
        $this->assertStringContainsString('RANK() OVER (', $sql);
        $this->assertStringContainsString('PARTITION BY "category"', $sql);
        $this->assertStringContainsString('ORDER BY "price" DESC', $sql);
    }

    /**
     * over() with an alias must append AS <quoted-alias> at the end of the expression.
     */
    public function testOverWithAliasAppendsQuotedAlias(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('postgresql')
            ->over('RANK()', alias: 'price_rank', partition: ['category'], order: ['price' => 'desc']);

        // Assert
        $this->assertStringEndsWith('AS "price_rank"', (string)$expr);
    }

    /**
     * over() with a ROWS BETWEEN frame clause must include it verbatim in the OVER.
     */
    public function testOverWithFrameClause(): void
    {
        // Arrange / Act
        $expr = $this->makeQB('postgresql')
            ->over(
                'SUM(amount)',
                order: ['created_at' => 'asc'],
                frame: 'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW'
            );

        // Assert
        $this->assertStringContainsString(
            'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW',
            (string)$expr
        );
    }

    /**
     * over() must accept an Expression as the function argument — it casts via __toString().
     */
    public function testOverAcceptsExpressionAsFunction(): void
    {
        // Arrange
        $qb = $this->makeQB('postgresql');
        $fn = $qb->raw('RANK()');

        // Act
        $expr = $qb->over($fn, alias: 'rn', order: ['score' => 'desc']);

        // Assert
        $this->assertStringContainsString('RANK() OVER', (string)$expr);
        $this->assertStringContainsString('AS "rn"', (string)$expr);
    }

    /**
     * over() must accept a single string (not array) for partition shorthand.
     */
    public function testOverPartitionByStringShorthand(): void
    {
        // Arrange / Act — single string instead of array
        $expr = $this->makeQB('postgresql')
            ->over('RANK()', partition: 'category', order: ['price' => 'desc']);

        // Assert
        $this->assertStringContainsString('PARTITION BY "category"', (string)$expr);
    }

    /**
     * over() expression used inside select() must appear verbatim in the compiled SQL.
     */
    public function testOverExpressionUsedInSelect(): void
    {
        // Arrange
        $qb = $this->makeQB('postgresql');
        $windowExpr = $qb->over('RANK()', 'price_rank',
            partition: ['category'],
            order: ['price' => 'desc']
        );

        // Act
        $qb->select(['id', 'name', 'price', $windowExpr])->from('products');

        $sql = $qb->toSql();

        // Assert — window expression appears verbatim in the SELECT list
        $this->assertStringContainsString('RANK() OVER (', $sql);
        $this->assertStringContainsString('PARTITION BY "category"', $sql);
        $this->assertStringContainsString('"price_rank"', $sql);
    }
}
