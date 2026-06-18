<?php

use PHPUnit\Framework\TestCase;

class DummyModelForSearch extends \Pramnos\Application\Model
{
    public function __construct() {}
    public $_primaryKey = "id";
    public $_dbtable = "dummy_table";

    public function getFullTableName($tableName = null) {
        return "dummy_table";
    }
}

class ModelSearchConditionsTest extends TestCase
{
    private $originalType;
    private $database;

    protected function setUp(): void
    {
        // Force-load the test settings
        \Pramnos\Application\Settings::loadSettings(APP_PATH . '/settings.php');
        $settings = \Pramnos\Application\Settings::getInstance();
        
        // Retrieve the database instance and explicitly configure its connection properties
        $this->database = \Pramnos\Database\Database::getInstance();
        $this->originalType = $this->database->type;
        
        $dbSettings = $settings->database;
        if ($dbSettings) {
            $this->database->server = $dbSettings->hostname;
            $this->database->database = $dbSettings->database;
            $this->database->user = $dbSettings->user;
            $this->database->password = $dbSettings->password;
            $this->database->port = $dbSettings->port ?? null;
            $this->database->prefix = $dbSettings->prefix ?? '';
            $this->database->collation = $dbSettings->collation ?? false;
        }

        $this->database->type = 'mysql';
        $this->database->connect(false);
    }

    protected function tearDown(): void
    {
        if ($this->database) {
            $this->database->type = $this->originalType;
            $this->database->close();
        }
    }

    public function testSearchConditionsForMySQL()
    {
        $this->database->type = 'mysql';

        $model = new DummyModelForSearch();
        
        // Populate cache
        $reflection = new \ReflectionClass(\Pramnos\Application\Model::class);
        $property = $reflection->getProperty("columnCache");
        $property->setValue(null, [
            "dummy_table" => [
                ["Field" => "id", "Type" => "int(11)"],
                ["Field" => "name", "Type" => "varchar(255)"],
                ["Field" => "price", "Type" => "decimal(10,2)"]
            ],
            "schema_columns_joined_table" => [
                ["Field" => "id", "Type" => "int(11)"],
                ["Field" => "user_id", "Type" => "int(11)"],
                ["Field" => "title", "Type" => "varchar(255)"]
            ]
        ]);
        
        $method = $reflection->getMethod('_buildSearchConditions');
        
        // 1. Numeric column (id) on main table with digit string -> Exact match
        $result = $method->invokeArgs($model, [
            ["id", "name", "price"],
            "",
            ["id" => "9"],
            ""
        ]);
        $this->assertEquals("`id` = 9", $result);

        // 2. Numeric decimal column (price) on main table -> Exact match
        $result = $method->invokeArgs($model, [
            ["id", "name", "price"],
            "",
            ["price" => "99"],
            ""
        ]);
        $this->assertEquals("`price` = 99", $result);

        // 3. Text column (name) on main table -> LIKE match
        $result = $method->invokeArgs($model, [
            ["id", "name", "price"],
            "",
            ["name" => "9"],
            ""
        ]);
        $this->assertEquals("`name` LIKE '%9%'", $result);

        // 4. Numeric column (id) with non-digit string -> LIKE match
        $result = $method->invokeArgs($model, [
            ["id", "name", "price"],
            "",
            ["id" => "abc"],
            ""
        ]);
        $this->assertEquals("`id` LIKE '%abc%'", $result);

        // 5. Joined table numeric column (b.user_id) -> Exact match
        $join = "LEFT JOIN `joined_table` AS b ON b.id = a.user_id";
        $result = $method->invokeArgs($model, [
            ["id", "name", "price", "b.user_id"],
            "",
            ["b.user_id" => "42"],
            $join
        ]);
        $this->assertEquals("b.user_id = 42", $result);

        // 6. Joined table text column (b.title) -> LIKE match
        $result = $method->invokeArgs($model, [
            ["id", "name", "price", "b.title"],
            "",
            ["b.title" => "hello"],
            $join
        ]);
        $this->assertEquals("b.title LIKE '%hello%'", $result);
    }

    public function testSearchConditionsForPostgreSQL()
    {
        $this->database->type = 'postgresql';

        $model = new DummyModelForSearch();
        
        // Populate cache
        $reflection = new \ReflectionClass(\Pramnos\Application\Model::class);
        $property = $reflection->getProperty("columnCache");
        $property->setValue(null, [
            "dummy_table" => [
                ["Field" => "id", "Type" => "integer"],
                ["Field" => "name", "Type" => "character varying(255)"],
                ["Field" => "price", "Type" => "numeric(10,2)"]
            ],
            "schema_columns_joined_table" => [
                ["Field" => "id", "Type" => "integer"],
                ["Field" => "user_id", "Type" => "integer"],
                ["Field" => "title", "Type" => "character varying(255)"]
            ]
        ]);
        
        $method = $reflection->getMethod('_buildSearchConditions');
        
        // 1. Numeric column (id) -> Exact match
        $result = $method->invokeArgs($model, [
            ["id", "name", "price"],
            "",
            ["id" => "9"],
            ""
        ]);
        $this->assertEquals('"id" = 9', $result);

        // 2. Joined table numeric column (b.user_id) -> Exact match
        $join = "LEFT JOIN `joined_table` AS b ON b.id = a.user_id";
        $result = $method->invokeArgs($model, [
            ["id", "name", "price", "b.user_id"],
            "",
            ["b.user_id" => "42"],
            $join
        ]);
        $this->assertEquals('b.user_id = 42', $result);
    }
}
