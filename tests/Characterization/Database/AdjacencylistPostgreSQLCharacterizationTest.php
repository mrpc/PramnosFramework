<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Adjacencylist;
use Pramnos\Database\Database;

/**
 * Characterization tests for Adjacencylist against live PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors AdjacencylistMySQLCharacterizationTest exactly — same contracts, same
 * assertions. These tests exist to lock Adjacencylist behavior on PostgreSQL
 * after the QueryBuilder migration (which replaced MySQL-only backtick quoting
 * with dialect-aware identifier quoting).
 *
 * Table used: al_cats_pg (catid SERIAL PK, parent INT, name VARCHAR)
 *
 * Fixture data:
 *   1 | 0 | Root A
 *   2 | 1 | Child A1
 *   3 | 2 | Grandchild A1-1
 *   4 | 0 | Root B
 */
#[CoversClass(Adjacencylist::class)]
class AdjacencylistPostgreSQLCharacterizationTest extends TestCase
{
    private Database $db;
    private Adjacencylist $al;

    private const TABLE = 'al_cats_pg';

    protected function setUp(): void
    {
        // Arrange — connect to Docker PostgreSQL / TimescaleDB
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->connect(true);

        // Arrange — create test table and seed fixture data
        $this->db->query('DROP TABLE IF EXISTS "' . self::TABLE . '"');
        $this->db->query(
            'CREATE TABLE "' . self::TABLE . '" ('
            . '"catid" SERIAL PRIMARY KEY,'
            . '"parent" INT NOT NULL DEFAULT 0,'
            . '"name" VARCHAR(255) NOT NULL'
            . ')'
        );
        $this->db->query(
            "INSERT INTO \"" . self::TABLE . "\" (catid, parent, name) OVERRIDING SYSTEM VALUE VALUES"
            . " (1, 0, 'Root A'),"
            . " (2, 1, 'Child A1'),"
            . " (3, 2, 'Grandchild A1-1'),"
            . " (4, 0, 'Root B')"
        );
        // Advance the sequence past the explicit inserts
        $this->db->query(
            "SELECT setval(pg_get_serial_sequence('\"" . self::TABLE . "\"', 'catid'), 4)"
        );

        $this->al = new Adjacencylist($this->db, self::TABLE, 'catid', 'parent', 'name');
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "' . self::TABLE . '"');
        $this->db->close();
    }

    // -------------------------------------------------------------------------
    // getArray()
    // -------------------------------------------------------------------------

    /**
     * getArray() with no arguments must return all items. Root items get their
     * plain name; child items get their full ancestor path joined by " » ".
     *
     * This locks the path-building contract after the QB migration.
     */
    public function testGetArrayReturnsAllItemsWithFullPaths(): void
    {
        // Act
        $items = $this->al->getArray();

        // Assert — all 4 items are returned
        $this->assertCount(4, $items, 'getArray() must return all rows');

        // Assert — root items have plain names
        $this->assertSame('Root A', $items[1]);
        $this->assertSame('Root B', $items[4]);

        // Assert — child items carry the full ancestor path
        $this->assertSame('Root A » Child A1',              $items[2]);
        $this->assertSame('Root A » Child A1 » Grandchild A1-1', $items[3]);
    }

    /**
     * getArray($parent) must return only the direct children of the given parent.
     * Items from other subtrees must not appear. Child items still carry their
     * full ancestor path from root — the $parent filter restricts WHICH rows are
     * returned, not how the path is built.
     */
    public function testGetArrayFilteredByParentReturnsSubtree(): void
    {
        // Act — ask for direct children of item 1 (Root A)
        $items = $this->al->getArray(1);

        // Assert — only Child A1 is a direct child of Root A
        $this->assertCount(1, $items);
        $this->assertArrayHasKey(2, $items);
        // Full path from root is still included even when filtering by parent
        $this->assertSame('Root A » Child A1', $items[2]);
    }

    /**
     * getArray(null, $itemId) must return only the single item identified by
     * $itemId, with its full ancestor path.
     */
    public function testGetArrayByItemIdReturnsSingleItemWithPath(): void
    {
        // Act — fetch the grandchild directly
        $items = $this->al->getArray(null, 3);

        // Assert
        $this->assertCount(1, $items);
        $this->assertSame('Root A » Child A1 » Grandchild A1-1', $items[3]);
    }

    // -------------------------------------------------------------------------
    // getPath()
    // -------------------------------------------------------------------------

    /**
     * getPath() must return the full ancestor path string for any node.
     * For a root node this is just its name; for deeper nodes the full chain.
     */
    public function testGetPathReturnsConcatenatedPath(): void
    {
        // Assert — root node
        $this->assertSame('Root B', $this->al->getPath(4));

        // Assert — intermediate node
        $this->assertSame('Root A » Child A1', $this->al->getPath(2));

        // Assert — leaf node (deepest)
        $this->assertSame('Root A » Child A1 » Grandchild A1-1', $this->al->getPath(3));
    }

    /**
     * getPath() must return null when the requested item does not exist.
     */
    public function testGetPathReturnsNullForNonExistentItem(): void
    {
        // Act
        $result = $this->al->getPath(999);

        // Assert
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // getPathAsArray()
    // -------------------------------------------------------------------------

    /**
     * getPathAsArray() must return the full ancestor chain from root to the
     * requested node as an array of stdClass objects (one per ancestor + self).
     *
     * For the grandchild (id=3) the chain is: [Root A, Child A1, Grandchild A1-1].
     */
    public function testGetPathAsArrayReturnsAncestorChain(): void
    {
        // Act
        $path = $this->al->getPathAsArray(3);

        // Assert — three nodes in the chain
        $this->assertCount(3, $path, 'chain must contain root + child + grandchild');

        // Assert — correct order: root first, leaf last
        $this->assertSame('Root A',             $path[0]->name);
        $this->assertSame('Child A1',           $path[1]->name);
        $this->assertSame('Grandchild A1-1',    $path[2]->name);

        // Assert — each element is a plain stdClass (not Pramnos\Database\stdClass)
        foreach ($path as $node) {
            $this->assertInstanceOf(\stdClass::class, $node);
            $this->assertSame('stdClass', get_class($node),
                'path elements must be plain stdClass objects');
        }
    }

    /**
     * getPathAsArray() for a root node must return a single-element array.
     */
    public function testGetPathAsArrayForRootNodeReturnsSingleElement(): void
    {
        // Act
        $path = $this->al->getPathAsArray(4);

        // Assert
        $this->assertCount(1, $path);
        $this->assertSame('Root B', $path[0]->name);
    }
}
