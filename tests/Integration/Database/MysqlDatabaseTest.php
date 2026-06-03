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
}
