<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Characterization tests for Model list/count/API query contracts against PostgreSQL.
 *
 * Mirrors ModelListApiCharacterizationTest but exercises the PostgreSQL path
 * (timescaledb:5432). Because Database::getInstance() is a singleton, each test
 * method runs in a separate process so that pg_settings.php takes effect before
 * any MySQL singleton is created by a sibling test.
 *
 * TimescaleDB coverage: the "timescaledb" Docker container is PostgreSQL 14 with
 * the TimescaleDB extension. Running against it satisfies both PostgreSQL and
 * TimescaleDB requirements for Model — which has no TimescaleDB-specific paths.
 *
 * All table names carry a random hex suffix to avoid cross-test collision.
 */
#[CoversClass(Model::class)]
#[RunTestsInSeparateProcesses]
class ModelListApiPostgreSQLCharacterizationTest extends TestCase
{
    private Database $db;
    private string $table;

    protected function setUp(): void
    {
        // Arrange
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        if (!is_dir(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs', 0777, true);
        }

        $pgSettingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        $this->table = 'char_model_api_pg_' . bin2hex(random_bytes(4));

        $this->db->query(
            'CREATE TABLE "' . $this->table . '" ('
            . '"id" SERIAL PRIMARY KEY,'
            . '"name" VARCHAR(120) NOT NULL,'
            . '"status" VARCHAR(40) NOT NULL,'
            . '"active" SMALLINT NOT NULL DEFAULT 0,'
            . '"meta" JSON'
            . ')'
        );

        $this->seedRows();
    }

    protected function tearDown(): void
    {
        // Cleanup
        $this->db->query('DROP TABLE IF EXISTS "' . $this->table . '"');
    }

    private function seedRows(): void
    {
        $rows = [
            ['alpha',    'active',   1, '{"role":"admin","city":"Athens"}'],
            ['beta',     'inactive', 0, '{"role":"user"}'],
            ['gamma',    'active',   1, '{"role":"editor"}'],
            ['delta',    'active',   0, '{"role":"user"}'],
            ['alphabet', 'inactive', 1, '{"role":"guest"}'],
        ];

        foreach ($rows as $row) {
            $sql = $this->db->prepareQuery(
                'INSERT INTO "' . $this->table . '" ("name", "status", "active", "meta") VALUES (%s, %s, %d, %s)',
                $row[0],
                $row[1],
                $row[2],
                $row[3]
            );
            $this->db->query($sql);
        }
    }

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

    // -------------------------------------------------------------------------
    // getCount
    // -------------------------------------------------------------------------

    /**
     * getCount() returns total rows when no filter is provided.
     *
     * Verifies the count-all contract is database-agnostic — same behavior as MySQL.
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
     * _stripSqlKeyword() must normalize the WHERE prefix on PostgreSQL just as
     * it does on MySQL — it operates at the PHP level, not the SQL level.
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

    // -------------------------------------------------------------------------
    // _getList
    // -------------------------------------------------------------------------

    /**
     * _getList() returns plain arrays when returnAsModels=false and useGetData=false.
     *
     * WHERE / ORDER BY clauses are dialect-neutral SQL — must produce the same
     * row count and ordering as on MySQL.
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
        $this->assertSame('alpha',    $rows[0]['name']);
        $this->assertSame('gamma',    $rows[1]['name']);
        $this->assertSame('alphabet', $rows[2]['name']);
    }

    /**
     * _getList() with useGetData=true and queryFields collapses payload to empty
     * arrays on PostgreSQL — same known limitation as MySQL.
     *
     * This is a characterization of current behavior (known limitation), not an
     * idealized expectation. Proves the bug is database-agnostic.
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
        // Current behavior on both MySQL and PostgreSQL: selected-field filtering
        // collapses the payload to an empty array.
        $this->assertSame([], $first);
    }

    // -------------------------------------------------------------------------
    // _getApiList
    // -------------------------------------------------------------------------

    /**
     * _getApiList() global search returns matching rows and no pagination block
     * when page=0.
     *
     * JSON decoding of the meta column and search behavior must be identical
     * to the MySQL path.
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
        $this->assertSame('alpha',    $result['data'][0]['name']);
        $this->assertSame('alphabet', $result['data'][1]['name']);

        // Proves JSON column decoding is applied in the API list path on PostgreSQL too.
        $this->assertIsArray($result['data'][0]['meta']);
        $this->assertSame('admin', $result['data'][0]['meta']['role']);
    }

    /**
     * _getApiList() paginated path returns an error envelope on PostgreSQL —
     * same known limitation as MySQL.
     *
     * Proves the bug is not dialect-specific.
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
        $this->assertArrayHasKey('error',      $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertNull($result['pagination']);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame([], $result['data']);
    }

    /**
     * _getApiList() accepts structured filter arrays with OR groups on PostgreSQL.
     *
     * _buildFilterFromConditions() is PHP-level logic and must produce the same
     * WHERE clause structure regardless of the underlying database engine.
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
