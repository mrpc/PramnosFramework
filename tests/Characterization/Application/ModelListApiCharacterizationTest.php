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
        // Until 2026-08-16 this collapsed to []. The cause was getData(), not the
        // field filtering it was blamed on: the model under test declares no public
        // properties, so its columns went through Base::__set into `_data` — and
        // getData() scanned object properties, saw `_data` as one array, and dropped
        // it whole with every column inside. A row of nothing, reported as a row.
        //
        // getData() reads the bag now, so useGetData returns the columns it selected.
        $this->assertSame(1, $first['id']);
        $this->assertSame('alpha', $first['name']);
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

    /**
     * With a global search that matches a subset, the DataTables envelope must
     * distinguish recordsTotal (the grand total, BEFORE the search box) from
     * recordsFiltered (the count AFTER the search). Regression guard for the
     * fix where both were previously set to the filtered total.
     *
     * Search 'alph' matches 'alpha' and 'alphabet' → 2 of 5 rows.
     */
    public function testGetApiListDataTablesRecordsTotalExcludesSearchOnMysql(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);
        $_REQUEST['draw'] = '3';

        // Act — paginated + datatables + a narrowing global search.
        $result = $model->_getApiList(
            [], 'alph', '', '', '', '',
            $this->table, 'id',
            1, 10,
            false, false, false, false, false,
            'datatables'
        );

        // Assert — recordsTotal is the full 5, recordsFiltered is the 2 matches.
        $this->assertSame(5, $result['recordsTotal'],
            'recordsTotal must be the grand total, unaffected by the search');
        $this->assertSame(2, $result['recordsFiltered'],
            'recordsFiltered must reflect only the rows matching the search');
        $this->assertCount(2, $result['data'],
            'data must contain only the 2 matching rows');

        unset($_REQUEST['draw']);
    }

    /**
     * Same distinction on the unpaginated (page = 0) datatables path: count(data)
     * is the filtered total, and recordsTotal is still the grand total.
     */
    public function testGetApiListDataTablesRecordsTotalExcludesSearchNoPaginationOnMysql(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);
        unset($_REQUEST['draw']);

        // Act — no pagination, datatables, narrowing search.
        $result = $model->_getApiList(
            [], 'alph', '', '', '', '',
            $this->table, 'id',
            0, 10,
            false, false, false, false, false,
            'datatables'
        );

        // Assert
        $this->assertSame(5, $result['recordsTotal'],
            'recordsTotal must be the grand total even without pagination');
        $this->assertSame(2, $result['recordsFiltered'],
            'recordsFiltered must be the matched-row count');
        $this->assertCount(2, $result['data']);
    }

    // ── Phase 1 lock-down (pre ApiListQuery extraction) ──────────────────────
    //
    // These snapshot the exact _getApiList() behaviours the standalone-engine
    // refactor must preserve byte-for-byte: the useGetData hydration path the
    // generated getApiList() wrapper uses, per-field search, order variants,
    // unknown-field dropping, GROUP BY, and the error envelope.

    /**
     * useGetData=true (the path the GENERATED getApiList() wrapper takes) hydrates
     * each row into a model instance, calls getData(), then prunes the payload
     * against the built SELECT clause. On the generic base Model that pruning
     * collapses every row to an EMPTY array (the built clause's aliased/quoted
     * field names do not line up with getData()'s plain keys) — the same quirk
     * already locked for _getList() in
     * {@see self::testGetListUseGetDataWithQueryFieldsFiltersPayload()}. This is
     * the exact contract the engine extraction must reproduce byte-for-byte; any
     * change here would silently alter every generated model's list endpoint.
     *
     * Note the deliberate argument alignment: positions are
     * (…, page, itemsPerPage, debug, returnAsModels, useGetData, …) — useGetData
     * is the 13th argument, returnAsModels the 12th.
     */
    public function testGetApiListUseGetDataMirrorsGeneratedWrapper(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — mirrors parent::_getApiList(fields, ..., returnAsModels=false, useGetData=true).
        $result = $model->_getApiList(
            ['id', 'name'], '', 'id ASC', '', '', '',
            $this->table, 'id',
            1, 10,
            false,     // 11 debug
            false,     // 12 returnAsModels
            true,      // 13 useGetData  ← the generated-wrapper path
            false,     // 14 customGetListMethod
            false,     // 15 addedfields
            ''         // 16 format → standard envelope
        );

        // Assert — envelope intact, 5 rows, first (id ASC) is alpha as a clean array.
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertCount(5, $result['data']);
        $this->assertSame(5, (int) $result['pagination']['totalitems']);

        // Until 2026-08-16 every row here was an empty array, and the note left
        // behind blamed a "getData/prune mismatch". The prune was innocent: this
        // model declares no public properties, so its columns live in `_data`, and
        // getData() dropped that bag whole while scanning object properties. The
        // endpoint returned the right number of rows with nothing in any of them —
        // a shape that reads as "no data for these records" rather than as a bug.
        // Asserted as "every row carries the fields it selected" rather than by
        // naming the fixture's values: the point is that rows are no longer empty,
        // and a test that also pins the seed data fails for the wrong reason when
        // somebody adds a row. The first draft of this did exactly that.
        foreach ($result['data'] as $index => $row) {
            $this->assertArrayHasKey('id', $row, "row {$index} lost its id");
            $this->assertArrayHasKey('name', $row, "row {$index} lost its name");
            $this->assertNotSame('', (string) $row['name']);
        }
        $this->assertSame(1, $result['data'][0]['id']);
    }

    /**
     * Per-field search passed as an associative array narrows on that column
     * only. 'gamm' on `name` matches the single 'gamma' row, proving the
     * $fieldSearches path (array → per-field LIKE) is distinct from global search.
     */
    public function testGetApiListPerFieldSearchArrayNarrowsRows(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — array search = per-field.
        $result = $model->_getApiList(
            ['id', 'name'], ['name' => 'gamm'], 'id ASC', '', '', '',
            $this->table, 'id',
            0, 10, false, false, false
        );

        // Assert — exactly the gamma row.
        $this->assertCount(1, $result['data']);
        $this->assertSame('gamma', $result['data'][0]['name']);
    }

    /**
     * Order handling: a '-name' token sorts descending, and an unknown order
     * field falls back to the primary key DESC — the same default as an empty
     * order (never an SQL error). Both are validated/sanitised by
     * _validateAndBuildOrder and must survive the extraction unchanged.
     */
    public function testGetApiListOrderDescAndInvalidFieldFallsBackToPrimaryKey(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — descending by name.
        $desc = $model->_getApiList(
            ['id', 'name'], '', '-name', '', '', '',
            $this->table, 'id', 0, 10, false, false, false
        );
        // Act — bogus order field → PK DESC fallback (no error).
        $fallback = $model->_getApiList(
            ['id', 'name'], '', 'not_a_column', '', '', '',
            $this->table, 'id', 0, 10, false, false, false
        );

        // Assert — desc: names in reverse-alphabetical order.
        $names = array_column($desc['data'], 'name');
        $sorted = $names;
        rsort($sorted);
        $this->assertSame($sorted, $names, "'-name' must sort descending");
        // Assert — fallback: strictly DESCENDING ids (PK default order is DESC).
        $ids = array_map('intval', array_column($fallback['data'], 'id'));
        $sortedIds = $ids;
        rsort($sortedIds);
        $this->assertSame($sortedIds, $ids, 'unknown order field falls back to PK desc');
    }

    /**
     * Requested fields that do not exist in the schema are silently dropped, but
     * the primary key is always force-included even when not requested. Locks the
     * field-validation contract the engine inherits.
     */
    public function testGetApiListUnknownRequestedFieldsDroppedPrimaryKeyKept(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — request only 'name' + a bogus column; id (PK) not requested.
        $result = $model->_getApiList(
            ['name', 'does_not_exist'], '', 'id ASC', '', '', '',
            $this->table, 'id', 1, 1, false, false, false
        );

        // Assert — row has name and the force-included id, but not the bogus key.
        $row = $result['data'][0];
        $this->assertArrayHasKey('id', $row, 'primary key is always included');
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayNotHasKey('does_not_exist', $row, 'unknown fields are dropped');
    }

    /**
     * A malformed raw filter must degrade to the error envelope (an 'error' key
     * plus empty data), not a fatal — the paginated branch catches the DB
     * exception. The engine extraction must keep this contract so callers still
     * receive a structured error.
     */
    public function testGetApiListReturnsErrorEnvelopeOnInvalidRawFilter(): void
    {
        // Arrange
        $model = $this->makeModel();
        $this->forceModelTable($model);

        // Act — a syntactically broken WHERE fragment.
        $result = $model->_getApiList(
            ['id', 'name'], '', '', 'id === =', '', '',
            $this->table, 'id', 1, 10, false, false, false
        );

        // Assert — structured error, empty data, null pagination.
        $this->assertArrayHasKey('error', $result, 'a broken filter yields an error envelope');
        $this->assertSame([], $result['data']);
        $this->assertNull($result['pagination']);
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

    // ── Regression — quoted qualified PK + JOIN sharing the PK column name ─────

    /**
     * A list query that JOINs a table which ALSO owns a column named like the
     * primary key must still return rows.
     *
     * Regression guard for _ensurePrimaryKeyInSelect(): generated queries put the
     * PK in the field list alias-qualified AND quoted (a.`id`). The buggy version
     * failed to recognise the quoted token as the already-present PK and prepended
     * a bare `id`. With the joined table also owning an `id` column, MySQL raised
     * "Column 'id' in field list is ambiguous", the query failed, and _getList
     * returned an empty array — silent data loss on a very common JOIN pattern.
     */
    public function testGetListWithJoinSharingPrimaryKeyColumnNameReturnsRows(): void
    {
        // Arrange — detail table that shares the `id` column name with the main PK
        $detail = $this->table . '_d';
        $this->db->query(
            "CREATE TABLE `{$detail}` ("
            . "`id` INT AUTO_INCREMENT PRIMARY KEY,"
            . "`main_id` INT NOT NULL,"
            . "`note` VARCHAR(80) NOT NULL"
            . ")"
        );
        // The first main row (name=alpha) has id=1 after seeding.
        $this->db->query(
            "INSERT INTO `{$detail}` (`main_id`, `note`) VALUES (1, 'note-alpha')"
        );

        $model = $this->makeModel();

        // Act — PK is alias-qualified and quoted, exactly like generated API SQL
        $rows = $model->_getList(
            "WHERE a.active = 1",
            "ORDER BY a.id ASC",
            $this->table,
            'id',
            false,
            "LEFT JOIN `{$detail}` b ON b.main_id = a.id",
            "a.`id`, a.`name`, b.`note`",
            '',
            false,
            false,
            false,
            false,
            []
        );

        $this->db->query("DROP TABLE IF EXISTS `{$detail}`");

        // Assert — must NOT collapse to zero rows
        $this->assertNotEmpty(
            $rows,
            'JOIN query with a quoted, qualified PK must return rows (not an ambiguous-column empty set)'
        );
        $this->assertSame('alpha', $rows[0]['name']);
        // Proves the joined column is really selected alongside the main PK.
        $this->assertSame('note-alpha', $rows[0]['note']);
    }
}
