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
    public function getApiList($fields = array(), $search = '', $order = '', $filter = '', $join = '', $group = '', $table = null, $key = null, $page = 0, $itemsPerPage = 10, $debug = false, $returnAsModels = false, $useGetData = false, $format = '')
    {
        return $this->_getApiList($fields, $search, $order, $filter, $join, $group, $table, $key, $page, $itemsPerPage, $debug, $returnAsModels, $useGetData, false, false, $format);
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

class TestUserOrmModel extends \Pramnos\Application\OrmModel
{
    protected $_dbtable = '#PREFIX#test_users';
    protected $_primaryKey = 'id';
    protected array $fillable = ['name', 'email'];
    protected bool $softDelete = true;

    public $id = null;
    public $name = null;
    public $email = null;
    public $deleted_at = null;

    public function posts()
    {
        return $this->hasMany(TestPostOrmModel::class, 'user_id');
    }

    public function profile()
    {
        return $this->hasOne(TestProfileOrmModel::class, 'user_id');
    }

    public function load($primaryKey)
    {
        return $this->_load($primaryKey);
    }

    public function delete($primaryKey = null)
    {
        return $this->_delete($primaryKey ?: $this->id);
    }

    public function isNew(): bool
    {
        return $this->_isnew;
    }
}

class TestPostOrmModel extends \Pramnos\Application\OrmModel
{
    protected $_dbtable = '#PREFIX#test_posts';
    protected $_primaryKey = 'id';
    protected array $fillable = ['user_id', 'title', 'body'];

    public $id = null;
    public $user_id = null;
    public $title = null;
    public $body = null;

    public function author()
    {
        return $this->belongsTo(TestUserOrmModel::class, 'user_id');
    }

    public function load($primaryKey)
    {
        return $this->_load($primaryKey);
    }

    public function delete($primaryKey = null)
    {
        return $this->_delete($primaryKey ?: $this->id);
    }
}

class TestProfileOrmModel extends \Pramnos\Application\OrmModel
{
    protected $_dbtable = '#PREFIX#test_profiles';
    protected $_primaryKey = 'id';
    protected array $fillable = ['user_id', 'bio'];

    public $id = null;
    public $user_id = null;
    public $bio = null;

    public function load($primaryKey)
    {
        return $this->_load($primaryKey);
    }

    public function delete($primaryKey = null)
    {
        return $this->_delete($primaryKey ?: $this->id);
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

        $this->db->query('DROP TABLE IF EXISTS `test_users`');
        $this->db->query('
            CREATE TABLE `test_users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `deleted_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        $this->db->query('DROP TABLE IF EXISTS `test_posts`');
        $this->db->query('
            CREATE TABLE `test_posts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `title` varchar(255) NOT NULL,
                `body` text NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        $this->db->query('DROP TABLE IF EXISTS `test_profiles`');
        $this->db->query('
            CREATE TABLE `test_profiles` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `bio` text NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        // Setup mock controller
        $this->controller = new class extends Controller {
            public $expectedModelName = '';
            public $returnedModelMock = null;
            public $getModelCalled = 0;
            public function __construct() {
                $this->application = Application::getInstance();
            }
            public function & getModel($name = '') {
                $this->getModelCalled++;
                if ($name === $this->expectedModelName) {
                    return $this->returnedModelMock;
                }
                $res =& parent::getModel($name);
                return $res;
            }
        };

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
        $this->db->query('DROP TABLE IF EXISTS `test_users`');
        $this->db->query('DROP TABLE IF EXISTS `test_posts`');
        $this->db->query('DROP TABLE IF EXISTS `test_profiles`');
        
        $singleton = &Factory::getDatabase();
        $singleton = null;

        Settings::clearSettings();

        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        
        // Reset event listeners
        TestUserOrmModel::flushEventListeners();
        TestPostOrmModel::flushEventListeners();
        TestProfileOrmModel::flushEventListeners();
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

        $this->controller->expectedModelName = 'OtherModel';
        $this->controller->returnedModelMock = $otherModelMock;
        $this->controller->getModelCalled = 0;

        $returnedModel = $model->getModel('OtherModel');
        $this->assertSame($otherModelMock, $returnedModel);
        $this->assertEquals(1, $this->controller->getModelCalled);
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

    /**
     * Tests getData() strips internal fields and returns valid object data.
     */
    public function testGetDataReturnsPublicFields(): void
    {
        $model = new TestProductModel($this->controller);
        $model->id = 15;
        $model->name = 'Flash Drive';
        $model->price = 19.99;
        $model->is_active = 1;

        $data = $model->getData();

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('price', $data);
        $this->assertArrayHasKey('is_active', $data);
        
        $this->assertArrayNotHasKey('_primaryKey', $data);
        $this->assertArrayNotHasKey('_dbtable', $data);
        $this->assertSame(15, $data['id']);
        $this->assertSame('Flash Drive', $data['name']);
    }

    /**
     * Tests getChanges() tracks loaded entity state changes.
     */
    public function testGetChangesTracksModifications(): void
    {
        $model = new TestProductModel($this->controller);
        $model->name = 'Initial Name';
        $model->price = 100.00;
        $model->save();

        $loader = new TestProductModel($this->controller);
        $loader->load($model->id);

        // Before modification, changes should be empty
        $this->assertEmpty($loader->getChanges());

        // Modify
        $loader->name = 'New Name';
        $loader->price = 150.00;

        $changes = $loader->getChanges();
        
        $this->assertArrayHasKey('name', $changes);
        $this->assertSame('Initial Name', $changes['name']['old']);
        $this->assertSame('New Name', $changes['name']['new']);
        
        $this->assertArrayHasKey('price', $changes);
        $this->assertEquals(100.00, $changes['price']['old']);
        $this->assertEquals(150.00, $changes['price']['new']);
    }

    /**
     * Tests getLastSaveChanges() records differences after a save.
     */
    public function testGetLastSaveChanges(): void
    {
        $model = new TestProductModel($this->controller);
        $model->name = 'Product A';
        $model->price = 50.00;
        $model->save();

        $loader = new TestProductModel($this->controller);
        $loader->load($model->id);
        
        $loader->price = 75.00;
        $loader->save();

        $lastChanges = $loader->getLastSaveChanges();
        $this->assertArrayHasKey('price', $lastChanges);
        $this->assertEquals(50.00, $lastChanges['price']['old']);
        $this->assertEquals(75.00, $lastChanges['price']['new']);
    }

    /**
     * Tests addJsonAction() properly populates _jsonactions array.
     */
    public function testAddJsonActionPopulatesArray(): void
    {
        $model = new class($this->controller) extends TestProductModel {
            public function exposeAddJsonAction($action, $field='', $column='', $title='', $confirm=false) {
                $this->addJsonAction($action, $field, $column, $title, $confirm);
            }
        };

        $model->exposeAddJsonAction('edit', 'id', 'action_col', 'Edit Item', true);
        
        $reflection = new \ReflectionClass(Model::class);
        $property = $reflection->getProperty('_jsonactions');
        $actions = $property->getValue($model);

        $this->assertArrayHasKey('edit', $actions);
        $this->assertSame('edit', $actions['edit']['action']);
        $this->assertSame('id', $actions['edit']['field']);
        $this->assertSame('action_col', $actions['edit']['column']);
        $this->assertSame('Edit Item', $actions['edit']['title']);
        $this->assertTrue($actions['edit']['confirm']);
    }

    /**
     * Tests save() with force=true skips getChanges check, and debug prints request mapping.
     */
    public function testSaveWithForceAndDebug(): void
    {
        $model = new TestProductModel($this->controller);
        $model->name = 'Debug Item';
        $model->price = 10.00;
        $model->save();

        // Ob_start to capture debug output
        ob_start();
        
        // Force save without changing anything
        $model->save(true, true, true);
        
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Debug Item', $output);
        $this->assertStringContainsString('10', $output);
        // Ensure ID wasn't changed
        $this->assertNotNull($model->id);
    }

    /**
     * Tests getApiList with array filter, JSON fields/search, and datatables format.
     */
    public function testGetApiListAdvancedParameters(): void
    {
        // Seed
        $this->db->query("INSERT INTO `test_products` (`name`, `price`, `is_active`) VALUES ('Apples', 1.50, 1)");
        $this->db->query("INSERT INTO `test_products` (`name`, `price`, `is_active`) VALUES ('Bananas', 2.00, 1)");

        $model = new TestProductModel($this->controller);

        // Fields as comma-separated
        $fieldsStr = 'id, name, price';
        // Search as JSON array structure
        $searchJson = json_encode(['name' => 'Apples']);
        // Filter as structured array
        $filterArr = [
            ['field' => 'is_active', 'op' => '=', 'value' => 1]
        ];

        // Format = datatables
        $result = $model->getApiList($fieldsStr, $searchJson, '`price` ASC', $filterArr, '', '', null, null, 1, 10, false, false, false, 'datatables');

        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        
        $this->assertEquals(1, $result['recordsFiltered']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('Apples', $result['data'][0]['name']);
        
        // Now test fields as JSON and search as flat string, format empty
        $fieldsJson = json_encode(['name']);
        $result2 = $model->getApiList($fieldsJson, 'Bananas', '', '', '', '', null, null, 0, 10);
        
        $this->assertArrayHasKey('data', $result2);
        $this->assertArrayHasKey('pagination', $result2);
        $this->assertCount(1, $result2['data']);
        $this->assertSame('Bananas', $result2['data'][0]['name']);
    }

    /**
     * Tests ORM relationships resolution (HasOne, HasMany, BelongsTo) and eager loading.
     */
    public function testOrmRelationships(): void
    {
        // 1. Create and Save User
        $user = new TestUserOrmModel($this->controller);
        $user->name = 'ORM User';
        $user->email = 'orm@example.com';
        $user->save();
        $userId = $user->id;
        $this->assertNotNull($userId);

        // 2. Create Profile
        $profile = new TestProfileOrmModel($this->controller);
        $profile->user_id = $userId;
        $profile->bio = 'Developer bio';
        $profile->save();

        // 3. Create Posts
        $post1 = new TestPostOrmModel($this->controller);
        $post1->user_id = $userId;
        $post1->title = 'Post Title 1';
        $post1->body = 'Post Body 1';
        $post1->save();

        $post2 = new TestPostOrmModel($this->controller);
        $post2->user_id = $userId;
        $post2->title = 'Post Title 2';
        $post2->body = 'Post Body 2';
        $post2->save();

        // 4. Resolve Relations dynamically
        $loadedUser = new TestUserOrmModel($this->controller);
        $loadedUser->load($userId);

        $this->assertInstanceOf(TestProfileOrmModel::class, $loadedUser->profile);
        $this->assertSame('Developer bio', $loadedUser->profile->bio);

        $posts = $loadedUser->posts;
        $this->assertCount(2, $posts);
        $this->assertSame('Post Title 1', $posts->all()[0]->title);
        $this->assertSame('Post Title 2', $posts->all()[1]->title);

        $loadedPost = new TestPostOrmModel($this->controller);
        $loadedPost->load($post1->id);
        $this->assertInstanceOf(TestUserOrmModel::class, $loadedPost->author);
        $this->assertSame('ORM User', $loadedPost->author->name);

        // 5. Test eager loading
        $checkUser = new TestUserOrmModel($this->controller);
        $checkUser->load($userId);
        $this->assertFalse($checkUser->isNew(), "Failed to load user normally before eager loading test");

        $eagerUser = new TestUserOrmModel($this->controller);
        $results = $eagerUser->with('posts')->_getList("a.id = " . (int)$userId);
        if (empty($results)) {
            $this->fail("eagerUser->_getList returned empty results. SQL Error: " . $eagerUser->sqlError);
        }
        $this->assertCount(1, $results);
        $firstResult = array_values($results)[0];
        $arr = $firstResult->toArray();
        $this->assertArrayHasKey('posts', $arr);
        $this->assertCount(2, $arr['posts']);
    }

    /**
     * Tests ORM lifecycle events (creating, created, updating, updated, deleting, deleted).
     */
    public function testOrmEvents(): void
    {
        $eventsFired = [];

        TestUserOrmModel::on('creating', function ($model) use (&$eventsFired) {
            $eventsFired[] = 'creating';
            return true;
        });

        TestUserOrmModel::on('created', function ($model) use (&$eventsFired) {
            $eventsFired[] = 'created';
        });

        TestUserOrmModel::on('updating', function ($model) use (&$eventsFired) {
            $eventsFired[] = 'updating';
            return true;
        });

        TestUserOrmModel::on('updated', function ($model) use (&$eventsFired) {
            $eventsFired[] = 'updated';
        });

        TestUserOrmModel::on('deleting', function ($model) use (&$eventsFired) {
            $eventsFired[] = 'deleting';
            return true;
        });

        TestUserOrmModel::on('deleted', function ($model) use (&$eventsFired) {
            $eventsFired[] = 'deleted';
        });

        // 1. Create lifecycle
        $user = new TestUserOrmModel($this->controller);
        $user->name = 'Event User';
        $user->email = 'event@example.com';
        $user->save();

        $this->assertContains('creating', $eventsFired);
        $this->assertContains('created', $eventsFired);

        // 2. Update lifecycle
        $user->name = 'Updated Event User';
        $user->save();

        $this->assertContains('updating', $eventsFired);
        $this->assertContains('updated', $eventsFired);

        // 3. Delete lifecycle
        $user->delete();

        $this->assertContains('deleting', $eventsFired);
        $this->assertContains('deleted', $eventsFired);

        // 4. Test before-event cancellation
        TestUserOrmModel::flushEventListeners();
        TestUserOrmModel::on('creating', function ($model) {
            return false; // Cancel save
        });

        $user2 = new TestUserOrmModel($this->controller);
        $user2->name = 'Cancelled User';
        $user2->email = 'cancel@example.com';
        $user2->save();

        $this->assertNull($user2->id); // Must not have been persisted
    }

    /**
     * Tests ORM Soft Deleting.
     */
    public function testOrmSoftDeletes(): void
    {
        $user = new TestUserOrmModel($this->controller);
        $user->name = 'SoftDelete User';
        $user->email = 'soft@example.com';
        $user->save();
        $userId = $user->id;

        // Perform Soft Delete
        $user->delete();
        $this->assertNotNull($user->deleted_at);

        // Verify standard load treats it as not found
        $loaded = new TestUserOrmModel($this->controller);
        $loaded->load($userId);
        $this->assertTrue($loaded->isNew());

        // Verify withTrashed fetches it
        $loadedWith = new TestUserOrmModel($this->controller);
        $loadedWith->withTrashed();
        $loadedWith->load($userId);
        $this->assertFalse($loadedWith->isNew());
        $this->assertSame('SoftDelete User', $loadedWith->name);

        // Test restore
        $loadedWith->restore();
        $this->assertNull($loadedWith->deleted_at);

        // Verify it can now be loaded normally
        $normal = new TestUserOrmModel($this->controller);
        $normal->load($userId);
        $this->assertFalse($normal->isNew());
    }
}
