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
        $tableProp->setValue($model, $this->table);

        $keyProp = new \ReflectionProperty($model, '_primaryKey');
        $keyProp->setValue($model, 'id');

        $cacheKeyProp = new \ReflectionProperty($model, '_cacheKey');
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
     * _getApiList() paginated path must return the correct pagination envelope
     * with actual data rows (page=1, limit=2 from 5 seeded rows).
     *
     * Phase 17 fix: the pre-existing empty-WHERE-clause bug (a leading space in
     * $finalFilter caused `WHERE ` to be emitted) has been resolved by removing
     * the spurious leading space from the _combineFilters() call. The paginated
     * path now works correctly when no filter/search is specified.
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
            'paginated _getApiList must return the data key');
        $this->assertArrayHasKey('pagination', $result,
            'paginated _getApiList must return the pagination key');
        $this->assertArrayNotHasKey('error', $result,
            'paginated _getApiList must not return an error when filter is empty');
        $this->assertCount(2, $result['data'],
            'page=1, limit=2 must return exactly 2 rows');
        $this->assertSame(5, $result['pagination']['totalitems'],
            'pagination.totalitems must reflect all 5 seeded rows');
        $this->assertSame(1, $result['pagination']['currentpage']);
        $this->assertSame(3, (int) $result['pagination']['totalpages'],
            'ceil(5/2) = 3 total pages');
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

    // ── Phase 17 — DataTables 2.x format wrapper ──────────────────────────────

    /**
     * _getApiList(format: 'datatables') must return the DataTables 2.x envelope
     * {draw, data, recordsTotal, recordsFiltered} on MySQL.
     *
     * DataTables 2.x serverSide expects these exact keys so that the JS plugin
     * knows how many total/filtered rows exist for the pagination control.
     * The `draw` value echoes back whatever was sent in $_REQUEST['draw']
     * (anti-CSRF counter used by DataTables).
     */
    public function testGetApiListDataTablesFormatReturnsDrawDataRecordsOnMysql(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);
        $_REQUEST['draw'] = '7';

        // Act — paginated path (page > 0) with datatables format
        $result = $model->_getApiList(
            [],        // fields — all
            '',        // search
            '',        // order
            '',        // filter
            '',        // join
            '',        // group
            $this->table,
            'id',
            1,         // page
            10,        // itemsPerPage
            false,
            false,
            false,
            false,
            false,
            'datatables'  // $format
        );

        // Assert — must have exactly the DT 2.x keys
        $this->assertArrayHasKey('draw', $result,
            'DataTables 2.x format must include draw key');
        $this->assertArrayHasKey('data', $result,
            'DataTables 2.x format must include data key');
        $this->assertArrayHasKey('recordsTotal', $result,
            'DataTables 2.x format must include recordsTotal key');
        $this->assertArrayHasKey('recordsFiltered', $result,
            'DataTables 2.x format must include recordsFiltered key');

        // draw echoes the request value back as int
        $this->assertSame(7, $result['draw'],
            'draw must echo back the $_REQUEST[draw] value as int');

        // 5 seeded rows — all visible
        $this->assertSame(5, $result['recordsTotal'],
            'recordsTotal must reflect the full unseeded count');
        $this->assertCount(5, $result['data'],
            'data must contain all 5 seeded rows on page 1 with limit 10');

        // Must NOT have the standard envelope keys
        $this->assertArrayNotHasKey('pagination', $result,
            'datatables format must not include the standard pagination sub-object');
        $this->assertArrayNotHasKey('fields', $result,
            'datatables format must not include the fields key');

        unset($_REQUEST['draw']);
    }

    /**
     * _getApiList(format: 'datatables') without pagination (page = 0) must still
     * return the DT 2.x envelope, deriving recordsTotal from count(data).
     *
     * Needed for endpoints that return all rows at once (small lookup tables).
     */
    public function testGetApiListDataTablesFormatNoPaginationOnMysql(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);
        unset($_REQUEST['draw']);

        // Act — non-paginated path (page = 0) with datatables format
        $result = $model->_getApiList(
            [],
            '',
            '',
            '',
            '',
            '',
            $this->table,
            'id',
            0,   // page = 0 → no pagination
            10,
            false, false, false, false, false,
            'datatables'
        );

        // Assert
        $this->assertArrayHasKey('draw', $result);
        $this->assertSame(0, $result['draw'],
            'draw defaults to 0 when $_REQUEST[draw] is absent');
        $this->assertSame($result['recordsTotal'], count($result['data']),
            'recordsTotal must equal count(data) for the unpaginated path');
        $this->assertCount(5, $result['data'],
            'all 5 seeded rows must be returned without pagination');
    }

    // ── Phase 17 — _getJsonList() introspection unification ───────────────────

    /**
     * After replacing SHOW COLUMNS with _getAllTableFields(), _getJsonList() must
     * still return an aaData array — the DataTables 1.9 legacy response.
     *
     * The key regression-guard: the column list comes from _getAllTableFields()
     * (which works on both MySQL and PostgreSQL) instead of a raw SHOW COLUMNS.
     * On MySQL the result set must be identical; on PostgreSQL it no longer throws.
     */
    public function testGetJsonListUsesAllTableFieldsAndReturnsAaDataOnMysql(): void
    {
        // Arrange — ensure no DT 1.9 request vars bleed in from other tests
        unset($_POST['sEcho'], $_POST['iDisplayStart'], $_POST['iDisplayLength']);
        unset($_GET['sEcho'], $_GET['iDisplayStart'], $_GET['iDisplayLength']);

        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — _getJsonList() returns a JSON-encoded string (Datasource::getList() contract)
        $raw = $model->_getJsonList('', $this->table, 'id');
        $result = json_decode($raw, true);

        // Assert — DT 1.9 legacy keys still present after introspection change
        $this->assertIsArray($result, '_getJsonList must return valid JSON that decodes to an array');
        $this->assertArrayHasKey('aaData', $result,
            '_getJsonList must still return the aaData key (DataTables 1.9 BC)');
        $this->assertArrayHasKey('iTotalRecords', $result,
            '_getJsonList must still return iTotalRecords (DataTables 1.9 BC)');

        // All 5 seeded rows must be visible (no pagination requested)
        $this->assertCount(5, $result['aaData'],
            '_getJsonList must return all 5 seeded rows when no DT paging params set');
    }
}
