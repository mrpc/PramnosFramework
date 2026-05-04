<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Adjacencylist;
use Pramnos\Database\Database;

/**
 * Characterization tests for Adjacencylist path-reconstruction behavior.
 *
 * These tests use a mocked Database that intercepts execute() calls so that
 * path-building logic can be verified without a live database connection.
 * The Database mock lets queryBuilder() pass through to the real implementation
 * (which just wraps the mock), so the full QB→grammar→execute() pipeline runs
 * and the assertions lock the observable contract rather than internal SQL details.
 *
 * For full dialect coverage against live MySQL and PostgreSQL, see:
 *   - AdjacencylistMySQLCharacterizationTest
 *   - AdjacencylistPostgreSQLCharacterizationTest
 */
#[CoversClass(Adjacencylist::class)]
class AdjacencylistCharacterizationTest extends TestCase
{
    /**
     * Ensures getArray builds full breadcrumb paths and keeps root labels intact.
     *
     * This protects the path reconstruction contract used by dropdown UIs:
     * root items carry their own name; children carry the full ancestor chain.
     */
    public function testGetArrayBuildsNestedPathsForChildren(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();
        $capturedSql = [];

        $db->method('execute')
            ->willReturnCallback(function (string $sql, mixed ...$bindings) use (&$capturedSql) {
                $capturedSql[] = $sql;

                // No binding → fetch all rows
                if (empty($bindings)) {
                    return $this->makeResult([
                        ['id' => 1, 'parent_id' => 0, 'title' => 'Root'],
                        ['id' => 2, 'parent_id' => 1, 'title' => 'Child'],
                        ['id' => 3, 'parent_id' => 2, 'title' => 'Leaf'],
                    ]);
                }

                // Ancestor walk: return the row matching the requested id
                $id = (int) $bindings[0];
                if ($id === 1) {
                    return $this->makeResult([['id' => 1, 'parent_id' => 0, 'title' => 'Root']]);
                }
                if ($id === 2) {
                    return $this->makeResult([['id' => 2, 'parent_id' => 1, 'title' => 'Child']]);
                }

                return $this->makeResult([]);
            });

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');

        // Act
        $items = $adjacency->getArray();

        // Assert
        $this->assertSame('Root', $items[1]);
        $this->assertSame('Root » Child', $items[2]);
        // Multilevel path concatenation must keep correct ancestor order
        $this->assertSame('Root » Child » Leaf', $items[3]);
        $this->assertNotEmpty($capturedSql);
    }

    /**
     * Ensures extraWhere is appended with the leading WHERE keyword stripped.
     *
     * getArray() calls whereRaw() with the raw condition (minus the WHERE
     * keyword) so the QB can compose it correctly alongside other conditions.
     */
    public function testGetArrayAppendsExtraWhereWithWhereKeywordStripped(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();
        $executedSql = [];

        $db->method('execute')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = $sql;
                return $this->makeResult([]);
            });

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');
        $adjacency->extraWhere = 'WHERE `active` = 1';

        // Act
        $adjacency->getArray();

        // Assert — exactly one query was executed (no rows → no ancestor walk)
        $this->assertCount(1, $executedSql);
        // The raw condition must appear in the SQL; WHERE keyword stripped so QB adds it once
        $this->assertStringContainsString('`active` = 1', $executedSql[0]);
        // Verify no double-WHERE (e.g. "WHERE WHERE active = 1")
        $this->assertSame(1, substr_count(strtoupper($executedSql[0]), 'WHERE'));
    }

    /**
     * Ensures getPath returns the same full breadcrumb string as getArray(itemId).
     */
    public function testGetPathReturnsSingleResolvedPath(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();

        $db->method('execute')
            ->willReturnCallback(function (string $sql, mixed ...$bindings) {
                $id = isset($bindings[0]) ? (int) $bindings[0] : 0;

                if ($id === 3) {
                    return $this->makeResult([['id' => 3, 'parent_id' => 2, 'title' => 'Leaf']]);
                }
                if ($id === 2) {
                    return $this->makeResult([['id' => 2, 'parent_id' => 1, 'title' => 'Child']]);
                }
                if ($id === 1) {
                    return $this->makeResult([['id' => 1, 'parent_id' => 0, 'title' => 'Root']]);
                }

                return $this->makeResult([]);
            });

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');

        // Act
        $path = $adjacency->getPath(3);

        // Assert
        $this->assertSame('Root » Child » Leaf', $path);
    }

    /**
     * Ensures getPath returns null when no row is found for the requested id.
     */
    public function testGetPathReturnsNullWhenItemDoesNotExist(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();

        $db->method('execute')
            ->willReturn($this->makeResult([]));

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');

        // Act
        $path = $adjacency->getPath(999);

        // Assert
        $this->assertNull($path);
    }

    /**
     * Ensures getPathAsArray returns the full ancestor chain from root to the
     * requested node, each item as an stdClass with the row fields.
     * Previously this method threw an Error because stdClass was referenced as
     * Pramnos\Database\stdClass (missing leading backslash). That bug is now fixed.
     */
    public function testGetPathAsArrayReturnsAncestorChainFromRootToNode(): void
    {
        // Arrange — mock DB returns a three-level tree: Root → Child → Leaf
        $db = $this->makeDatabaseMock();

        $db->method('execute')
            ->willReturnCallback(function (string $sql, mixed ...$bindings) {
                $id = isset($bindings[0]) ? (int) $bindings[0] : 0;

                if ($id === 3) {
                    return $this->makePrefetchedResult(['id' => 3, 'parent_id' => 2, 'title' => 'Leaf']);
                }
                if ($id === 2) {
                    return $this->makePrefetchedResult(['id' => 2, 'parent_id' => 1, 'title' => 'Child']);
                }
                if ($id === 1) {
                    return $this->makePrefetchedResult(['id' => 1, 'parent_id' => 0, 'title' => 'Root']);
                }

                return $this->makePrefetchedResult([]);
            });

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');

        // Act — traverse up from Leaf (id=3)
        $path = $adjacency->getPathAsArray(3);

        // Assert — three items returned in root-first order
        $this->assertIsArray($path);
        $this->assertCount(3, $path, 'Path must contain Root, Child, and Leaf');

        // Each element must be a plain stdClass (not Pramnos\Database\stdClass)
        foreach ($path as $item) {
            $this->assertInstanceOf(\stdClass::class, $item);
        }

        // Root first, Leaf last — proves ancestor order is preserved
        $this->assertSame('Root', $path[0]->title);
        $this->assertSame('Child', $path[1]->title);
        $this->assertSame('Leaf', $path[2]->title);
    }

    /**
     * @return Database&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeDatabaseMock(): Database
    {
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $mock */
        $mock = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();

        // queryBuilder() creates new QueryBuilder($this) — let it call through
        // to the real implementation. The QB uses $mock->type for grammar
        // selection and $mock->prefix for SQL compilation, so set them here.
        $mock->type   = 'mysql';
        $mock->prefix = '';

        return $mock;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return object
     */
    private function makeResult(array $rows): object
    {
        return new class($rows) {
            /** @var array<string, mixed> */
            public array $fields = [];

            /** @var array<int, array<string, mixed>> */
            private array $rows;

            private int $index = -1;

            /**
             * @param array<int, array<string, mixed>> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function fetch(): bool
            {
                $this->index++;
                if (!isset($this->rows[$this->index])) {
                    return false;
                }

                $this->fields = $this->rows[$this->index];

                return true;
            }
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return object
     */
    private function makePrefetchedResult(array $row): object
    {
        return new class($row) {
            /** @var array<string, mixed> */
            public array $fields;

            /**
             * @param array<string, mixed> $row
             */
            public function __construct(array $row)
            {
                $this->fields = $row;
            }
        };
    }
}
