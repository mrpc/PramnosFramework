<?php

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Database::class)]
class MySQLConnectionTest extends TestCase
{
    /**
     * Verify that the framework can natively connect to MySQL
     * and execute prepared statements correctly.
     */
    public function testMySQLConnectionAndPreparedStatements()
    {
        $db = new Database();
        
        // Credentials matching docker-compose.yml 'db' service
        $db->type = 'mysql';
        $db->server = 'db';
        $db->user = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port = 3306;
        
        $connected = $db->connect(true);
        $this->assertTrue($connected, "Database failed to connect to MySQL!");
        
        // Test basic connectivity
        $result = $db->execute("SELECT 1 AS health_check");
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(1, $result->fields['health_check']);
        
        // Test prepared statement with parameters (MySQL uses '?' internally)
        $val = 456;
        $stmtResult = $db->execute("SELECT %i AS test_val", $val);
        $this->assertEquals(1, $stmtResult->numRows);
        $this->assertEquals($val, $stmtResult->fields['test_val']);
        
        // cleanup
        $db->close();
    }
}
