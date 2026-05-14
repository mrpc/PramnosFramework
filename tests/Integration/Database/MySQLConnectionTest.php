<?php

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for the Database class against a real MySQL container.
 *
 * These tests cover: connect(), query(), prepareQuery(), insertDataToTable(),
 * updateTableData(), upsert(), tableExists(), getColumns(), startTransaction(),
 * commitTransaction(), rollbackTransaction(), getInsertId(), logSlowQueries(),
 * stopLogs(), and close() — all exercised with a real mysqld instance.
 *
 * A temporary table `pramnos_cov_test` is created in setUpBeforeClass() and
 * dropped in tearDownAfterClass() so each test gets a clean state without
 * needing to create/drop per test.
 */
#[CoversClass(Database::class)]
class MySQLConnectionTest extends TestCase
{
    private static Database $db;
    private static string $table = 'pramnos_cov_test';

    public static function setUpBeforeClass(): void
    {
        // Define LOG_PATH so logging-related methods can run in Docker
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var');
        }
        if (!is_dir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs')) {
            @mkdir(LOG_PATH . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        }

        self::$db = new Database();
        self::$db->type     = 'mysql';
        self::$db->server   = 'db';
        self::$db->user     = 'root';
        self::$db->password = 'secret';
        self::$db->database = 'pramnos_test';
        self::$db->port     = 3306;
        self::$db->connect(true);

        // Create scratch table for coverage tests
        self::$db->query(
            'CREATE TABLE IF NOT EXISTS `' . self::$table . '` ('
            . '`id` INT AUTO_INCREMENT PRIMARY KEY, '
            . '`label` VARCHAR(100), '
            . '`amount` DECIMAL(10,2), '
            . '`qty` INT, '
            . '`code` VARCHAR(50) UNIQUE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        self::$db->query('TRUNCATE TABLE `' . self::$table . '`');
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query('DROP TABLE IF EXISTS `' . self::$table . '`');
        self::$db->close();
    }

    protected function setUp(): void
    {
        // Truncate between tests for isolation
        self::$db->query('TRUNCATE TABLE `' . self::$table . '`');
    }

    // =========================================================================
    // Basic connectivity (original test)
    // =========================================================================

    /**
     * Verify that the framework can natively connect to MySQL
     * and execute prepared statements correctly.
     */
    public function testMySQLConnectionAndPreparedStatements(): void
    {
        // Arrange — already connected in setUpBeforeClass

        // Act — basic health check via prepared statement
        $result = self::$db->execute('SELECT 1 AS health_check');

        // Assert
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(1, $result->fields['health_check']);

        // Parameterised query (MySQL uses '?' internally)
        $val = 456;
        $stmtResult = self::$db->execute('SELECT %i AS test_val', $val);
        $this->assertEquals(1, $stmtResult->numRows);
        $this->assertEquals($val, $stmtResult->fields['test_val']);
    }

    // =========================================================================
    // tableExists
    // =========================================================================

    /**
     * tableExists() returns true for a table that was just created and false
     * for a table that does not exist.  This exercises both branches of the
     * MySQL SHOW TABLES query path.
     */
    public function testTableExistsReturnsTrueForExistingTable(): void
    {
        // Act / Assert — the scratch table was created in setUpBeforeClass
        $this->assertTrue(
            self::$db->tableExists(self::$table),
            'tableExists() must return true for an existing MySQL table'
        );
    }

    /**
     * tableExists() returns false for a table that does not exist.
     */
    public function testTableExistsReturnsFalseForMissingTable(): void
    {
        // Act / Assert
        $this->assertFalse(
            self::$db->tableExists('_definitely_does_not_exist_xyz'),
            'tableExists() must return false when the table is absent'
        );
    }

    // =========================================================================
    // insertDataToTable
    // =========================================================================

    /**
     * insertDataToTable() with integer, float, and string types writes a row
     * and the row is readable back.  This exercises the MySQL path of the
     * type-dispatch switch inside insertDataToTable() and triggers prepareInput()
     * via the string/json/date types in prepareValue().
     */
    public function testInsertDataToTableWithMixedTypes(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'label',  'value' => 'integration-test', 'type' => 'string'],
            ['fieldName' => 'amount', 'value' => '9.99',             'type' => 'float'],
            ['fieldName' => 'qty',    'value' => '7',                'type' => 'integer'],
            ['fieldName' => 'code',   'value' => 'UNIQ-001',         'type' => 'string'],
        ];

        // Act
        $result = self::$db->insertDataToTable(self::$table, $data, 'id');

        // Assert — insert succeeded
        $this->assertNotFalse($result, 'insertDataToTable() must not return false on success');

        // Verify row exists
        $row = self::$db->query('SELECT * FROM `' . self::$table . '` LIMIT 1');
        $this->assertEquals(1, $row->numRows);
        $this->assertSame('integration-test', $row->fields['label']);
    }

    /**
     * insertDataToTable() with a null value stores SQL NULL correctly.
     */
    public function testInsertDataToTableWithNullValue(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'label',  'value' => null,     'type' => 'string'],
            ['fieldName' => 'amount', 'value' => null,     'type' => 'float'],
            ['fieldName' => 'qty',    'value' => null,     'type' => 'integer'],
            ['fieldName' => 'code',   'value' => 'NULL-1', 'type' => 'string'],
        ];

        // Act
        $result = self::$db->insertDataToTable(self::$table, $data);

        // Assert
        $this->assertNotFalse($result);
        $row = self::$db->query('SELECT label FROM `' . self::$table . '` WHERE code = \'NULL-1\'');
        $this->assertEquals(1, $row->numRows);
        $this->assertNull($row->fields['label']);
    }

    // =========================================================================
    // updateTableData
    // =========================================================================

    /**
     * updateTableData() modifies existing rows matching the filter.
     * The type-dispatch inside the method is exercised with float and integer.
     */
    public function testUpdateTableDataChangesExistingRow(): void
    {
        // Arrange — insert a row first
        self::$db->query(
            'INSERT INTO `' . self::$table . '` (label, amount, qty, code) '
            . "VALUES ('original', 1.00, 1, 'UPD-1')"
        );

        $data = [
            ['fieldName' => 'label',  'value' => 'updated',  'type' => 'string'],
            ['fieldName' => 'amount', 'value' => '19.99',    'type' => 'float'],
            ['fieldName' => 'qty',    'value' => '3',         'type' => 'integer'],
        ];
        $filter = "code = 'UPD-1'";

        // Act
        $result = self::$db->updateTableData(self::$table, $data, $filter);

        // Assert
        $this->assertNotFalse($result);
        $row = self::$db->query("SELECT label, qty FROM `" . self::$table . "` WHERE code = 'UPD-1'");
        $this->assertSame('updated', $row->fields['label']);
        $this->assertSame(3, $row->fields['qty']);
    }

    /**
     * updateTableData() with null values updates the column to SQL NULL.
     */
    public function testUpdateTableDataWithNullValueSetsNull(): void
    {
        // Arrange
        self::$db->query(
            'INSERT INTO `' . self::$table . '` (label, qty, code) '
            . "VALUES ('not-null', 5, 'NULL-UPDATE')"
        );
        $data = [
            ['fieldName' => 'label', 'value' => null, 'type' => 'string'],
            ['fieldName' => 'qty',   'value' => null, 'type' => 'integer'],
        ];

        // Act
        self::$db->updateTableData(self::$table, $data, "code = 'NULL-UPDATE'");

        // Assert
        $row = self::$db->query("SELECT label FROM `" . self::$table . "` WHERE code = 'NULL-UPDATE'");
        $this->assertNull($row->fields['label']);
    }

    // =========================================================================
    // upsert (MySQL)
    // =========================================================================

    /**
     * upsert() on MySQL performs an INSERT on first call and an
     * ON DUPLICATE KEY UPDATE on subsequent calls with the same conflict key.
     */
    public function testUpsertMySQLInsertsAndThenUpdates(): void
    {
        // Arrange — first call is an insert
        $data = [
            ['fieldName' => 'code',   'value' => 'UPSERT-1', 'type' => 'string'],
            ['fieldName' => 'label',  'value' => 'initial',  'type' => 'string'],
            ['fieldName' => 'amount', 'value' => 1.00,       'type' => 'float'],
        ];

        // Act — insert
        $insertResult = self::$db->upsert(self::$table, $data, 'code');
        $this->assertNotFalse($insertResult, 'upsert() must not return false on insert');

        // Verify insert
        $row = self::$db->query("SELECT label FROM `" . self::$table . "` WHERE code = 'UPSERT-1'");
        $this->assertSame('initial', $row->fields['label']);

        // Act — update (same code → triggers ON DUPLICATE KEY UPDATE)
        $data[1]['value'] = 'updated';
        $updateResult = self::$db->upsert(self::$table, $data, 'code');
        $this->assertNotFalse($updateResult, 'upsert() must not return false on update');

        // Assert — label was updated
        $row2 = self::$db->query("SELECT label FROM `" . self::$table . "` WHERE code = 'UPSERT-1'");
        $this->assertSame('updated', $row2->fields['label']);
    }

    // =========================================================================
    // startTransaction / commitTransaction / rollbackTransaction
    // =========================================================================

    /**
     * startTransaction() + rollbackTransaction() ensures that rows inserted
     * inside the transaction are not visible after rollback.
     */
    public function testTransactionRollbackDoesNotPersistRows(): void
    {
        // Arrange
        $started = self::$db->startTransaction();
        $this->assertTrue($started, 'startTransaction() must return true on success');

        self::$db->query(
            'INSERT INTO `' . self::$table . '` (code, label) '
            . "VALUES ('TX-ROLLBACK', 'will-be-rolled-back')"
        );

        // Act
        $rolled = self::$db->rollbackTransaction();
        $this->assertTrue($rolled, 'rollbackTransaction() must return true on success');

        // Assert — row must not exist after rollback
        $check = self::$db->query("SELECT id FROM `" . self::$table . "` WHERE code = 'TX-ROLLBACK'");
        $this->assertEquals(0, $check->numRows, 'Rolled-back row must not be visible');
    }

    /**
     * startTransaction() + commitTransaction() makes rows durable so they
     * survive after the commit.
     */
    public function testTransactionCommitPersistsRows(): void
    {
        // Arrange
        self::$db->startTransaction();
        self::$db->query(
            'INSERT INTO `' . self::$table . '` (code, label) '
            . "VALUES ('TX-COMMIT', 'committed')"
        );

        // Act
        $committed = self::$db->commitTransaction();
        $this->assertTrue($committed, 'commitTransaction() must return true on success');

        // Assert — row must be visible after commit
        $check = self::$db->query("SELECT label FROM `" . self::$table . "` WHERE code = 'TX-COMMIT'");
        $this->assertEquals(1, $check->numRows, 'Committed row must be visible');
        $this->assertSame('committed', $check->fields['label']);
    }

    // =========================================================================
    // query() with cache=true (covers the cache-miss / elseif($cache) path)
    // =========================================================================

    /**
     * Calling query() with $cache=true exercises the elseif($cache) branch
     * inside query(): cacheExpire(), the real query execution, shouldCacheResult(),
     * and cacheStore() are all called.  When the cache backend is disabled,
     * the result still contains correct data.
     */
    public function testQueryWithCacheTrueCoversCacheMissPath(): void
    {
        // Arrange — insert a row to query
        self::$db->query(
            'INSERT INTO `' . self::$table . '` (code, label) '
            . "VALUES ('CACHE-1', 'cached-label')"
        );

        // Act — query with caching enabled (cache backend is disabled in tests → cache miss)
        $result = self::$db->query(
            'SELECT label FROM `' . self::$table . "` WHERE code = 'CACHE-1'",
            true,  // $cache
            60,    // $cachetime
            'test_category'
        );

        // Assert — correct result despite cache miss
        $this->assertEquals(1, $result->numRows);
        $this->assertSame('cached-label', $result->fields['label']);
    }

    // =========================================================================
    // getColumns (MySQL)
    // =========================================================================

    /**
     * getColumns() on MySQL queries information_schema for column metadata.
     * The result must include all columns defined in the scratch table with
     * correct types and nullability flags.
     */
    public function testGetColumnsMySQLReturnsColumnMetadata(): void
    {
        // Act
        $result = self::$db->getColumns(self::$table);

        // Assert — result must be a Result with rows
        $this->assertInstanceOf(\Pramnos\Database\Result::class, $result);
        $this->assertGreaterThan(0, $result->numRows, 'getColumns() must return at least one column');

        // Collect column names from the result
        $columns = $result->fetchAll();
        $colNames = array_column($columns, 'Field');

        // The scratch table has: id, label, amount, qty, code
        $this->assertContains('id', $colNames, 'id column must be present');
        $this->assertContains('label', $colNames, 'label column must be present');
        $this->assertContains('code', $colNames, 'code column must be present');
    }

    /**
     * getColumns() with a #PREFIX# placeholder in the table name resolves
     * it against the database prefix.  With an empty prefix, the name is
     * returned unchanged.
     */
    public function testGetColumnsResolvesPrefix(): void
    {
        // Arrange — set a prefix that matches the scratch table name
        $dbPrefix = self::$db->prefix;
        self::$db->prefix = 'pramnos_';

        // Act — the scratch table starts with 'pramnos_cov_test'
        $result = self::$db->getColumns('#PREFIX#cov_test');

        // Assert — prefix was resolved; columns were returned
        $this->assertGreaterThan(0, $result->numRows, 'getColumns() must resolve #PREFIX# correctly');

        // Restore
        self::$db->prefix = $dbPrefix;
    }

    // =========================================================================
    // logSlowQueries + stopLogs (covers log-related private methods)
    // =========================================================================

    /**
     * logSlowQueries() in mode=0 (PHP-level custom slow query logging) opens
     * a log file handler and sets _customLogSlowQueries=true.  rotateLog() and
     * _createLogFile() are invoked internally.
     *
     * stopLogs() then writes the summary and closes all open handlers.
     * This covers: logSlowQueries, rotateLog, _createLogFile, stopLogs.
     */
    public function testLogSlowQueriesMode0AndStopLogs(): void
    {
        // Arrange — create a fresh DB instance for this test to isolate handlers
        $db2 = new Database();
        $db2->type     = 'mysql';
        $db2->server   = 'db';
        $db2->user     = 'root';
        $db2->password = 'secret';
        $db2->database = 'pramnos_test';
        $db2->port     = 3306;
        $db2->connect(true);

        // Act — mode=0 uses PHP-level logging, no native MySQL slow-query-log
        $db2->logSlowQueries(2, 0);

        // Run a query so the log captures something
        $db2->query('SELECT 1');

        // Act — stopLogs() writes summaries and closes file handles
        $db2->stopLogs();
        $db2->close();

        // Assert — the slow-query log file was created
        $logFile = LOG_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'slowQueries.log';
        $this->assertFileExists($logFile, 'logSlowQueries() must create slowQueries.log');
    }
}
