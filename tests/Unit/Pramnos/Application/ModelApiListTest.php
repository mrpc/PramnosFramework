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
        // Inject into private static $columnCache of Model
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

    public function testGetApiListResolvesAliasedFields()
    {
        $model = new DummyModelForApiList();
        
        $result = $model->_getApiList(["deyacode"]);
        
        $this->assertContains("`deyacode`", $result["fields"]);
        $this->assertTrue($model->dbCalled);
        $this->assertStringContainsString("deyacode", $model->passedSelectFields);
    }
}
