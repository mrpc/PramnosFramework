<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Scopes;
use Pramnos\Database\Database;

/**
 * Integration tests for Scopes::areApplicationScopesGranted() against PostgreSQL.
 *
 * Mirrors ScopesMySQLIntegrationTest but runs against the timescaledb container
 * (host: timescaledb, port: 5432).  Because Database::getInstance() is a
 * singleton that defaults to MySQL, each test runs in a separate process so
 * the pg_settings.php fixture takes effect before any MySQL singleton is
 * created by sibling tests.
 *
 * Requires the Docker TimescaleDB/PostgreSQL container (host: timescaledb, port: 5432).
 */
#[CoversClass(Scopes::class)]
#[RunTestsInSeparateProcesses]
class ScopesPostgreSQLIntegrationTest extends TestCase
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

        $pgSettingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        $this->testAppIds = [];
        // Drop any leftover table from a previous interrupted run, then recreate
        // with the limited schema needed for these tests.
        $this->db->query('DROP TABLE IF EXISTS "applications"');
        $this->ensureApplicationsTable();
    }

    protected function tearDown(): void
    {
        // Drop the limited-schema table created in setUp() so it does not
        // interfere with FrameworkMigrationsPostgreSQLTest, which expects the
        // full applications schema after running the authserver migration up().
        $this->db->query('DROP TABLE IF EXISTS "applications"');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure the applications table exists with a minimal schema for PostgreSQL.
     */
    private function ensureApplicationsTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS "applications" (
            "appid"     SERIAL PRIMARY KEY,
            "name"      VARCHAR(191) NOT NULL,
            "apikey"    VARCHAR(191) NOT NULL,
            "apisecret" VARCHAR(191) NOT NULL DEFAULT \'\',
            "status"    INT NOT NULL DEFAULT 0,
            "scope"     TEXT NULL
        )');
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
            'INSERT INTO "applications" ("name", "apikey", "apisecret", "status", "scope")
             VALUES (\'Scopes Test App\', \'' . $this->db->prepareInput($apikey) . '\', \'secret\', 1,
                     \'' . $this->db->prepareInput($scope) . '\')'
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
     */
    public function testAllScopesGrantedWhenAppHasExplicitScope(): void
    {
        // Arrange
        $apikey = 'scopes-test-pg-' . bin2hex(random_bytes(4));
        $this->insertApp($apikey, 'system:notifications_read');

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'profile system:notifications_read',
            $apikey
        );

        // Assert
        $this->assertTrue($allGranted, 'All scopes should be granted');
        $this->assertEmpty($problematic, 'No problematic scopes expected');
    }

    /**
     * When the application does NOT have 'system:notifications_write' in its
     * allowed scopes, requesting it must fail with that scope flagged.
     */
    public function testNotGrantedWhenAppLacksRequestedScope(): void
    {
        // Arrange
        $apikey = 'scopes-test-pg-' . bin2hex(random_bytes(4));
        $this->insertApp($apikey, 'system:notifications_read');

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'profile system:notifications_write',
            $apikey
        );

        // Assert
        $this->assertFalse($allGranted);
        $this->assertContains('system:notifications_write', $problematic);
        $this->assertNotContains('profile', $problematic);
    }

    /**
     * When the application is not found in the database, no explicit scopes are
     * available.  Requesting a non-default scope must fail.
     */
    public function testNotGrantedWhenApplicationNotFound(): void
    {
        // Arrange — apikey that does not exist in the table
        $apikey = 'scopes-test-pg-nonexistent-' . bin2hex(random_bytes(4));

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'system:notifications_read',
            $apikey
        );

        // Assert
        $this->assertFalse($allGranted);
        $this->assertContains('system:notifications_read', $problematic);
    }

    /**
     * Default scopes (profile, email, user) must always be granted regardless
     * of the application's scope column.
     */
    public function testDefaultScopesAlwaysGranted(): void
    {
        // Arrange — app with empty scope column
        $apikey = 'scopes-test-pg-' . bin2hex(random_bytes(4));
        $this->insertApp($apikey, '');

        // Act
        [$allGranted, $problematic] = Scopes::areApplicationScopesGranted(
            'profile email user',
            $apikey
        );

        // Assert
        $this->assertTrue($allGranted);
        $this->assertEmpty($problematic);
    }
}
