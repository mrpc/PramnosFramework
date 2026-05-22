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
        $tableProp->setValue($model, $this->table);

        $keyProp = new \ReflectionProperty($model, '_primaryKey');
        $keyProp->setValue($model, 'id');

        $cacheKeyProp = new \ReflectionProperty($model, '_cacheKey');
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
     * _getApiList() paginated path must return the correct pagination envelope
     * on PostgreSQL (page=1, limit=2 from 5 seeded rows).
     *
     * Phase 17 fix: the pre-existing empty-WHERE-clause bug (a leading space in
     * $finalFilter caused `WHERE ` to be emitted) has been resolved. Verifies
     * the fix is dialect-neutral — PostgreSQL now returns correct rows too.
     */
    public function testGetApiListWithPaginationReturnsPaginatedRows(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — page 1, 2 items per page, 5 total seeded rows
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

        // Assert — must have the standard API envelope keys
        $this->assertArrayHasKey('data', $result,
            'PostgreSQL: paginated _getApiList must return the data key');
        $this->assertArrayHasKey('pagination', $result,
            'PostgreSQL: paginated _getApiList must return the pagination key');
        $this->assertArrayNotHasKey('error', $result,
            'PostgreSQL: paginated _getApiList must not return an error when filter is empty');
        $this->assertCount(2, $result['data'],
            'PostgreSQL: page=1, limit=2 must return exactly 2 rows');
        $this->assertSame(5, $result['pagination']['totalitems'],
            'PostgreSQL: pagination.totalitems must reflect all 5 seeded rows');
        $this->assertSame(1, $result['pagination']['currentpage']);
        $this->assertSame(3, (int) $result['pagination']['totalpages'],
            'PostgreSQL: ceil(5/2) = 3 total pages');
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

    // ── Phase 17 — DataTables 2.x format wrapper ──────────────────────────────

    /**
     * _getApiList(format: 'datatables') must return the DataTables 2.x envelope
     * on PostgreSQL: {draw, data, recordsTotal, recordsFiltered}.
     *
     * Tests the paginated path (page = 1) which exercises the standard pagination
     * code before wrapping the output.
     */
    public function testGetApiListDataTablesFormatOnPostgresql(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);
        $_REQUEST['draw'] = '3';

        // Act
        $result = $model->_getApiList(
            [],
            '',
            '',
            '',
            '',
            '',
            $this->table,
            'id',
            1,
            10,
            false, false, false, false, false,
            'datatables'
        );

        // Assert
        $this->assertArrayHasKey('draw', $result,
            'PostgreSQL: DataTables 2.x format must include draw');
        $this->assertArrayHasKey('data', $result,
            'PostgreSQL: DataTables 2.x format must include data');
        $this->assertArrayHasKey('recordsTotal', $result,
            'PostgreSQL: DataTables 2.x format must include recordsTotal');
        $this->assertArrayHasKey('recordsFiltered', $result,
            'PostgreSQL: DataTables 2.x format must include recordsFiltered');
        $this->assertSame(3, $result['draw'],
            'draw must echo the $_REQUEST[draw] value as int on PostgreSQL');
        $this->assertArrayNotHasKey('pagination', $result,
            'datatables format must not include the standard pagination key on PostgreSQL');

        unset($_REQUEST['draw']);
    }

    /**
     * _getJsonList() must work on PostgreSQL after introspection is unified to
     * _getAllTableFields() (which queries information_schema on PG instead of SHOW COLUMNS).
     *
     * Before Phase 17, _getJsonList() ran SHOW COLUMNS — a MySQL-only statement
     * that would cause a syntax error on PostgreSQL. This test proves the fix.
     */
    public function testGetJsonListWorksOnPostgresqlAfterIntrospectionUnification(): void
    {
        // Arrange
        unset($_POST['sEcho'], $_POST['iDisplayStart'], $_POST['iDisplayLength']);
        unset($_GET['sEcho'],  $_GET['iDisplayStart'],  $_GET['iDisplayLength']);

        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — this would throw a syntax error on PostgreSQL before Phase 17
        // _getJsonList() returns a JSON-encoded string (Datasource::getList() contract)
        $raw = $model->_getJsonList('', $this->table, 'id');
        $result = json_decode($raw, true);

        // Assert — DT 1.9 keys still present after introspection fix
        $this->assertIsArray($result,
            'PostgreSQL: _getJsonList must return valid JSON that decodes to an array');
        $this->assertArrayHasKey('aaData', $result,
            'PostgreSQL: _getJsonList must return aaData key (DataTables 1.9 BC)');
        $this->assertArrayHasKey('iTotalRecords', $result,
            'PostgreSQL: _getJsonList must return iTotalRecords (DataTables 1.9 BC)');
        $this->assertCount(5, $result['aaData'],
            'PostgreSQL: all 5 seeded rows must be returned without DT paging params');
    }
}
