<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Integration tests for Common Table Expressions (CTEs) against PostgreSQL 14
 * (TimescaleDB container, which runs a superset of standard PostgreSQL).
 *
 * PostgreSQL has supported CTEs since version 8.4 and recursive CTEs since 8.4
 * as well. Unlike MySQL, PostgreSQL also supports data-modifying CTEs
 * (INSERT/UPDATE/DELETE inside a WITH clause), but those are not tested here —
 * this suite focuses on the SELECT path that matches what we tested on MySQL.
 *
 * Schema used:
 *   cte_pg_employees  — flat salary table for non-recursive CTE tests
 *   cte_pg_categories — self-referential parent_id tree for recursive CTE tests
 *
 * Requires the Docker TimescaleDB/PostgreSQL container to be running
 * (host: timescaledb, port: 5432).
 */
class CTEPostgreSQLTest extends TestCase
{
    /** @var Database Live PostgreSQL connection used by all tests in the class. */
    protected Database $db;

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Connect to the PostgreSQL/TimescaleDB test instance running in Docker.
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';
        $this->db->connect(true);

        // Create flat employee table for non-recursive CTE tests.
        $this->db->execute("DROP TABLE IF EXISTS cte_pg_employees");
        $this->db->execute("CREATE TABLE cte_pg_employees (
            id         SERIAL PRIMARY KEY,
            name       VARCHAR(100)   NOT NULL,
            department VARCHAR(50)    NOT NULL,
            salary     DECIMAL(10,2)  NOT NULL
        )");

        // Create self-referential category table for recursive CTE tests.
        $this->db->execute("DROP TABLE IF EXISTS cte_pg_categories");
        $this->db->execute("CREATE TABLE cte_pg_categories (
            id        SERIAL PRIMARY KEY,
            name      VARCHAR(100) NOT NULL,
            parent_id INTEGER      DEFAULT NULL
        )");
    }

    protected function tearDown(): void
    {
        // Clean up test tables after each test so the next run starts clean.
        $this->db->execute("DROP TABLE IF EXISTS cte_pg_employees");
        $this->db->execute("DROP TABLE IF EXISTS cte_pg_categories");
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
        $this->db->execute("INSERT INTO cte_pg_employees (name, department, salary) VALUES
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
        $this->db->execute("INSERT INTO cte_pg_categories (id, name, parent_id)
            OVERRIDING SYSTEM VALUE VALUES
            (1, 'Root',          NULL),
            (2, 'Child A',       1),
            (3, 'Grandchild A1', 2),
            (4, 'Child B',       1)
        ");
    }

    // =========================================================================
    // Non-recursive CTE
    // =========================================================================

    /**
     * Verify a simple CTE filters the underlying table and the outer query
     * reads from it correctly.
     *
     * This is the baseline "does it work at all" test for PostgreSQL CTEs.
     * If this fails, all other CTE tests would also fail, so it's intentionally
     * kept minimal.
     */
    public function testSimpleCTEFiltersCorrectly(): void
    {
        // Arrange: populate the employee table with 5 rows across 2 departments.
        $this->seedEmployees();

        $qb = $this->db->queryBuilder();

        // Act: CTE "eng" selects only Engineering employees.
        //      Outer query orders by salary descending.
        $result = $qb
            ->with('eng', function (\Pramnos\Database\QueryBuilder $sub) {
                $sub->select('*')
                    ->from('cte_pg_employees')
                    ->where('department', 'Engineering');
            })
            ->select('name', 'salary')
            ->from('eng')
            ->orderBy('salary', 'desc')
            ->get();

        // Assert: 3 Engineering employees, highest salary (Alice) first.
        $this->assertEquals(
            3,
            $result->numRows,
            'CTE should restrict result to the 3 Engineering department employees'
        );
        $this->assertEquals(
            'Alice',
            $result->fields['name'],
            'First row must be Alice, the highest-paid Engineering employee'
        );
    }

    /**
     * Verify a CTE built from a QueryBuilder sub-instance rather than a raw
     * SQL string works identically on PostgreSQL.
     *
     * This confirms the QB → PostgreSQLGrammar::compileSelect() → CTE path.
     */
    public function testCTEFromQueryBuilderInstance(): void
    {
        // Arrange: populate employees.
        $this->seedEmployees();

        // Build the CTE sub-query as a separate QB instance.
        $sub = $this->db->queryBuilder()
            ->select('*')
            ->from('cte_pg_employees')
            ->where('department', 'Marketing');

        $result = $this->db->queryBuilder()
            ->with('mkt', $sub)
            ->select('name')
            ->from('mkt')
            ->orderBy('salary', 'asc')
            ->get();

        // Assert: 2 Marketing employees; Eve (50k) comes before Diana (60k).
        $this->assertEquals(
            2,
            $result->numRows,
            'CTE sub-QB should yield both Marketing employees'
        );
        $this->assertEquals(
            'Eve',
            $result->fields['name'],
            'Lowest-salaried Marketing employee (Eve) should be first row'
        );
    }

    /**
     * Verify that multiple CTEs in the same WITH clause can JOIN each other
     * inside subsequent CTEs (chained CTEs).
     *
     * PostgreSQL allows later CTEs to reference earlier ones in the same WITH.
     * This tests that our grammar emits them in declaration order with commas.
     */
    public function testChainedCTEsReferenceEachOther(): void
    {
        // Arrange: populate employees.
        $this->seedEmployees();

        $result = $this->db->queryBuilder()
            // First CTE: per-department average salary.
            ->with('avg_sal', 'SELECT department, AVG(salary) AS avg_salary FROM cte_pg_employees GROUP BY department')
            // Second CTE: employees above their department average.
            ->with('high_earners', 'SELECT e.name, e.department FROM cte_pg_employees e JOIN avg_sal a ON e.department = a.department WHERE e.salary > a.avg_salary')
            ->select('name', 'department')
            ->from('high_earners')
            ->orderBy('name')
            ->get();

        // Assert: Alice (90k vs 80k avg) and Diana (60k vs 55k avg) qualify.
        $this->assertEquals(
            2,
            $result->numRows,
            'Two employees should be above their respective department averages'
        );
    }

    // =========================================================================
    // Recursive CTE
    // =========================================================================

    /**
     * Verify a recursive CTE walks an entire tree from the root node downward,
     * visiting all descendants.
     *
     * PostgreSQL requires WITH RECURSIVE for recursive CTEs. The grammar emits
     * WITH RECURSIVE when at least one CTE is marked recursive. This test
     * proves the full traversal actually happens in the database.
     */
    public function testRecursiveCTETraversesFullTree(): void
    {
        // Arrange: insert the 4-node category tree.
        $this->seedCategories();

        $result = $this->db->queryBuilder()
            ->withRecursive(
                'tree',
                // Anchor: start at the root node (no parent).
                'SELECT id, name, parent_id FROM cte_pg_categories WHERE parent_id IS NULL'
                . ' UNION ALL'
                // Recursive step: join children onto already-visited nodes.
                . ' SELECT c.id, c.name, c.parent_id FROM cte_pg_categories c'
                . ' INNER JOIN tree t ON c.parent_id = t.id'
            )
            ->select('id', 'name')
            ->from('tree')
            ->orderBy('id')
            ->get();

        // Assert: all 4 nodes returned in id order.
        $this->assertEquals(
            4,
            $result->numRows,
            'Recursive CTE must visit all 4 tree nodes starting from the root'
        );
    }

    /**
     * Verify that mixing a non-recursive and a recursive CTE in the same query
     * forces the grammar to emit WITH RECURSIVE (required by PostgreSQL when
     * *any* CTE in the list is recursive, even if others are not).
     */
    public function testMixedRecursiveAndNonRecursiveCTEsWork(): void
    {
        // Arrange: insert tree data.
        $this->seedCategories();

        $result = $this->db->queryBuilder()
            // Non-recursive CTE: all nodes with a parent (i.e., non-root nodes).
            ->with('non_roots', 'SELECT id, name, parent_id FROM cte_pg_categories WHERE parent_id IS NOT NULL')
            // Recursive CTE: walk down from Root.
            ->withRecursive(
                'tree',
                'SELECT id, name FROM cte_pg_categories WHERE parent_id IS NULL'
                . ' UNION ALL'
                . ' SELECT c.id, c.name FROM cte_pg_categories c'
                . ' INNER JOIN tree t ON c.parent_id = t.id'
            )
            ->select('t.id', 't.name')
            ->from('tree t')
            ->get();

        // Assert: all 4 tree nodes returned.
        $this->assertEquals(
            4,
            $result->numRows,
            'Mixed CTE query must still visit all 4 tree nodes'
        );
    }
}
