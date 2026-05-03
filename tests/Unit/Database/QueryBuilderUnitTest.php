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
}
