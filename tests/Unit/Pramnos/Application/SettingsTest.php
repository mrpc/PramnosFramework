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

    /**
     * loadSettings() successfully loads settings from an existing PHP file.
     *
     * Covers lines 93-97: the `file_exists()` true branch where settings are
     * included, $loaded is set to true, and each key is stored via setSetting().
     */
    public function testLoadSettingsFromExistingFile(): void
    {
        // Arrange — write a temporary settings file
        $tmpFile = sys_get_temp_dir() . '/pramnos_settings_test_' . uniqid() . '.php';
        file_put_contents($tmpFile, '<?php return ["site_name" => "TestSite", "version" => "1.0"];');

        try {
            // Act
            $result = Settings::loadSettings($tmpFile);

            // Assert — returns true and settings are accessible
            $this->assertTrue($result,
                'loadSettings() must return true when the settings file exists');
            $this->assertEquals('TestSite', Settings::getSetting('site_name'),
                'loadSettings() must store all settings from the file');
            $this->assertEquals('1.0', Settings::getSetting('version'));
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * getSetting() returns the default value and does not propagate Throwable when
     * the database query fails (e.g. settings table does not yet exist).
     *
     * Covers lines 204-208: the empty `catch (\Throwable $e)` block that suppresses
     * DB errors so the application can boot before running migrations.
     */
    public function testGetSettingReturnDefaultWhenDatabaseThrows(): void
    {
        // Arrange — mock database that throws on query()
        $mockDb = $this->createMock(Database::class);
        $mockDb->method('prepareQuery')->willReturn('MOCK QUERY');
        $mockDb->method('query')->willThrowException(new \RuntimeException('table not found'));

        Settings::setDatabase($mockDb);

        // Act — should NOT propagate; must return default
        $result = Settings::getSetting('some_unset_key', 'fallback');

        // Assert — catch block suppresses and default is returned
        $this->assertSame('fallback', $result,
            'getSetting() must return default value when the database throws');
    }

    /**
     * getSetting() for a database connection key that exists as a nested array
     * under the 'database' key returns the nested value.
     *
     * Covers lines 180-184: `isset(self::$settings['database']) && is_array(...)`
     * backward-compatibility branch for legacy config format.
     */
    public function testGetSettingDatabaseKeyFromNestedArray(): void
    {
        // Arrange — legacy config format: DB settings nested under 'database' key
        Settings::setSetting('database', [
            'hostname' => 'legacy.host.example',
            'user'     => 'legacy_user',
        ], false);

        // Act — 'hostname' is a $databaseSettingKeys entry; must look in nested array
        $hostname = Settings::getSetting('hostname', 'default');
        $user     = Settings::getSetting('user',     'default');

        // Assert — backward-compatible nested lookup
        $this->assertSame('legacy.host.example', $hostname,
            'getSetting() must return nested database array value for DB connection keys');
        $this->assertSame('legacy_user', $user);
    }

    /**
     * getInstance() returns the same singleton instance on subsequent calls.
     *
     * Covers lines 64-75: the static getInstance() reference-return singleton.
     */
    public function testGetInstanceReturnsSingleton(): void
    {
        // Act — two calls must return the same object
        $a = Settings::getInstance();
        $b = Settings::getInstance();

        // Assert — same instance
        $this->assertSame($a, $b,
            'getInstance() must return the same Settings singleton every time');
    }
}
