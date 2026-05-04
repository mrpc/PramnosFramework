<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Adjacencylist;

/**
 * Formal record that Adjacencylist does NOT support PostgreSQL.
 *
 * Adjacencylist builds every query with MySQL-specific backtick quoting:
 *
 *   SELECT * FROM `table` WHERE `id` = 1
 *
 * PostgreSQL requires double-quoted identifiers or unquoted lowercase names.
 * Backticks are a syntax error in PG. As a result, every Adjacencylist method
 * that touches the database raises a PDO/pgsql exception on PostgreSQL.
 *
 * All tests in this class are skipped to document the known limitation.
 * The planned fix is tracked in ROADMAP_1.2.md under
 * "Phase 1 — Internal QB Migration": migrating Adjacencylist to use the
 * Pramnos QueryBuilder, which emits backend-correct identifier quoting.
 *
 * Once Phase 1 is complete, this file should be converted to a real
 * integration test suite mirroring AdjacencylistMySQLCharacterizationTest.
 */
#[CoversClass(Adjacencylist::class)]
class AdjacencylistPostgreSQLCharacterizationTest extends TestCase
{
    private const SKIP_REASON =
        'Adjacencylist uses MySQL-only backtick quoting (`table`, `column`) '
        . 'which is a syntax error in PostgreSQL. Support requires migrating '
        . 'Adjacencylist to the Pramnos QueryBuilder (ROADMAP_1.2.md Phase 1).';

    // -------------------------------------------------------------------------
    // getArray()
    // -------------------------------------------------------------------------

    /**
     * getArray() with no arguments must return all items with full ancestor
     * paths. Skipped because Adjacencylist uses MySQL-only backtick syntax.
     */
    public function testGetArrayReturnsAllItemsWithFullPaths(): void
    {
        $this->markTestSkipped(self::SKIP_REASON);
    }

    /**
     * getArray($parent) must return only the subtree rooted at the given
     * parent. Skipped because Adjacencylist uses MySQL-only backtick syntax.
     */
    public function testGetArrayFilteredByParentReturnsSubtree(): void
    {
        $this->markTestSkipped(self::SKIP_REASON);
    }

    /**
     * getArray(null, $itemId) must return the single item with its full
     * ancestor path. Skipped because Adjacencylist uses MySQL-only backtick
     * syntax.
     */
    public function testGetArrayByItemIdReturnsSingleItemWithPath(): void
    {
        $this->markTestSkipped(self::SKIP_REASON);
    }

    // -------------------------------------------------------------------------
    // getPath()
    // -------------------------------------------------------------------------

    /**
     * getPath() must return the full ancestor path string for any node.
     * Skipped because Adjacencylist uses MySQL-only backtick syntax.
     */
    public function testGetPathReturnsConcatenatedPath(): void
    {
        $this->markTestSkipped(self::SKIP_REASON);
    }

    /**
     * getPath() must return null for a non-existent item.
     * Skipped because Adjacencylist uses MySQL-only backtick syntax.
     */
    public function testGetPathReturnsNullForNonExistentItem(): void
    {
        $this->markTestSkipped(self::SKIP_REASON);
    }

    // -------------------------------------------------------------------------
    // getPathAsArray()
    // -------------------------------------------------------------------------

    /**
     * getPathAsArray() must return the full ancestor chain from root to the
     * requested node. Skipped because Adjacencylist uses MySQL-only backtick
     * syntax.
     */
    public function testGetPathAsArrayReturnsAncestorChain(): void
    {
        $this->markTestSkipped(self::SKIP_REASON);
    }

    /**
     * getPathAsArray() for a root node must return a single-element array.
     * Skipped because Adjacencylist uses MySQL-only backtick syntax.
     */
    public function testGetPathAsArrayForRootNodeReturnsSingleElement(): void
    {
        $this->markTestSkipped(self::SKIP_REASON);
    }
}
