<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * QueryBuilder integration tests — MySQL 8.0.
 *
 * Uses two tables created per-test to exercise all QB features against a live
 * MySQL connection. Requires the Docker MySQL container to be running.
 *
 * Schema:
 *   qb_products  (id, name, category, price, stock, active, notes)
 *   qb_tags      (id, product_id, tag)
 */
class QueryBuilderMySQLTest extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        $this->db->query("DROP TABLE IF EXISTS `qb_tags`");
        $this->db->query("DROP TABLE IF EXISTS `qb_products`");
        $this->db->query("CREATE TABLE `qb_products` (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            name     VARCHAR(255)    NOT NULL,
            category VARCHAR(50)     DEFAULT NULL,
            price    DECIMAL(10,2)   DEFAULT 0.00,
            stock    INT             DEFAULT 0,
            active   TINYINT(1)      DEFAULT 1,
            notes    TEXT            DEFAULT NULL
        )");
        $this->db->query("CREATE TABLE `qb_tags` (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT         NOT NULL,
            tag        VARCHAR(50) NOT NULL
        )");
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `qb_tags`");
        $this->db->query("DROP TABLE IF EXISTS `qb_products`");
        $this->db->close();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedProducts(): void
    {
        $this->db->query("INSERT INTO `qb_products` (name, category, price, stock, active, notes) VALUES
            ('Apple',      'fruit',  1.20, 100, 1, 'fresh'),
            ('Banana',     'fruit',  0.50, 200, 1, NULL),
            ('Carrot',     'veggie', 0.80,  50, 1, 'organic'),
            ('Daikon',     'veggie', 1.50,   0, 0, NULL),
            ('Elderberry', 'fruit',  3.00,  10, 1, 'seasonal')
        ");
    }

    private function seedTags(): void
    {
        // product 1 = Apple, product 3 = Carrot
        $this->db->query("INSERT INTO `qb_tags` (product_id, tag) VALUES
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

    public function testSelectSpecificColumns(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->select('name', 'price')
            ->from('qb_products')
            ->orderBy('price')
            ->get();

        $this->assertEquals(5, $result->numRows);
        $this->assertArrayHasKey('name',  $result->fields);
        $this->assertArrayHasKey('price', $result->fields);
        $this->assertArrayNotHasKey('stock', $result->fields);
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

    public function testFirstOnEmptyTableReturnsEmptyResult(): void
    {
        $result = $this->db->queryBuilder()->from('qb_products')->first();
        $this->assertEquals(0, $result->numRows);
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

    public function testWhereWithOperator(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('price', '>', 1.00)
            ->get();
        $this->assertEquals(3, $result->numRows); // Apple 1.20, Daikon 1.50, Elderberry 3.00
    }

    public function testOrWhere(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->orWhere('name', 'Carrot')
            ->get();
        $this->assertEquals(2, $result->numRows);
    }

    public function testNestedWhere(): void
    {
        $this->seedProducts();
        // (category = 'fruit' AND price < 1.00) OR category = 'veggie'
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where(function ($q) {
                $q->where('category', 'fruit')->where('price', '<', 1.00);
            })
            ->orWhere('category', 'veggie')
            ->get();
        // Banana(fruit, 0.50), Carrot(veggie), Daikon(veggie) = 3
        $this->assertEquals(3, $result->numRows);
    }

    public function testWhereIn(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereIn('name', ['Apple', 'Banana', 'Carrot'])
            ->get();
        $this->assertEquals(3, $result->numRows);
    }

    public function testWhereNotIn(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereIn('category', ['veggie'], 'and', true)
            ->get();
        $this->assertEquals(3, $result->numRows); // the 3 fruit rows
    }

    public function testWhereNull(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereNull('notes')
            ->get();
        // Banana and Daikon have NULL notes
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

    public function testOrWhereNull(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('category', 'fruit')
            ->orWhereNull('notes')
            ->get();
        // Apple(fruit, 'fresh'), Banana(fruit, NULL), Elderberry(fruit, seasonal), Daikon(NULL notes)
        // but Banana already matched by fruit, so unique: Apple, Banana, Elderberry, Daikon = 4
        $this->assertEquals(4, $result->numRows);
    }

    public function testOrWhereNotNull(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('active', 0)
            ->orWhereNotNull('notes')
            ->get();
        // active=0: Daikon; notes not null: Apple, Carrot, Elderberry → 4 unique
        $this->assertEquals(4, $result->numRows);
    }

    public function testWhereBetween(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereBetween('price', [0.70, 1.60])
            ->get();
        // Apple(1.20), Carrot(0.80), Daikon(1.50) = 3
        $this->assertEquals(3, $result->numRows);
    }

    public function testWhereNotBetween(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereNotBetween('price', [0.70, 1.60])
            ->get();
        // Banana(0.50), Elderberry(3.00) = 2
        $this->assertEquals(2, $result->numRows);
    }

    public function testOrWhereBetween(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereBetween('stock', [90, 110])
            ->orWhereBetween('price', [2.50, 4.00])
            ->get();
        // stock 90-110: Apple(100); price 2.50-4.00: Elderberry(3.00)
        $this->assertEquals(2, $result->numRows);
    }

    public function testOrWhereNotBetween(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('category', 'veggie')
            ->orWhereNotBetween('price', [0.40, 2.00])
            ->get();
        // veggie: Carrot, Daikon; price outside 0.40-2.00: Elderberry(3.00)
        $this->assertEquals(3, $result->numRows);
    }

    public function testWhereRaw(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->whereRaw('price * stock > %i', [100])
            ->get();
        // Apple: 1.20*100=120, Banana: 0.50*200=100 (not >100), Carrot: 0.80*50=40 → Apple only
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Apple', $result->fields['name']);
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

        // Apple(2 tags) + Carrot(2 tags) + Elderberry(1 tag) = 5 rows
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

        // All 5 products; Banana and Daikon have no tags → NULL tag column
        $this->assertEquals(7, $result->numRows); // 2+2+1 matched + 2 unmatched
    }

    public function testJoinRaw(): void
    {
        $this->seedProducts();
        $this->seedTags();

        $result = $this->db->queryBuilder()
            ->select('p.name', 't.tag')
            ->from('qb_products p')
            ->joinRaw('INNER JOIN qb_tags t ON t.product_id = p.id AND t.tag = \'organic\'')
            ->get();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Carrot', $result->fields['name']);
    }

    // -------------------------------------------------------------------------
    // GROUP BY / HAVING
    // -------------------------------------------------------------------------

    public function testGroupBy(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->select('category', $this->db->queryBuilder()->raw('COUNT(*) as cnt'))
            ->from('qb_products')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        $this->assertEquals(2, $result->numRows);
        // First row: 'fruit' with 3 items
        $this->assertEquals('fruit', $result->fields['category']);
        $this->assertEquals(3, $result->fields['cnt']);
    }

    public function testHaving(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->select('category', $this->db->queryBuilder()->raw('COUNT(*) as cnt'))
            ->from('qb_products')
            ->groupBy('category')
            ->having('cnt', '>=', 3)
            ->get();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('fruit', $result->fields['category']);
    }

    public function testHavingRaw(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->select('category', $this->db->queryBuilder()->raw('SUM(stock) as total_stock'))
            ->from('qb_products')
            ->groupBy('category')
            ->havingRaw('SUM(stock) > %i', [100])
            ->get();

        // fruit: 100+200+10=310, veggie: 50+0=50 → fruit only
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('fruit', $result->fields['category']);
    }

    // -------------------------------------------------------------------------
    // ORDER BY / LIMIT / OFFSET
    // -------------------------------------------------------------------------

    public function testOrderBy(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('price', 'asc')
            ->get();

        $rows = $result->fetchAll();
        $this->assertEquals('Banana', $rows[0]['name']);
        $this->assertEquals('Elderberry', $rows[4]['name']);
    }

    public function testLimitOffset(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('id')
            ->limit(2)
            ->offset(1)
            ->get();

        $this->assertEquals(2, $result->numRows);
        $this->assertEquals('Banana', $result->fields['name']);
    }

    public function testClearOrderingAndPaging(): void
    {
        $this->seedProducts();
        $qb = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('active', 1)
            ->orderBy('price')
            ->limit(2)
            ->offset(1);

        $countQb = clone $qb;
        $countQb->select($this->db->queryBuilder()->raw('COUNT(*) as n'))
                ->clearOrderingAndPaging();

        $total = (int)($countQb->first()->fields['n'] ?? 0);
        $this->assertEquals(4, $total); // 4 active products

        // Paginated query still has ORDER BY LIMIT OFFSET
        $paginated = $qb->get();
        $this->assertEquals(2, $paginated->numRows);
    }

    // -------------------------------------------------------------------------
    // Raw expressions in SELECT
    // -------------------------------------------------------------------------

    public function testRawExpressionInSelect(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->select('name', $this->db->queryBuilder()->raw('price * stock as value'))
            ->from('qb_products')
            ->where('name', 'Apple')
            ->first();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(120.00, (float)$result->fields['value']);
    }

    // -------------------------------------------------------------------------
    // INSERT / UPDATE / DELETE
    // -------------------------------------------------------------------------

    public function testInsert(): void
    {
        $this->db->queryBuilder()
            ->table('qb_products')
            ->insert(['name' => 'Fig', 'category' => 'fruit', 'price' => 2.50, 'stock' => 30]);

        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Fig')
            ->first();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(2.50, (float)$result->fields['price']);
    }

    public function testUpdate(): void
    {
        $this->seedProducts();
        $this->db->queryBuilder()
            ->table('qb_products')
            ->where('name', 'Apple')
            ->update(['stock' => 150]);

        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->first();

        $this->assertEquals(150, (int)$result->fields['stock']);
    }

    public function testDelete(): void
    {
        $this->seedProducts();
        $this->db->queryBuilder()
            ->from('qb_products')
            ->where('active', 0)
            ->delete();

        $result = $this->db->queryBuilder()->from('qb_products')->get();
        $this->assertEquals(4, $result->numRows);
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

    public function testTruncateResetsAutoIncrement(): void
    {
        $this->seedProducts();
        $this->db->queryBuilder()->from('qb_products')->truncate();

        $this->db->queryBuilder()
            ->table('qb_products')
            ->insert(['name' => 'NewItem', 'category' => 'fruit', 'price' => 1.00, 'stock' => 5]);

        $result = $this->db->queryBuilder()->from('qb_products')->where('name', 'NewItem')->first();
        $this->assertEquals(1, (int)$result->fields['id']); // auto-increment reset to 1
    }

    // -------------------------------------------------------------------------
    // UNION / UNION ALL
    // -------------------------------------------------------------------------

    public function testUnion(): void
    {
        $this->seedProducts();
        $fruits = $this->db->queryBuilder()
            ->select('name', 'category')
            ->from('qb_products')
            ->where('category', 'fruit');

        $veggies = $this->db->queryBuilder()
            ->select('name', 'category')
            ->from('qb_products')
            ->where('category', 'veggie');

        $result = $fruits->union($veggies)->get();
        $this->assertEquals(5, $result->numRows);
    }

    public function testUnionDeduplicates(): void
    {
        $this->seedProducts();
        $q1 = $this->db->queryBuilder()
            ->select('category')->from('qb_products')->where('category', 'fruit');
        $q2 = $this->db->queryBuilder()
            ->select('category')->from('qb_products')->where('category', 'fruit');

        // UNION removes duplicates — should return 1 row ('fruit')
        $result = $q1->union($q2)->get();
        $this->assertEquals(1, $result->numRows);
    }

    public function testUnionAllPreservesDuplicates(): void
    {
        $this->seedProducts();
        $q1 = $this->db->queryBuilder()
            ->select('category')->from('qb_products')->where('category', 'fruit');
        $q2 = $this->db->queryBuilder()
            ->select('category')->from('qb_products')->where('category', 'fruit');

        // UNION ALL keeps both rows
        $result = $q1->unionAll($q2)->get();
        $this->assertEquals(2, $result->numRows);
    }

    public function testUnionWithBindings(): void
    {
        $this->seedProducts();
        $cheap = $this->db->queryBuilder()
            ->select('name')->from('qb_products')->where('price', '<', 0.60);
        $expensive = $this->db->queryBuilder()
            ->select('name')->from('qb_products')->where('price', '>', 2.50);

        $result = $cheap->union($expensive)->get();
        // Banana(0.50) + Elderberry(3.00) = 2
        $this->assertEquals(2, $result->numRows);
    }

    // -------------------------------------------------------------------------
    // INSERT IGNORE (insertOrIgnore)
    // -------------------------------------------------------------------------

    public function testInsertOrIgnore(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `qb_unique_products`");
        $this->db->query("CREATE TABLE `qb_unique_products` (
            id    INT AUTO_INCREMENT PRIMARY KEY,
            sku   VARCHAR(50) NOT NULL UNIQUE,
            name  VARCHAR(255)
        )");

        $this->db->queryBuilder()
            ->table('qb_unique_products')
            ->insertOrIgnore(['sku' => 'APPLE-001', 'name' => 'Apple']);

        // Duplicate SKU — should be silently ignored, no exception
        $this->db->queryBuilder()
            ->table('qb_unique_products')
            ->insertOrIgnore(['sku' => 'APPLE-001', 'name' => 'Apple v2']);

        $result = $this->db->query("SELECT * FROM `qb_unique_products`");
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Apple', $result->fields['name']); // original preserved

        $this->db->query("DROP TABLE IF EXISTS `qb_unique_products`");
    }

    // -------------------------------------------------------------------------
    // UPSERT (ON DUPLICATE KEY UPDATE)
    // -------------------------------------------------------------------------

    public function testUpsertInsertsNewRow(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `qb_inventory`");
        $this->db->query("CREATE TABLE `qb_inventory` (
            id    INT AUTO_INCREMENT PRIMARY KEY,
            sku   VARCHAR(50) NOT NULL UNIQUE,
            qty   INT DEFAULT 0,
            price DECIMAL(10,2)
        )");

        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-A', 'qty' => 10, 'price' => 5.00], ['sku'], ['qty', 'price']);

        $row = $this->db->query("SELECT * FROM `qb_inventory` WHERE sku = 'ITEM-A'")->fields;
        $this->assertEquals(10, (int)$row['qty']);
        $this->assertEquals(5.00, (float)$row['price']);

        $this->db->query("DROP TABLE IF EXISTS `qb_inventory`");
    }

    public function testUpsertUpdatesOnConflict(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `qb_inventory`");
        $this->db->query("CREATE TABLE `qb_inventory` (
            id    INT AUTO_INCREMENT PRIMARY KEY,
            sku   VARCHAR(50) NOT NULL UNIQUE,
            qty   INT DEFAULT 0,
            price DECIMAL(10,2)
        )");

        // Insert
        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-B', 'qty' => 5, 'price' => 2.00], ['sku'], ['qty', 'price']);

        // Upsert with same SKU — should update qty and price
        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-B', 'qty' => 99, 'price' => 3.50], ['sku'], ['qty', 'price']);

        $count = $this->db->query("SELECT COUNT(*) as n FROM `qb_inventory`")->fields;
        $this->assertEquals(1, (int)$count['n']); // still only 1 row

        $row = $this->db->query("SELECT * FROM `qb_inventory` WHERE sku = 'ITEM-B'")->fields;
        $this->assertEquals(99, (int)$row['qty']);
        $this->assertEquals(3.50, (float)$row['price']);

        $this->db->query("DROP TABLE IF EXISTS `qb_inventory`");
    }

    public function testUpsertWithNoUpdateColumnsActsAsInsertIgnore(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `qb_inventory`");
        $this->db->query("CREATE TABLE `qb_inventory` (
            id    INT AUTO_INCREMENT PRIMARY KEY,
            sku   VARCHAR(50) NOT NULL UNIQUE,
            qty   INT DEFAULT 0
        )");

        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-C', 'qty' => 7], ['sku'], []);

        // Second call with empty updateValues — behaves like INSERT IGNORE
        $this->db->queryBuilder()
            ->table('qb_inventory')
            ->upsert(['sku' => 'ITEM-C', 'qty' => 99], ['sku'], []);

        $row = $this->db->query("SELECT * FROM `qb_inventory` WHERE sku = 'ITEM-C'")->fields;
        $this->assertEquals(7, (int)$row['qty']); // original preserved

        $this->db->query("DROP TABLE IF EXISTS `qb_inventory`");
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
        $this->assertEquals('Elderberry', $rows[4]['name']);
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
        $this->assertEquals('Elderberry', $names[4]);
        $this->assertTrue($result->eof);
    }
}
