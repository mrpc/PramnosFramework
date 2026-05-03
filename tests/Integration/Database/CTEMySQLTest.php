<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Integration tests for Common Table Expressions (CTEs) against MySQL 8.0.
 *
 * MySQL 8.0 introduced full support for both non-recursive and recursive CTEs
 * via the WITH / WITH RECURSIVE syntax. These tests verify that:
 *   - QueryBuilder::with() produces SQL that MySQL actually executes correctly.
 *   - QueryBuilder::withRecursive() produces recursive CTE SQL that MySQL can
 *     traverse tree/graph structures with.
 *   - The CTE result set is correct (not just that the query parses).
 *
 * Schema used:
 *   cte_employees  — flat table for non-recursive CTE tests
 *   cte_categories — self-referential parent_id tree for recursive CTE tests
 *
 * Requires the Docker MySQL container to be running (host: db, port: 3306).
 */
class CTEMySQLTest extends TestCase
{
    /** @var Database Live MySQL connection used by all tests in the class. */
    protected Database $db;

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Connect to the test MySQL instance running in Docker.
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        // Create a flat employee table for non-recursive CTE tests.
        $this->db->query("DROP TABLE IF EXISTS `cte_employees`");
        $this->db->query("CREATE TABLE `cte_employees` (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            department VARCHAR(50)  NOT NULL,
            salary     DECIMAL(10,2) NOT NULL
        )");

        // Create a self-referential categories table for recursive CTE tests.
        $this->db->query("DROP TABLE IF EXISTS `cte_categories`");
        $this->db->query("CREATE TABLE `cte_categories` (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            name      VARCHAR(100) NOT NULL,
            parent_id INT          DEFAULT NULL
        )");
    }

    protected function tearDown(): void
    {
        // Always clean up test tables so parallel or re-run test suites start fresh.
        $this->db->query("DROP TABLE IF EXISTS `cte_employees`");
        $this->db->query("DROP TABLE IF EXISTS `cte_categories`");
        $this->db->close();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Insert five employees across two departments.
     * Engineering: Alice (90k), Bob (80k), Charlie (70k)
     * Marketing:   Diana (60k), Eve (50k)
     */
    private function seedEmployees(): void
    {
        $this->db->query("INSERT INTO `cte_employees` (name, department, salary) VALUES
            ('Alice',   'Engineering', 90000.00),
            ('Bob',     'Engineering', 80000.00),
            ('Charlie', 'Engineering', 70000.00),
            ('Diana',   'Marketing',   60000.00),
            ('Eve',     'Marketing',   50000.00)
        ");
    }

    /**
     * Insert a simple 3-level category tree:
     *   Root (id=1, parent=NULL)
     *     └─ Child A (id=2, parent=1)
     *          └─ Grandchild A1 (id=3, parent=2)
     *     └─ Child B (id=4, parent=1)
     */
    private function seedCategories(): void
    {
        $this->db->query("INSERT INTO `cte_categories` (id, name, parent_id) VALUES
            (1, 'Root',         NULL),
            (2, 'Child A',      1),
            (3, 'Grandchild A1',2),
            (4, 'Child B',      1)
        ");
    }

    // =========================================================================
    // Non-recursive CTE
    // =========================================================================

    /**
     * Verify a simple CTE that filters a table and is then queried by the outer
     * SELECT. This is the most common use case: compute a named intermediate
     * result set and reference it multiple times without repeating the subquery.
     *
     * Expected: the CTE yields only Engineering employees, and the outer query
     * orders them by salary descending — highest earner first.
     */
    public function testSimpleCTEFiltersCorrectly(): void
    {
        // Arrange: populate the employee table.
        $this->seedEmployees();

        $qb = $this->db->queryBuilder();

        // Act: build a CTE "eng" that selects only Engineering employees,
        //      then query that CTE ordered by salary descending.
        $result = $qb
            ->with('eng', function (\Pramnos\Database\QueryBuilder $sub) {
                $sub->select('*')
                    ->from('cte_employees')
                    ->where('department', 'Engineering');
            })
            ->select('name', 'salary')
            ->from('eng')
            ->orderBy('salary', 'desc')
            ->get();

        // Assert: only 3 Engineering employees returned, highest salary first.
        $this->assertEquals(
            3,
            $result->numRows,
            'CTE should restrict result to Engineering department only'
        );

        $first = $result->fields;
        $this->assertEquals(
            'Alice',
            $first['name'],
            'Highest-paid Engineering employee (Alice) should be first row'
        );
    }

    /**
     * Verify that two CTEs declared in the same WITH clause are both usable
     * in the outer query via a JOIN.
     *
     * This tests multi-CTE chaining — a critical feature when building complex
     * analytical queries that need multiple named intermediate result sets.
     */
    public function testMultipleCTEsJoinable(): void
    {
        // Arrange: populate employees.
        $this->seedEmployees();

        $qb = $this->db->queryBuilder();

        // Act: CTE "avg_sal" computes per-department average salary;
        //      CTE "high_earners" selects employees above average.
        //      Outer query joins them to return names of above-average earners.
        $result = $qb
            ->with('avg_sal', 'SELECT department, AVG(salary) AS avg_salary FROM cte_employees GROUP BY department')
            ->with('high_earners', 'SELECT e.name, e.department, e.salary FROM cte_employees e JOIN avg_sal a ON e.department = a.department WHERE e.salary > a.avg_salary')
            ->select('name', 'department')
            ->from('high_earners')
            ->orderBy('name')
            ->get();

        // Assert: Alice (90k vs 80k avg) and Diana (60k vs 55k avg) are above average.
        $this->assertEquals(
            2,
            $result->numRows,
            'Exactly two employees earn above their department average'
        );
    }

    /**
     * Verify that a CTE built from a QueryBuilder sub-instance (rather than
     * a raw SQL string) produces the same result as the string form.
     *
     * This exercises the QB → compileSelect() → CTE SQL path.
     */
    public function testCTEFromQueryBuilderInstance(): void
    {
        // Arrange: populate employees.
        $this->seedEmployees();

        // Build the CTE sub-query as a separate QB instance.
        $sub = $this->db->queryBuilder()
            ->select('*')
            ->from('cte_employees')
            ->where('department', 'Marketing');

        $result = $this->db->queryBuilder()
            ->with('mkt', $sub)
            ->select('name')
            ->from('mkt')
            ->orderBy('salary', 'asc')
            ->get();

        // Assert: two Marketing employees returned, lowest salary first.
        $this->assertEquals(
            2,
            $result->numRows,
            'CTE built from QB instance should work identically to raw-string form'
        );
        $this->assertEquals(
            'Eve',
            $result->fields['name'],
            'Lowest-paid Marketing employee (Eve, 50k) should be first'
        );
    }

    // =========================================================================
    // Recursive CTE
    // =========================================================================

    /**
     * Verify a recursive CTE traverses an entire parent-child tree starting
     * from the root node and visiting all descendants.
     *
     * MySQL recursive CTEs require WITH RECURSIVE. The test confirms that:
     *   - The grammar emits "WITH RECURSIVE" (not just "WITH").
     *   - MySQL actually executes the recursion and returns all 4 nodes.
     */
    public function testRecursiveCTETraversesFullTree(): void
    {
        // Arrange: insert a 3-level category tree.
        $this->seedCategories();

        // Act: recursive CTE that starts at Root (parent_id IS NULL) and
        //      walks down through every child/grandchild level.
        $result = $this->db->queryBuilder()
            ->withRecursive(
                'tree',
                'SELECT id, name, parent_id FROM cte_categories WHERE parent_id IS NULL'
                . ' UNION ALL'
                . ' SELECT c.id, c.name, c.parent_id FROM cte_categories c'
                . ' INNER JOIN tree t ON c.parent_id = t.id'
            )
            ->select('id', 'name')
            ->from('tree')
            ->orderBy('id')
            ->get();

        // Assert: all 4 nodes (Root + 3 descendants) are returned.
        $this->assertEquals(
            4,
            $result->numRows,
            'Recursive CTE must visit all nodes in the tree (root + 3 children/grandchildren)'
        );
    }

    /**
     * Verify a recursive CTE can start from a specific leaf node and walk
     * *upward* to the root — i.e., the anchor is a leaf and the recursive
     * step follows parent_id until it reaches NULL.
     *
     * This is a common "breadcrumb" query pattern.
     */
    public function testRecursiveCTEWalksUpToRoot(): void
    {
        // Arrange: insert tree.
        $this->seedCategories();

        // Act: start from Grandchild A1 (id=3) and walk up through parents.
        $result = $this->db->queryBuilder()
            ->withRecursive(
                'ancestors',
                'SELECT id, name, parent_id FROM cte_categories WHERE id = 3'
                . ' UNION ALL'
                . ' SELECT c.id, c.name, c.parent_id FROM cte_categories c'
                . ' INNER JOIN ancestors a ON c.id = a.parent_id'
            )
            ->select('id', 'name')
            ->from('ancestors')
            ->orderBy('id')
            ->get();

        // Assert: Grandchild A1 (3) → Child A (2) → Root (1) = 3 rows.
        $this->assertEquals(
            3,
            $result->numRows,
            'Ancestor walk from Grandchild A1 must return 3 rows: itself, Child A, and Root'
        );
    }
}
