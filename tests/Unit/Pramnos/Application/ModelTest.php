<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;

/**
 * Subclass of Model for database-interacting testing.
 */
class TestProductModel extends Model
{
    protected $_dbtable = '#PREFIX#test_products';
    protected $_primaryKey = 'id';

    // Declared untyped to match active record behavior and avoid PHP 8 TypeErrors on string-from-db assignment
    public $id = null;
    public $name = null;
    public $price = null;
    public $is_active = null;

    /**
     * Retrieve the internal model name.
     *
     * @return string The model name.
     */
    public function getModelName()
    {
        return $this->modelname;
    }

    /**
     * Public wrapper for the protected _save method.
     *
     * @param bool $autoGetValues Automatically fetch field values from Request parameters.
     * @param bool $debug If true, dumps request data and terminates.
     * @param bool $force Force database write even if no changes are detected.
     * @return Model The saved model instance.
     */
    public function save(bool $autoGetValues = false, bool $debug = false, bool $force = false)
    {
        return $this->_save(null, null, $autoGetValues, $debug, $force);
    }

    /**
     * Public wrapper for the protected _load method.
     *
     * @param mixed $primaryKey Primary key value of the row to load.
     * @return Model The loaded model instance.
     */
    public function load($primaryKey)
    {
        return $this->_load($primaryKey);
    }

    /**
     * Public wrapper for the protected _delete method.
     *
     * @param mixed $primaryKey Primary key value of the row to delete.
     * @return Model The deleted model instance.
     */
    public function delete($primaryKey)
    {
        return $this->_delete($primaryKey);
    }

    /**
     * Public wrapper for the protected _getList method.
     *
     * @param string|null $filter SQL WHERE clause filter.
     * @param string|null $order SQL ORDER BY clause.
     * @param string $join SQL JOIN clause.
     * @param string|null $queryFields SQL select fields.
     * @param string $group SQL GROUP BY clause.
     * @param bool $returnAsModels Convert results to Model instances.
     * @param bool $useGetData Return raw arrays formatted via getData() instead of Model instances.
     * @return array List of retrieved records.
     */
    public function getList($filter = NULL, $order = NULL, $join = '', $queryFields = NULL, $group = '', $returnAsModels = true, $useGetData = false)
    {
        return $this->_getList($filter, $order, null, null, false, $join, $queryFields, $group, $returnAsModels, $useGetData);
    }

    /**
     * Public wrapper for the protected _getPaginated method.
     *
     * @param int $items Number of items per page.
     * @param int $page Page offset.
     * @param string|null $filter SQL WHERE clause filter.
     * @param string|null $order SQL ORDER BY clause.
     * @param string $join SQL JOIN clause.
     * @param string|null $queryFields SQL select fields.
     * @param string $group SQL GROUP BY clause.
     * @param bool $returnAsModels Convert results to Model instances.
     * @param bool $useGetData Return raw arrays formatted via getData() instead of Model instances.
     * @return array Envelope with keys: total, pages, items.
     */
    public function getPaginated($items=10, $page=1, $filter = NULL, $order = NULL, $join = '', $queryFields = NULL, $group = '', $returnAsModels = true, $useGetData = false)
    {
        return $this->_getPaginated($items, $page, $filter, $order, null, null, false, $join, $queryFields, $group, $returnAsModels, $useGetData);
    }

    /**
     * Public wrapper for the protected _getApiList method.
     *
     * @param array $fields List of fields to select/return.
     * @param string $search Search query pattern.
     * @param string $order ORDER BY column.
     * @param string $filter SQL WHERE filter.
     * @param string $join JOIN SQL expression.
     * @param string $group GROUP BY SQL expression.
     * @param string|null $table Target database table.
     * @param string|null $key Primary key name.
     * @param int $page Current page offset.
     * @param int $itemsPerPage Page limit.
     * @param bool $debug If true, outputs generated SQL and terminates.
     * @param bool $returnAsModels Instantiate models for returned rows.
     * @param bool $useGetData Format items using getData().
     * @return array Pagination and data envelope.
     */
    public function getApiList($fields = array(), $search = '', $order = '', $filter = '', $join = '', $group = '', $table = null, $key = null, $page = 0, $itemsPerPage = 10, $debug = false, $returnAsModels = false, $useGetData = false)
    {
        return $this->_getApiList($fields, $search, $order, $filter, $join, $group, $table, $key, $page, $itemsPerPage, $debug, $returnAsModels, $useGetData);
    }

    /**
     * Public wrapper for the protected _getJsonList method (DataTables 1.9 compatibility).
     *
     * @param string|null $filter SQL WHERE filter.
     * @param string|null $table Target database table.
     * @param string|null $key Primary key name.
     * @return string JSON representation of results.
     */
    public function getJsonList($filter = NULL, $table = NULL, $key = NULL)
    {
        return $this->_getJsonList($filter, $table, $key);
    }
}

#[CoversClass(Model::class)]
class ModelTest extends TestCase
{
    private \Pramnos\Database\Database $db;
    private Controller $controller;

    /**
     * Sets up the database schema, loads development settings, and initializes
     * the mock controller before each test execution.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Clean static columns cache
        Model::$columnCache = [];

        // Setup test table
        $this->db->query('DROP TABLE IF EXISTS `test_products`');
        $this->db->query('
            CREATE TABLE `test_products` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `price` decimal(10,2) NOT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        // Setup mock controller
        $this->controller = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->controller->application = Application::getInstance();

        // Clean global request globals
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    /**
     * Cleans up temporary database tables and resets the global Factory database
     * singleton after each test execution.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `test_products`');
        
        $singleton = &Factory::getDatabase();
        $singleton = null;

        Settings::clearSettings();

        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    /**
     * Tests that the Model constructor populates key properties correctly and
     * substitutes the global DB prefix pattern in the table name.
     *
     * @return void
     */
    public function testConstructorSetsPropertiesAndResolvesPrefix(): void
    {
        $model = new TestProductModel($this->controller, 'TestProduct');
        $this->assertSame('TestProduct', $model->getModelName());
        $this->assertSame($this->controller, $model->controller);
        
        // #PREFIX# should be replaced by empty or DB prefix (empty in our test settings)
        $this->assertSame('test_products', $model->getFullTableName());
    }

    /**
     * Tests that getModel() correctly delegates execution to the controller context.
     *
     * @return void
     */
    public function testGetModelDelegatesToController(): void
    {
        $model = new TestProductModel($this->controller);
        
        $otherModelMock = $this->getMockBuilder(Model::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->controller->expects($this->once())
            ->method('getModel')
            ->with('OtherModel')
            ->willReturn($otherModelMock);

        $returnedModel = $model->getModel('OtherModel');
        $this->assertSame($otherModelMock, $returnedModel);
    }

    /**
     * Tests inserting a new Model record, loading it by primary key, verifying values,
     * updating it, and checking the persistence layer correctness.
     *
     * @return void
     */
    public function testSaveInsertAndLoadAndSaveUpdate(): void
    {
        $model = new TestProductModel($this->controller);
        $model->name = 'Laptop';
        $model->price = 999.99;
        $model->is_active = 1;

        // Act - Insert
        $model->save();
        $this->assertNotNull($model->id);
        $this->assertGreaterThan(0, $model->id);

        // Load
        $loaded = new TestProductModel($this->controller);
        $loaded->load($model->id);
        $this->assertSame('Laptop', $loaded->name);
        $this->assertEquals(999.99, $loaded->price);
        $this->assertEquals(1, $loaded->is_active);

        // Act - Update
        $loaded->name = 'Gaming Laptop';
        $loaded->price = 1499.50;
        $loaded->save();

        // Verify Update
        $reloaded = new TestProductModel($this->controller);
        $reloaded->load($model->id);
        $this->assertSame('Gaming Laptop', $reloaded->name);
        $this->assertEquals(1499.50, $reloaded->price);
    }

    /**
     * Tests deleting a persistent Model record and invalidating the active caches.
     *
     * @return void
     */
    public function testDeleteRemovesRecord(): void
    {
        $model = new TestProductModel($this->controller);
        $model->name = 'Keyboard';
        $model->price = 49.99;
        $model->is_active = 1;
        $model->save();

        $productId = $model->id;

        // Verify exists
        $loader = new TestProductModel($this->controller);
        $loader->load($productId);
        $this->assertSame('Keyboard', $loader->name);

        // Act - Delete
        $model->delete($productId);

        // Verify deleted
        $check = new TestProductModel($this->controller);
        $check->load($productId);
        $this->assertNull($check->name);
    }

    /**
     * Tests retrieving correct record count, filtered list retrieval, formatting as models
     * vs arrays, and verifying paginated boundaries and offsets.
     *
     * @return void
     */
    public function testGetCountAndGetListAndGetPaginated(): void
    {
        // Seed database
        $this->db->query("INSERT INTO `test_products` (`name`, `price`, `is_active`) VALUES ('Mouse', 15.00, 1)");
        $this->db->query("INSERT INTO `test_products` (`name`, `price`, `is_active`) VALUES ('Keyboard', 45.00, 1)");
        $this->db->query("INSERT INTO `test_products` (`name`, `price`, `is_active`) VALUES ('Monitor', 150.00, 0)");

        $model = new TestProductModel($this->controller);

        // GetCount
        $this->assertEquals(3, $model->getCount());
        $this->assertEquals(2, $model->getCount('WHERE `is_active` = 1'));
        $this->assertEquals(1, $model->getCount('WHERE `price` > 100'));

        // GetList
        $list = $model->getList('WHERE `is_active` = 1', 'ORDER BY `price` ASC');
        $this->assertCount(2, $list);
        $first = reset($list);
        $this->assertSame('Mouse', $first->name);

        // GetList as Array
        $listArray = $model->getList('WHERE `is_active` = 1', 'ORDER BY `price` ASC', '', null, '', false, false);
        $this->assertCount(2, $listArray);
        $this->assertIsArray($listArray[0]);
        $this->assertSame('Mouse', $listArray[0]['name']);

        // GetPaginated
        $paginated = $model->getPaginated(1, 2, 'WHERE `is_active` = 1', 'ORDER BY `price` ASC');
        $this->assertEquals(2, $paginated['total']);
        $this->assertEquals(2, $paginated['pages']);
        $this->assertCount(1, $paginated['items']);
        $item = reset($paginated['items']);
        $this->assertSame('Keyboard', $item->name);
    }

    /**
     * Tests modern pagination/envelope-based REST API listings (getApiList) and DataTables 1.9
     * backward compatible JSON layout outputs (getJsonList).
     *
     * @return void
     */
    public function testGetApiListAndJsonList(): void
    {
        // Seed
        $this->db->query("INSERT INTO `test_products` (`name`, `price`, `is_active`) VALUES ('Phone', 599.00, 1)");
        $this->db->query("INSERT INTO `test_products` (`name`, `price`, `is_active`) VALUES ('Tablet', 399.00, 1)");

        $model = new TestProductModel($this->controller);

        // getApiList with pagination ($page=1, $itemsPerPage=10)
        $apiList = $model->getApiList(['id', 'name', 'price'], 'Phone', '', '', '', '', null, null, 1, 10);
        $this->assertNotNull($apiList['pagination']);
        $this->assertEquals(1, $apiList['pagination']['totalitems']);
        $this->assertNotEmpty($apiList['data']);
        $this->assertSame('Phone', $apiList['data'][0]['name']);

        // getJsonList (DataTables 1.9 format)
        $_POST['sEcho'] = '1';
        $_REQUEST['sEcho'] = '1';
        $_POST['iDisplayStart'] = '0';
        $_REQUEST['iDisplayStart'] = '0';
        $_POST['iDisplayLength'] = '10';
        $_REQUEST['iDisplayLength'] = '10';
        $jsonStr = $model->getJsonList();
        $this->assertJson($jsonStr);
        $json = json_decode($jsonStr, true);
        $this->assertSame(1, $json['sEcho']);
        $this->assertEquals(2, $json['iTotalRecords']);
        $this->assertNotEmpty($json['aaData']);
    }

    /**
     * Tests resolving request data and dynamically populating matching table fields
     * when autoGetValues is set to true during save operations.
     *
     * @return void
     */
    public function testSaveAutogetsValuesFromRequest(): void
    {
        $_POST['name'] = 'USB Drive';
        $_REQUEST['name'] = 'USB Drive';
        $_POST['price'] = '12.50';
        $_REQUEST['price'] = '12.50';
        $_POST['is_active'] = '1';
        $_REQUEST['is_active'] = '1';

        $model = new TestProductModel($this->controller);
        
        // Act - save with autoGetValues = true
        $model->save(true);
        $this->assertNotNull($model->id);

        $loaded = new TestProductModel($this->controller);
        $loaded->load($model->id);
        $this->assertSame('USB Drive', $loaded->name);
        $this->assertEquals(12.50, $loaded->price);
    }
}
