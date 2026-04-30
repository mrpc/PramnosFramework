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
        $this->assertEquals('John', $results->fields['name']);
        
        $results->fetch();
        $this->assertEquals('Jane', $results->fields['name']);
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
}
