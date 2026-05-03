<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;

/**
 * Characterization tests for Settings static state management.
 *
 * Locks the contracts for get/set, default-value fallback, loaded-state
 * tracking, magic __get/__set, and the database-key bypass list.
 */
#[CoversClass(Settings::class)]
class SettingsCharacterizationTest extends TestCase
{
    /**
     * Reset all static state before each test so tests are isolated.
     */
    protected function setUp(): void
    {
        // Arrange – wipe static fields via reflection
        $ref = new \ReflectionClass(Settings::class);

        $settingsProp = $ref->getProperty('settings');
        $settingsProp->setValue(null, []);

        $loadedProp = $ref->getProperty('loaded');
        $loadedProp->setValue(null, false);

        $dbProp = $ref->getProperty('database');
        $dbProp->setValue(null, null);
    }

    // -------------------------------------------------------------------------
    // getSetting / setSetting
    // -------------------------------------------------------------------------

    /**
     * setSetting stores a value that getSetting can retrieve.
     * Verifies the core read-after-write contract.
     */
    public function testSetAndGetSettingRoundTrip(): void
    {
        // Act
        Settings::setSetting('siteName', 'Pramnos Test', false);

        // Assert
        $this->assertSame('Pramnos Test', Settings::getSetting('siteName'));
    }

    /**
     * getSetting returns the $defaultValue when the setting does not exist.
     * This is the primary fallback contract used throughout the framework.
     */
    public function testGetSettingReturnsDefaultValueWhenNotSet(): void
    {
        // Act
        $result = Settings::getSetting('nonExistentKey', 'fallback');

        // Assert
        $this->assertSame('fallback', $result);
    }

    /**
     * getSetting returns false (not null) when no default is provided and the
     * setting is absent — callers rely on `if ($setting)` patterns.
     */
    public function testGetSettingReturnsFalseWhenNotSetAndNoDefault(): void
    {
        // Act
        $result = Settings::getSetting('ghost');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * An array value is returned as an object (stdClass cast) so that callers
     * can use ->property syntax on settings groups.
     */
    public function testGetSettingCastsArrayValueToObject(): void
    {
        // Arrange
        Settings::setSetting('db', ['host' => 'localhost', 'port' => 3306], false);

        // Act
        $result = Settings::getSetting('db');

        // Assert
        $this->assertIsObject($result);
        $this->assertSame('localhost', $result->host);
        $this->assertSame(3306, $result->port);
    }

    /**
     * setSetting overwrites a previously stored value.
     * Confirms there is no "first write wins" lock.
     */
    public function testSetSettingOverwritesPreviousValue(): void
    {
        // Arrange
        Settings::setSetting('version', '1.0', false);

        // Act
        Settings::setSetting('version', '2.0', false);

        // Assert
        $this->assertSame('2.0', Settings::getSetting('version'));
    }

    // -------------------------------------------------------------------------
    // loaded state
    // -------------------------------------------------------------------------

    /**
     * loadSettings from a valid PHP file marks the class as loaded and
     * populates the settings array from the returned array.
     */
    public function testLoadSettingsFromFileMarksLoadedAndPopulates(): void
    {
        // Arrange
        $file = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';

        // Act
        $result = Settings::loadSettings($file);

        // Assert
        $this->assertTrue($result);
        // securitySalt is defined in the fixture
        $this->assertSame('test_salt_123456789', Settings::getSetting('securitySalt'));
    }

    /**
     * loadSettings returns false when the file does not exist and no callback
     * is given. Callers check the boolean return to detect missing config.
     */
    public function testLoadSettingsReturnsFalseForMissingFile(): void
    {
        // Act
        $result = Settings::loadSettings('/tmp/does_not_exist_pramnos_test.php');

        // Assert
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // database key bypass list
    // -------------------------------------------------------------------------

    /**
     * 'hostname', 'database', 'user', 'password', 'type', 'prefix', 'schema',
     * 'collation', 'cache' are intercepted before any DB lookup to prevent
     * recursion during connection establishment.  Without a database object
     * they must fall back to $defaultValue.
     */
    #[DataProvider('databaseKeyProvider')]
    public function testDatabaseConnectionKeysReturnDefaultWithoutDatabase(string $key): void
    {
        // Act
        $result = Settings::getSetting($key, 'SENTINEL');

        // Assert – must be sentinel, not false (which would be the absent-key default)
        $this->assertSame('SENTINEL', $result);
    }

    /** @return array<string,array{0:string}> */
    public static function databaseKeyProvider(): array
    {
        return [
            'hostname'  => ['hostname'],
            'database'  => ['database'],
            'schema'    => ['schema'],
            'user'      => ['user'],
            'password'  => ['password'],
            'collation' => ['collation'],
            'prefix'    => ['prefix'],
            'type'      => ['type'],
            'cache'     => ['cache'],
        ];
    }

    /**
     * When the 'database' setting is stored as a nested array (legacy config
     * pattern), getSetting('hostname') resolves it from that sub-array.
     */
    public function testLegacyNestedDatabaseArrayResolvesSubKey(): void
    {
        // Arrange
        Settings::setSetting('database', [
            'hostname' => 'db-server',
            'user'     => 'app',
        ], false);

        // Act
        $host = Settings::getSetting('hostname', 'FALLBACK');
        $user = Settings::getSetting('user', 'FALLBACK');

        // Assert
        $this->assertSame('db-server', $host);
        $this->assertSame('app', $user);
    }

    // -------------------------------------------------------------------------
    // Magic __get / __set
    // -------------------------------------------------------------------------

    /**
     * __set and __get proxies work correctly through an instance, keeping
     * the same static backing store as the static methods.
     */
    public function testMagicGetSetProxyToStaticStore(): void
    {
        // Arrange
        $settings = new Settings();

        // Act
        $settings->appTitle = 'My App';

        // Assert – same value visible through static getSetting
        $this->assertSame('My App', Settings::getSetting('appTitle'));
        // And through the magic getter
        $this->assertSame('My App', $settings->appTitle);
    }

    /**
     * dbsettings=false flag prevents any DB lookup for arbitrary settings,
     * returning the default value immediately (anti-recursion guard).
     */
    public function testDbsettingsFalseSkipsDatabaseLookup(): void
    {
        // Arrange – disable DB settings lookup
        Settings::setSetting('dbsettings', false, false);

        // Act – a key that is not in memory should get the default
        $result = Settings::getSetting('someRemoteSetting', 'DEFAULT');

        // Assert
        $this->assertSame('DEFAULT', $result);
    }
}
