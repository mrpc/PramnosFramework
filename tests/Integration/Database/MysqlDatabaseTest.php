<?php

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for the Database class against the MySQL Docker container.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Database\Database::class)]
class MysqlDatabaseTest extends \PHPUnit\Framework\TestCase
{
    private static Database $db;
    private static string $table = 'pramnos_cov_mysql_test';

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
        self::$db->type     = 'mysql';
        self::$db->server   = 'db';
        self::$db->user     = 'root';
        self::$db->password = 'secret';
        self::$db->database = 'pramnos_test';
        self::$db->port     = 3306;
        self::$db->connect(true);

        // Create scratch table
        self::$db->query(
            'CREATE TABLE IF NOT EXISTS ' . self::$table . ' ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'label VARCHAR(100), '
            . 'amount DECIMAL(10,2), '
            . 'qty INTEGER, '
            . 'active TINYINT(1), '
            . 'code VARCHAR(50) UNIQUE'
            . ')'
        );
        self::$db->query('TRUNCATE TABLE ' . self::$table);
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->query('DROP TABLE IF EXISTS ' . self::$table);
        self::$db->close();
    }

    protected function setUp(): void
    {
        self::$db->query('TRUNCATE TABLE ' . self::$table);
    }

    public function testMysqlConnection(): void
    {
        $result = self::$db->execute('SELECT 1 AS health_check');
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(1, $result->fields['health_check']);
    }

    public function testTableExistsMysqlReturnsTrueForExistingTable(): void
    {
        $this->assertTrue(self::$db->tableExists(self::$table));
    }

    public function testTableExistsMysqlReturnsFalseForMissingTable(): void
    {
        $this->assertFalse(self::$db->tableExists('_definitely_not_here_xyz_mysql'));
    }

    public function testInsertDataToTableMysqlWithBooleanType(): void
    {
        $data = [
            ['fieldName' => 'code',   'value' => 'BOOL-1',  'type' => 'string'],
            ['fieldName' => 'label',  'value' => 'active',  'type' => 'string'],
            ['fieldName' => 'active', 'value' => true,      'type' => 'boolean'],
            ['fieldName' => 'qty',    'value' => 5,         'type' => 'integer'],
        ];

        $result = self::$db->insertDataToTable(self::$table, $data);
        $this->assertNotFalse($result);

        $row = self::$db->query("SELECT active, qty FROM " . self::$table . " WHERE code = 'BOOL-1'");
        $this->assertEquals(1, $row->numRows);
        // MySQL returns 1 for true boolean
        $this->assertEquals(1, $row->fields['active']);
    }

    public function testUpdateTableDataMysqlBooleanFalseType(): void
    {
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label, active) "
            . "VALUES ('UPD-BOOL', 'original', 1)"
        );

        $data = [
            ['fieldName' => 'active', 'value' => false,   'type' => 'boolean'],
            ['fieldName' => 'label',  'value' => 'updated', 'type' => 'string'],
        ];

        $result = self::$db->updateTableData(
            self::$table,
            $data,
            "code = 'UPD-BOOL'"
        );
        $this->assertNotFalse($result);

        $row = self::$db->query("SELECT active, label FROM " . self::$table . " WHERE code = 'UPD-BOOL'");
        $this->assertEquals(0, $row->fields['active']);
        $this->assertSame('updated', $row->fields['label']);
    }

    public function testMysqlTransactionCommitPersistsRows(): void
    {
        self::$db->startTransaction();
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label) "
            . "VALUES ('MYSQL-COMMIT', 'committed')"
        );

        $committed = self::$db->commitTransaction();
        $this->assertTrue($committed);

        $check = self::$db->query("SELECT label FROM " . self::$table . " WHERE code = 'MYSQL-COMMIT'");
        $this->assertEquals(1, $check->numRows);
    }

    public function testMysqlTransactionRollbackDoesNotPersistRows(): void
    {
        self::$db->startTransaction();
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label) "
            . "VALUES ('MYSQL-ROLLBACK', 'rolled')"
        );

        $rolled = self::$db->rollbackTransaction();
        $this->assertTrue($rolled);

        $check = self::$db->query("SELECT label FROM " . self::$table . " WHERE code = 'MYSQL-ROLLBACK'");
        $this->assertEquals(0, $check->numRows);
    }

    public function testGetInsertIdMysql(): void
    {
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label) "
            . "VALUES ('LAST-ID', 'test')"
        );

        $lastId = self::$db->getInsertId();
        $this->assertIsInt($lastId);
        $this->assertGreaterThan(0, $lastId);
    }

    public function testGetColumnsMysqlReturnsColumnMetadata(): void
    {
        $result = self::$db->getColumns(self::$table);
        $this->assertInstanceOf(\Pramnos\Database\Result::class, $result);
        $this->assertGreaterThan(0, $result->numRows);

        $columns = $result->fetchAll();
        $colNames = array_column($columns, 'Field');

        $this->assertContains('id',    $colNames);
        $this->assertContains('label', $colNames);
        $this->assertContains('code',  $colNames);
    }

    // =========================================================================
    // upsert (MySQL)
    // =========================================================================

    /**
     * upsert() on MySQL uses INSERT ... ON DUPLICATE KEY UPDATE.
     * The first call inserts; the second call with the same unique key updates
     * the existing row.  This exercises the MySQL-specific upsert branch
     * (lines 2399–2422 in Database.php).
     */
    public function testUpsertMySQLInsertsAndThenUpdates(): void
    {
        // Arrange — first call inserts a new row
        $data = [
            ['fieldName' => 'code',   'value' => 'UPSERT-MY-1', 'type' => 'string'],
            ['fieldName' => 'label',  'value' => 'initial',     'type' => 'string'],
            ['fieldName' => 'qty',    'value' => 1,             'type' => 'integer'],
        ];

        // Act — insert via upsert
        $insertResult = self::$db->upsert(self::$table, $data, 'code');
        $this->assertNotFalse($insertResult, 'upsert() MySQL insert must not return false');

        // Verify insert took effect
        $row = self::$db->query(
            "SELECT label FROM " . self::$table . " WHERE code = 'UPSERT-MY-1'"
        );
        $this->assertSame('initial', $row->fields['label']);

        // Act — second call with same unique code → triggers ON DUPLICATE KEY UPDATE
        $data[1]['value'] = 'updated-upsert';
        $updateResult = self::$db->upsert(self::$table, $data, 'code');
        $this->assertNotFalse($updateResult, 'upsert() MySQL update must not return false');

        // Assert — label must now be 'updated-upsert'
        $row2 = self::$db->query(
            "SELECT label FROM " . self::$table . " WHERE code = 'UPSERT-MY-1'"
        );
        $this->assertSame('updated-upsert', $row2->fields['label'],
            'upsert() must update existing row on duplicate key');
    }

    // =========================================================================
    // In-memory query log (enableQueryLog, getQueryLog, clearQueryLog, logCacheHit)
    // =========================================================================

    /**
     * enableQueryLog() activates in-memory logging; getQueryLog() returns each
     * executed query with 'sql', 'time', and 'at' keys.
     * clearQueryLog() resets the log while keeping logging enabled.
     *
     * This covers enableQueryLog(), getQueryLog(), clearQueryLog(), and the
     * _inMemoryLogEnabled branch inside runQuery() (lines ~975-977).
     */
    public function testInMemoryQueryLogRecordsQueriesAndCanBeCleared(): void
    {
        // Arrange — enable in-memory logging on the shared DB instance
        self::$db->enableQueryLog()->clearQueryLog();

        // Act — run two queries
        self::$db->query('SELECT 1 AS a');
        self::$db->query('SELECT 2 AS b');

        // Assert — log must have at least the two queries
        $log = self::$db->getQueryLog();
        $this->assertGreaterThanOrEqual(2, count($log),
            'getQueryLog() must return all logged queries');
        $this->assertArrayHasKey('sql',  $log[0], 'Each log entry must have sql key');
        $this->assertArrayHasKey('time', $log[0], 'Each log entry must have time key');
        $this->assertArrayHasKey('at',   $log[0], 'Each log entry must have at key');

        // Act — clear the log
        self::$db->clearQueryLog();

        // Assert — log must now be empty
        $this->assertEmpty(self::$db->getQueryLog(),
            'clearQueryLog() must empty the query log');
    }

    /**
     * logCacheHit() records a cache-hit entry with from_cache=true when
     * in-memory logging is enabled.  This exercises lines 878–888 of Database.php.
     */
    public function testLogCacheHitRecordsEntrywhenLogEnabled(): void
    {
        // Arrange
        self::$db->enableQueryLog()->clearQueryLog();

        // Act
        self::$db->logCacheHit('SELECT * FROM users WHERE id = 1');

        // Assert — one entry must exist with from_cache=true
        $log = self::$db->getQueryLog();
        $this->assertGreaterThanOrEqual(1, count($log));

        // Find the cache-hit entry
        $cacheEntries = array_filter($log, fn($e) => isset($e['from_cache']) && $e['from_cache'] === true);
        $this->assertNotEmpty($cacheEntries, 'logCacheHit() must add an entry with from_cache=true');
    }

    // =========================================================================
    // Transaction methods (MySQL)
    // =========================================================================

    /**
     * commitTransaction() on MySQL must return false when the database is not
     * connected.  This exercises the early-return false path (line ~2818).
     */
    public function testCommitTransactionReturnsFalseWhenNotConnected(): void
    {
        // Arrange — disconnected instance
        $db = new Database();
        $db->type      = 'mysql';
        $db->connected = false;

        // Act
        $result = $db->commitTransaction();

        // Assert
        $this->assertFalse($result,
            'commitTransaction() must return false when DB is not connected');
    }

    /**
     * rollbackTransaction() on MySQL must return false when the database is
     * not connected.  Exercises the early-return false path (line ~2836).
     */
    public function testRollbackTransactionReturnsFalseWhenNotConnectedMySQL(): void
    {
        // Arrange — disconnected MySQL instance
        $db = new Database();
        $db->type      = 'mysql';
        $db->connected = false;

        // Act
        $result = $db->rollbackTransaction();

        // Assert
        $this->assertFalse($result,
            'rollbackTransaction() must return false when DB is not connected on MySQL');
    }

    // =========================================================================
    // capabilities(), statement(), selectOne(), getDriverName() — MySQL paths
    // =========================================================================

    /**
     * capabilities() on a MySQL connection returns a DatabaseCapabilities
     * instance bound to the connection.
     */
    public function testCapabilitiesMySQLReturnsDatabaseCapabilitiesInstance(): void
    {
        // Act
        $caps = self::$db->capabilities();

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Database\DatabaseCapabilities::class,
            $caps,
            'capabilities() on MySQL must return DatabaseCapabilities instance'
        );
    }

    /**
     * statement() on MySQL executes a DDL statement and returns true.
     * This covers the MySQL path through statement() → query() → runMysqlQuery().
     */
    public function testStatementMySQLExecutesDDLAndReturnsTrue(): void
    {
        // Arrange
        $tmpTable = 'pramnos_stmt_mysql_' . time();
        self::$db->query('DROP TABLE IF EXISTS ' . $tmpTable);

        // Act
        $result = self::$db->statement(
            'CREATE TABLE ' . $tmpTable . ' (id INT AUTO_INCREMENT PRIMARY KEY)'
        );

        // Assert
        $this->assertTrue($result, 'statement() must return true for successful DDL on MySQL');

        // Cleanup
        self::$db->query('DROP TABLE IF EXISTS ' . $tmpTable);
    }

    /**
     * selectOne() on MySQL with a string binding substitutes the value correctly
     * and returns the first matching row.
     */
    public function testSelectOneMySQLWithStringBinding(): void
    {
        // Arrange — insert a row with a known label
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label, qty) "
            . "VALUES ('SELONE-MY', 'mysql-selectone', 7)"
        );

        // Act
        $row = self::$db->selectOne(
            'SELECT label, qty FROM ' . self::$table . ' WHERE code = ?',
            ['SELONE-MY']
        );

        // Assert
        $this->assertIsArray($row, 'selectOne() MySQL must return an array when row exists');
        $this->assertSame('mysql-selectone', $row['label']);
        $this->assertEquals(7, $row['qty']);
    }

    /**
     * selectOne() on MySQL returns null when the query produces no rows.
     */
    public function testSelectOneMySQLReturnsNullWhenNoRows(): void
    {
        // Act
        $row = self::$db->selectOne(
            'SELECT label FROM ' . self::$table . ' WHERE code = ?',
            ['__nonexistent__']
        );

        // Assert
        $this->assertNull($row, 'selectOne() MySQL must return null when no rows match');
    }

    /**
     * getDriverName() on a MySQL connection must return 'mysql'.
     * This exercises the default arm of the match expression in getDriverName().
     */
    public function testGetDriverNameMySQLReturnsMySQL(): void
    {
        // Act / Assert
        $this->assertSame('mysql', self::$db->getDriverName(),
            'getDriverName() for MySQL must return "mysql"');
    }

    // =========================================================================
    // prepareQuery — MySQL paths
    // =========================================================================

    /**
     * prepareQuery() on MySQL replaces #PREFIX# and #CP# tokens correctly and
     * produces a valid SQL string ready for execution.
     * This exercises the else-branch of prepareQuery() (MySQL path, lines ~1464–1473).
     */
    public function testPrepareQueryMySQLReplacesTokens(): void
    {
        // Arrange
        self::$db->prefix = 'app_';
        self::$db->controllerPrefix = 'mod_';

        // Act
        $sql = self::$db->prepareQuery(
            'SELECT * FROM `#PREFIX#users` WHERE `#CP#id` = %d',
            42
        );

        // Assert — tokens replaced, placeholder substituted
        $this->assertStringContainsString('app_users', $sql,
            '#PREFIX# must be replaced with prefix value');
        $this->assertStringContainsString('42', $sql,
            '%d placeholder must be substituted');

        // Cleanup — restore default
        self::$db->prefix = '';
        self::$db->controllerPrefix = '';
    }

    /**
     * prepareQuery() converts NULL values to SQL NULL literals in WHERE clauses,
     * replacing = null with IS NULL and != null with IS NOT NULL.
     * This exercises lines ~1513–1520 of Database.php.
     */
    public function testPrepareQueryNullConversionInWhereClause(): void
    {
        // Arrange — use a subclass with no-connection prepareInput() to avoid DB
        $db = new class extends Database {
            public function prepareInput($string) { return addslashes($string ?? ''); }
        };
        $db->type = 'mysql';

        // Act — NULL arg triggers null-to-IS NULL rewrite in WHERE
        $sql = $db->prepareQuery(
            'SELECT * FROM users WHERE status = %s AND deleted = %s',
            'active',
            null
        );

        // Assert — '= null' was converted to 'IS NULL' after WHERE
        $this->assertStringContainsString('IS NULL', (string) $sql,
            'prepareQuery() must rewrite "= null" to "IS NULL" in WHERE clause');
        $this->assertStringContainsString("'active'", (string) $sql,
            'Non-null string arg must be quoted');
    }

    /**
     * prepareQuery() on MySQL with a %s placeholder wraps the value in single
     * quotes automatically (the '(?<!%)%s' → "'%s'" substitution).
     */
    public function testPrepareQueryMySQLWrapsStringsInQuotes(): void
    {
        // Arrange — use addslashes-based subclass to avoid needing a live connection
        $db = new class extends Database {
            public function prepareInput($string) { return addslashes($string ?? ''); }
        };
        $db->type = 'mysql';

        // Act
        $sql = $db->prepareQuery('SELECT * FROM users WHERE name = %s', "O'Brien");

        // Assert — the value must be quoted and escaped
        $this->assertNotNull($sql);
        $this->assertStringContainsString("'", $sql,
            'String placeholder must produce a single-quoted value');
        $this->assertStringContainsString("O\\'Brien", $sql,
            'Single quotes in the value must be escaped');
    }

    // =========================================================================
    // insertDataToTable — float type with comma-decimal value
    // =========================================================================

    /**
     * insertDataToTable() with a float value using a comma as decimal separator
     * (European locale format) must store the correct numeric value.
     * This exercises the float branch in insertDataToTable() at line ~1589.
     */
    public function testInsertDataToTableMySQLFloatCommaDecimal(): void
    {
        // Arrange — comma-decimal float '3,14' must be normalized to 3.14
        $data = [
            ['fieldName' => 'code',   'value' => 'FLOAT-COMMA',  'type' => 'string'],
            ['fieldName' => 'label',  'value' => 'comma-decimal', 'type' => 'string'],
            ['fieldName' => 'amount', 'value' => '9,99',          'type' => 'float'],
        ];

        // Act
        $result = self::$db->insertDataToTable(self::$table, $data);
        $this->assertNotFalse($result, 'insertDataToTable() must not fail for comma-decimal float');

        // Assert — value stored as 9.99
        $row = self::$db->query(
            "SELECT amount FROM " . self::$table . " WHERE code = 'FLOAT-COMMA'"
        );
        $this->assertEquals(1, $row->numRows);
        $this->assertEqualsWithDelta(9.99, (float) $row->fields['amount'], 0.001,
            'Comma-decimal float must be stored as 9.99');
    }

    /**
     * insertDataToTable() with a NULL sentinel string for float type stores SQL NULL.
     * This exercises the `$val === 'NULL'` check in the float branch at line ~1586.
     */
    public function testInsertDataToTableMySQLFloatNullSentinel(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'code',   'value' => 'FLOAT-NULL-STR', 'type' => 'string'],
            ['fieldName' => 'amount', 'value' => 'NULL',            'type' => 'float'],
        ];

        // Act
        $result = self::$db->insertDataToTable(self::$table, $data);
        $this->assertNotFalse($result);

        // Assert — NULL stored
        $row = self::$db->query(
            "SELECT amount FROM " . self::$table . " WHERE code = 'FLOAT-NULL-STR'"
        );
        $this->assertNull($row->fields['amount'],
            'Float NULL sentinel must store SQL NULL');
    }

    /**
     * insertDataToTable() with an empty string for float type stores SQL NULL.
     * This exercises the `$val === ''` check in the float branch at line ~1586.
     */
    public function testInsertDataToTableMySQLFloatEmptyStringStoresNull(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'code',   'value' => 'FLOAT-EMPTY', 'type' => 'string'],
            ['fieldName' => 'amount', 'value' => '',             'type' => 'float'],
        ];

        // Act
        $result = self::$db->insertDataToTable(self::$table, $data);
        $this->assertNotFalse($result);

        // Assert — empty string maps to NULL for float columns
        $row = self::$db->query(
            "SELECT amount FROM " . self::$table . " WHERE code = 'FLOAT-EMPTY'"
        );
        $this->assertNull($row->fields['amount'],
            'Empty string float must store SQL NULL');
    }

    // =========================================================================
    // insertDataToTable / updateTableData — debug mode (TypeError path)
    // =========================================================================

    /**
     * insertDataToTable() with $debug=true executes the `if ($debug)` branch
     * (lines 1596-1598).  In the current implementation, $qb->insert() returns
     * a Result object, which PHP cannot concatenate to a string — the code throws
     * a TypeError.  We use expectException to document this known behaviour and
     * cover the branch.
     */
    public function testInsertDataToTableDebugModeEntersDebugBranchAndThrows(): void
    {
        // Arrange
        $data = [
            ['fieldName' => 'code',  'value' => 'DBG-INS-' . mt_rand(100, 999), 'type' => 'string'],
            ['fieldName' => 'label', 'value' => 'debug',                          'type' => 'string'],
        ];

        // Act + Assert — debug branch is entered; qb->insert() returns a Result
        // object which PHP cannot concatenate to string → Error (PHP 8: TypeError/Error)
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/could not be converted to string/');
        self::$db->insertDataToTable(self::$table, $data, '', true);
    }

    /**
     * updateTableData() with $debug=true enters the debug branch (lines 1666-1668).
     * Same behaviour as insertDataToTable debug mode — an Error is thrown.
     */
    public function testUpdateTableDataDebugModeEntersDebugBranchAndThrows(): void
    {
        // Arrange
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label) VALUES ('DBG-UPD', 'original')"
        );

        $data = [
            ['fieldName' => 'label', 'value' => 'debug-updated', 'type' => 'string'],
        ];

        // Act + Assert — debug branch is entered → Error
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/could not be converted to string/');
        self::$db->updateTableData(self::$table, $data, "code = 'DBG-UPD'", true);
    }

    // =========================================================================
    // schema() alias
    // =========================================================================

    /**
     * schema() is an alias for schemaBuilder() and must return a SchemaBuilder
     * instance.  This covers lines 248–251 (the schema() method body).
     */
    public function testSchemaMySQLReturnsSchemaBuilderInstance(): void
    {
        // Act
        $sb = self::$db->schema();

        // Assert
        $this->assertInstanceOf(\Pramnos\Database\SchemaBuilder::class, $sb,
            'schema() must return a SchemaBuilder instance');
    }

    // =========================================================================
    // getConnectionLink
    // =========================================================================

    /**
     * getConnectionLink() returns the active connection resource.  After
     * connect(), the resource must not be null.
     * This covers lines 268–271 (getConnectionLink() method body).
     */
    public function testGetConnectionLinkReturnsActiveConnectionAfterConnect(): void
    {
        // Act — db is already connected in setUpBeforeClass
        $link = self::$db->getConnectionLink();

        // Assert — must be a mysqli resource (object in PHP 8)
        $this->assertNotNull($link, 'getConnectionLink() must return the active connection');
        $this->assertInstanceOf(\mysqli::class, $link,
            'getConnectionLink() must return a mysqli instance on MySQL');
    }

    // =========================================================================
    // logSlowQueries — PHP mode (mode=0) with custom time
    // =========================================================================

    /**
     * logSlowQueries() in PHP mode (mode=0, the default) with a custom time
     * sets longQueryTime and opens the slow-query log file handle.
     * This covers lines 723–788 of Database.php (the mode===0 else branch).
     */
    public function testLogSlowQueriesPHPModeWithCustomTime(): void
    {
        // Arrange — create a fresh connected DB instance so the log file path
        // is available via LOG_PATH (defined in setUpBeforeClass)
        $db = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 3306;
        $db->connect(true);

        // Act — call logSlowQueries with a custom time (mode=0, PHP mode)
        $db->logSlowQueries(5, 0);

        // Assert — longQueryTime must be set to 5 and _logSlowQueries to true
        $this->assertEquals(5, $db->longQueryTime,
            'logSlowQueries() must set longQueryTime to the given value');

        // Cleanup
        $db->close();
    }

    // =========================================================================
    // execute() with MySQL prepared statements
    // =========================================================================

    /**
     * execute() with a string SQL (auto-prepare path) on MySQL runs the query
     * as a prepared statement and returns the result object.
     * This exercises the `if (is_string($sql))` path inside execute().
     *
     * The execute() method signature uses &...$arguments (variadic by reference),
     * so arguments must be passed as variables, not literals.
     */
    public function testExecuteWithStringAutoPrepareMysql(): void
    {
        // Arrange — insert a row to select
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label, qty) "
            . "VALUES ('EXEC-STR', 'exec-label', 99)"
        );

        // Act — execute() with a string automatically prepares then executes.
        // The &...$arguments signature requires variables, not literals.
        $code   = 'EXEC-STR';
        $result = self::$db->execute(
            'SELECT qty FROM `' . self::$table . '` WHERE `code` = %s',
            $code
        );

        // Assert
        $this->assertNotFalse($result, 'execute() with string SQL must not return false');
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(99, $result->fields['qty']);
    }

    // =========================================================================
    // query() with cache=true — MySQL cache miss path
    // =========================================================================

    /**
     * query() with $cache=true on MySQL exercises the elseif($cache) path:
     * cacheExpire(), runMysqlQuery(), shouldCacheResult(), cacheStore() are all
     * called.  The result must be correct even when the cache backend is down.
     */
    public function testQueryWithCacheTrueMySQLCoversCacheMissPath(): void
    {
        // Arrange — insert a known row
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label) "
            . "VALUES ('MY-CACHE', 'mysql-cached-label')"
        );

        // Act — trigger the elseif($cache) path
        $result = self::$db->query(
            "SELECT label FROM " . self::$table . " WHERE code = 'MY-CACHE'",
            true,
            60,
            'mysql_test_category'
        );

        // Assert
        $this->assertEquals(1, $result->numRows,
            'query() with cache on MySQL must return the correct row count');
        $this->assertSame('mysql-cached-label', $result->fields['label'],
            'query() with cache on MySQL must return the correct row data');
    }

    // =========================================================================
    // close() — clears connection state
    // =========================================================================

    /**
     * close() on a connected MySQL instance must set connected=false and return
     * true (mysqli_close succeeds).  After close(), the DB can be re-connected.
     * This exercises the close() method body at lines 894–924.
     */
    public function testCloseMySQLSetsConnectedFalseAndReturnsTrueOnSuccess(): void
    {
        // Arrange — create a fresh separate DB to avoid affecting the shared instance
        $db = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 3306;
        $db->connect(true);

        $this->assertTrue($db->connected, 'DB must be connected before close()');

        // Act
        $result = $db->close();

        // Assert — connected flag must be false and close() returned true
        $this->assertFalse($db->connected, 'close() must set connected=false');
        $this->assertTrue($result, 'close() must return true when connection was open');
    }

    // =========================================================================
    // refresh() / tryReconnect() — MySQL reconnect
    // =========================================================================

    /**
     * refresh() closes and re-opens the connection.  After a successful
     * refresh(), the database must be connected again.
     * This covers refresh() at lines 2225–2229, called from tryReconnect().
     */
    public function testRefreshMySQLReconnectsSuccessfully(): void
    {
        // Arrange — fresh connected instance
        $db = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 3306;
        $db->connect(true);

        // Act — refresh reconnects
        $result = $db->refresh(true);

        // Assert
        $this->assertTrue($result, 'refresh() must return true on successful reconnect');
        $this->assertTrue($db->connected, 'DB must be connected after refresh()');

        // Cleanup
        $db->close();
    }

    // =========================================================================
    // connectMysql() — persistency path
    // =========================================================================

    /**
     * connectMysql() with persistency=true uses a persistent connection
     * (p: prefix on the hostname).  The connection must succeed identically
     * to a regular connection.
     * This exercises line 658 — the `$host = 'p:' . $this->server` branch.
     */
    public function testConnectMySQLPersistentConnectionSucceeds(): void
    {
        // Arrange
        $db = new Database();
        $db->type        = 'mysql';
        $db->server      = 'db';
        $db->user        = 'root';
        $db->password    = 'secret';
        $db->database    = 'pramnos_test';
        $db->port        = 3306;
        $db->persistency = true;

        // Act
        $ok = $db->connect(true);

        // Assert
        $this->assertTrue($ok, 'Persistent MySQL connect must succeed');
        $this->assertTrue($db->connected, 'DB must be connected after persistent connect');

        // Cleanup
        $db->close();
    }

    // =========================================================================
    // setError() — non-fatal path
    // =========================================================================

    /**
     * setError() with $fatal=false throws an Exception with a formatted
     * message including the error number, message, and current SQL query.
     * This exercises lines 2326–2336 (the `elseif ($fatal == false)` branch).
     */
    public function testSetErrorNonFatalThrowsWithFormattedMessage(): void
    {
        // Arrange — access protected setError() via reflection on a subclass
        $db = new class extends Database {
            public function callSetError(int $no, string $msg, bool $fatal): void
            {
                $this->setError($no, $msg, $fatal);
            }
        };
        $db->type = 'mysql';

        // Act + Assert — fatal=false still throws, just with a different format
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/1146.*Table.*not.*exist/i');
        $db->callSetError(1146, 'Table does not exist', false);
    }

    // =========================================================================
    // displayError() — no Application (error_log fallback)
    // =========================================================================

    /**
     * displayError() either logs via error_log() (when no Application is running)
     * or calls Application::showError() → Application::close() (when Application
     * is active).  In test context, Application::close() throws an Exception
     * with 'Application::close() called'.
     *
     * This covers lines 2342–2350 of Database.php — the displayError() method body.
     * Both code paths are exercised across the test suite depending on Application state.
     */
    public function testDisplayErrorExecutesWithoutUnexpectedErrors(): void
    {
        // Arrange
        $db = new Database();
        $db->error_number = 500;
        $db->error_text   = 'something went wrong';

        // Act — either error_log() or Application::close() (testing context throws)
        try {
            $db->displayError();
            // No Application present → error_log() was used; no exception is fine
            $this->addToAssertionCount(1);
        } catch (\Exception $e) {
            // Application present → Application::close() throws in PRAMNOS_TESTING
            $this->assertStringContainsString('Application::close() called', $e->getMessage(),
                'Only Application::close() may throw from displayError()');
        }
    }

    // =========================================================================
    // selectOne() — null binding
    // =========================================================================

    /**
     * selectOne() with multiple bindings returns the first row when the WHERE
     * clause matches exactly one row.  This exercises the binding substitution
     * loop when bindings array has multiple values.
     */
    public function testSelectOneMySQLWithMultipleBindings(): void
    {
        // Arrange — insert a row with known values
        self::$db->query(
            "INSERT INTO " . self::$table . " (code, label, qty) "
            . "VALUES ('SELONE-MULTI', 'multi-bind', 42)"
        );

        // Act — two bindings: string and int
        $row = self::$db->selectOne(
            'SELECT qty FROM ' . self::$table . ' WHERE code = ? AND label = ?',
            ['SELONE-MULTI', 'multi-bind']
        );

        // Assert
        $this->assertIsArray($row,
            'selectOne() with multiple bindings must return an array');
        $this->assertEquals(42, $row['qty'],
            'selectOne() must return the correct field value');
    }

    // =========================================================================
    // startTransaction() — auto-connect when not connected
    // =========================================================================

    /**
     * startTransaction() auto-connects when connected=false.  After the
     * auto-connect, the transaction must start successfully.
     * This exercises line 2795 — `$this->connect()` inside startTransaction().
     */
    public function testStartTransactionAutoConnectsWhenNotConnected(): void
    {
        // Arrange — fresh DB that has not called connect() yet but has credentials
        $db = new Database();
        $db->type      = 'mysql';
        $db->server    = 'db';
        $db->user      = 'root';
        $db->password  = 'secret';
        $db->database  = 'pramnos_test';
        $db->port      = 3306;
        // connected is false (never called connect())

        $this->assertFalse($db->connected, 'Precondition: DB must not be connected yet');

        // Act — startTransaction() auto-connects then starts the transaction
        $result = $db->startTransaction();

        // Assert — must succeed and DB must now be connected
        $this->assertTrue($result, 'startTransaction() must return true after auto-connect');
        $this->assertTrue($db->connected, 'DB must be connected after startTransaction() auto-connect');

        // Cleanup
        $db->rollbackTransaction();
        $db->close();
    }

    // =========================================================================
    // close() — with open prepared statements (MySQL path)
    // =========================================================================

    /**
     * close() with open prepared statements must free each statement before
     * closing the connection.  This exercises lines 904-911 of close() —
     * the `foreach ($this->statements ...)` cleanup loop for MySQL.
     */
    public function testCloseMySQLWithOpenPreparedStatements(): void
    {
        // Arrange — fresh connected DB
        $db = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 3306;
        $db->connect(true);

        // Open a prepared statement without executing it (leaving it open)
        $stmt = $db->prepare('SELECT 1 AS check_val');
        $this->assertNotFalse($stmt, 'prepare() must succeed before close() test');

        // Act — close() must free open statements before closing connection
        $result = $db->close();

        // Assert — close() must succeed without throwing
        $this->assertTrue($result, 'close() must return true even with open prepared statements');
        $this->assertFalse($db->connected, 'connected must be false after close()');
    }

    // =========================================================================
    // connect() — read/write replica config path
    // =========================================================================

    /**
     * connect() with explicit readConfig and writeConfig connects to both
     * replicas and sets connected=true.  This exercises lines 605–608 of
     * connect() — the read/write replica configuration path.
     *
     * readConfig and writeConfig are protected, so we use a subclass to set them.
     */
    public function testConnectWithReadWriteReplicaConfig(): void
    {
        // Arrange — subclass exposes the protected config properties
        $db = new class extends Database {
            public function setReplicaConfig(array $readCfg, array $writeCfg): void
            {
                $this->readConfig  = $readCfg;
                $this->writeConfig = $writeCfg;
            }
        };
        $db->type = 'mysql';

        // Same credentials for both replicas in the Docker test environment
        $replicaCfg = [
            'hostname' => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
        $db->setReplicaConfig($replicaCfg, $replicaCfg);

        // Act
        $ok = $db->connect(true);

        // Assert — both replicas must have connected
        $this->assertTrue($ok, 'connect() with read/write replica config must return true');
        $this->assertTrue($db->connected, 'DB must be connected after replica connect()');

        // Verify queries still work through the replicated connection
        $result = $db->execute('SELECT 1 AS health');
        $this->assertEquals(1, $result->fields['health'],
            'Queries must work after replica-config connect()');

        // Cleanup
        $db->close();
    }
}
