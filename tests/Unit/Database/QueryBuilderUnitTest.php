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
}
