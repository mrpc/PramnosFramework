<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Health;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Application\Controllers\Health;
use Pramnos\Database\Database;
use Pramnos\Health\HealthRegistry;

/**
 * Integration tests for Health::display() against MySQL 8.0.
 *
 * Verifies that the DB-info query inside display() (`SELECT VERSION() AS v`)
 * executes correctly against a real MySQL connection and that the resulting
 * HTML contains the expected database type and version string.
 *
 * These tests exercise the path that unit tests cannot cover: the branch in
 * display() where `$db->connected === true` and the query actually runs.
 *
 * Coverage:
 * - SELECT VERSION() AS v returns a non-empty string on MySQL
 * - display() HTML contains the DB type label "Mysql"
 * - display() HTML contains a recognisable MySQL version substring (e.g. "8.")
 * - display() renders the System Info table even when no health checks exist
 * - PHP version shown in the info table matches PHP_VERSION constant
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class HealthDbInfoMySQLTest extends TestCase
{
    protected Database $db;

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

        HealthRegistry::reset();
    }

    protected function tearDown(): void
    {
        HealthRegistry::reset();
    }

    // -------------------------------------------------------------------------
    // SELECT VERSION() query
    // -------------------------------------------------------------------------

    /**
     * SELECT VERSION() AS v must return a non-empty string when executed against
     * the MySQL container.
     *
     * This is the exact query used by Health::display() to populate the DB Version
     * row in the System Info table.  If the query fails or returns an empty result
     * the display() method silently shows "—", which is the bug this test prevents.
     */
    public function testSelectVersionQueryReturnsNonEmptyString(): void
    {
        // Arrange — ensure we have a real connection
        $this->assertTrue(
            $this->db->connected,
            'MySQL container must be reachable for this integration test'
        );

        // Act — replicate the exact query used in Health::display()
        $result = $this->db->execute('SELECT VERSION() AS v');

        // Assert — query must succeed and return a usable version string
        $this->assertNotFalse($result, 'SELECT VERSION() must not return false');
        $this->assertNotEmpty($result->fields['v'] ?? '', 'Version field must not be empty');
    }

    /**
     * The MySQL version string must indicate MySQL 8.x (the Docker image).
     *
     * Protects against accidentally running against a different server version
     * or receiving a mangled response.
     */
    public function testSelectVersionReturnsMySql8(): void
    {
        // Arrange
        $this->assertTrue($this->db->connected, 'MySQL container must be reachable');

        // Act
        $result  = $this->db->execute('SELECT VERSION() AS v');
        $version = $result->fields['v'] ?? '';

        // Assert — Docker MySQL image is 8.x; version string must contain "8."
        $this->assertStringContainsString(
            '8.',
            $version,
            "Expected MySQL 8.x but got: {$version}"
        );
    }

    // -------------------------------------------------------------------------
    // Health::display() HTML — DB type and version
    // -------------------------------------------------------------------------

    /**
     * display() must include the database type label "Mysql" in the System Info
     * table when connected to a MySQL server.
     *
     * Health::display() calls ucfirst($db->type) to build the label; this test
     * ensures that path is exercised and the value reaches the HTML output.
     */
    public function testDisplayHtmlContainsMysqlLabel(): void
    {
        // Arrange
        $this->assertTrue($this->db->connected, 'MySQL container must be reachable');

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — "Mysql" (ucfirst of "mysql") must appear in the DB row
        $this->assertStringContainsString(
            'Mysql',
            $html,
            'Database row must contain "Mysql" label when connected to MySQL'
        );
    }

    /**
     * display() must embed the real MySQL version string in the HTML output.
     *
     * This verifies the full end-to-end path: DB connection → SELECT VERSION()
     * → ucfirst($db->type) + version in <td> — no "—" placeholder when DB is up.
     */
    public function testDisplayHtmlContainsMySqlVersion(): void
    {
        // Arrange
        $this->assertTrue($this->db->connected, 'MySQL container must be reachable');

        // Fetch the real version so we can assert the exact string is echoed
        $result  = $this->db->execute('SELECT VERSION() AS v');
        $version = $result->fields['v'] ?? '';
        $this->assertNotEmpty($version, 'Pre-condition: version must be non-empty');

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — the actual version string must appear verbatim in the HTML
        $this->assertStringContainsString(
            $version,
            $html,
            'DB version from SELECT VERSION() must appear in the System Info table'
        );
    }

    /**
     * display() must show the PHP_VERSION constant in the System Info table even
     * when connected to a real database.
     *
     * Regression guard: ensures that adding DB-query logic inside display() did
     * not accidentally break the PHP version row.
     */
    public function testDisplayHtmlContainsPhpVersionWithRealDb(): void
    {
        // Arrange
        $this->assertTrue($this->db->connected, 'MySQL container must be reachable');

        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — PHP_VERSION must always appear regardless of DB connectivity
        $this->assertStringContainsString(
            PHP_VERSION,
            $html,
            'PHP version must appear in the System Info table even with a live DB'
        );
    }
}
