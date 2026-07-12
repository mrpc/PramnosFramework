<?php

namespace Pramnos\Tests\Unit\Database;

use Pramnos\Database\Database;
use PHPUnit\Framework\TestCase;

class DatabaseCacheAndLogTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // Use an empty application mock to avoid dependencies
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $this->db = new Database($app, 'mysql');
    }

    public function testGetQueryLogInitiallyEmpty(): void
    {
        $this->assertEmpty($this->db->getQueryLog());
    }

    public function testIsWriteQuery(): void
    {
        $this->assertTrue($this->db->isWriteQuery('INSERT INTO table (a) VALUES (1)'));
        $this->assertTrue($this->db->isWriteQuery('UPDATE table SET a=1'));
        $this->assertTrue($this->db->isWriteQuery('DELETE FROM table'));
        $this->assertTrue($this->db->isWriteQuery('REPLACE INTO table (a) VALUES (1)'));
        
        $this->assertFalse($this->db->isWriteQuery('SELECT * FROM table'));
        $this->assertFalse($this->db->isWriteQuery('SHOW TABLES'));
    }
    
}
