<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Application\Settings;

class QueryBuilderTest extends TestCase
{
    protected $db;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type = 'mysql';
        $this->db->server = 'db';
        $this->db->user = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port = 3306;
        $this->db->connect(true);
        
        // Ensure test table exists
        $this->db->query("DROP TABLE IF EXISTS `#PREFIX#qb_test` ");
        $this->db->query("CREATE TABLE `#PREFIX#qb_test` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255),
            age INT,
            active TINYINT(1) DEFAULT 1
        )");
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `#PREFIX#qb_test` ");
    }

    public function testBasicSelect()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('John', 30), ('Jane', 25)");

        $results = $this->db->queryBuilder()
            ->select('*')
            ->from('#PREFIX#qb_test')
            ->orderBy('age', 'desc')
            ->get();

        $this->assertEquals(2, $results->numRows);
        // Row 0 (John, age=30) is pre-fetched into fields after get().
        $this->assertEquals('John', $results->fields['name']);

        // Use fetchNext() to iterate: first call returns pre-fetched row 0,
        // second call reads row 1.
        $this->assertTrue($results->fetchNext());
        $this->assertEquals('John', $results->fields['name']);
        $this->assertTrue($results->fetchNext());
        $this->assertEquals('Jane', $results->fields['name']);
        $this->assertFalse($results->fetchNext());
    }

    public function testWhereClauses()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('John', 30), ('Jane', 25), ('Bob', 40)");

        $results = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->where('age', '>', 25)
            ->where('name', '!=', 'Bob')
            ->get();

        $this->assertEquals(1, $results->numRows);
        $this->assertEquals('John', $results->fields['name']);
    }

    public function testInsertUpdateDelete()
    {
        $qb = $this->db->queryBuilder()->from('#PREFIX#qb_test');

        // Insert
        $qb->insert(['name' => 'Alice', 'age' => 20]);

        $check = $this->db->query("SELECT * FROM `#PREFIX#qb_test` WHERE name = 'Alice'")->fields;
        $this->assertEquals(20, $check['age']);

        // Update
        $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->where('name', 'Alice')
            ->update(['age' => 21]);

        $check = $this->db->query("SELECT * FROM `#PREFIX#qb_test` WHERE name = 'Alice'")->fields;
        $this->assertEquals(21, $check['age']);

        // Delete
        $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->where('name', 'Alice')
            ->delete();

        $check = $this->db->query("SELECT * FROM `#PREFIX#qb_test` WHERE name = 'Alice'");
        $this->assertEquals(0, $check->numRows);
    }

    public function testFirst()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('Alpha', 10), ('Beta', 20)");

        $result = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->orderBy('age')
            ->first();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Alpha', $result->fields['name']);
    }

    public function testWhereNull()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('WithAge', 30), ('NoAge', NULL)");

        $result = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->whereNull('age')
            ->get();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('NoAge', $result->fields['name']);
    }

    public function testWhereNotNull()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('WithAge', 30), ('NoAge', NULL)");

        $result = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->whereNotNull('age')
            ->get();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('WithAge', $result->fields['name']);
    }

    public function testOrWhereNull()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('Active', 30), ('NoAge', NULL)");

        $result = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->where('name', 'Active')
            ->orWhereNull('age')
            ->get();

        $this->assertEquals(2, $result->numRows);
    }

    public function testWhereBetween()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('Young', 10), ('Mid', 25), ('Old', 50)");

        $result = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->whereBetween('age', [20, 30])
            ->get();

        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('Mid', $result->fields['name']);
    }

    public function testWhereNotBetween()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('Young', 10), ('Mid', 25), ('Old', 50)");

        $result = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->whereNotBetween('age', [20, 30])
            ->orderBy('age')
            ->get();

        $this->assertEquals(2, $result->numRows);
        $this->assertEquals('Young', $result->fields['name']);
    }

    public function testOrWhereBetween()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('Teen', 15), ('Mid', 25), ('Senior', 65)");

        $result = $this->db->queryBuilder()
            ->from('#PREFIX#qb_test')
            ->whereBetween('age', [10, 20])
            ->orWhereBetween('age', [60, 70])
            ->get();

        $this->assertEquals(2, $result->numRows);
    }

    public function testUnion()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('Alice', 20), ('Bob', 30)");

        $q1 = $this->db->queryBuilder()
            ->select('name', 'age')
            ->from('#PREFIX#qb_test')
            ->where('age', 20);

        $q2 = $this->db->queryBuilder()
            ->select('name', 'age')
            ->from('#PREFIX#qb_test')
            ->where('age', 30);

        $result = $q1->union($q2)->get();

        $this->assertEquals(2, $result->numRows);
    }

    public function testUnionAll()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('Alice', 20)");

        $q1 = $this->db->queryBuilder()
            ->select('name', 'age')
            ->from('#PREFIX#qb_test')
            ->where('age', 20);

        $q2 = $this->db->queryBuilder()
            ->select('name', 'age')
            ->from('#PREFIX#qb_test')
            ->where('age', 20);

        // UNION deduplicates identical rows; UNION ALL keeps both
        $result = $q1->unionAll($q2)->get();
        $this->assertEquals(2, $result->numRows);

        // UNION (without ALL) deduplicates
        $q3 = $this->db->queryBuilder()->select('name', 'age')->from('#PREFIX#qb_test')->where('age', 20);
        $q4 = $this->db->queryBuilder()->select('name', 'age')->from('#PREFIX#qb_test')->where('age', 20);
        $result2 = $q3->union($q4)->get();
        $this->assertEquals(1, $result2->numRows);
    }

    public function testTruncate()
    {
        $this->db->query("INSERT INTO `#PREFIX#qb_test` (name, age) VALUES ('A', 1), ('B', 2), ('C', 3)");

        $before = $this->db->queryBuilder()->from('#PREFIX#qb_test')->get();
        $this->assertEquals(3, $before->numRows);

        $this->db->queryBuilder()->from('#PREFIX#qb_test')->truncate();

        $after = $this->db->queryBuilder()->from('#PREFIX#qb_test')->get();
        $this->assertEquals(0, $after->numRows);
    }

    public function testInsertOrIgnore()
    {
        // Create a table with unique constraint for this test
        $this->db->query("DROP TABLE IF EXISTS `#PREFIX#qb_unique_test`");
        $this->db->query("CREATE TABLE `#PREFIX#qb_unique_test` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE,
            name VARCHAR(255)
        )");

        $this->db->queryBuilder()
            ->table('#PREFIX#qb_unique_test')
            ->insertOrIgnore(['email' => 'a@b.com', 'name' => 'First']);

        // Second insert with same email — should be silently ignored
        $this->db->queryBuilder()
            ->table('#PREFIX#qb_unique_test')
            ->insertOrIgnore(['email' => 'a@b.com', 'name' => 'Second']);

        $result = $this->db->query("SELECT * FROM `#PREFIX#qb_unique_test`");
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals('First', $result->fields['name']);

        $this->db->query("DROP TABLE IF EXISTS `#PREFIX#qb_unique_test`");
    }

    public function testUpsert()
    {
        $this->db->query("DROP TABLE IF EXISTS `#PREFIX#qb_upsert_test`");
        $this->db->query("CREATE TABLE `#PREFIX#qb_upsert_test` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE,
            name VARCHAR(255),
            age INT
        )");

        // First insert
        $this->db->queryBuilder()
            ->table('#PREFIX#qb_upsert_test')
            ->upsert(['email' => 'x@y.com', 'name' => 'Original', 'age' => 25], ['email'], ['name', 'age']);

        $row = $this->db->query("SELECT * FROM `#PREFIX#qb_upsert_test` WHERE email = 'x@y.com'")->fields;
        $this->assertEquals('Original', $row['name']);
        $this->assertEquals(25, $row['age']);

        // Upsert again — conflict on email, should update name and age
        $this->db->queryBuilder()
            ->table('#PREFIX#qb_upsert_test')
            ->upsert(['email' => 'x@y.com', 'name' => 'Updated', 'age' => 30], ['email'], ['name', 'age']);

        $row2 = $this->db->query("SELECT * FROM `#PREFIX#qb_upsert_test` WHERE email = 'x@y.com'")->fields;
        $this->assertEquals('Updated', $row2['name']);
        $this->assertEquals(30, $row2['age']);

        $count = $this->db->query("SELECT COUNT(*) as n FROM `#PREFIX#qb_upsert_test`")->fields;
        $this->assertEquals(1, $count['n']);

        $this->db->query("DROP TABLE IF EXISTS `#PREFIX#qb_upsert_test`");
    }
}
