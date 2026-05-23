<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Health;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Application\Controllers\Health;
use Pramnos\Database\Database;
use Pramnos\Health\HealthRegistry;

/**
 * Integration tests for Health::display() against PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors HealthDbInfoMySQLTest but targets the timescaledb container
 * (host: timescaledb, port: 5432). Each test runs in a separate process so
 * pg_settings.php is loaded before any MySQL singleton is created by sibling
 * tests in the same suite run.
 *
 * Coverage:
 * - SELECT VERSION() AS v returns a non-empty string on PostgreSQL
 * - The version string contains the word "PostgreSQL"
 * - display() HTML contains the DB type label "Postgresql"
 * - display() HTML contains a recognisable PostgreSQL version substring
 * - PHP version shown in the info table matches PHP_VERSION constant
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[RunTestsInSeparateProcesses]
class HealthDbInfoPostgreSQLTest extends TestCase
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
     * the PostgreSQL / TimescaleDB container.
     *
     * This is the exact query used by Health::display() to populate the DB Version
     * row in the System Info table.  PostgreSQL's VERSION() returns a string like
     * "PostgreSQL 14.x on x86_64-pc-linux-musl, compiled by …"; this test ensures
     * the query works and the framework result-set accessor ($r->fields['v'])
     * surfaces it correctly.
     */
    public function testSelectVersionQueryReturnsNonEmptyString(): void
    {
        // Arrange — setUp already verified connection, so db is live

        // Act — replicate the exact query used in Health::display()
        $result = $this->db->execute('SELECT VERSION() AS v');

        // Assert — query must succeed and return a usable version string
        $this->assertNotFalse($result, 'SELECT VERSION() must not return false on PostgreSQL');
        $this->assertNotEmpty($result->fields['v'] ?? '', 'Version field must not be empty');
    }

    /**
     * The PostgreSQL version string must contain the word "PostgreSQL".
     *
     * On PostgreSQL, VERSION() always starts with "PostgreSQL X.Y …".
     * This ensures we are querying a real PostgreSQL server and that the result
     * is not being mistakenly swallowed or replaced with a fallback value.
     */
    public function testSelectVersionContainsPostgresKeyword(): void
    {
        // Act
        $result  = $this->db->execute('SELECT VERSION() AS v');
        $version = $result->fields['v'] ?? '';

        // Assert — PostgreSQL always identifies itself in VERSION()
        $this->assertStringContainsString(
            'PostgreSQL',
            $version,
            "Expected 'PostgreSQL' in version string, got: {$version}"
        );
    }

    // -------------------------------------------------------------------------
    // Health::display() HTML — DB type and version
    // -------------------------------------------------------------------------

    /**
     * display() must include the database type label "Postgresql" in the System
     * Info table when connected to a PostgreSQL server.
     *
     * Health::display() calls ucfirst($db->type) to build the label; the
     * PostgreSQL adapter sets $db->type = 'postgresql', so ucfirst produces
     * "Postgresql". This test exercises that code path end-to-end.
     */
    public function testDisplayHtmlContainsPostgresqlLabel(): void
    {
        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — ucfirst('postgresql') === 'Postgresql'
        $this->assertStringContainsString(
            'Postgresql',
            $html,
            'Database row must contain "Postgresql" label when connected to PostgreSQL'
        );
    }

    /**
     * display() must embed the real PostgreSQL version string in the HTML output.
     *
     * Fetches the version via a direct query first, then asserts that the exact
     * same string appears verbatim in the display() HTML.  Guards against the
     * controller silently showing "—" instead of the real version.
     */
    public function testDisplayHtmlContainsPostgreSqlVersion(): void
    {
        // Arrange — fetch the real version to compare against HTML output
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
     * when connected to a real PostgreSQL database.
     *
     * Regression guard: ensures that the DB-query path does not corrupt the
     * PHP version row that is always rendered regardless of DB status.
     */
    public function testDisplayHtmlContainsPhpVersionWithRealDb(): void
    {
        // Act
        $ctrl = new Health();
        $html = $ctrl->display();

        // Assert — PHP_VERSION must always appear regardless of DB backend
        $this->assertStringContainsString(
            PHP_VERSION,
            $html,
            'PHP version must appear in the System Info table even with a live PostgreSQL DB'
        );
    }
}
