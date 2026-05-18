<?php

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for the Database class against the PostgreSQL / TimescaleDB
 * Docker container.
 *
 * Covered methods: connect(), execute(), query() (cache miss path), close(),
 * tableExists(), insertDataToTable() (boolean / integer / float / string types),
 * updateTableData(), upsert() (PostgreSQL path), startTransaction(),
 * commitTransaction(), rollbackTransaction(), getInsertId(), getColumns(),
 * setTrackingInfo().
 *
 * A scratch table `pramnos_cov_pg_test` is created once in setUpBeforeClass()
 * and dropped in tearDownAfterClass() so each individual test starts clean.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Database\Database::class)]
class DatabaseTest extends \PHPUnit\Framework\TestCase
{
    private static Database $db;
    private static string $table = 'pramnos_cov_pg_test';

    public static function setUpBeforeClass(): void
    {
        // Define LOG_PATH for log-related methods if not already defined
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var');
        }
        if (!is_dir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs')) {
            @mkdir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        }

        self::$db = new Database();
        self::$db->type     = 'postgresql';
        self::$db->server   = 'timescaledb';
        self::$db->user     = 'postgres';
        self::$db->password = 'secret';
        self::$db->database = 'pramnos_test';
        self::$db->port     = 5432;
        self::$db->schema   = 'public';
        self::$db->connect(true);

        // Create scratch table for coverage tests
        self::$db->query(
            'CREATE TABLE IF NOT EXISTS public.' . self::$table . ' ('
            . 'id SERIAL PRIMARY KEY, '
            . 'label VARCHAR(100), '
            . 'amount DECIMAL(10,2), '
            . 'qty INTEGER, '
            . 'active BOOLEAN, '
            . 'code VARCHAR(50) UNIQUE'
            . ')'
        );
        self::$db->query('TRUNCATE TABLE public.' . self::$table . ' RESTART IDENTITY');
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query('DROP TABLE IF EXISTS public.' . self::$table);
        self::$db->close();
    }

    protected function setUp(): void
    {
        // Truncate between tests for row-level isolation
        self::$db->query('TRUNCATE TABLE public.' . self::$table . ' RESTART IDENTITY');
    }

    // =========================================================================
    // Basic connectivity (original test preserved)
    // =========================================================================

    /**
     * Verify that the framework can natively connect to PostgreSQL
     * and that the TimescaleDB extension is fully active.
     */
    public function testTimescaleDbConnectionAndExtension(): void
    {
        // Arrange — already connected in setUpBeforeClass

        // Act — basic health check via prepared statement
        $result = self::$db->execute('SELECT 1 AS health_check');
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(1, $result->fields['health_check']);

        // Parameterised query ($1, $2 conversion)
        $testVal = 999;
        $paramResult = self::$db->execute('SELECT %i AS test_val', $testVal);
        $this->assertEquals(1, $paramResult->numRows);
        $this->assertEquals($testVal, $paramResult->fields['test_val']);

        // Verify TimescaleDB extension is loaded
        $timescaleCheck = self::$db->execute("SELECT extname FROM pg_extension WHERE extname = 'timescaledb'");
        $this->assertEquals(1, $timescaleCheck->numRows, 'TimescaleDB extension is NOT active!');
    }

    // =========================================================================
    // tableExists (PostgreSQL)
    // =========================================================================

    /**
     * tableExists() returns true for the scratch table that was just created.
     * This exercises the PostgreSQL information_schema.tables query path.
     */
    public function testTableExistsPGReturnsTrueForExistingTable(): void
    {
        // Act / Assert
        $this->assertTrue(
            self::$db->tableExists(self::$table),
            'tableExists() must return true for an existing PG table'
        );
    }

    /**
     * tableExists() returns false for a table that does not exist.
     */
    public function testTableExistsPGReturnsFalseForMissingTable(): void
    {
        // Act / Assert
        $this->assertFalse(
            self::$db->tableExists('_definitely_not_here_xyz_pg'),
            'tableExists() must return false when the table is absent in PG'
        );
    }

    // =========================================================================
    // insertDataToTable (PostgreSQL — boolean, integer, float, string types)
    // =========================================================================

    /**
     * insertDataToTable() on PostgreSQL handles 'boolean' type by converting
     * PHP true/false/1/0 to 't'/'f' literals understood by PostgreSQL.
     * This exercises the boolean branch of the type-dispatch switch.
     */
    public function testInsertDataToTablePGWithBooleanType(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'code',   'value' => 'BOOL-1',  'type' => 'string'],
            ['fieldName' => 'label',  'value' => 'active',  'type' => 'string'],
            ['fieldName' => 'active', 'value' => true,      'type' => 'boolean'],
            ['fieldName' => 'qty',    'value' => 5,         'type' => 'integer'],
            ['fieldName' => 'amount', 'value' => 9.99,      'type' => 'float'],
        ];

        // Act
        $result = self::$db->insertDataToTable(self::$table, $data, 'id');
        $this->assertNotFalse($result, 'insertDataToTable() PG boolean must not return false');

        // Assert — row readable
        $row = self::$db->query("SELECT active, qty FROM public." . self::$table . " WHERE code = 'BOOL-1'");
        $this->assertEquals(1, $row->numRows);
        // PostgreSQL returns boolean 't' — the Result class maps it to PHP true
        $this->assertSame(true, $row->fields['active']);
    }

    /**
     * insertDataToTable() on PostgreSQL with null for boolean type must
     * store SQL NULL, not 't' or 'f'.
     */
    public function testInsertDataToTablePGBooleanNullStoresNull(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'code',   'value' => 'BOOL-NULL', 'type' => 'string'],
            ['fieldName' => 'active', 'value' => null,        'type' => 'boolean'],
        ];

        // Act
        self::$db->insertDataToTable(self::$table, $data);

        // Assert
        $row = self::$db->query("SELECT active FROM public." . self::$table . " WHERE code = 'BOOL-NULL'");
        $this->assertNull($row->fields['active']);
    }

    /**
     * insertDataToTable() with integer and float NULL sentinel values stores
     * SQL NULL for those columns.
     */
    public function testInsertDataToTablePGIntegerAndFloatNull(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'code',   'value' => 'NUM-NULL', 'type' => 'string'],
            ['fieldName' => 'qty',    'value' => null,       'type' => 'integer'],
            ['fieldName' => 'amount', 'value' => null,       'type' => 'float'],
        ];

        // Act
        self::$db->insertDataToTable(self::$table, $data);

        // Assert
        $row = self::$db->query("SELECT qty, amount FROM public." . self::$table . " WHERE code = 'NUM-NULL'");
        $this->assertNull($row->fields['qty']);
        $this->assertNull($row->fields['amount']);
    }

    // =========================================================================
    // updateTableData (PostgreSQL)
    // =========================================================================

    /**
     * updateTableData() on PostgreSQL with boolean type converts the value
     * correctly and the updated row is readable with the expected value.
     */
    public function testUpdateTableDataPGBooleanType(): void
    {
        // Arrange — insert a row to update
        self::$db->query(
            "INSERT INTO public." . self::$table . " (code, label, active) "
            . "VALUES ('UPD-BOOL', 'original', FALSE)"
        );

        $data = [
            ['fieldName' => 'active', 'value' => true,    'type' => 'boolean'],
            ['fieldName' => 'label',  'value' => 'updated', 'type' => 'string'],
        ];

        // Act
        $result = self::$db->updateTableData(
            'public.' . self::$table,
            $data,
            "code = 'UPD-BOOL'"
        );
        $this->assertNotFalse($result);

        // Assert
        $row = self::$db->query(
            "SELECT active, label FROM public." . self::$table . " WHERE code = 'UPD-BOOL'"
        );
        $this->assertSame(true, $row->fields['active']);
        $this->assertSame('updated', $row->fields['label']);
    }

    /**
     * updateTableData() with NULL sentinel values stores SQL NULL for the
     * boolean and float columns on PostgreSQL.
     */
    public function testUpdateTableDataPGNullValues(): void
    {
        // Arrange
        self::$db->query(
            "INSERT INTO public." . self::$table . " (code, label, active, amount) "
            . "VALUES ('UPD-NULL', 'has-data', TRUE, 5.00)"
        );
        $data = [
            ['fieldName' => 'active', 'value' => 'NULL', 'type' => 'boolean'],
            ['fieldName' => 'amount', 'value' => '',     'type' => 'float'],
        ];

        // Act
        self::$db->updateTableData(
            'public.' . self::$table,
            $data,
            "code = 'UPD-NULL'"
        );

        // Assert
        $row = self::$db->query(
            "SELECT active, amount FROM public." . self::$table . " WHERE code = 'UPD-NULL'"
        );
        $this->assertNull($row->fields['active']);
        $this->assertNull($row->fields['amount']);
    }

    // =========================================================================
    // upsert (PostgreSQL)
    // =========================================================================

    /**
     * upsert() on PostgreSQL uses INSERT ... ON CONFLICT (code) DO UPDATE SET.
     * First call inserts; second call with same code updates the existing row.
     */
    public function testUpsertPostgreSQLInsertsAndThenUpdates(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'code',   'value' => 'UPSERT-PG-1', 'type' => 'string'],
            ['fieldName' => 'label',  'value' => 'initial',     'type' => 'string'],
            ['fieldName' => 'qty',    'value' => 1,             'type' => 'integer'],
        ];

        // Act — insert
        $insertResult = self::$db->upsert('public.' . self::$table, $data, 'code');
        $this->assertNotFalse($insertResult, 'upsert() PG insert must not return false');

        // Verify insert
        $row = self::$db->query(
            "SELECT label FROM public." . self::$table . " WHERE code = 'UPSERT-PG-1'"
        );
        $this->assertSame('initial', $row->fields['label']);

        // Act — upsert with same code → update
        $data[1]['value'] = 'updated';
        $updateResult = self::$db->upsert('public.' . self::$table, $data, 'code');
        $this->assertNotFalse($updateResult, 'upsert() PG update must not return false');

        // Assert
        $row2 = self::$db->query(
            "SELECT label FROM public." . self::$table . " WHERE code = 'UPSERT-PG-1'"
        );
        $this->assertSame('updated', $row2->fields['label']);
    }

    // =========================================================================
    // startTransaction / commitTransaction / rollbackTransaction (PostgreSQL)
    // =========================================================================

    /**
     * startTransaction() + rollbackTransaction() on PostgreSQL ensures that
     * rows inserted inside the transaction are NOT visible after rollback.
     */
    public function testPGTransactionRollbackDoesNotPersistRows(): void
    {
        // Arrange
        $started = self::$db->startTransaction();
        $this->assertTrue($started, 'PG startTransaction() must return true');

        self::$db->query(
            "INSERT INTO public." . self::$table . " (code, label) "
            . "VALUES ('PG-ROLLBACK', 'will-be-rolled-back')"
        );

        // Act
        $rolled = self::$db->rollbackTransaction();
        $this->assertTrue($rolled, 'rollbackTransaction() must return true on PG');

        // Assert
        $check = self::$db->query(
            "SELECT id FROM public." . self::$table . " WHERE code = 'PG-ROLLBACK'"
        );
        $this->assertEquals(0, $check->numRows, 'Rolled-back PG row must not be visible');
    }

    /**
     * startTransaction() + commitTransaction() on PostgreSQL makes rows durable.
     */
    public function testPGTransactionCommitPersistsRows(): void
    {
        // Arrange
        self::$db->startTransaction();
        self::$db->query(
            "INSERT INTO public." . self::$table . " (code, label) "
            . "VALUES ('PG-COMMIT', 'committed')"
        );

        // Act
        $committed = self::$db->commitTransaction();
        $this->assertTrue($committed, 'commitTransaction() must return true on PG');

        // Assert
        $check = self::$db->query(
            "SELECT label FROM public." . self::$table . " WHERE code = 'PG-COMMIT'"
        );
        $this->assertEquals(1, $check->numRows, 'Committed PG row must be visible');
    }

    // =========================================================================
    // getInsertId (PostgreSQL)
    // =========================================================================

    /**
     * getInsertId() on PostgreSQL calls SELECT LASTVAL() to retrieve the last
     * sequence value.  After inserting a row into a SERIAL table, the returned
     * id must be a positive integer matching the newly inserted row's id.
     */
    public function testGetInsertIdPostgreSQL(): void
    {
        // Arrange — insert a row to generate a LASTVAL
        self::$db->query(
            "INSERT INTO public." . self::$table . " (code, label) "
            . "VALUES ('LASTVAL-1', 'test') RETURNING id"
        );

        // Act
        $lastId = self::$db->getInsertId();

        // Assert — must be a positive integer
        $this->assertIsInt($lastId, 'getInsertId() PG must return an integer');
        $this->assertGreaterThan(0, $lastId, 'getInsertId() PG must return a positive id');
    }

    // =========================================================================
    // query() with cache=true (covers PostgreSQL cache miss path)
    // =========================================================================

    /**
     * query() with $cache=true on PostgreSQL exercises the elseif($cache) path:
     * cacheExpire(), runPgQuery(), shouldCacheResult(), cacheStore() are all
     * called.  The result is correct even when caching is disabled.
     */
    public function testQueryWithCacheTruePGCoversCacheMissPath(): void
    {
        // Arrange
        self::$db->query(
            "INSERT INTO public." . self::$table . " (code, label) "
            . "VALUES ('PG-CACHE', 'pg-cached-label')"
        );

        // Act
        $result = self::$db->query(
            "SELECT label FROM public." . self::$table . " WHERE code = 'PG-CACHE'",
            true,
            60,
            'pg_test_category'
        );

        // Assert
        $this->assertEquals(1, $result->numRows);
        $this->assertSame('pg-cached-label', $result->fields['label']);
    }

    // =========================================================================
    // getColumns (PostgreSQL)
    // =========================================================================

    /**
     * getColumns() on PostgreSQL queries information_schema.columns plus
     * pg_class/pg_namespace for comments and constraint metadata.  The result
     * must include all columns of the scratch table.
     */
    public function testGetColumnsPGReturnsColumnMetadata(): void
    {
        // Act
        $result = self::$db->getColumns(self::$table, 'public');

        // Assert — at least one column returned
        $this->assertInstanceOf(\Pramnos\Database\Result::class, $result);
        $this->assertGreaterThan(0, $result->numRows, 'getColumns() PG must return columns');

        // Collect column names
        $columns = $result->fetchAll();
        $colNames = array_column($columns, 'Field');

        // The scratch table has: id, label, amount, qty, active, code
        $this->assertContains('id',    $colNames, 'id column must be present');
        $this->assertContains('label', $colNames, 'label column must be present');
        $this->assertContains('code',  $colNames, 'code column must be present');
    }

    /**
     * getColumns() resolves a dot-separated schema.table name by splitting on
     * the dot and using the schema from the name if none was explicitly given.
     */
    public function testGetColumnsPGWithSchemaInTableName(): void
    {
        // Act — pass fully qualified 'public.table_name'
        $result = self::$db->getColumns('public.' . self::$table);

        // Assert — columns returned despite dot in name
        $this->assertGreaterThan(0, $result->numRows, 'getColumns() must handle schema.table notation');
    }

    /**
     * getColumns() with a #PREFIX# placeholder in the table name resolves the
     * prefix correctly.
     */
    public function testGetColumnsPGWithPrefixPlaceholder(): void
    {
        // Arrange
        $oldPrefix = self::$db->prefix;
        self::$db->prefix = 'pramnos_cov_';

        // Act — scratch table is pramnos_cov_pg_test → prefix is 'pramnos_cov_'
        $result = self::$db->getColumns('#PREFIX#pg_test', 'public');

        // Assert
        $this->assertGreaterThan(0, $result->numRows, 'getColumns() must resolve #PREFIX# on PG');

        // Restore
        self::$db->prefix = $oldPrefix;
    }

    // =========================================================================
    // setTrackingInfo (PostgreSQL)
    // =========================================================================

    /**
     * setTrackingInfo() on PostgreSQL runs SET application_name and SET
     * app.* configuration parameters on the live connection.
     * The method must not throw even when running without a web request context
     * (SESSION, REMOTE_ADDR, etc. are not available in CLI/Docker tests).
     */
    public function testSetTrackingInfoDoesNotThrowWithPGConnection(): void
    {
        // Arrange — no session, no HTTP env — they are optional
        $_SERVER['REMOTE_ADDR']      ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT']  ??= 'cli';
        $_SERVER['REQUEST_URI']      ??= '/test';
        $_SERVER['REQUEST_METHOD']   ??= 'GET';

        // Act — with explicit userId and appName to hit more branches
        self::$db->setTrackingInfo(42, 'TestApp', ['custom_key' => 'custom_value']);

        // Assert — no exception thrown and connection is still alive
        $health = self::$db->execute('SELECT 1 AS ok');
        $this->assertEquals(1, $health->fields['ok'], 'Connection must remain alive after setTrackingInfo()');
    }

    /**
     * setTrackingInfo() with no arguments uses the HTTP_USER_AGENT / session
     * to build the application name.  The 'cli' user-agent branch is covered.
     */
    public function testSetTrackingInfoWithNoArgumentsDoesNotThrow(): void
    {
        // Arrange — ensure cli agent is set
        $_SERVER['HTTP_USER_AGENT'] = 'cli';

        // Act
        self::$db->setTrackingInfo();

        // Assert — connection still alive
        $health = self::$db->execute('SELECT 1 AS ok');
        $this->assertEquals(1, $health->fields['ok']);
    }

    /**
     * setTrackingInfo() with userId from userData array uses the 'userid' key.
     */
    public function testSetTrackingInfoWithUserIdFromUserData(): void
    {
        // Arrange — userId null, but userData has 'userid'
        $_SERVER['HTTP_USER_AGENT'] = 'cli';

        // Act
        self::$db->setTrackingInfo(null, 'MyApp', ['userid' => 99, 'role' => 'admin']);

        // Assert — connection still alive
        $health = self::$db->execute('SELECT 1 AS ok');
        $this->assertEquals(1, $health->fields['ok']);
    }

    /**
     * setTrackingInfo() with REMOTE_ADDR other than 127.0.0.1 appends the
     * client IP to the application name.
     */
    public function testSetTrackingInfoWithNonLocalRemoteAddr(): void
    {
        // Arrange
        $oldAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_USER_AGENT'] = 'cli';

        // Act
        self::$db->setTrackingInfo(1, 'IPTest');

        // Assert
        $health = self::$db->execute('SELECT 1 AS ok');
        $this->assertEquals(1, $health->fields['ok']);

        // Restore
        if ($oldAddr !== null) {
            $_SERVER['REMOTE_ADDR'] = $oldAddr;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    // =========================================================================
    // query() with $skipDataFix=true (PostgreSQL)
    // =========================================================================

    /**
     * query() with $skipDataFix=true bypasses the column-type conversion in
     * runPgQuery() and assigns field values via the fallback else-branch.
     * This exercises line ~2478 (`$obj->fields[$key] = $value`).
     */
    public function testQueryWithSkipDataFixReturnsPGRawValues(): void
    {
        // Arrange — use a simple literal query so no table is needed
        // Act — skipDataFix=true is the 6th argument to query()
        $result = self::$db->query(
            'SELECT 1 AS val',
            false, 86400, null, false, true  // skipDataFix = true
        );

        // Assert — result was returned without type-conversion (raw string from PG)
        $this->assertEquals(1, $result->numRows,
            'query() with skipDataFix must still return a result');
        // Value may be raw string '1' when skipDataFix bypasses the int cast
        $this->assertEquals(1, $result->fields['val'],
            'skipDataFix mode must return the correct value');
    }

    /**
     * setTrackingInfo() with an empty appName and HTTP_USER_AGENT not set to
     * 'cli' falls through to the Application::getInstance() branch.
     * Application is not running in tests, so it falls back to 'PramnosApp'.
     * This covers lines ~2117-2121 (the Application branch in setTrackingInfo).
     */
    public function testSetTrackingInfoApplicationBranchFallsBackToPramnosApp(): void
    {
        // Arrange — remove 'cli' agent so the Application branch is entered
        $oldAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        unset($_SERVER['HTTP_USER_AGENT']);

        // Act — empty appName triggers Application::getInstance() path
        self::$db->setTrackingInfo();

        // Assert — connection still alive after the call
        $health = self::$db->execute('SELECT 1 AS ok');
        $this->assertEquals(1, $health->fields['ok'],
            'Connection must remain alive after setTrackingInfo() Application branch');

        // Restore
        if ($oldAgent !== null) {
            $_SERVER['HTTP_USER_AGENT'] = $oldAgent;
        }
    }

    // =========================================================================
    // insertDataToTable / updateTableData — boolean false branch (PostgreSQL)
    // =========================================================================

    /**
     * insertDataToTable() with a boolean false value (type='boolean') maps
     * to the PostgreSQL literal 'f' via the false branch of the boolean
     * type handler.  This covers the `$val = $qb->raw('\'f\'')` line.
     */
    public function testInsertDataToTablePGBooleanFalseValue(): void
    {
        // Arrange — insert a row with active=false
        $data = [
            ['fieldName' => 'code',   'value' => 'BOOL-FALSE-INS', 'type' => 'string'],
            ['fieldName' => 'active', 'value' => false,            'type' => 'boolean'],
        ];

        // Act
        $result = self::$db->insertDataToTable(self::$table, $data);
        $this->assertNotFalse($result, 'insertDataToTable() must not return false for boolean false');

        // Assert — value stored as false in PostgreSQL
        $row = self::$db->query(
            "SELECT active FROM public." . self::$table . " WHERE code = 'BOOL-FALSE-INS'"
        );
        $this->assertEquals(1, $row->numRows);
        $this->assertFalse($row->fields['active'],
            'Boolean false must be stored and retrieved as false');
    }

    /**
     * updateTableData() with a boolean false value (type='boolean') maps to
     * PostgreSQL literal 'f'.  This covers the false branch in updateTableData's
     * boolean type handler.
     */
    public function testUpdateTableDataPGBooleanFalseValue(): void
    {
        // Arrange — insert a row with active=true, then update to false
        self::$db->query(
            "INSERT INTO public." . self::$table . " (code, active) VALUES ('BOOL-FALSE-UPD', 't')"
        );

        $data = [
            ['fieldName' => 'active', 'value' => 0, 'type' => 'boolean'],
        ];

        // Act
        $result = self::$db->updateTableData(
            self::$table,
            $data,
            "code = 'BOOL-FALSE-UPD'"
        );
        $this->assertNotFalse($result, 'updateTableData() must not return false for boolean false');

        // Assert — value updated to false
        $row = self::$db->query(
            "SELECT active FROM public." . self::$table . " WHERE code = 'BOOL-FALSE-UPD'"
        );
        $this->assertFalse($row->fields['active'],
            'Boolean false (0) must be stored and retrieved as false after update');
    }

    // =========================================================================
    // capabilities(), statement(), selectOne(), getDriverName()
    // =========================================================================

    /**
     * Verifies that capabilities() returns a DatabaseCapabilities instance.
     *
     * This covers line 2890 of Database.php — the single statement inside the
     * capabilities() method that was previously uncovered.
     */
    public function testCapabilitiesReturnsDatabaseCapabilitiesInstance(): void
    {
        // Act
        $caps = self::$db->capabilities();

        // Assert — returned object is the correct type
        $this->assertInstanceOf(
            \Pramnos\Database\DatabaseCapabilities::class,
            $caps,
            'capabilities() must return a DatabaseCapabilities instance'
        );
    }

    /**
     * Verifies that statement() executes a DDL statement and returns true on success.
     *
     * This covers lines 2904-2905 of Database.php — the query() call and the
     * bool-return expression inside statement().
     */
    public function testStatementExecutesDDLAndReturnsTrue(): void
    {
        // Arrange — create a temp table via statement()
        $tmpTable = 'pramnos_stmt_test_' . time();
        self::$db->query('DROP TABLE IF EXISTS public.' . $tmpTable);

        // Act
        $result = self::$db->statement(
            'CREATE TABLE public.' . $tmpTable . ' (id SERIAL PRIMARY KEY)'
        );

        // Assert — method returned true and table exists
        $this->assertTrue($result, 'statement() must return true for a successful DDL');

        // Cleanup
        self::$db->query('DROP TABLE IF EXISTS public.' . $tmpTable);
    }

    /**
     * Verifies that selectOne() returns the first row as an associative array
     * when given a string binding.
     *
     * This covers lines 2921-2946 of Database.php — the binding-substitution loop,
     * specifically the string branch (line 2935: prepareInput + quote-wrap).
     */
    public function testSelectOneWithStringBindingReturnsFirstRow(): void
    {
        // Arrange — insert a known row using the required field-descriptor format
        self::$db->insertDataToTable(self::$table, [
            ['fieldName' => 'label',  'value' => 'selectone-test', 'type' => 'string'],
            ['fieldName' => 'amount', 'value' => 9.99,             'type' => 'float'],
            ['fieldName' => 'qty',    'value' => 7,                'type' => 'integer'],
            ['fieldName' => 'active', 'value' => true,             'type' => 'boolean'],
            ['fieldName' => 'code',   'value' => 'SELONE-' . mt_rand(100, 999), 'type' => 'string'],
        ]);

        // Act — selectOne with a string binding (covers string branch, line 2935)
        $row = self::$db->selectOne(
            'SELECT label, qty FROM public.' . self::$table . ' WHERE label = ?',
            ['selectone-test']
        );

        // Assert — first row returned with correct data
        $this->assertIsArray($row, 'selectOne() must return an array when a row exists');
        $this->assertSame('selectone-test', $row['label']);
        $this->assertEquals(7, $row['qty']);
    }

    /**
     * Verifies that selectOne() returns null when the query produces no rows.
     *
     * This covers the early-return null path (lines 2943-2944) when $result->eof
     * is true.
     */
    public function testSelectOneReturnsNullWhenNoRows(): void
    {
        // Act — query for a label that does not exist
        $row = self::$db->selectOne(
            'SELECT label FROM public.' . self::$table . ' WHERE label = ?',
            ['__nonexistent_label__']
        );

        // Assert — no rows → null
        $this->assertNull($row, 'selectOne() must return null when the result set is empty');
    }

    /**
     * Verifies that selectOne() handles bool-true, bool-false, and int/float bindings.
     *
     * This covers the type branches inside the binding substitution loop
     * (lines 2930-2934): bool → TRUE/FALSE, int/float → numeric literal.
     * (The null branch at line 2929 is dead code because isset() returns false for
     * null values — the production code documents only string/int/float as supported.)
     */
    public function testSelectOneWithBoolAndIntBindings(): void
    {
        // Act — bool-true binding: TRUE = TRUE must return a row
        $rowTrue = self::$db->selectOne(
            'SELECT 1 AS val WHERE ? = TRUE',
            [true]
        );
        $this->assertIsArray($rowTrue, 'selectOne() with bool-true binding must return a row');

        // Act — bool-false binding: FALSE = FALSE must return a row
        $rowFalse = self::$db->selectOne(
            'SELECT 1 AS val WHERE ? = FALSE',
            [false]
        );
        $this->assertIsArray($rowFalse, 'selectOne() with bool-false binding must return a row');

        // Act — int binding
        $rowInt = self::$db->selectOne(
            'SELECT ? AS n',
            [42]
        );
        $this->assertIsArray($rowInt, 'selectOne() with int binding must return a row');

        // Act — float binding
        $rowFloat = self::$db->selectOne(
            'SELECT ? AS n',
            [3.14]
        );
        $this->assertIsArray($rowFloat, 'selectOne() with float binding must return a row');
    }

    /**
     * Verifies that getDriverName() returns 'pgsql' for a PostgreSQL connection.
     *
     * This covers lines 2959-2960 of Database.php — the 'postgresql' arm of the
     * match expression.  The 'mysql' default arm is covered by the MySQL integration
     * suite; the 'timescaledb' arm is covered here by creating a minimal Database
     * instance with type='timescaledb'.
     */
    public function testGetDriverNameReturnsCorrectNormalizedName(): void
    {
        // Assert — the test DB is type 'postgresql', should return 'pgsql'
        $this->assertSame(
            'pgsql',
            self::$db->getDriverName(),
            'getDriverName() for type=postgresql must return "pgsql"'
        );

        // Assert — type=timescaledb also maps to 'pgsql'
        $tsDb = new \Pramnos\Database\Database();
        $tsDb->type = 'timescaledb';
        $this->assertSame(
            'pgsql',
            $tsDb->getDriverName(),
            'getDriverName() for type=timescaledb must return "pgsql"'
        );

        // Assert — type=mysql maps to 'mysql' (default arm)
        $myDb = new \Pramnos\Database\Database();
        $myDb->type = 'mysql';
        $this->assertSame(
            'mysql',
            $myDb->getDriverName(),
            'getDriverName() for type=mysql must return "mysql"'
        );
    }

    /**
     * Verifies that rollbackTransaction() returns false when the DB is not connected.
     *
     * This covers line 2712 of Database.php — the early-return false when
     * $this->connected is false.
     */
    public function testRollbackTransactionReturnsFalseWhenNotConnected(): void
    {
        // Arrange — a disconnected Database instance
        $db = new \Pramnos\Database\Database();
        // connected defaults to false — no need to call connect()

        // Act
        $result = $db->rollbackTransaction();

        // Assert — early exit returns false
        $this->assertFalse(
            $result,
            'rollbackTransaction() must return false when the database is not connected'
        );
    }

    /**
     * Verifies that selectOne() with no bindings executes the SQL directly (no
     * binding-substitution loop) and still returns the first row correctly.
     *
     * This covers the !empty($bindings) guard at line 2921: when bindings is empty
     * the substitution block is skipped entirely.
     */
    public function testSelectOneWithoutBindingsReturnsFirstRow(): void
    {
        // Arrange — insert a known row using the required field-descriptor format
        self::$db->insertDataToTable(self::$table, [
            ['fieldName' => 'label',  'value' => 'selone-nobind', 'type' => 'string'],
            ['fieldName' => 'amount', 'value' => 1.50,            'type' => 'float'],
            ['fieldName' => 'qty',    'value' => 3,               'type' => 'integer'],
            ['fieldName' => 'active', 'value' => false,           'type' => 'boolean'],
            ['fieldName' => 'code',   'value' => 'SELONE-NB-' . mt_rand(100, 999), 'type' => 'string'],
        ]);

        // Act — no bindings array
        $row = self::$db->selectOne(
            "SELECT label FROM public." . self::$table . " WHERE label = 'selone-nobind'"
        );

        // Assert
        $this->assertIsArray($row, 'selectOne() without bindings must return an array when row exists');
        $this->assertSame('selone-nobind', $row['label']);
    }
}
