<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Scopes;
use Pramnos\Database\Database;

/**
 * Integration tests for Scopes::areApplicationScopesGranted() against MySQL.
 *
 * This method calls Factory::getDatabase() (a fully-qualified static) to look
 * up the application's allowed scopes in the `applications` table.  It cannot
 * be exercised by unit tests, so these integration tests are the only way to
 * cover lines 247–275 in Scopes.php.
 *
 * Coverage target: push Scopes.php from 85.3% (116/136) to 90%+ (123+/136).
 *
 * Isolation strategy:
 *   - The `applications` table is created IF NOT EXISTS (compatible with any
 *     prior test that may already have created it).
 *   - Only rows inserted by this class are deleted in tearDown(), identified
 *     by the unique apikey prefix 'scopes-test-mysql-'.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
#[CoversClass(Scopes::class)]
class ScopesMySQLIntegrationTest extends TestCase
{
    protected Database $db;

    /** @var int[] appids inserted during this test run — deleted in tearDown */
    private array $testAppIds = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->testAppIds = [];
        $this->ensureApplicationsTable();
    }

    protected function tearDown(): void
    {
        foreach ($this->testAppIds as $appid) {
            $this->db->query("DELETE FROM `applications` WHERE `appid` = " . (int) $appid);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure the applications table exists with a minimal schema.
     * Uses IF NOT EXISTS so it works whether or not a prior test already created it.
     */
    private function ensureApplicationsTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `applications` (
            `appid`     INT AUTO_INCREMENT PRIMARY KEY,
            `name`      VARCHAR(191) NOT NULL,
            `apikey`    VARCHAR(191) NOT NULL,
            `apisecret` VARCHAR(191) NOT NULL DEFAULT '',
            `status`    INT NOT NULL DEFAULT 0,
            `scope`     TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Insert a test application row and record its ID for tearDown cleanup.
     *
     * @param string $apikey Unique API key for this application
     * @param string $scope  Space-delimited allowed scopes (or empty)
     * @return int           The new appid
     */
    private function insertApp(string $apikey, string $scope): int
    {
        $this->db->query(
            "INSERT INTO `applications` (`name`, `apikey`, `apisecret`, `status`, `scope`)
             VALUES ('Scopes Test App', '" . $this->db->prepareInput($apikey) . "', 'secret', 1,
                     '" . $this->db->prepareInput($scope) . "')"
        );
        $appid = (int) $this->db->getInsertId();
        $this->testAppIds[] = $appid;
        return $appid;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When the application has 'system:notifications_read' in its allowed scopes
     * and the request includes only that scope plus a default scope (profile),
     * all scopes should be granted and the problematic list should be empty.
     *
     * This covers the "granted" branch of line 270: scope IS in allowedScopes.
     */
    public function testAllScopesGrantedWhenAppHasExplicitScope(): void
    {
        // Arrange — app explicitly allows system:notifications_read
        $apikey = 'scopes-test-mysql-' . bin2hex(random_bytes(4));
        $this->insertApp($apikey, 'system:notifications_read');

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'profile system:notifications_read',
            $apikey
        );

        // Assert — profile is a default scope; notifications_read is in app's explicit list
        $this->assertTrue($allGranted, 'All scopes should be granted');
        $this->assertEmpty($problematic, 'No problematic scopes expected');
    }

    /**
     * When the application does NOT have 'system:notifications_write' in its
     * allowed scopes, requesting it should fail with that scope reported as
     * problematic.
     *
     * This covers the "not granted" branch of line 271: scope NOT in defaultScopes
     * AND NOT in allowedScopes → appended to $problematic.
     */
    public function testNotGrantedWhenAppLacksRequestedScope(): void
    {
        // Arrange — app only allows notifications_read, not notifications_write
        $apikey = 'scopes-test-mysql-' . bin2hex(random_bytes(4));
        $this->insertApp($apikey, 'system:notifications_read');

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'profile system:notifications_write',
            $apikey
        );

        // Assert — profile is default (ok), notifications_write is not in app scopes
        $this->assertFalse($allGranted, 'Should fail when app lacks the requested scope');
        $this->assertContains('system:notifications_write', $problematic);
        $this->assertNotContains('profile', $problematic, 'Default scope should not be problematic');
    }

    /**
     * When the application is not found in the database (no row matching the
     * apikey), no explicit scopes are available.  Requesting a non-default scope
     * must fail because the allowed-scopes list is empty.
     *
     * This covers line 258: ($result && $result->numRows > 0) is false →
     * $allowedScopes stays [].
     */
    public function testNotGrantedWhenApplicationNotFound(): void
    {
        // Arrange — use an apikey that has no corresponding row
        $apikey = 'scopes-test-mysql-nonexistent-' . bin2hex(random_bytes(4));

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'system:notifications_read',
            $apikey
        );

        // Assert — scope is not default and no app found → not granted
        $this->assertFalse($allGranted);
        $this->assertContains('system:notifications_read', $problematic);
    }

    /**
     * When the request contains only default scopes (profile, email, user),
     * they must be granted regardless of what the application's scope column
     * contains, because default scopes are implicitly granted to all clients.
     *
     * This covers line 270: scope IS in $defaultScopes → not added to $problematic.
     */
    public function testDefaultScopesAlwaysGranted(): void
    {
        // Arrange — app with empty scope column
        $apikey = 'scopes-test-mysql-' . bin2hex(random_bytes(4));
        $this->insertApp($apikey, '');

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'profile email user',
            $apikey
        );

        // Assert — all three are default scopes, must be granted
        $this->assertTrue($allGranted);
        $this->assertEmpty($problematic);
    }

    /**
     * An entirely unknown scope string must be flagged as problematic.
     *
     * Line 247: hasInvalidScopes() populates $problematic immediately.
     * Line 267: the loop skips already-invalid scopes via continue.
     */
    public function testInvalidScopeIsProblematic(): void
    {
        // Arrange
        $apikey = 'scopes-test-mysql-' . bin2hex(random_bytes(4));
        $this->insertApp($apikey, 'system:notifications_read');

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'totally_unknown_scope',
            $apikey
        );

        // Assert — unknown scope is not valid, so allGranted=false
        $this->assertFalse($allGranted);
        $this->assertContains('totally_unknown_scope', $problematic);
    }
}
