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

    /**
     * A recording stand-in for the query builder.
     *
     * Settings reads and writes through the builder rather than hand-built SQL
     * — a raw statement carried MySQL backticks to PostgreSQL, silently. These
     * tests therefore assert on the *calls*, which is also the more honest
     * subject: what matters is that a write checks for the row and then inserts
     * or updates it, not what the statement looked like.
     *
     * @param object $log      Receives ->table, ->where, ->inserted, ->updated
     * @param bool   $exists   What exists() reports
     * @param array  $fields   What get() returns as its single row
     */
    private function fakeBuilder(object $log, bool $exists = false, array $fields = []): object
    {
        return new class ($log, $exists, $fields) {
            public function __construct(
                private object $log,
                private bool $exists,
                private array $fields,
            ) {
            }

            public function table($t) { $this->log->table = $t; return $this; }
            public function from($t) { $this->log->table = $t; return $this; }
            public function select($c) { return $this; }
            public function limit($n) { return $this; }

            public function where($col, $op = null, $val = null)
            {
                $this->log->where = [$col, func_num_args() === 2 ? $op : $val];
                return $this;
            }

            public function exists(): bool { return $this->exists; }

            public function insert(array $values) { $this->log->inserted = $values; return true; }

            public function update(array $values) { $this->log->updated = $values; return true; }

            public function get($cache = false, $ttl = 0, $category = '')
            {
                $fields = $this->fields;
                return new class ($fields) {
                    public $numRows;
                    public $fields;
                    public function __construct(array $fields)
                    {
                        $this->fields  = $fields;
                        $this->numRows = $fields === [] ? 0 : 1;
                    }
                };
            }
        };
    }

    public function testSetAndGetSettingWithDatabase()
    {
        // Arrange — no such row yet, so the write must insert
        $log = new \stdClass();
        $log->inserted = null;

        $mockDb = $this->createMock(Database::class);
        $mockDb->method('queryBuilder')->willReturnCallback(
            fn(): object => $this->fakeBuilder($log, false)
        );

        // Act
        Settings::setDatabase($mockDb);
        Settings::setSetting('db_key', 'db_value', true);

        // Assert
        $this->assertSame(['setting' => 'db_key', 'value' => 'db_value'], $log->inserted);
        $this->assertEquals('db_value', Settings::getSetting('db_key'));
    }

    public function testSetSettingUpdatesExistingDatabaseRecord()
    {
        // Arrange — the row is there, so the write must update rather than add
        $log = new \stdClass();
        $log->updated = null;
        $log->where   = null;

        $mockDb = $this->createMock(Database::class);
        $mockDb->method('queryBuilder')->willReturnCallback(
            fn(): object => $this->fakeBuilder($log, true)
        );

        // Act
        Settings::setDatabase($mockDb);
        Settings::setSetting('update_key', 'new_value', true);

        // Assert
        $this->assertSame(['value' => 'new_value'], $log->updated);
        $this->assertSame(['setting', 'update_key'], $log->where);
        $this->assertEquals('new_value', Settings::getSetting('update_key'));
    }

    public function testGetSettingQueriesDatabaseIfForced()
    {
        // Arrange
        $log    = new \stdClass();
        $mockDb = $this->createMock(Database::class);
        $mockDb->expects($this->once())
               ->method('queryBuilder')
               ->willReturnCallback(fn(): object => $this->fakeBuilder($log, true, ['value' => 'db_value']));
        $mockDb->expects($this->never())->method('query');

        // Act — force=true bypasses the in-memory store and reads the database
        Settings::setDatabase($mockDb);

        // Assert
        $this->assertEquals('db_value', Settings::getSetting('force_key', false, true));
    }

    public function testDeleteSetting()
    {
        // deleteSetting() must go through the query builder (a raw
        // "DELETE ... LIMIT 1" is invalid on PostgreSQL). Assert it targets
        // the settings table, filters by the setting key, and issues a delete.
        $captured = new \stdClass();
        $captured->table = null;
        $captured->where = null;
        $captured->deleted = false;

        $builder = new class ($captured) {
            private $c;
            public function __construct($c) { $this->c = $c; }
            public function table($t) { $this->c->table = $t; return $this; }
            public function from($t) { $this->c->table = $t; return $this; }
            public function where($col, $op = null, $val = null)
            {
                $this->c->where = [$col, func_num_args() === 2 ? $op : $val];
                return $this;
            }
            public function delete() { $this->c->deleted = true; return true; }
        };

        $mockDb = $this->createMock(Database::class);
        $mockDb->expects($this->once())
               ->method('queryBuilder')
               ->willReturn($builder);
        $mockDb->expects($this->never())->method('query');

        Settings::setDatabase($mockDb);
        $this->assertTrue(Settings::deleteSetting('delete_key'));
        $this->assertSame('#PREFIX#settings', $captured->table);
        $this->assertSame(['setting', 'delete_key'], $captured->where);
        $this->assertTrue($captured->deleted);
    }

    public function testDeleteSettingWithoutDatabaseReturnsFalse()
    {
        // Without a database configured, deleteSetting must not fatal on a
        // null database and should report failure.
        Settings::clearSettings();
        $this->assertFalse(Settings::deleteSetting('whatever'));
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
