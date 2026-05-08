<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * QueryBuilder integration tests — PostgreSQL 14 / TimescaleDB.
 *
 * Covers all general QB features plus PostgreSQL-specific dialect behaviour:
 * - RETURNING clause on INSERT / UPDATE / DELETE
 * - ON CONFLICT DO NOTHING  (insertOrIgnore)
 * - ON CONFLICT DO UPDATE   (upsert)
 * - ILIKE operator (case-insensitive LIKE)
 * - Double-quote column quoting in INSERT/UPDATE
 * - $1/$2 positional parameters (handled transparently by Database::prepare)
 *
 * Schema:
 *   qb_products  (id SERIAL, name, category, price, stock, active, notes)
 *   qb_tags      (id SERIAL, product_id, tag)
 */
class QueryBuilderPostgreSQLTest extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';
        $this->db->connect(true);

        $this->db->execute("DROP TABLE IF EXISTS qb_tags");
        $this->db->execute("DROP TABLE IF EXISTS qb_products");
        $this->db->execute("DROP TABLE IF EXISTS qb_events");
        $this->db->execute("CREATE TABLE qb_products (
            id       SERIAL PRIMARY KEY,
            name     VARCHAR(255)   NOT NULL,
            category VARCHAR(50)    DEFAULT NULL,
            price    DECIMAL(10,2)  DEFAULT 0.00,
            stock    INTEGER        DEFAULT 0,
            active   BOOLEAN        DEFAULT true,
            notes    TEXT           DEFAULT NULL
        )");
        $this->db->execute("CREATE TABLE qb_tags (
            id         SERIAL PRIMARY KEY,
            product_id INTEGER    NOT NULL,
            tag        VARCHAR(50) NOT NULL
        )");
        $this->db->execute("CREATE TABLE qb_events (
            id         SERIAL PRIMARY KEY,
            name       VARCHAR(100)  NOT NULL,
            event_time TIMESTAMPTZ   NOT NULL
        )");
    }

    protected function tearDown(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_tags");
        $this->db->execute("DROP TABLE IF EXISTS qb_products");
        $this->db->execute("DROP TABLE IF EXISTS qb_events");
        $this->db->close();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedProducts(): void
    {
        $this->db->execute("INSERT INTO qb_products (name, category, price, stock, active, notes) VALUES
            ('Apple',      'fruit',  1.20, 100, true,  'fresh'),
            ('Banana',     'fruit',  0.50, 200, true,  NULL),
            ('Carrot',     'veggie', 0.80,  50, true,  'organic'),
            ('Daikon',     'veggie', 1.50,   0, false, NULL),
            ('Elderberry', 'fruit',  3.00,  10, true,  'seasonal')
        ");
    }

    private function seedTags(): void
    {
        $this->db->execute("INSERT INTO qb_tags (product_id, tag) VALUES
            (1, 'popular'), (1, 'sweet'),
            (3, 'healthy'), (3, 'organic'),
            (5, 'rare')
        ");
    }

    // -------------------------------------------------------------------------
    // Basic SELECT
    // -------------------------------------------------------------------------

    public function testSelectAll(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()->from('qb_products')->get();
        $this->assertEquals(5, $result->numRows);
    }

    public function testDistinct(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->select('category')
            ->distinct()
            ->from('qb_products')
            ->orderBy('category')
            ->get();

        $this->assertEquals(2, $result->numRows);
    }

    // -------------------------------------------------------------------------
    // first()
    // -------------------------------------------------------------------------

    public function testFirstReturnsResult(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('price')
            ->first();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Banana', $result->fields['name']);
    }

    // -------------------------------------------------------------------------
    // WHERE conditions
    // -------------------------------------------------------------------------

    public function testWhereEquals(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('category', 'fruit')
            ->get();
        $this->assertEquals(3, $result->numRows);
    }

    public function testWhereNull(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereNull('notes')
            ->get();
        $this->assertEquals(2, $result->numRows);
    }

    public function testWhereNotNull(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereNotNull('notes')
            ->get();
        $this->assertEquals(3, $result->numRows);
    }

    public function testWhereBetween(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereBetween('price', [0.70, 1.60])
            ->get();
        $this->assertEquals(3, $result->numRows); // Apple, Carrot, Daikon
    }

    public function testWhereNotBetween(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereNotBetween('price', [0.70, 1.60])
            ->get();
        $this->assertEquals(2, $result->numRows); // Banana, Elderberry
    }

    public function testWhereIn(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereIn('name', ['Apple', 'Banana'])
            ->get();
        $this->assertEquals(2, $result->numRows);
    }

    public function testNestedWhere(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where(function ($q) {
                $q->where('category', 'fruit')->where('price', '<', 1.00);
            })
            ->orWhere('category', 'veggie')
            ->get();
        $this->assertEquals(3, $result->numRows); // Banana, Carrot, Daikon
    }

    public function testWhereRaw(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereRaw('price * stock > %i', [100])
            ->get();
        // Apple: 1.20*100=120 → only Apple
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Apple', $result->fields['name']);
    }

    /**
     * PostgreSQL-specific: ILIKE for case-insensitive LIKE.
     * The QB casts the column to ::text to avoid "operator does not exist: varchar ilike unknown".
     */
    public function testILike(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'ILIKE', 'a%')
            ->get();
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Apple', $result->fields['name']);
    }

    public function testLikeWithPercent(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('notes', 'LIKE', '%anic')
            ->get();
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Carrot', $result->fields['name']);
    }

    // -------------------------------------------------------------------------
    // JOINs
    // -------------------------------------------------------------------------

    public function testInnerJoin(): void
    {
        $this->seedProducts();
        $this->seedTags();

        $result = $this->db->queryBuilder()
            ->select('p.name', 't.tag')
            ->from('qb_products p')
            ->join('qb_tags t', 't.product_id', '=', 'p.id')
            ->orderBy('p.name')
            ->get();

        $this->assertEquals(5, $result->numRows);
    }

    public function testLeftJoin(): void
    {
        $this->seedProducts();
        $this->seedTags();

        $result = $this->db->queryBuilder()
            ->select('p.name', 't.tag')
            ->from('qb_products p')
            ->leftJoin('qb_tags t', 't.product_id', '=', 'p.id')
            ->get();

        // 5 matched rows + 2 unmatched (Banana, Daikon with NULL tag) = 7
        $this->assertEquals(7, $result->numRows);
    }

    // -------------------------------------------------------------------------
    // GROUP BY / HAVING
    // -------------------------------------------------------------------------

    public function testGroupByHaving(): void
    {
        $this->seedProducts();
        // PostgreSQL does not allow column aliases in HAVING; use the aggregate directly.
        $result = $this->db->queryBuilder()
            ->select('category', $this->db->queryBuilder()->raw('COUNT(*) as cnt'))
            ->from('qb_products')
            ->groupBy('category')
            ->havingRaw('COUNT(*) >= %i', [3])
            ->get();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('fruit', $result->fields['category']);
    }

    // -------------------------------------------------------------------------
    // ORDER BY / LIMIT / OFFSET
    // -------------------------------------------------------------------------

    public function testOrderByLimitOffset(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('price')
            ->limit(2)
            ->offset(1)
            ->get();

        $this->assertEquals(2, $result->numRows);
        // After Banana(0.50), next is Carrot(0.80) and Apple(1.20)
        $rows = $result->fetchAll();
        $this->assertEquals('Carrot', $rows[0]['name']);
    }

    public function testClearOrderingAndPaging(): void
    {
        $this->seedProducts();
        $qb = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('active', true)
            ->orderBy('price')
            ->limit(2);

        $countQb = clone $qb;
        $countQb->select($this->db->queryBuilder()->raw('COUNT(*) as n'))
                ->clearOrderingAndPaging();

        $total = (int)($countQb->first()->fields['n'] ?? 0);
        $this->assertEquals(4, $total);
    }

    // -------------------------------------------------------------------------
    // UNION / UNION ALL
    // -------------------------------------------------------------------------

    public function testUnion(): void
    {
        $this->seedProducts();
        $fruits = $this->db->queryBuilder()
            ->select('name', 'category')->from('qb_products')->where('category', 'fruit');
        $veggies = $this->db->queryBuilder()
            ->select('name', 'category')->from('qb_products')->where('category', 'veggie');

        $result = $fruits->union($veggies)->get();
        $this->assertEquals(5, $result->numRows);
    }

    public function testUnionDeduplicates(): void
    {
        $this->seedProducts();
        $q1 = $this->db->queryBuilder()->select('category')->from('qb_products')->where('category', 'fruit');
        $q2 = $this->db->queryBuilder()->select('category')->from('qb_products')->where('category', 'fruit');

        $result = $q1->union($q2)->get();
        $this->assertEquals(1, $result->numRows);
    }

    public function testUnionAll(): void
    {
        $this->seedProducts();
        $q1 = $this->db->queryBuilder()->select('category')->from('qb_products')->where('category', 'fruit');
        $q2 = $this->db->queryBuilder()->select('category')->from('qb_products')->where('category', 'fruit');

        // UNION ALL keeps all rows (3 fruit rows × 2 queries = 6)
        $result = $q1->unionAll($q2)->get();
        $this->assertEquals(6, $result->numRows);
    }

    // -------------------------------------------------------------------------
    // TRUNCATE
    // -------------------------------------------------------------------------

    public function testTruncate(): void
    {
        $this->seedProducts();
        $this->db->queryBuilder()->from('qb_products')->truncate();

        $result = $this->db->queryBuilder()->from('qb_products')->get();
        $this->assertEquals(0, $result->numRows);
    }

    // -------------------------------------------------------------------------
    // RETURNING clause (PostgreSQL-specific)
    // -------------------------------------------------------------------------

    public function testReturningOnInsert(): void
    {
        $result = $this->db->queryBuilder()
            ->table('qb_products')
            ->returning('id', 'name')
            ->insert(['name' => 'Fig', 'category' => 'fruit', 'price' => 2.50, 'stock' => 30]);

        $this->assertEquals(1, $result->numRows);
        $this->assertGreaterThan(0, (int)$result->fields['id']);
        $this->assertEquals('Fig', $result->fields['name']);
    }

    public function testReturningOnUpdate(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->table('qb_products')
            ->where('name', 'Apple')
            ->returning('id', 'stock')
            ->update(['stock' => 999]);

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(999, (int)$result->fields['stock']);
    }

    public function testReturningOnDelete(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('active', false)
            ->returning('name')
            ->delete();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Daikon', $result->fields['name']);
    }

    public function testReturningIdAfterInsertChain(): void
    {
        // Insert two rows and collect their generated IDs
        $r1 = $this->db->queryBuilder()
            ->table('qb_products')
            ->returning('id')
            ->insert(['name' => 'Grape', 'category' => 'fruit', 'price' => 1.80, 'stock' => 80]);

        $r2 = $this->db->queryBuilder()
            ->table('qb_products')
            ->returning('id')
            ->insert(['name' => 'Hawthorn', 'category' => 'fruit', 'price' => 4.00, 'stock' => 5]);

        $id1 = (int)$r1->fields['id'];
        $id2 = (int)$r2->fields['id'];

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan($id1, $id2); // sequential IDs
    }

    // -------------------------------------------------------------------------
    // insertOrIgnore — ON CONFLICT DO NOTHING
    // -------------------------------------------------------------------------

    public function testInsertOrIgnore(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_unique_products");
        $this->db->execute("CREATE TABLE qb_unique_products (
            id   SERIAL PRIMARY KEY,
            sku  VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255)
        )");

        $this->db->queryBuilder()
            ->table('qb_unique_products')
            ->insertOrIgnore(['sku' => 'APPLE-001', 'name' => 'Apple']);

        // Duplicate SKU — ON CONFLICT DO NOTHING
        $this->db->queryBuilder()
            ->table('qb_unique_products')
            ->insertOrIgnore(['sku' => 'APPLE-001', 'name' => 'Apple v2']);

        $result = $this->db->execute("SELECT * FROM qb_unique_products");
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Apple', $result->fields['name']);

        $this->db->execute("DROP TABLE IF EXISTS qb_unique_products");
    }

    public function testInsertOrIgnoreWithReturning(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_unique_products");
        $this->db->execute("CREATE TABLE qb_unique_products (
            id   SERIAL PRIMARY KEY,
            sku  VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255)
        )");

        $result = $this->db->queryBuilder()
            ->table('qb_unique_products')
            ->returning('id', 'sku')
            ->insertOrIgnore(['sku' => 'B-001', 'name' => 'Berry']);

        // First insert returns the new row
        $this->assertEquals(1, $result->numRows);
        $this->assertGreaterThan(0, (int)$result->fields['id']);

        $this->db->execute("DROP TABLE IF EXISTS qb_unique_products");
    }

    // -------------------------------------------------------------------------
    // upsert — ON CONFLICT DO UPDATE
    // -------------------------------------------------------------------------

    public function testUpsertInsertsNewRow(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_inventory");
        $this->db->execute("CREATE TABLE qb_inventory (
            id    SERIAL PRIMARY KEY,
            sku   VARCHAR(50) NOT NULL UNIQUE,
            qty   INTEGER DEFAULT 0,
            price DECIMAL(10,2)
        )");

        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-A', 'qty' => 10, 'price' => 5.00], ['sku'], ['qty', 'price']);

        $result = $this->db->execute("SELECT * FROM qb_inventory WHERE sku = 'ITEM-A'");
        $this->assertEquals(10, (int)$result->fields['qty']);
        $this->assertEquals(5.00, (float)$result->fields['price']);

        $this->db->execute("DROP TABLE IF EXISTS qb_inventory");
    }

    public function testUpsertUpdatesOnConflict(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_inventory");
        $this->db->execute("CREATE TABLE qb_inventory (
            id    SERIAL PRIMARY KEY,
            sku   VARCHAR(50) NOT NULL UNIQUE,
            qty   INTEGER DEFAULT 0,
            price DECIMAL(10,2)
        )");

        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-B', 'qty' => 5, 'price' => 2.00], ['sku'], ['qty', 'price']);

        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-B', 'qty' => 99, 'price' => 3.50], ['sku'], ['qty', 'price']);

        $result = $this->db->execute("SELECT * FROM qb_inventory WHERE sku = 'ITEM-B'");
        $this->assertEquals(99, (int)$result->fields['qty']);
        $this->assertEquals(3.50, (float)$result->fields['price']);

        $count = $this->db->execute("SELECT COUNT(*) as n FROM qb_inventory")->fields;
        $this->assertEquals(1, (int)$count['n']);

        $this->db->execute("DROP TABLE IF EXISTS qb_inventory");
    }

    public function testUpsertWithReturning(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_inventory");
        $this->db->execute("CREATE TABLE qb_inventory (
            id    SERIAL PRIMARY KEY,
            sku   VARCHAR(50) NOT NULL UNIQUE,
            qty   INTEGER DEFAULT 0
        )");

        $result = $this->db->queryBuilder()
            ->table('qb_inventory')
            ->returning('id', 'qty')
            ->upsert(['sku' => 'ITEM-C', 'qty' => 7], ['sku'], ['qty']);

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(7, (int)$result->fields['qty']);

        // Upsert again — RETURNING should give updated qty
        $result2 = $this->db->queryBuilder()
            ->table('qb_inventory')
            ->returning('id', 'qty')
            ->upsert(['sku' => 'ITEM-C', 'qty' => 42], ['sku'], ['qty']);

        $this->assertEquals(42, (int)$result2->fields['qty']);
        $this->assertEquals($result->fields['id'], $result2->fields['id']); // same row

        $this->db->execute("DROP TABLE IF EXISTS qb_inventory");
    }

    // -------------------------------------------------------------------------
    // fetchAll() and cursor iteration
    // -------------------------------------------------------------------------

    public function testFetchAll(): void
    {
        $this->seedProducts();
        $rows = $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('id')
            ->get()
            ->fetchAll();

        $this->assertIsArray($rows);
        $this->assertCount(5, $rows);
        $this->assertEquals('Apple', $rows[0]['name']);
    }

    public function testFetchNextIteration(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('price')
            ->get();

        $names = [];
        while ($result->fetch()) {
            $names[] = $result->fields['name'];
        }

        $this->assertCount(5, $names);
        $this->assertEquals('Banana', $names[0]);
        $this->assertTrue($result->eof);
    }

    public function testSingleRowFetchNext(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->get();

        $this->assertNotNull($result->fetch());
        $this->assertEquals('Apple', $result->fields['name']);
        $this->assertNull($result->fetch()); // eof after single row
        $this->assertTrue($result->eof);
    }

    // -------------------------------------------------------------------------
    // Grammar: upsert DO NOTHING on conflict (PostgreSQLGrammar line 75)
    // -------------------------------------------------------------------------

    public function testUpsertWithEmptyUpdateColumnsActsAsInsertIgnore(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_pg_ignore");
        $this->db->execute("CREATE TABLE qb_pg_ignore (
            id    SERIAL PRIMARY KEY,
            email VARCHAR(100) UNIQUE NOT NULL,
            name  VARCHAR(100)
        )");

        $this->db->queryBuilder()
            ->table('qb_pg_ignore')
            ->upsert(['email' => 'x@test.com', 'name' => 'First'], ['email'], []);

        // Second call with same email and empty updateValues → ON CONFLICT DO NOTHING
        $this->db->queryBuilder()
            ->table('qb_pg_ignore')
            ->upsert(['email' => 'x@test.com', 'name' => 'Second'], ['email'], []);

        $row = $this->db->execute("SELECT * FROM qb_pg_ignore WHERE email = 'x@test.com'")->fields;
        $this->assertEquals('First', $row['name']); // original preserved

        $this->db->execute("DROP TABLE IF EXISTS qb_pg_ignore");
    }

    // -------------------------------------------------------------------------
    // Result utility methods — PostgreSQL paths
    // -------------------------------------------------------------------------

    public function testResultGetInsertId(): void
    {
        $result = $this->db->execute(
            "INSERT INTO qb_products (name, category, price, stock) VALUES ('PgInsertIdTest', 'fruit', 1.00, 1)"
        );

        // For PostgreSQL, pg_last_oid() is used; modern PG may return 0 — just verify it doesn't throw
        $id = $result->getInsertId();
        $this->assertTrue($id >= 0);
    }

    public function testResultGetAffectedRows(): void
    {
        $this->seedProducts();

        $result = $this->db->execute(
            "UPDATE qb_products SET stock = 999 WHERE category = 'fruit'"
        );

        $this->assertEquals(3, $result->getAffectedRows());
    }

    public function testResultGetNumFields(): void
    {
        $this->seedProducts();
        $result = $this->db->execute("SELECT id, name, category FROM qb_products LIMIT 1");

        $this->assertEquals(3, $result->getNumFields());
    }

    public function testResultFreeDoesNotThrow(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()->from('qb_products')->get();

        // free() on a PgSql\Result should not throw
        $result->free();
        $this->assertTrue(true);
    }

    public function testFetchSkipDataFixBypassesTypeConversion(): void
    {
        // skipDataFix=true causes fetch() to assign values without type conversion,
        // covering the else branch at Result.php line 255
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->get();

        $row = $result->fetch(true); // skipDataFix = true
        $this->assertIsArray($row);
        $this->assertEquals('Apple', $row['name']);
        // With skipDataFix=true, numeric columns come back as strings
        $this->assertIsString($row['stock']);
    }

    // =========================================================================
    // rightJoin() / crossJoin()
    // =========================================================================

    /**
     * rightJoin() must execute a valid RIGHT JOIN and return tag-driven rows.
     * All 5 tag rows must appear; product columns are NULL for unmatched products.
     */
    public function testRightJoinExecutesWithoutError(): void
    {
        // Arrange
        $this->seedProducts();
        $this->seedTags();

        // Act — RIGHT JOIN drives from qb_tags side
        $result = $this->db->queryBuilder()
            ->select(['qb_products.name', 'qb_tags.tag'])
            ->from('qb_products')
            ->rightJoin('qb_tags', 'qb_products.id', '=', 'qb_tags.product_id')
            ->get();

        // Assert — all 5 tag rows appear
        $this->assertEquals(5, $result->numRows);
    }

    /**
     * crossJoin() must produce the Cartesian product — every row from left
     * combined with every row from right.  5 products × 2 tags = 10 rows.
     */
    public function testCrossJoinProducesCartesianProduct(): void
    {
        // Arrange
        $this->seedProducts();
        $this->db->execute("INSERT INTO qb_tags (product_id, tag) VALUES (1, 'a'), (2, 'b')");

        // Act — CROSS JOIN of 5 products × 2 tags = 10 rows
        $result = $this->db->queryBuilder()
            ->select(['qb_products.name', 'qb_tags.tag'])
            ->from('qb_products')
            ->crossJoin('qb_tags')
            ->get();

        // Assert
        $this->assertEquals(10, $result->numRows);
    }

    // =========================================================================
    // latest() / oldest() / forPage()
    // =========================================================================

    /**
     * latest() must order results by the specified column descending.
     * Most expensive product is Elderberry at 3.00.
     */
    public function testLatestOrdersByColumnDesc(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — latest by price → most expensive first (Elderberry 3.00)
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->from('qb_products')
            ->latest('price')
            ->first();

        // Assert
        $this->assertEquals('Elderberry', $result->fields['name']);
    }

    /**
     * oldest() must order results by the specified column ascending.
     * Cheapest product is Banana at 0.50.
     */
    public function testOldestOrdersByColumnAsc(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — oldest by price → cheapest first (Banana 0.50)
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->from('qb_products')
            ->oldest('price')
            ->first();

        // Assert
        $this->assertEquals('Banana', $result->fields['name']);
    }

    /**
     * forPage() must set LIMIT and OFFSET so page 2 at 2-per-page returns rows 3+4.
     */
    public function testForPageReturnsCorrectSlice(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — ordered by id; page 2, 2 per page → rows 3+4 (Carrot, Daikon)
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->from('qb_products')
            ->orderBy('id')
            ->forPage(2, 2)
            ->get();

        $rows = $result->fetchAll();

        // Assert
        $this->assertCount(2, $rows);
        $this->assertEquals('Carrot', $rows[0]['name']);
        $this->assertEquals('Daikon', $rows[1]['name']);
    }

    // =========================================================================
    // sum() / avg() / min() / max()
    // =========================================================================

    /**
     * sum() must return the total of the price column.
     * 1.20 + 0.50 + 0.80 + 1.50 + 3.00 = 7.00
     */
    public function testSumReturnsCorrectTotal(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $total = $this->db->queryBuilder()->from('qb_products')->sum('price');

        // Assert
        $this->assertEqualsWithDelta(7.00, $total, 0.001);
    }

    /**
     * avg() must return the arithmetic mean of the price column.
     * 7.00 / 5 = 1.40
     */
    public function testAvgReturnsCorrectMean(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $mean = $this->db->queryBuilder()->from('qb_products')->avg('price');

        // Assert
        $this->assertEqualsWithDelta(1.40, $mean, 0.001);
    }

    /**
     * min() must return the minimum price.
     * Cheapest product is Banana at 0.50.
     */
    public function testMinReturnsSmallestValue(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $min = $this->db->queryBuilder()->from('qb_products')->min('price');

        // Assert
        $this->assertEqualsWithDelta(0.50, (float)$min, 0.001);
    }

    /**
     * max() must return the maximum price.
     * Most expensive product is Elderberry at 3.00.
     */
    public function testMaxReturnsLargestValue(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $max = $this->db->queryBuilder()->from('qb_products')->max('price');

        // Assert
        $this->assertEqualsWithDelta(3.00, (float)$max, 0.001);
    }

    /**
     * Aggregate methods must respect WHERE clauses.
     * Active products: Apple 1.20, Banana 0.50, Carrot 0.80, Elderberry 3.00 → sum 5.50.
     * PostgreSQL uses BOOLEAN true/false (not 1/0).
     */
    public function testSumRespectsWhereClause(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — PostgreSQL active column is BOOLEAN, compare with true
        $total = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('active', true)
            ->sum('price');

        // Assert
        $this->assertEqualsWithDelta(5.50, $total, 0.001);
    }

    // =========================================================================
    // exists() / doesntExist()
    // =========================================================================

    /**
     * exists() must return true when at least one row matches the conditions.
     */
    public function testExistsReturnsTrueWhenRowFound(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $found = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->exists();

        // Assert
        $this->assertTrue($found);
    }

    /**
     * exists() must return false when no rows match the conditions.
     */
    public function testExistsReturnsFalseWhenNoRowFound(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $found = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Zucchini')
            ->exists();

        // Assert
        $this->assertFalse($found);
    }

    /**
     * doesntExist() is the inverse of exists() — true when no rows match.
     */
    public function testDoesntExistReturnsTrueWhenNoRowFound(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $absent = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Zucchini')
            ->doesntExist();

        // Assert
        $this->assertTrue($absent);
    }

    // =========================================================================
    // value() / pluck()
    // =========================================================================

    /**
     * value() must execute with LIMIT 1 and return the value of the requested column.
     */
    public function testValueReturnsSingleColumnValue(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — cheapest product by price, get its name
        $name = $this->db->queryBuilder()
            ->from('qb_products')
            ->oldest('price')
            ->value('name');

        // Assert
        $this->assertEquals('Banana', $name);
    }

    /**
     * value() must return null when no rows match the conditions.
     */
    public function testValueReturnsNullWhenNoMatch(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $val = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Nonexistent')
            ->value('name');

        // Assert
        $this->assertNull($val);
    }

    /**
     * pluck() must return a flat array of one column's values across all matching rows.
     */
    public function testPluckReturnsFlatArray(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — names of fruit products ordered by price
        $names = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('category', 'fruit')
            ->orderBy('price')
            ->pluck('name');

        // Assert — Banana (0.50), Apple (1.20), Elderberry (3.00)
        $this->assertEquals(['Banana', 'Apple', 'Elderberry'], $names);
    }

    /**
     * pluck() must return an empty array when no rows match.
     */
    public function testPluckReturnsEmptyArrayWhenNoMatch(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $names = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('category', 'grain')
            ->pluck('name');

        // Assert
        $this->assertSame([], $names);
    }

    // =========================================================================
    // increment() / decrement()
    // =========================================================================

    /**
     * increment() must add the step to the column and return the number of affected rows.
     * Apple starts at stock=100; after increment(5) it should be 105.
     * PostgreSQL uses pg_affected_rows() (not the MySQL prepared-statement path).
     */
    public function testIncrementUpdatesColumnByStep(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $affected = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->increment('stock', 5);

        // Assert — 1 row updated
        $this->assertEquals(1, $affected);

        // Verify in DB
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->value('stock');
        $this->assertEquals(105, (int)$result);
    }

    /**
     * decrement() must subtract the step from the column.
     * Banana starts at stock=200; after decrement(50) it should be 150.
     */
    public function testDecrementUpdatesColumnByStep(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $affected = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Banana')
            ->decrement('stock', 50);

        // Assert
        $this->assertEquals(1, $affected);

        // Verify in DB
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Banana')
            ->value('stock');
        $this->assertEquals(150, (int)$result);
    }

    /**
     * Default step for increment() / decrement() is 1.
     * Carrot starts at stock=50; after increment() with no step it should be 51.
     */
    public function testIncrementDefaultStepIsOne(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Carrot')
            ->increment('stock');

        // Assert — stock was 50, now 51
        $stock = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Carrot')
            ->value('stock');
        $this->assertEquals(51, (int)$stock);
    }

    // =========================================================================
    // chunk()
    // =========================================================================

    /**
     * chunk() must process all rows in batches of the given size.
     * With 5 products and chunk size 2: chunks of [2, 2, 1] rows.
     */
    public function testChunkProcessesAllRowsInBatches(): void
    {
        // Arrange
        $this->seedProducts();
        $collected = [];
        $pagesVisited = [];

        // Act
        $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('id')
            ->chunk(2, function (array $rows, int $page) use (&$collected, &$pagesVisited) {
                $pagesVisited[] = $page;
                foreach ($rows as $row) {
                    $collected[] = $row['name'];
                }
            });

        // Assert — all 5 products visited across 3 pages
        $this->assertCount(5, $collected);
        $this->assertEquals([1, 2, 3], $pagesVisited);
        $this->assertEquals(['Apple', 'Banana', 'Carrot', 'Daikon', 'Elderberry'], $collected);
    }

    /**
     * chunk() must stop processing when the callback returns false.
     * Only the first chunk (2 rows) should be collected.
     */
    public function testChunkStopsEarlyWhenCallbackReturnsFalse(): void
    {
        // Arrange
        $this->seedProducts();
        $collected = [];

        // Act — stop after first chunk
        $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('id')
            ->chunk(2, function (array $rows) use (&$collected) {
                foreach ($rows as $row) {
                    $collected[] = $row['name'];
                }
                return false; // Stop after first chunk
            });

        // Assert — only the first 2 rows processed
        $this->assertCount(2, $collected);
        $this->assertEquals(['Apple', 'Banana'], $collected);
    }

    // =========================================================================
    // lockForUpdate() / sharedLock()
    // =========================================================================

    /**
     * lockForUpdate() must execute without error inside a PostgreSQL transaction.
     * PostgreSQL syntax is FOR UPDATE (same as MySQL); uses BEGIN/COMMIT.
     */
    public function testLockForUpdateExecutesWithinTransaction(): void
    {
        // Arrange
        $this->seedProducts();
        $this->db->execute('BEGIN');

        // Act
        $result = $this->db->queryBuilder()
            ->select(['name', 'stock'])
            ->from('qb_products')
            ->where('name', 'Apple')
            ->lockForUpdate()
            ->get();

        $this->db->execute('COMMIT');

        // Assert — row returned correctly with lock
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Apple', $result->fields['name']);
    }

    /**
     * sharedLock() must execute without error inside a transaction.
     * PostgreSQL compiles sharedLock() to FOR SHARE (not LOCK IN SHARE MODE).
     */
    public function testSharedLockExecutesWithinTransaction(): void
    {
        // Arrange
        $this->seedProducts();
        $this->db->execute('BEGIN');

        // Act — active is BOOLEAN in PostgreSQL, compare with true
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->from('qb_products')
            ->where('active', true)
            ->sharedLock()
            ->get();

        $this->db->execute('COMMIT');

        // Assert — 4 active products returned
        $this->assertEquals(4, $result->numRows);
    }

    // =========================================================================
    // whereExists() / whereNotExists()
    // =========================================================================

    /**
     * whereExists() must return only rows for which the sub-query finds a match.
     * Products that have at least one tag → Apple, Carrot, Elderberry (3 products).
     */
    public function testWhereExistsFiltersToMatchingRows(): void
    {
        // Arrange
        $this->seedProducts();
        $this->seedTags();

        // Act — products with at least one tag
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->from('qb_products')
            ->whereExists(function (\Pramnos\Database\QueryBuilder $sub) {
                $sub->select(['1'])
                    ->from('qb_tags')
                    ->whereRaw('qb_tags.product_id = qb_products.id');
            })
            ->orderBy('id')
            ->get();

        $rows = $result->fetchAll();

        // Assert — only products with tags
        $this->assertCount(3, $rows);
        $this->assertEquals('Apple',      $rows[0]['name']);
        $this->assertEquals('Carrot',     $rows[1]['name']);
        $this->assertEquals('Elderberry', $rows[2]['name']);
    }

    /**
     * whereNotExists() must return only rows for which the sub-query finds no match.
     * Products without tags → Banana, Daikon (2 products).
     */
    public function testWhereNotExistsFiltersToNonMatchingRows(): void
    {
        // Arrange
        $this->seedProducts();
        $this->seedTags();

        // Act — products without any tag
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->from('qb_products')
            ->whereNotExists(function (\Pramnos\Database\QueryBuilder $sub) {
                $sub->select(['1'])
                    ->from('qb_tags')
                    ->whereRaw('qb_tags.product_id = qb_products.id');
            })
            ->orderBy('id')
            ->get();

        $rows = $result->fetchAll();

        // Assert — only untagged products
        $this->assertCount(2, $rows);
        $this->assertEquals('Banana', $rows[0]['name']);
        $this->assertEquals('Daikon', $rows[1]['name']);
    }

    // =========================================================================
    // whereDate() / whereYear() / whereMonth() / whereDay() / whereTime()
    // =========================================================================

    /**
     * Helper: seed qb_events with known TIMESTAMPTZ values.
     * PostgreSQL accepts ISO-8601 literals with no special quoting.
     */
    private function seedEvents(): void
    {
        $this->db->execute("INSERT INTO qb_events (name, event_time) VALUES
            ('Morning meeting',   '2026-03-15 09:00:00'),
            ('Afternoon standup', '2026-03-15 14:30:00'),
            ('Evening review',    '2026-03-16 18:00:00'),
            ('Monthly sync',      '2026-04-01 10:00:00')
        ");
    }

    /**
     * whereDate() must filter rows by date portion only, ignoring the time.
     * PostgreSQL compiles this as (col)::date = '2026-03-15'.
     * 2 events are on 2026-03-15.
     */
    public function testWhereDateFiltersCorrectly(): void
    {
        // Arrange
        $this->seedEvents();

        // Act
        $result = $this->db->queryBuilder()
            ->from('qb_events')
            ->whereDate('event_time', '2026-03-15')
            ->get();

        // Assert — 2 events on that date
        $this->assertEquals(2, $result->numRows);
    }

    /**
     * whereYear() must filter rows to events in 2026.
     * PostgreSQL compiles this as EXTRACT(YEAR FROM col) = 2026.
     * All 4 events are in 2026.
     */
    public function testWhereYearFiltersCorrectly(): void
    {
        // Arrange
        $this->seedEvents();

        // Act
        $result = $this->db->queryBuilder()
            ->from('qb_events')
            ->whereYear('event_time', 2026)
            ->get();

        // Assert
        $this->assertEquals(4, $result->numRows);
    }

    /**
     * whereMonth() must filter to events in March (month 3).
     * PostgreSQL compiles this as EXTRACT(MONTH FROM col) = 3.
     * 3 of the 4 events are in March.
     */
    public function testWhereMonthFiltersCorrectly(): void
    {
        // Arrange
        $this->seedEvents();

        // Act
        $result = $this->db->queryBuilder()
            ->from('qb_events')
            ->whereMonth('event_time', 3)
            ->get();

        // Assert — 3 March events
        $this->assertEquals(3, $result->numRows);
    }

    /**
     * whereDay() must filter to events on day 15 of the month.
     * PostgreSQL compiles this as EXTRACT(DAY FROM col) = 15.
     * 2 events are on the 15th.
     */
    public function testWhereDayFiltersCorrectly(): void
    {
        // Arrange
        $this->seedEvents();

        // Act
        $result = $this->db->queryBuilder()
            ->from('qb_events')
            ->whereDay('event_time', 15)
            ->get();

        // Assert
        $this->assertEquals(2, $result->numRows);
    }

    /**
     * whereTime() must filter rows to a specific time portion.
     * PostgreSQL compiles this as (col)::time = '09:00:00'.
     * Only 'Morning meeting' starts at exactly 09:00:00.
     */
    public function testWhereTimeFiltersCorrectly(): void
    {
        // Arrange
        $this->seedEvents();

        // Act
        $result = $this->db->queryBuilder()
            ->from('qb_events')
            ->whereTime('event_time', '09:00:00')
            ->get();

        // Assert
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Morning meeting', $result->fields['name']);
    }

    // =========================================================================
    // when()
    // =========================================================================

    /**
     * when() with a truthy condition must apply the callback to the query.
     * 3 fruit products are expected when the filter is active.
     */
    public function testWhenTruthyAppliesFilterInIntegration(): void
    {
        // Arrange
        $this->seedProducts();
        $filterFruit = true;

        // Act
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->when($filterFruit, fn($q) => $q->where('category', 'fruit'))
            ->get();

        // Assert — only 3 fruit products
        $this->assertEquals(3, $result->numRows);
    }

    /**
     * when() with a falsy condition must not modify the query.
     * All 5 products must be returned when the condition is false.
     */
    public function testWhenFalsyLeavesQueryUnmodified(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->when(false, fn($q) => $q->where('category', 'fruit'))
            ->get();

        // Assert — all 5 products returned
        $this->assertEquals(5, $result->numRows);
    }

    // =========================================================================
    // selectSub() / fromSub()
    // =========================================================================

    /**
     * selectSub() must execute a correlated subquery per row and return the
     * derived column.  PostgreSQL uses double-quote identifier quoting.
     */
    public function testSelectSubCorrelatedSubquery(): void
    {
        // Arrange
        $this->seedProducts();
        $this->seedTags();

        // Act — tag count per product as a correlated subquery column
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->selectSub(function (\Pramnos\Database\QueryBuilder $sub) {
                $sub->select('COUNT(*)')
                    ->from('qb_tags')
                    ->whereRaw('qb_tags.product_id = qb_products.id');
            }, 'tag_count')
            ->from('qb_products')
            ->orderBy('id')
            ->get();

        $rows = $result->fetchAll();

        // Assert — Apple has 2 tags, Banana 0
        $this->assertCount(5, $rows);
        $this->assertEquals('Apple', $rows[0]['name']);
        $this->assertEquals(2, (int)$rows[0]['tag_count']);
        $this->assertEquals(0, (int)$rows[1]['tag_count']);
    }

    /**
     * fromSub() must execute the outer query against a PostgreSQL derived table.
     * Inner: avg price per category.  Outer: filter where avg_price > 1.00.
     */
    public function testFromSubDerivedTable(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $result = $this->db->queryBuilder()
            ->select(['category', 'avg_price'])
            ->fromSub(function (\Pramnos\Database\QueryBuilder $sub) {
                $sub->select(['category', $sub->raw('AVG(price) AS avg_price')])
                    ->from('qb_products')
                    ->groupBy('category');
            }, 'cat_avgs')
            ->where('avg_price', '>', 1.00)
            ->get();

        $rows = $result->fetchAll();

        // Assert — both fruit (≈1.567) and veggie (1.15) exceed 1.00
        $this->assertCount(2, $rows);
        $categories = array_column($rows, 'category');
        $this->assertContains('fruit',  $categories);
        $this->assertContains('veggie', $categories);
    }

    /**
     * Binding order must be preserved when fromSub() and outer WHERE are combined.
     * Subquery binding ('active' = true) must come before outer WHERE binding (price < 2.00).
     */
    public function testFromSubWithOuterWhereUsesCorrectBindingOrder(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — derived table: active products; outer WHERE price < 2.00
        $result = $this->db->queryBuilder()
            ->select('*')
            ->fromSub(function (\Pramnos\Database\QueryBuilder $sub) {
                // PostgreSQL: active is BOOLEAN, compare with true
                $sub->select('*')->from('qb_products')->where('active', true);
            }, 'active_p')
            ->where('price', '<', 2.00)
            ->get();

        $rows = $result->fetchAll();

        // Assert — active products under 2.00: Apple(1.20), Banana(0.50), Carrot(0.80)
        $this->assertCount(3, $rows);
    }

    // =========================================================================
    // over() — window functions (PostgreSQL 14)
    // =========================================================================

    /**
     * over() with PARTITION BY and ORDER BY must produce correct RANK() results.
     * PostgreSQL uses double-quote column quoting in PARTITION BY and ORDER BY.
     * Fruit: Banana(0.50)→1, Apple(1.20)→2, Elderberry(3.00)→3
     * Veggie: Carrot(0.80)→1, Daikon(1.50)→2
     */
    public function testWindowRankPartitionByCategory(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $result = $this->db->queryBuilder()
            ->select([
                'id',
                'name',
                'category',
                'price',
                $this->db->queryBuilder()->over(
                    'RANK()',
                    'price_rank',
                    partition: ['category'],
                    order: ['price' => 'asc']
                ),
            ])
            ->from('qb_products')
            ->orderBy('id')
            ->get();

        $rows = $result->fetchAll();
        $byName = array_column($rows, null, 'name');

        // Assert — Banana (cheapest fruit) gets rank 1
        $this->assertEquals(1, (int)$byName['Banana']['price_rank']);
        // Carrot (cheaper veggie) gets rank 1
        $this->assertEquals(1, (int)$byName['Carrot']['price_rank']);
        // Apple is rank 2 within fruit
        $this->assertEquals(2, (int)$byName['Apple']['price_rank']);
    }

    /**
     * ROW_NUMBER() OVER (ORDER BY price) must assign unique sequential integers.
     * 5 products → row numbers 1..5.
     */
    public function testWindowRowNumberOverGlobalOrder(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $result = $this->db->queryBuilder()
            ->select([
                'name',
                $this->db->queryBuilder()->over('ROW_NUMBER()', 'rn', order: ['price' => 'asc']),
            ])
            ->from('qb_products')
            ->orderBy('price')
            ->get();

        $rows = $result->fetchAll();
        $rowNumbers = array_column($rows, 'rn');

        // Assert — 5 unique sequential row numbers
        $this->assertCount(5, $rowNumbers);
        $this->assertEquals([1, 2, 3, 4, 5], array_map('intval', $rowNumbers));
    }

    /**
     * SUM(...) OVER (PARTITION BY category) — aggregate window function —
     * must return the category total on every row without collapsing rows.
     * Fruit total = 1.20 + 0.50 + 3.00 = 4.70; veggie total = 0.80 + 1.50 = 2.30.
     */
    public function testWindowSumPartitionBy(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $result = $this->db->queryBuilder()
            ->select([
                'name',
                'category',
                $this->db->queryBuilder()->over('SUM(price)', 'cat_total', partition: ['category']),
            ])
            ->from('qb_products')
            ->orderBy('id')
            ->get();

        $rows = $result->fetchAll();
        $byName = array_column($rows, null, 'name');

        // Assert
        $this->assertEqualsWithDelta(4.70, (float)$byName['Apple']['cat_total'],  0.01);
        $this->assertEqualsWithDelta(4.70, (float)$byName['Banana']['cat_total'], 0.01);
        $this->assertEqualsWithDelta(2.30, (float)$byName['Carrot']['cat_total'], 0.01);
    }

    /**
     * DENSE_RANK() with a running SUM frame clause (cumulative sum) using
     * ROWS BETWEEN — verifies that the optional $frame parameter is passed
     * through correctly by PostgreSQL grammar.
     */
    public function testWindowCumulativeSumWithFrameClause(): void
    {
        // Arrange
        $this->seedProducts();

        // Act — running cumulative price sum ordered by id
        $result = $this->db->queryBuilder()
            ->select([
                'id',
                'name',
                'price',
                $this->db->queryBuilder()->over(
                    'SUM(price)',
                    'running_total',
                    order: ['id' => 'asc'],
                    frame: 'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW'
                ),
            ])
            ->from('qb_products')
            ->orderBy('id')
            ->get();

        $rows = $result->fetchAll();

        // Assert — cumulative sum grows row by row; last row = total of all 5 prices
        $lastRow = end($rows);
        $this->assertEqualsWithDelta(7.00, (float)$lastRow['running_total'], 0.01);
    }

    // -------------------------------------------------------------------------
    // timeBucket() — PostgreSQL dialect (DATE_TRUNC fallback)
    // -------------------------------------------------------------------------

    /**
     * timeBucket() on plain PostgreSQL must translate to date_trunc() and produce
     * valid SQL that PostgreSQL 14 can actually execute.
     *
     * We seed qb_events with 4 rows: 2 in the 09:xx hour and 2 in the 11:xx hour.
     * GROUP BY with a 1-hour bucket must produce exactly 2 groups each containing
     * 2 events. This verifies the end-to-end path: timeBucket() →
     * PostgreSQLGrammar → date_trunc('hour', col) → real query.
     *
     * On the timescaledb container the result is the same as plain PG because
     * the default type is 'postgresql' (timescale flag not set), so no native
     * time_bucket() is used here.
     */
    public function testTimeBucketGroupByHourOnPostgres(): void
    {
        // Arrange — 2 events per hour in two distinct hourly windows
        $this->db->execute("INSERT INTO qb_events (name, event_time) VALUES
            ('a', '2026-03-15 09:05:00+00'),
            ('b', '2026-03-15 09:55:00+00'),
            ('c', '2026-03-15 11:10:00+00'),
            ('d', '2026-03-15 11:50:00+00')
        ");

        // Act — group by 1-hour bucket using the QB timeBucket() helper
        $qb     = $this->db->queryBuilder()->from('qb_events');
        $bucket = $qb->timeBucket('1 hour', 'event_time');

        $result = $qb
            ->select([$bucket, $qb->raw('COUNT(*) AS cnt')])
            ->groupBy([$bucket])
            ->orderByRaw('1 ASC')
            ->get();

        // Assert — two distinct hour buckets, each containing exactly 2 events
        $this->assertSame(2, $result->numRows, 'must produce 2 hourly time buckets');

        $counts = [];
        while ($result->fetch()) {
            $counts[] = (int) $result->fields['cnt'];
        }
        $this->assertSame([2, 2], $counts, 'each hourly bucket must contain exactly 2 events');
    }
}
