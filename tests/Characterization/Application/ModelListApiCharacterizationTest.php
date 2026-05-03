<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Characterization tests for Model list/count/API query contracts.
 *
 * These tests lock current behavior of getCount(), _getList(), and _getApiList()
 * using a real MySQL table created per test run.
 */
#[CoversClass(Model::class)]
class ModelListApiCharacterizationTest extends TestCase
{
    private Database $db;
    private string $table;

    protected function setUp(): void
    {
        // Arrange
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->table = 'char_model_api_' . bin2hex(random_bytes(4));

        $this->db->query(
            "CREATE TABLE `{$this->table}` ("
            . "`id` INT AUTO_INCREMENT PRIMARY KEY,"
            . "`name` VARCHAR(120) NOT NULL,"
            . "`status` VARCHAR(40) NOT NULL,"
            . "`active` TINYINT(1) NOT NULL DEFAULT 0,"
            . "`meta` JSON NULL"
            . ")"
        );

        $this->seedRows();
    }

    protected function tearDown(): void
    {
        // Arrange/Act cleanup
        $this->db->query("DROP TABLE IF EXISTS `{$this->table}`");
    }

    /**
     * Seeds deterministic rows used by all characterization checks.
     */
    private function seedRows(): void
    {
        $rows = [
            ['alpha', 'active', 1, '{"role":"admin","city":"Athens"}'],
            ['beta', 'inactive', 0, '{"role":"user"}'],
            ['gamma', 'active', 1, '{"role":"editor"}'],
            ['delta', 'active', 0, '{"role":"user"}'],
            ['alphabet', 'inactive', 1, '{"role":"guest"}'],
        ];

        foreach ($rows as $row) {
            // Act
            $sql = $this->db->prepareQuery(
                "INSERT INTO `{$this->table}` (`name`, `status`, `active`, `meta`) VALUES (%s, %s, %d, %s)",
                $row[0],
                $row[1],
                $row[2],
                $row[3]
            );
            $this->db->query($sql);
        }
    }

    /**
     * Creates a Model instance with a mocked controller.
     */
    private function makeModel(): Model
    {
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $controller */
        $controller = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new Model($controller, 'Item');
    }

    /**
     * Force internal model table/key for methods that inspect schema before
     * honoring the $table argument (current _getApiList behavior).
     */
    private function forceModelTable(Model $model): void
    {
        $tableProp = new \ReflectionProperty($model, '_dbtable');
        $tableProp->setAccessible(true);
        $tableProp->setValue($model, $this->table);

        $keyProp = new \ReflectionProperty($model, '_primaryKey');
        $keyProp->setAccessible(true);
        $keyProp->setValue($model, 'id');

        $cacheKeyProp = new \ReflectionProperty($model, '_cacheKey');
        $cacheKeyProp->setAccessible(true);
        $cacheKeyProp->setValue($model, null);
    }

    /**
     * getCount() returns total rows when no filter is provided.
     *
     * This locks the "count all rows" behavior for model list endpoints.
     */
    public function testGetCountReturnsTotalRows(): void
    {
        // Arrange
        $model = $this->makeModel();

        // Act
        $count = $model->getCount('', $this->table, 'id');

        // Assert
        $this->assertSame(5, (int) $count);
    }

    /**
     * getCount() accepts legacy SQL-style filters that include the WHERE keyword.
     *
     * This proves _stripSqlKeyword() compatibility in count queries.
     */
    public function testGetCountSupportsLegacyWherePrefixFilter(): void
    {
        // Arrange
        $model = $this->makeModel();

        // Act
        $count = $model->getCount("WHERE status = 'active'", $this->table, 'id');

        // Assert
        $this->assertSame(3, (int) $count);
    }

    /**
     * _getList() returns plain arrays when returnAsModels=false and useGetData=false.
     *
     * Ordering and filtering are applied through the query builder path.
     */
    public function testGetListReturnsPlainArrayRowsWithOrderAndFilter(): void
    {
        // Arrange
        $model = $this->makeModel();

        // Act
        $rows = $model->_getList(
            "WHERE active = 1",
            "ORDER BY id ASC",
            $this->table,
            'id',
            false,
            '',
            '*',
            '',
            false,
            false,
            false,
            false,
            []
        );

        // Assert
        $this->assertCount(3, $rows);
        $this->assertSame('alpha', $rows[0]['name']);
        $this->assertSame('gamma', $rows[1]['name']);
        $this->assertSame('alphabet', $rows[2]['name']);
    }

    /**
     * _getList() with useGetData=true and queryFields currently over-filters
     * model payloads to empty arrays in this path.
     *
     * This is a characterization of current behavior (known limitation), not
     * an idealized expectation.
     */
    public function testGetListUseGetDataWithQueryFieldsFiltersPayload(): void
    {
        // Arrange
        $model = $this->makeModel();

        // Act
        $rows = $model->_getList(
            '',
            'ORDER BY id ASC',
            $this->table,
            'id',
            false,
            '',
            'id, name',
            '',
            true,
            true,
            false,
            false,
            []
        );

        // Assert
        $this->assertCount(5, $rows);
        $first = $rows[1] ?? null;
        $this->assertIsArray($first);
        // Current behavior: selected-field filtering can collapse payload to empty array.
        $this->assertSame([], $first);
    }

    /**
     * _getApiList() global search returns matching rows and no pagination block
     * when page=0.
     */
    public function testGetApiListWithGlobalSearchAndNoPagination(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act
        $result = $model->_getApiList(
            ['id', 'name', 'meta'],
            'alpha',
            'id ASC',
            '',
            '',
            '',
            $this->table,
            'id',
            0,
            10,
            false,
            false,
            false
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertNull($result['pagination']);
        $this->assertCount(2, $result['data']);
        $this->assertSame('alpha', $result['data'][0]['name']);
        $this->assertSame('alphabet', $result['data'][1]['name']);

        // Proves JSON column decoding is applied in API list path.
        $this->assertIsArray($result['data'][0]['meta']);
        $this->assertSame('admin', $result['data'][0]['meta']['role']);
    }

    /**
     * _getApiList() paginated path currently returns an error envelope (with
     * pagination=null) for this field-selection configuration.
     *
     * This locks the current behavior so future fixes can be made explicitly.
     */
    public function testGetApiListWithPaginationReturnsErrorEnvelopeInCurrentImplementation(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act
        $result = $model->_getApiList(
            ['id', 'name'],
            '',
            'id ASC',
            '',
            '',
            '',
            $this->table,
            'id',
            1,
            2,
            false,
            false,
            false
        );

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertNull($result['pagination']);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame([], $result['data']);
    }

    /**
     * _getApiList() accepts structured filter arrays with OR groups.
     *
     * This locks the _buildFilterFromConditions() contract for safe filter
     * composition from structured input.
     */
    public function testGetApiListSupportsStructuredArrayFilters(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);
        $filter = [
            ['field' => 'status', 'op' => '=', 'value' => 'active'],
            ['or' => [
                ['field' => 'name', 'op' => 'LIKE', 'value' => '%alp%'],
                ['field' => 'name', 'op' => 'LIKE', 'value' => '%gam%'],
            ]],
        ];

        // Act
        $result = $model->_getApiList(
            ['id', 'name', 'status'],
            '',
            '-id',
            $filter,
            '',
            '',
            $this->table,
            'id',
            0,
            10,
            false,
            false,
            false
        );

        // Assert
        $this->assertCount(2, $result['data']);
        $this->assertSame('gamma', $result['data'][0]['name']);
        $this->assertSame('alpha', $result['data'][1]['name']);
    }
}
