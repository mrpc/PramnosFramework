<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * Unit tests for QueryBuilder SQL compilation — no database connection required.
 */
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
        $prop->setAccessible(true);
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
        $method->setAccessible(true);

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
}
