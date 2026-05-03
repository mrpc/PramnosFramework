<?php

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Result;

/**
 * Unit tests for Result — no real database connection required.
 * Exercises cached paths, fallback paths, and field accessors.
 */
class ResultUnitTest extends TestCase
{
    private function makeResult(?array $rows = null): Result
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type = 'mysql';

        $result          = new Result($db);
        $result->cursor  = -1;
        $result->eof     = false;

        if ($rows !== null) {
            $result->isCached = true;
            $result->result   = $rows;
            $result->numRows  = count($rows);
            if (!empty($rows)) {
                $result->fields = $rows[0];
            } else {
                $result->eof = true;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // fetchAll() — cached path (Result.php line 104)
    // -------------------------------------------------------------------------

    public function testFetchAllCachedReturnsData(): void
    {
        $rows   = [['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 25]];
        $result = $this->makeResult($rows);

        $this->assertSame($rows, $result->fetchAll());
    }

    // -------------------------------------------------------------------------
    // fetchAll() — fallback empty array (Result.php line 152)
    // Reached when mysqlResult is not an object (e.g. boolean after UPDATE/DELETE)
    // -------------------------------------------------------------------------

    public function testFetchAllFallbackReturnsEmptyArray(): void
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type = 'mysql';

        $result               = new Result($db);
        $result->mysqlResult  = null;   // not an object → fallback
        $result->isCached     = false;

        $this->assertSame([], $result->fetchAll());
    }

    // -------------------------------------------------------------------------
    // fetch() — cached path (Result.php lines 208-215)
    // -------------------------------------------------------------------------

    public function testFetchCachedIteratesThroughRows(): void
    {
        $rows   = [['name' => 'Alice'], ['name' => 'Bob']];
        $result = $this->makeResult($rows);

        // Reset cursor to -1 so fetch() starts from the beginning
        $result->cursor = -1;

        $row1 = $result->fetch();
        $this->assertEquals(['name' => 'Alice'], $row1);

        $row2 = $result->fetch();
        $this->assertEquals(['name' => 'Bob'], $row2);

        // Past the end → eof
        $row3 = $result->fetch();
        $this->assertNull($row3);
        $this->assertTrue($result->eof);
    }

    public function testFetchCachedEofReturnNullImmediately(): void
    {
        $result      = $this->makeResult([]);
        $result->eof = true;

        $this->assertNull($result->fetch());
    }

    // -------------------------------------------------------------------------
    // fetchAll() on empty cached result
    // -------------------------------------------------------------------------

    public function testFetchAllEmptyCachedResultReturnsEmptyArray(): void
    {
        $result = $this->makeResult([]);
        $this->assertSame([], $result->fetchAll());
    }

    // -------------------------------------------------------------------------
    // getAffectedRows() / getNumFields() fallback return 0 for unsupported type
    // -------------------------------------------------------------------------

    private function makeResultWithType(string $type): Result
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();
        $db->type = $type;
        return new Result($db);
    }

    public function testGetAffectedRowsFallbackReturnsZeroForUnknownType(): void
    {
        $result = $this->makeResultWithType('sqlite');
        $this->assertEquals(0, $result->getAffectedRows());
    }

    public function testGetNumFieldsFallbackReturnsZeroWhenNoResult(): void
    {
        // mysqlResult defaults to null — not an object → fallback
        $result = $this->makeResultWithType('mysql');
        $this->assertEquals(0, $result->getNumFields());
    }
}
