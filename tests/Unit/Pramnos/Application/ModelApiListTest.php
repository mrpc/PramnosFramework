<?php

use PHPUnit\Framework\TestCase;

class DummyModelForApiList extends \Pramnos\Application\Model
{
    public function __construct() {}
    public $_primaryKey = "id";
    public $_dbtable = "dummy_table";
    public $dbCalled = false;
    public $passedSelectFields = "";

    public function getFullTableName($tableName = null) {
        return "dummy_table";
    }

    public function _getList(
        $filter = "", $order = "", $table = null, $key = null, $debug = false,
        $join = "", $select = "*", $group = "", $returnAsModels = false, $useGetData = false, $dummy = false,
        $customMethod = false, $addedfields = false
    ) {
        $this->dbCalled = true;
        $this->passedSelectFields = is_array($select) ? implode(",", $select) : $select;
        return [
            "total" => 1,
            "pages" => 1,
            "items" => [["id" => 1, "deyacode" => "D123"]]
        ];
    }

    protected function _getPaginated($items = 10, $page = 1, $filter = null, $order = null, $table = null, $key = null, $debug = false, $join = "", $queryFields = null, $group = "", $returnAsModels = true, $useGetData = false, $customGetListMethod = false, $addedfields = []) {
        $this->dbCalled = true;
        $this->passedSelectFields = is_array($queryFields) ? implode(",", $queryFields) : $queryFields;
        return [
            "total" => 1,
            "pages" => 1,
            "items" => [["id" => 1, "deyacode" => "D123"]]
        ];
    }

    protected function _buildSelectFields($fields, $join) {
        return implode(",", $fields);
    }
}

class ModelApiListTest extends TestCase
{
    public function setUp(): void
    {
        // Inject into private static $columnCache of Model so _getAllTableFields()
        // returns mock data without hitting the database.
        $reflection = new \ReflectionClass(\Pramnos\Application\Model::class);
        if ($reflection->hasProperty("columnCache")) {
            $property = $reflection->getProperty("columnCache");
            $property->setValue(null, [
                "dummy_table" => [
                    ["Field" => "id"],
                    ["Field" => "b.`deyacode`"],
                    ["Field" => "name"]
                ]
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Restore Model::$columnCache to empty so subsequent tests start from a
        // clean slate.  setUp() injected fake data; without this reset any Model
        // test that runs after will find "dummy_table" in the cache (harmless) but
        // — more critically — the columnCache injection caused _getAllTableFields()
        // to call Database::getInstance(), which creates an unconnected static
        // Database instance that poisons integration tests further down the suite.
        $reflection = new \ReflectionClass(\Pramnos\Application\Model::class);
        if ($reflection->hasProperty("columnCache")) {
            $property = $reflection->getProperty("columnCache");
            $property->setValue(null, []);
        }

        // Reset Database::getInstance() static so integration tests get a fresh
        // connection instead of the broken (unconnected) instance created above.
        // The trick mirrors ConsoleApplicationCoverageTest::tearDown(): fetch the
        // static by reference and assign null so the next call creates a new one.
        $db = &\Pramnos\Database\Database::getInstance();
        $db = null;
    }

    public function testGetApiListResolvesAliasedFields()
    {
        $model = new DummyModelForApiList();
        
        $result = $model->_getApiList(["deyacode"]);
        
        $this->assertContains("`deyacode`", $result["fields"]);
        $this->assertTrue($model->dbCalled);
        $this->assertStringContainsString("deyacode", $model->passedSelectFields);
    }
}
