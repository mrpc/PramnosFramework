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
        $this->db->query("DROP TABLE IF EXISTS `qb_events`");
        $this->db->query("CREATE TABLE `qb_events` (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            event_time DATETIME NOT NULL
        )");
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `qb_tags`");
        $this->db->query("DROP TABLE IF EXISTS `qb_products`");
        $this->db->query("DROP TABLE IF EXISTS `qb_events`");
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

        // UNION ALL keeps all rows (3 fruit rows × 2 queries = 6)
        $result = $q1->unionAll($q2)->get();
        $this->assertEquals(6, $result->numRows);
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
        while ($result->fetch()) {
            $names[] = $result->fields['name'];
        }

        $this->assertCount(5, $names);
        $this->assertEquals('Banana', $names[0]);
        $this->assertEquals('Elderberry', $names[4]);
        $this->assertTrue($result->eof);
    }

    // -------------------------------------------------------------------------
    // Result utility methods
    // -------------------------------------------------------------------------

    public function testResultMagicGet(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('name', 'Apple')
            ->first();

        // __get proxies to $fields array
        $this->assertEquals('Apple', $result->name);
        $this->assertNull($result->nonexistent_field);
    }

    public function testGetInsertId(): void
    {
        $result = $this->db->query(
            "INSERT INTO `qb_products` (name, category, price, stock) VALUES ('InsertIdTest', 'fruit', 1.00, 1)"
        );

        $id = $result->getInsertId();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        // Verify the row actually has that ID
        $row = $this->db->query(
            "SELECT id FROM `qb_products` WHERE name = 'InsertIdTest'"
        )->fields;
        $this->assertEquals($id, (int)$row['id']);
    }

    public function testGetAffectedRows(): void
    {
        $this->seedProducts();

        $result = $this->db->query(
            "UPDATE `qb_products` SET stock = 999 WHERE category = 'fruit'"
        );

        // 3 fruit rows: Apple, Banana, Elderberry
        $this->assertEquals(3, $result->getAffectedRows());
    }

    public function testGetAffectedRowsOnDelete(): void
    {
        $this->seedProducts();

        $result = $this->db->query("DELETE FROM `qb_products` WHERE active = 0");
        // Daikon is the only inactive product
        $this->assertEquals(1, $result->getAffectedRows());
    }

    public function testGetNumFields(): void
    {
        $this->seedProducts();
        $result = $this->db->query("SELECT id, name, category FROM `qb_products` LIMIT 1");

        $this->assertEquals(3, $result->getNumFields());
    }

    public function testFreeReleasesResult(): void
    {
        $this->seedProducts();
        $result = $this->db->queryBuilder()->from('qb_products')->get();

        // free() should not throw; mysqlResult is set to null afterwards
        $result->free();
        $this->assertNull($result->mysqlResult);
    }

    public function testFetchNextOnSingleRowResult(): void
    {
        $this->seedProducts();

        // first() returns a 1-row result; fetch() on it should cover the
        // MySQL single-row seek-to-1 failure path (sets eof=true on first call)
        $result = $this->db->queryBuilder()
            ->from('qb_products')
            ->orderBy('price')
            ->first();

        $this->assertEquals(1, $result->numRows);

        // First fetch() — pre-fetched row 0 is available, seek to row 1 fails,
        // eof is set to true, but returns fields (row 0)
        $this->assertNotNull($result->fetch());
        $this->assertEquals('Banana', $result->fields['name']);

        // Second fetch() — eof is true → returns null
        $this->assertNull($result->fetch());
    }

    // =========================================================================
    // rightJoin / crossJoin
    // =========================================================================

    /**
     * rightJoin() must include rows from the right table even when there is no
     * matching row in the left table.  Here all tags appear, even if the product
     * referenced does not exist in qb_products (we filter to product_id=99).
     * The test verifies that RIGHT JOIN emits the correct SQL and executes without error.
     */
    public function testRightJoinExecutesWithoutError(): void
    {
        // Arrange
        $this->seedProducts();
        $this->seedTags();

        // Act — RIGHT JOIN brings all tags; LEFT-side columns are NULL when unmatched
        $result = $this->db->queryBuilder()
            ->select(['qb_products.name', 'qb_tags.tag'])
            ->from('qb_products')
            ->rightJoin('qb_tags', 'qb_products.id', '=', 'qb_tags.product_id')
            ->get();

        // Assert — all 5 tag rows appear (tags drive the result set)
        $this->assertEquals(5, $result->numRows);
    }

    /**
     * crossJoin() must produce the Cartesian product — every row from left
     * combined with every row from right.  2 categories × 5 products = 10 rows.
     */
    public function testCrossJoinProducesCartesianProduct(): void
    {
        // Arrange
        $this->seedProducts();
        $this->db->query("INSERT INTO `qb_tags` (product_id, tag) VALUES (1, 'a'), (2, 'b')");

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
     * forPage() must limit and offset correctly.
     * Page 1 (size 2) → first 2 rows; page 2 → next 2 rows.
     */
    public function testForPageReturnsCorrctSlice(): void
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
     * sum() must return the total of the specified column.
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
     * avg() must return the arithmetic mean of the column.
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
     * min() must return the minimum value of the column.
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
     * max() must return the maximum value of the column.
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
     * Aggregate methods must respect WHERE clauses — only active products counted.
     * Active products: Apple 1.20, Banana 0.50, Carrot 0.80, Elderberry 3.00 → sum 5.50
     */
    public function testSumRespectsWhereClause(): void
    {
        // Arrange
        $this->seedProducts();

        // Act
        $total = $this->db->queryBuilder()
            ->from('qb_products')
            ->where('active', 1)
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
     * value() must return null when no rows match.
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
     * pluck() must return a flat array of one column's values across all rows.
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
     * increment() must add the given step to the column and return the affected rows.
     * Apple starts at stock=100; after increment(5) it should be 105.
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
     * Stopping early by returning false from the callback must halt processing.
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
     * chunk() must stop early when the callback returns false.
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
     * lockForUpdate() must execute without error inside a transaction.
     * The lock is real — it prevents other connections from writing to the row.
     * Here we verify it executes and returns the correct rows.
     */
    public function testLockForUpdateExecutesWithinTransaction(): void
    {
        // Arrange
        $this->seedProducts();
        $this->db->query('START TRANSACTION');

        // Act
        $result = $this->db->queryBuilder()
            ->select(['name', 'stock'])
            ->from('qb_products')
            ->where('name', 'Apple')
            ->lockForUpdate()
            ->get();

        $this->db->query('COMMIT');

        // Assert — row returned correctly with lock
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Apple', $result->fields['name']);
    }

    /**
     * sharedLock() must execute without error inside a transaction.
     */
    public function testSharedLockExecutesWithinTransaction(): void
    {
        // Arrange
        $this->seedProducts();
        $this->db->query('START TRANSACTION');

        // Act
        $result = $this->db->queryBuilder()
            ->select(['name'])
            ->from('qb_products')
            ->where('active', 1)
            ->sharedLock()
            ->get();

        $this->db->query('COMMIT');

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
     * Helper: seed the qb_events table with known datetime values.
     */
    private function seedEvents(): void
    {
        $this->db->query("INSERT INTO `qb_events` (name, event_time) VALUES
            ('Morning meeting',  '2026-03-15 09:00:00'),
            ('Afternoon standup','2026-03-15 14:30:00'),
            ('Evening review',   '2026-03-16 18:00:00'),
            ('Monthly sync',     '2026-04-01 10:00:00')
        ");
    }

    /**
     * whereDate() must filter rows by the date portion of a DATETIME column,
     * ignoring the time part.  Only rows on 2026-03-15 (2 of 4) must be returned.
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
     * whereYear() must filter rows to the given year.
     * All 4 events are in 2026, so 4 rows should be returned.
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
     * whereMonth() must filter rows to events in March (month 3).
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
     * whereDay() must filter rows to events on day 15.
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

    /**
     * when() with a truthy condition must apply the callback to the live query.
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
}
