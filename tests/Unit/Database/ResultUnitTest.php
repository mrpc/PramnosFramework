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
        // Verifies that fetch() returns every row exactly once when starting
        // from cursor=-1 (the initial state set by query()/execute()).
        $rows   = [['name' => 'Alice'], ['name' => 'Bob']];
        $result = $this->makeResult($rows);

        $row1 = $result->fetch();
        $this->assertEquals(['name' => 'Alice'], $row1, 'first fetch() must return row 0 (already prefetched)');

        $row2 = $result->fetch();
        $this->assertEquals(['name' => 'Bob'], $row2, 'second fetch() must return row 1');

        // Past the end → eof
        $row3 = $result->fetch();
        $this->assertNull($row3);
        $this->assertTrue($result->eof);
    }

    /**
     * fetch() must not double-count row 0 when cursor starts at -1.
     *
     * query()/execute() pre-populate $fields with row 0 and set cursor=-1.
     * A naive implementation re-reads row 0 on the first fetch() call.
     * The correct implementation skips that re-read and returns the
     * already-loaded row, so a 3-row result yields exactly 3 iterations.
     */
    public function testFetchNeverDoubleCountsFirstRow(): void
    {
        // Arrange — three rows, cursor=-1 (as set by query()/execute())
        $rows   = [['id' => 1], ['id' => 2], ['id' => 3]];
        $result = $this->makeResult($rows);

        // Act — collect everything
        $collected = [];
        while ($result->fetch()) {
            $collected[] = $result->fields;
        }

        // Assert — exactly 3 rows, no duplicates
        $this->assertCount(3, $collected, 'each row must appear exactly once');
        $this->assertSame(['id' => 1], $collected[0]);
        $this->assertSame(['id' => 2], $collected[1]);
        $this->assertSame(['id' => 3], $collected[2]);
    }

    /**
     * fetch() returns null on the very first call when the result is empty
     * (eof=true from the start). Verifies that the cursor=-1 fast path does
     * not accidentally return $this->fields (which would be an empty array).
     */
    public function testFetchReturnsNullImmediatelyOnEmptyResult(): void
    {
        // Arrange — empty result set: eof=true, cursor=-1
        $result = $this->makeResult([]);

        // Assert
        $this->assertNull($result->fetch(), 'empty result must return null on first fetch()');
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

    // -------------------------------------------------------------------------
    // IteratorAggregate — foreach ($result as $row)
    // -------------------------------------------------------------------------

    /**
     * Result implements IteratorAggregate so views can iterate over it with
     * foreach without the controller explicitly calling fetchAll().
     *
     * Regression guard: before this was added, foreach iterated over the
     * Result's public properties (including the raw PgSql\Result object),
     * causing "Cannot use object of type PgSql\Result as array" in views.
     */
    public function testResultIsIterableViaForeach(): void
    {
        // Arrange — cached result with two rows
        $rows   = [['id' => 1, 'name' => 'Alpha'], ['id' => 2, 'name' => 'Beta']];
        $result = $this->makeResult($rows);

        // Act
        $collected = [];
        foreach ($result as $row) {
            $collected[] = $row;
        }

        // Assert — rows match, not properties of the Result object
        $this->assertCount(2, $collected, 'foreach must yield 2 rows, not object properties');
        $this->assertSame($rows[0], $collected[0]);
        $this->assertSame($rows[1], $collected[1]);
    }

    /**
     * Iterating an empty Result with foreach must produce no iterations.
     */
    public function testForeachOnEmptyResultYieldsNothing(): void
    {
        // Arrange
        $result = $this->makeResult([]);

        // Act
        $collected = [];
        foreach ($result as $row) {
            $collected[] = $row;
        }

        // Assert
        $this->assertCount(0, $collected);
    }

    // -------------------------------------------------------------------------
    // EOF property alias (__get) — legacy ADOdb compatibility
    // -------------------------------------------------------------------------

    /**
     * $result->EOF must alias $result->eof (uppercase vs lowercase).
     *
     * PHP property names are case-sensitive. DevPanelController and other legacy
     * code use the uppercase variant from ADOdb convention. The __get alias ensures
     * they read the correct value without requiring a mass-rename across old callers.
     */
    public function testEofUppercaseAliasMatchesLowercaseProperty(): void
    {
        // Arrange — empty result (eof is true by default)
        $result = $this->makeResult([]);

        // Assert — uppercase alias equals lowercase property
        $this->assertTrue($result->eof,  '$result->eof (lowercase) must be true for empty result');
        $this->assertTrue($result->EOF,  '$result->EOF (uppercase alias) must equal $result->eof');

        // Mutate lowercase — alias must reflect the change
        $result->eof = false;
        $this->assertFalse($result->EOF, 'uppercase alias must track the lowercase property after mutation');
    }
}
