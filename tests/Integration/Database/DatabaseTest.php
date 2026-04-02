<?php

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;

#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Database\Database::class)]
class DatabaseTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Verify that the framework can natively connect to PostgreSQL
     * and that the TimescaleDB extension is fully active.
     */
    public function testTimescaleDbConnectionAndExtension()
    {
        $db = new Database();
        
        // Read credentials natively from the Docker compose environment
        $db->type = 'postgresql';
        $db->server = 'timescaledb';
        $db->user = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port = 5432;
        
        // This will throw a RuntimeException if the connection fails
        $connected = $db->connect(true);
        $this->assertTrue($connected, "Database failed to connect to PostgreSQL!");
        
        // Verify postgres connection executes queries safely
        $result = $db->execute("SELECT 1 AS health_check");
        $this->assertEquals(1, $result->numRows);
        $this->assertEquals(1, $result->fields['health_check']);
        
        // New: Verify parameterized queries (which test the $1, $2 conversion)
        $testVal = 999;
        $paramResult = $db->execute("SELECT %i AS test_val", $testVal);
        $this->assertEquals(1, $paramResult->numRows);
        $this->assertEquals($testVal, $paramResult->fields['test_val']);
        
        // Verify TimescaleDB extension is loaded
        $timescaleCheck = $db->execute("SELECT extname FROM pg_extension WHERE extname = 'timescaledb'");
        $this->assertEquals(1, $timescaleCheck->numRows, "TimescaleDB extension is NOT active inside the PostgreSQL container!");
        
        // cleanup
        $db->close();
    }
}
