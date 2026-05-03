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
    }

    protected function tearDown(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS qb_tags");
        $this->db->execute("DROP TABLE IF EXISTS qb_products");
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
        while ($result->fetchNext()) {
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

        $this->assertTrue($result->fetchNext());
        $this->assertEquals('Apple', $result->fields['name']);
        $this->assertFalse($result->fetchNext()); // eof after single row
        $this->assertTrue($result->eof);
    }
}
