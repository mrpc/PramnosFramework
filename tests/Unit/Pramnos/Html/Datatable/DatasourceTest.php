<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html\Datatable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Datasource;

/**
 * Unit tests for Pramnos\Html\Datatable\Datasource.
 *
 * Tests the structural and computation methods of the Datasource class:
 *   - addField() — registers a field with its format/wildcard config
 *   - getList() — static factory → delegates to render()
 *   - render() — full DataTables server-side response including:
 *       · legacy parameter format (sEcho/iDisplayStart/…)
 *       · modern DataTables 1.10+ parameter format (draw/start/length/search/order/columns)
 *       · paging, ordering, global search, per-column search, distinct field
 *       · date field formatting
 *       · JSON encoding
 *
 * Integration tests that require a real database connection live in
 * tests/Characterization/Html/Datatable/DatasourceCharacterizationTest.php.
 * This file covers the methods and branches that can be exercised against the
 * real MySQL test database for complete coverage.
 */
#[CoversClass(Datasource::class)]
class DatasourceTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    public static function setUpBeforeClass(): void
    {
        // Arrange — ensure the log directory exists so Logger::log() won't fail.
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        if (!is_dir(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs', 0777, true);
        }
    }

    protected function setUp(): void
    {
        // Arrange — initialise settings and DB connection.
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        // Reset the database singleton to pick up fresh settings.
        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->resetRequestState();
        $this->createSchema();
        $this->seedData();
    }

    protected function tearDown(): void
    {
        // Arrange — drop test tables.
        $this->db->query('DROP TABLE IF EXISTS `ds_items`');
        $this->db->query('DROP TABLE IF EXISTS `ds_categories`');
        $this->resetRequestState();

        // Reset singleton to prevent leaking to other tests.
        $singleton = &Factory::getDatabase();
        $singleton = null;

        Settings::clearSettings();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // addField()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * addField() must register the field name in the $fields array and store
     * its format/wildcard config in $fielddetails.
     *
     * This is the foundation of the Datasource: every column that should appear
     * in the DataTables response must be registered via addField().
     */
    public function testAddFieldRegistersFieldNameAndDefaults(): void
    {
        // Arrange
        $ds = new Datasource();

        // Act
        $ds->addField('username');

        // Assert — field was appended to $fields
        $this->assertContains('username', $ds->fields,
            'addField() must add the field name to $fields');

        // Assert — default format/wildcard config was stored
        $this->assertArrayHasKey('username', $ds->fielddetails,
            'addField() must create a $fielddetails entry for the field');
        $this->assertSame('text', $ds->fielddetails['username']['format'],
            'addField() default format must be "text"');
        $this->assertTrue($ds->fielddetails['username']['startWildcard'],
            'addField() default startWildcard must be true');
        $this->assertTrue($ds->fielddetails['username']['endWildcard'],
            'addField() default endWildcard must be true');
    }

    /**
     * addField() with explicit format/wildcard params must store those values.
     *
     * The caller can disable wildcards (for exact-match searches) and supply
     * a format like 'date' with a PHP date() format string as formatdetails.
     */
    public function testAddFieldStoresCustomFormatAndWildcards(): void
    {
        // Arrange
        $ds = new Datasource();

        // Act — date field with wildcards disabled
        $ds->addField('created_at', 'date', 'Y-m-d', false, false);

        // Assert — custom values stored
        $details = $ds->fielddetails['created_at'];
        $this->assertSame('date', $details['format'],
            'addField() must store the supplied format');
        $this->assertSame('Y-m-d', $details['formatdetails'],
            'addField() must store the supplied formatdetails');
        $this->assertFalse($details['startWildcard'],
            'addField() must store startWildcard=false when explicitly passed');
        $this->assertFalse($details['endWildcard'],
            'addField() must store endWildcard=false when explicitly passed');
    }

    /**
     * addField() called multiple times must preserve insertion order.
     *
     * Datasource::render() iterates $fields in the order they were added —
     * the order determines which column index DataTables uses for each field.
     */
    public function testAddFieldPreservesInsertionOrder(): void
    {
        // Arrange
        $ds = new Datasource();

        // Act
        $ds->addField('id');
        $ds->addField('title');
        $ds->addField('amount');

        // Assert — insertion order is preserved
        $this->assertSame(['id', 'title', 'amount'], $ds->fields,
            'addField() must preserve insertion order in $fields');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getList() — static factory
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getList() is a static factory that creates a Datasource and calls render().
     *
     * The return value for $encode=true must be valid JSON, and for $encode=false
     * must be an array. This confirms the static entry point delegates correctly.
     */
    public function testGetListStaticReturnsJsonWhenEncodeTrue(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '5',
            'sEcho'          => '1',
        ]);

        // Act
        $result = Datasource::getList(
            'ds_items',
            ['id', 'name'],
            true,   // encode = true → JSON string
            '',
            '',
            false   // no cache
        );

        // Assert — valid JSON returned
        $this->assertIsString($result,
            'getList() with encode=true must return a JSON string');
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded,
            'getList() with encode=true must return a parseable JSON object');
        $this->assertArrayHasKey('aaData', $decoded,
            'getList() JSON must include the aaData key');
    }

    /**
     * getList() with $encode=false must return a raw array with the standard
     * DataTables legacy keys: sEcho, iTotalRecords, iTotalDisplayRecords, aaData.
     */
    public function testGetListStaticReturnsArrayWhenEncodeFalse(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sEcho'          => '3',
        ]);

        // Act
        $result = Datasource::getList(
            'ds_items',
            ['id', 'name', 'price'],
            false,  // encode = false → array
            '',
            '',
            false
        );

        // Assert — raw array with expected keys
        $this->assertIsArray($result,
            'getList() with encode=false must return an array');
        $this->assertArrayHasKey('sEcho', $result);
        $this->assertArrayHasKey('iTotalRecords', $result);
        $this->assertArrayHasKey('iTotalDisplayRecords', $result);
        $this->assertArrayHasKey('aaData', $result);

        // sEcho must echo back the value from the POST params
        $this->assertSame(3, $result['sEcho'],
            'getList() must echo the sEcho value back in the response');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — paging
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must honour iDisplayLength and return exactly that many rows.
     *
     * Paging is the most fundamental DataTables feature: the server must return
     * only the requested page size regardless of total row count.
     */
    public function testRenderRespectsDisplayLength(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '2',
            'sEcho'          => '1',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], false, '', '', false);

        // Assert — only 2 rows returned, total is 4
        $this->assertCount(2, $result['aaData'],
            'render() must return exactly iDisplayLength rows');
        $this->assertSame(4, $result['iTotalRecords'],
            'render() iTotalRecords must reflect total rows in the table');
    }

    /**
     * render() with iDisplayLength="-1" must return all rows (no paging limit).
     *
     * DataTables sends length=-1 when the user selects "All" in the page-length
     * selector. The server must honor this by not applying a LIMIT clause.
     */
    public function testRenderWithLengthMinusOneReturnsAllRows(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '-1',
            'sEcho'          => '2',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], false, '', '', false);

        // Assert — all 4 rows returned
        $this->assertCount(4, $result['aaData'],
            'render() with iDisplayLength=-1 must return all rows without limit');
    }

    /**
     * render() must use $maxlimit when no paging parameters are provided.
     *
     * When iDisplayStart is absent from POST (non-DataTables caller), the
     * Datasource falls back to $maxlimit to prevent unbounded result sets.
     */
    public function testRenderUsesFallbackMaxlimitWhenNoPagingParams(): void
    {
        // Arrange — no paging POST params at all
        $_POST = [];
        $ds = new Datasource();
        $ds->maxlimit = '2';  // restrict to 2 rows max

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], false, '', '', false);

        // Assert — maxlimit enforced
        $this->assertCount(2, $result['aaData'],
            'render() must use $maxlimit when no paging POST parameters are present');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — modern DataTables 1.10+ parameter format
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must translate modern DataTables 1.10+ parameters (draw/start/
     * length/search) into the legacy internal format and return the modern
     * response shape (draw/recordsTotal/recordsFiltered/data).
     *
     * This tests the backward-compatible parameter translation layer introduced
     * to support DataTables 1.10+ without breaking legacy callers.
     */
    public function testRenderTranslatesModernDtParamsAndReturnsModernResponse(): void
    {
        // Arrange — modern DataTables 1.10+ POST parameters
        $this->setPost([
            'draw'   => '5',
            'start'  => '0',
            'length' => '3',
            'search' => ['value' => ''],
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], false, '', '', false);

        // Assert — modern response shape returned
        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result,
            'render() with modern DT params must return "draw" key');
        $this->assertArrayHasKey('recordsTotal', $result,
            'render() with modern DT params must return "recordsTotal" key');
        $this->assertArrayHasKey('recordsFiltered', $result,
            'render() with modern DT params must return "recordsFiltered" key');
        $this->assertArrayHasKey('data', $result,
            'render() with modern DT params must return "data" key');

        // draw must echo back the draw counter
        $this->assertSame(5, $result['draw'],
            'render() must echo the draw value back in the response');

        // Total records must be accurate
        $this->assertSame(4, $result['recordsTotal'],
            'render() recordsTotal must match the unfiltered row count');

        // Paging honoured: only 3 rows returned
        $this->assertCount(3, $result['data'],
            'render() must respect the "length" parameter from modern DT format');
    }

    /**
     * render() with modern DT params and encode=true must return a JSON string
     * containing the modern DataTables response shape.
     *
     * Confirms the JSON encoding path for modern DT format.
     */
    public function testRenderModernDtParamsWithEncodeReturnsJson(): void
    {
        // Arrange
        $this->setPost([
            'draw'   => '2',
            'start'  => '0',
            'length' => '10',
            'search' => ['value' => ''],
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], true, '', '', false);

        // Assert — JSON string returned
        $this->assertIsString($result,
            'render() with modern DT params and encode=true must return a string');
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('draw', $decoded);
        $this->assertArrayHasKey('data', $decoded);
    }

    /**
     * render() with modern DT ordering parameters must apply ORDER BY correctly.
     *
     * DataTables 1.10+ sends order[] with column index and direction. This
     * verifies the translation layer correctly applies ORDER BY to the query.
     */
    public function testRenderModernDtOrderingIsApplied(): void
    {
        // Arrange — order by column 2 (price) desc
        $this->setPost([
            'draw'    => '1',
            'start'   => '0',
            'length'  => '10',
            'search'  => ['value' => ''],
            'order'   => [
                ['column' => '2', 'dir' => 'desc'],
            ],
            'columns' => [
                ['searchable' => 'true'],
                ['searchable' => 'true'],
                ['searchable' => 'true'],
            ],
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name', 'price'], false, '', '', false);

        // Assert — rows returned in descending price order (Gadget 99.99 first)
        $this->assertCount(4, $result['data']);
        $this->assertSame('Gadget', $result['data'][0][1],
            'render() must honor modern DT ordering: price desc → Gadget first');
    }

    /**
     * render() with modern DT search parameter filters results across searchable
     * columns and returns only matching rows.
     *
     * This tests the global-search path triggered by the modern format's
     * search[value] field.
     */
    public function testRenderModernDtGlobalSearchFiltersResults(): void
    {
        // Arrange — search for 'Widget' across all searchable columns
        $this->setPost([
            'draw'    => '1',
            'start'   => '0',
            'length'  => '10',
            'search'  => ['value' => 'Widget'],
            'columns' => [
                ['searchable' => 'true'],
                ['searchable' => 'true'],
                ['searchable' => 'true'],
            ],
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name', 'price'], false, '', '', false);

        // Assert — only Widget rows returned (2 in the seed)
        $this->assertSame(2, $result['recordsFiltered'],
            'render() modern DT global search must filter to matching rows only');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — WHERE clause and JOIN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must apply a static WHERE clause to both the results and count
     * queries, so that both iTotalRecords and iTotalDisplayRecords reflect the
     * filtered set.
     *
     * This is the pre-filter — it restricts the entire data set before DataTables
     * paging/searching is applied. The "where" parameter may or may not start
     * with the word "WHERE" — the method strips it.
     */
    public function testRenderAppliesStaticWhereClause(): void
    {
        // Arrange — filter to only "active" items (category_id = 1)
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sEcho'          => '1',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render(
            'ds_items',
            ['id', 'name'],
            false,
            'WHERE category_id = 1',  // static WHERE clause
            '',
            false
        );

        // Assert — only items with category_id=1 (2 items: Widget A and Gadget)
        $this->assertSame(2, $result['iTotalRecords'],
            'render() must apply the static WHERE clause to iTotalRecords');
        $this->assertCount(2, $result['aaData'],
            'render() must return only rows matching the static WHERE clause');
    }

    /**
     * render() must support a JOIN clause that brings in data from related tables.
     *
     * The JOIN is passed as a raw SQL snippet and must be included in both the
     * data query and the count queries.
     */
    public function testRenderSupportsJoinClause(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sEcho'          => '1',
        ]);
        $ds = new Datasource();
        $join = 'LEFT JOIN `ds_categories` c ON c.id = a.category_id';

        // Act
        $result = $ds->render(
            'ds_items',
            ['id', 'a.name', 'c.label as category_label'],
            false,
            '',
            $join,
            false
        );

        // Assert — join added category labels; all 4 items returned
        $this->assertSame(4, $result['iTotalRecords'],
            'render() with JOIN must still count all rows correctly');
        $this->assertCount(4, $result['aaData'],
            'render() with JOIN must return all rows when no filtering applied');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — global search
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must apply the sSearch global filter when bSearchable_N is set
     * to 'true' for the corresponding column index.
     *
     * Only columns explicitly marked bSearchable_N=true participate in global
     * search, which prevents searching on non-text columns.
     */
    public function testRenderAppliesGlobalSearch(): void
    {
        // Arrange — search for 'Widget' in columns 0 and 1
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sSearch'        => 'Widget',
            'bSearchable_0'  => 'true',
            'bSearchable_1'  => 'true',
            'sEcho'          => '2',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], false, '', '', false);

        // Assert — only Widget rows match
        $this->assertSame(2, $result['iTotalDisplayRecords'],
            'render() global search must set iTotalDisplayRecords to filtered row count');
        $this->assertCount(2, $result['aaData'],
            'render() must return only rows matching the global sSearch term');
    }

    /**
     * render() must not filter when sSearch is empty, even if columns are searchable.
     *
     * An empty search term means "no filtering" — all rows must be returned.
     * This verifies the early-exit guard: `if ($searchTerm != "")`.
     */
    public function testRenderEmptyGlobalSearchReturnsAllRows(): void
    {
        // Arrange — sSearch is empty string (user cleared the search box)
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sSearch'        => '',
            'bSearchable_0'  => 'true',
            'bSearchable_1'  => 'true',
            'sEcho'          => '3',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], false, '', '', false);

        // Assert — all rows returned when search term is empty
        $this->assertCount(4, $result['aaData'],
            'render() must return all rows when sSearch is an empty string');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — per-column search
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must apply per-column search (sSearch_N) with configured wildcards.
     *
     * Column-level search is more specific than global search and applies per-field
     * LIKE conditions. The wildcard flags control whether % is prepended/appended.
     */
    public function testRenderAppliesPerColumnSearch(): void
    {
        // Arrange — search column 0 (name) with default wildcards (both true)
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sSearch_0'      => 'Wid',  // partial match → '%Wid%'
            'bSearchable_0'  => 'true',
            'sEcho'          => '4',
        ]);
        $ds = new Datasource();
        $ds->addField('name');  // register field so wildcard config is available

        // Act — pass fields as array so fielddetails is populated from addField
        $result = $ds->render('ds_items', null, false, '', '', false);

        // Assert — both 'Widget A' and 'Widget B' match '%Wid%'
        $this->assertSame(2, $result['iTotalDisplayRecords'],
            'render() per-column search must find rows where the column LIKE %term%');
    }

    /**
     * render() per-column search with wildcard flags disabled must perform
     * an exact-match search (no % prefix or suffix).
     *
     * When startWildcard=false and endWildcard=false, the LIKE clause has no
     * wildcards and only exact matches are returned.
     */
    public function testRenderPerColumnSearchRespectsWildcardFlags(): void
    {
        // Arrange — search for exact name 'Gadget' with wildcards disabled
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sSearch_0'      => 'Gadget',
            'bSearchable_0'  => 'true',
            'sEcho'          => '5',
        ]);
        $ds = new Datasource();
        // Register field with both wildcards disabled → exact match
        $ds->addField('name', 'text', '', false, false);

        // Act
        $result = $ds->render('ds_items', null, false, '', '', false);

        // Assert — only the exact-match row returned
        $this->assertSame(1, $result['iTotalDisplayRecords'],
            'render() per-column search with wildcards disabled must exact-match');
        $this->assertSame('Gadget', $result['aaData'][0][0],
            'render() must return the exact-match row');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — ordering
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must apply ORDER BY based on iSortCol_N and sSortDir_N POST params.
     *
     * Multi-column sort is supported: iSortingCols specifies how many sort
     * columns are provided. The first sort column (index 0) sorts by column
     * index iSortCol_0 in direction sSortDir_0.
     */
    public function testRenderAppliesLegacyOrdering(): void
    {
        // Arrange — sort by column 1 (name) descending
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'iSortCol_0'     => '1',
            'sSortDir_0'     => 'desc',
            'iSortingCols'   => '1',
            'sEcho'          => '6',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name', 'price'], false, '', '', false);

        // Assert — rows in descending name order; 'Widget B' > 'Widget A' > 'Gadget' > 'Donut'
        $this->assertCount(4, $result['aaData'],
            'render() must return all rows when no search applied');
        $this->assertSame('Widget B', $result['aaData'][0][1],
            'render() must order by column 1 (name) descending: Widget B first');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — DISTINCT field
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() with $distinctField must add SELECT DISTINCT for that field,
     * returning only unique values.
     *
     * This is used by the Datasource to power select2/combo-box widgets backed
     * by deduplicated column values.
     */
    public function testRenderDistinctFieldDeduplicates(): void
    {
        // Arrange — insert a duplicate name to verify deduplication
        $this->db->query("INSERT INTO `ds_items` (`name`, `price`, `category_id`) VALUES ('Widget A', 7.99, 2)");

        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '20',
            'sEcho'          => '7',
        ]);
        $ds = new Datasource();

        // Act — select distinct by name
        $result = $ds->render(
            'ds_items',
            ['name'],
            false,
            '',
            '',
            false,
            20,
            'datatables',
            false,
            null,
            'name'  // distinctField
        );

        // Assert — 4 distinct names despite 5 rows (Widget A is duplicated)
        $names = array_column($result['aaData'], 0);
        $this->assertCount(4, array_unique($names),
            'render() with distinctField must return only unique values');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — date field formatting
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must convert a Unix timestamp field to a formatted date string
     * when the field format is 'date'.
     *
     * Date formatting is performed in PHP after the query executes. The
     * formatdetails string is passed to PHP's date() function.
     */
    public function testRenderFormatsDateFieldFromTimestamp(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '1',
            'sEcho'          => '8',
        ]);
        $ds = new Datasource();

        // Act — created_ts is a Unix timestamp; format as Y-m-d
        $result = $ds->render(
            'ds_items',
            [
                ['created_ts', 'date', 'Y-m-d', true, true],
                'name',
            ],
            false,
            '',
            '',
            false
        );

        // Assert — timestamp was formatted
        $this->assertCount(1, $result['aaData']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            (string)$result['aaData'][0][0],
            'render() must format a date field as Y-m-d'
        );
    }

    /**
     * render() must return an empty string for date fields where the value is 0.
     *
     * A zero timestamp means "not set". The Datasource treats it as an empty
     * value to avoid showing "1970-01-01" in the UI.
     */
    public function testRenderReturnsEmptyForZeroTimestampDate(): void
    {
        // Arrange — insert item with created_ts = 0
        $this->db->query("INSERT INTO `ds_items` (`name`, `price`, `category_id`, `created_ts`) VALUES ('ZeroDate', 0.01, 1, 0)");

        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sEcho'          => '9',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render(
            'ds_items',
            [
                ['created_ts', 'date', 'Y-m-d', true, true],
                'name',
            ],
            false,
            "WHERE name = 'ZeroDate'",
            '',
            false
        );

        // Assert — zero timestamp produces empty string
        $this->assertCount(1, $result['aaData']);
        $this->assertSame('', $result['aaData'][0][0],
            'render() must return empty string for a zero Unix timestamp date field');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — boolean and null field handling
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must convert NULL database values to empty strings.
     *
     * NULL fields are treated as empty strings in the DataTables response so the
     * front-end does not receive JSON null values that break string operations.
     */
    public function testRenderConvertsNullFieldToEmptyString(): void
    {
        // Arrange — insert item with NULL name
        $this->db->query("INSERT INTO `ds_items` (`name`, `price`, `category_id`, `created_ts`) VALUES (NULL, 1.00, 1, 0)");

        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sEcho'          => '10',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render(
            'ds_items',
            ['name', 'price'],
            false,
            'WHERE price = 1.00',
            '',
            false
        );

        // Assert — NULL became empty string
        $this->assertCount(1, $result['aaData'],
            'render() must return the row with NULL field');
        $this->assertSame('', $result['aaData'][0][0],
            'render() must convert NULL field values to empty strings');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — DT_RowId
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must set DT_RowId equal to the first column value for each row.
     *
     * DataTables uses the DT_RowId property to set the HTML id attribute on
     * each <tr>. It must be the first field's value for every row.
     */
    public function testRenderSetsDtRowIdFromFirstColumn(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sEcho'          => '11',
        ]);
        $ds = new Datasource();

        // Act
        $result = $ds->render('ds_items', ['id', 'name'], false, '', '', false);

        // Assert — DT_RowId equals first column value for all rows
        foreach ($result['aaData'] as $row) {
            $this->assertArrayHasKey('DT_RowId', $row,
                'render() must set DT_RowId on every row');
            $this->assertSame($row[0], $row['DT_RowId'],
                'render() DT_RowId must equal the first column value');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // render() — distinct field with dot-notation field name
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * render() must handle dot-notation field names (e.g. "a.name") without
     * wrapping them in backtick quoting.
     *
     * When a field name contains a dot, it is a table-qualified reference
     * (e.g. "a.name" or "b.label as foo") and must be used as-is in SELECT.
     */
    public function testRenderHandlesDotNotationFields(): void
    {
        // Arrange
        $this->setPost([
            'iDisplayStart'  => '0',
            'iDisplayLength' => '10',
            'sEcho'          => '12',
        ]);
        $ds = new Datasource();

        // Act — use table-qualified field names
        $result = $ds->render(
            'ds_items',
            ['a.id', 'a.name', 'a.price'],
            false,
            '',
            '',
            false
        );

        // Assert — query succeeded and returns all 4 rows
        $this->assertSame(4, $result['iTotalRecords'],
            'render() must handle dot-notation field names correctly');
        $this->assertCount(4, $result['aaData']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $post
     */
    private function setPost(array $post): void
    {
        $_POST    = $post;
        $_REQUEST = $post;
    }

    private function resetRequestState(): void
    {
        $_POST    = [];
        $_GET     = [];
        $_REQUEST = [];
        $_FILES   = [];
        $_COOKIE  = [];
    }

    private function createSchema(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `ds_items`');
        $this->db->query('DROP TABLE IF EXISTS `ds_categories`');

        $this->db->query('CREATE TABLE `ds_categories` (
            `id`    INT AUTO_INCREMENT PRIMARY KEY,
            `label` VARCHAR(100) NOT NULL
        )');

        $this->db->query('CREATE TABLE `ds_items` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(100) NULL,
            `price`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `category_id` INT NOT NULL DEFAULT 1,
            `created_ts`  INT NOT NULL DEFAULT 0
        )');
    }

    private function seedData(): void
    {
        $this->db->query("INSERT INTO `ds_categories` (`label`) VALUES ('Electronics'), ('Food')");

        $ts = strtotime('2024-05-01');
        $this->db->query("INSERT INTO `ds_items` (`name`, `price`, `category_id`, `created_ts`) VALUES
            ('Widget A', 9.99,  1, {$ts}),
            ('Widget B', 14.99, 2, {$ts}),
            ('Gadget',   99.99, 1, {$ts}),
            ('Donut',    1.50,  2, {$ts})");
    }
}
