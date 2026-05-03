<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Adjacencylist;
use Pramnos\Database\Database;

/**
 * Characterization tests for Adjacencylist observable behavior.
 *
 * These tests lock current path reconstruction and SQL assembly semantics
 * before any internal migration of this class to QueryBuilder/CTE logic.
 */
#[CoversClass(Adjacencylist::class)]
class AdjacencylistCharacterizationTest extends TestCase
{
    /**
     * Ensures getArray builds full breadcrumb paths and keeps root labels intact.
     *
     * This protects the legacy path reconstruction contract used by dropdown UIs.
     */
    public function testGetArrayBuildsNestedPathsForChildren(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();
        $capturedSql = [];

        $db->method('prepareQuery')
            ->willReturnCallback(function (string $sql, ...$args): string {
                if (isset($args[0])) {
                    return str_replace('%d', (string) $args[0], $sql);
                }

                return $sql;
            });

        $db->method('query')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql[] = $sql;

                if ($sql === 'SELECT * FROM `tree`') {
                    return $this->makeResult([
                        ['id' => 1, 'parent_id' => 0, 'title' => 'Root'],
                        ['id' => 2, 'parent_id' => 1, 'title' => 'Child'],
                        ['id' => 3, 'parent_id' => 2, 'title' => 'Leaf'],
                    ]);
                }

                if (str_contains($sql, "WHERE `id` = '1'")) {
                    return $this->makeResult([
                        ['id' => 1, 'parent_id' => 0, 'title' => 'Root'],
                    ]);
                }

                if (str_contains($sql, "WHERE `id` = '2'")) {
                    return $this->makeResult([
                        ['id' => 2, 'parent_id' => 1, 'title' => 'Child'],
                    ]);
                }

                return $this->makeResult([]);
            });

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');

        // Act
        $items = $adjacency->getArray();

        // Assert
        $this->assertSame('Root', $items[1]);
        $this->assertSame('Root » Child', $items[2]);
        // This proves multilevel path concatenation keeps ancestor order.
        $this->assertSame('Root » Child » Leaf', $items[3]);
        $this->assertNotEmpty($capturedSql);
    }

    /**
     * Ensures extraWhere is appended and leading WHERE keyword is stripped.
     *
     * This preserves current SQL assembly behavior even if formatting is odd.
     */
    public function testGetArrayAppendsExtraWhereWithWhereKeywordStripped(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();
        $executedSql = [];

        $db->method('prepareQuery')
            ->willReturnCallback(function (string $sql): string {
                return $sql;
            });

        $db->method('query')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = $sql;

                return $this->makeResult([]);
            });

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');
        $adjacency->extraWhere = 'WHERE `active` = 1';

        // Act
        $adjacency->getArray();

        // Assert
        $this->assertCount(1, $executedSql);
        // This proves the historical str_ireplace('where', '', extraWhere) behavior remains stable.
        $this->assertStringContainsString(' where  `active` = 1 ', $executedSql[0]);
    }

    /**
     * Ensures getPath returns the same full breadcrumb string as getArray(itemId).
     */
    public function testGetPathReturnsSingleResolvedPath(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();

        $db->method('prepareQuery')
            ->willReturnCallback(function (string $sql): string {
                return $sql;
            });

        $db->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, "where `id` = '3'")) {
                    return $this->makeResult([
                        ['id' => 3, 'parent_id' => 2, 'title' => 'Leaf'],
                    ]);
                }

                if (str_contains($sql, "WHERE `id` = '2'")) {
                    return $this->makeResult([
                        ['id' => 2, 'parent_id' => 1, 'title' => 'Child'],
                    ]);
                }

                if (str_contains($sql, "WHERE `id` = '1'")) {
                    return $this->makeResult([
                        ['id' => 1, 'parent_id' => 0, 'title' => 'Root'],
                    ]);
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

        $db->method('prepareQuery')
            ->willReturnCallback(function (string $sql): string {
                return $sql;
            });

        $db->method('query')
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
        // Arrange – mock DB returns a three-level tree: Root → Child → Leaf
        $db = $this->makeDatabaseMock();

        $db->method('prepareQuery')
            ->willReturnCallback(function (string $sql, ...$args): string {
                if (isset($args[0])) {
                    return str_replace('%d', (string) $args[0], $sql);
                }

                return $sql;
            });

        $db->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, '`id` = 3')) {
                    return $this->makePrefetchedResult(['id' => 3, 'parent_id' => 2, 'title' => 'Leaf']);
                }

                if (str_contains($sql, '`id` = 2')) {
                    return $this->makePrefetchedResult(['id' => 2, 'parent_id' => 1, 'title' => 'Child']);
                }

                if (str_contains($sql, '`id` = 1')) {
                    return $this->makePrefetchedResult(['id' => 1, 'parent_id' => 0, 'title' => 'Root']);
                }

                return $this->makePrefetchedResult([]);
            });

        $adjacency = new Adjacencylist($db, 'tree', 'id', 'parent_id', 'title');

        // Act – traverse up from Leaf (id=3)
        $path = $adjacency->getPathAsArray(3);

        // Assert – three items returned in root-first order
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
            ->onlyMethods(['prepareQuery', 'query'])
            ->getMock();

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
