<?php

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::clearSettings();
    }

    public function testSetAndGetSettingNoDatabase()
    {
        Settings::setSetting('test_key', 'test_value', false);
        $this->assertEquals('test_value', Settings::getSetting('test_key'));
    }

    public function testGetSettingArrayConvertsToObject()
    {
        Settings::setSetting('test_array', ['a' => 1, 'b' => 2], false);
        $result = Settings::getSetting('test_array');
        $this->assertIsObject($result);
        $this->assertEquals(1, $result->a);
    }

    public function testGetSettingSkipsDatabaseForDatabaseKeys()
    {
        $this->assertEquals('default', Settings::getSetting('hostname', 'default'));
        
        // Backward compatibility check
        Settings::setSetting('database', ['hostname' => 'legacy_host'], false);
        $this->assertEquals('legacy_host', Settings::getSetting('hostname', 'default'));
    }

    public function testGetSettingSkipsDatabaseIfDbSettingsFalse()
    {
        Settings::setSetting('dbsettings', false, false);
        $this->assertEquals('default', Settings::getSetting('some_key', 'default'));
    }

    public function testSetAndGetSettingWithDatabase()
    {
        $mockDb = $this->createMock(Database::class);
        $mockDb->method('prepareQuery')->willReturn('MOCK QUERY');
        
        // setSetting checks if exists first
        $mockResult0 = new class { public $numRows = 0; };
        // setSetting inserts
        $mockResultInsert = new class { public $numRows = 1; };
        
        $mockDb->expects($this->any())
               ->method('query')
               ->willReturn($mockResult0, $mockResultInsert);
               
        Settings::setDatabase($mockDb);
        Settings::setSetting('db_key', 'db_value', true);
        
        $this->assertEquals('db_value', Settings::getSetting('db_key'));
    }

    public function testSetSettingUpdatesExistingDatabaseRecord()
    {
        $mockDb = $this->createMock(Database::class);
        $mockDb->method('prepareQuery')->willReturn('MOCK QUERY');
        
        // setSetting checks if exists first
        $mockResultExists = new class { public $numRows = 1; };
        
        $mockDb->expects($this->any())
               ->method('query')
               ->willReturn($mockResultExists);
               
        Settings::setDatabase($mockDb);
        Settings::setSetting('update_key', 'new_value', true);
        $this->assertEquals('new_value', Settings::getSetting('update_key'));
    }

    public function testGetSettingQueriesDatabaseIfForced()
    {
        $mockDb = $this->createMock(Database::class);
        $mockDb->method('prepareQuery')->willReturn('MOCK QUERY');
        
        $mockResult = new class { 
            public $numRows = 1; 
            public $fields = ['value' => 'db_value']; 
        };
        
        $mockDb->expects($this->once())
               ->method('query')
               ->willReturn($mockResult);
               
        Settings::setDatabase($mockDb);
        
        // Call getSetting with force=true so it hits the DB
        $this->assertEquals('db_value', Settings::getSetting('force_key', false, true));
    }

    public function testDeleteSetting()
    {
        $mockDb = $this->createMock(Database::class);
        $mockDb->method('prepareQuery')->willReturn('MOCK DELETE');
        $mockDb->expects($this->once())
               ->method('query')
               ->with('MOCK DELETE')
               ->willReturn(true);
               
        Settings::setDatabase($mockDb);
        $this->assertTrue(Settings::deleteSetting('delete_key'));
    }

    public function testLoadSettingsNoFileCallsFallback()
    {
        $GLOBALS['settings_fallback_called'] = false;
        
        $result = Settings::loadSettings('non_existent_file.php', 'array_map', [function($v) { $GLOBALS['settings_fallback_called'] = true; return $v; }, ['success']]);
        
        $this->assertTrue($GLOBALS['settings_fallback_called']);
        $this->assertEquals(['success'], $result);
    }
    
    public function testLoadSettingsNoFileReturnsFalseIfNoFallback()
    {
        $result = Settings::loadSettings('non_existent_file.php');
        $this->assertFalse($result);
    }

    public function testMagicMethods()
    {
        $settings = Settings::getInstance();
        $settings->magic_key = 'magic_value';
        $this->assertEquals('magic_value', $settings->magic_key);
    }
}
