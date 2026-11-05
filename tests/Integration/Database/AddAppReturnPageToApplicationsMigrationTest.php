<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\AddAppReturnPageToApplications;

/**
 * Integration test for the 2026_11_05_000001_add_app_return_page_to_applications migration.
 *
 * WHAT: the `app_return_page` column is created on / dropped from the real `applications`
 *       table, and an application inserted without it reads 0.
 * WHY: 0 is the 302 every existing client already gets; a column that defaulted to anything
 *      else, or NULL on one engine, would change how every native app is answered.
 *
 * Runs against whichever engine DB_TYPE selects (MySQL / PostgreSQL /
 * TimescaleDB); the CI matrix exercises all three.
 */
class AddAppReturnPageToApplicationsMigrationTest extends TestCase
{
    /** @var Database Live test-database connection. */
    private Database $db;

    /** @var Application Application carrying the live connection for the migration. */
    private Application $app;

    /** @var bool True for any PostgreSQL variant. */
    private bool $isPg;

    /** @var bool True when this test created the applications stub (so tearDown fully drops it). */
    private bool $createdApplicationsTable = false;

    /** @var bool True when the column was there before the test, so tearDown puts it back. */
    private bool $hadColumn = false;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $driver = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $this->isPg = in_array($driver, ['postgresql', 'pgsql', 'timescaledb'], true);

        $this->db = new Database();
        $this->db->type     = $driver;
        $this->db->server   = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $this->db->port     = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: ($this->isPg ? 5432 : 3306)));
        $this->db->user     = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        $this->db->password  = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $this->db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: TEST_DATABASE);

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('Database not reachable');
            }
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        $this->app = new Application();
        $this->app->database = $this->db;

        $schema = $this->db->schema();

        // Guarantee an `applications` table exists so the migration has a target.
        // CRITICAL: only create a stub when none exists, and remember that we did,
        // so tearDown can fully drop it. Leaving a degenerate stub behind would make
        // the create_applications_table migration's hasTable() guard skip in a later
        // test, hiding the real columns (order-dependent pollution of the shared DB).
        if (!$schema->hasTable('applications')) {
            $this->createdApplicationsTable = true;
            if ($this->isPg) {
                $this->db->statement(
                    'CREATE TABLE applications (appid SERIAL PRIMARY KEY, name VARCHAR(255))'
                );
            } else {
                $this->db->statement(
                    'CREATE TABLE applications (appid INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))'
                );
            }
        }

        // Clean slate — a prior auto-run may already have added the column.
        $this->hadColumn = $schema->hasColumn('applications', 'app_return_page');
        if ($this->hadColumn) {
            (new AddAppReturnPageToApplications($this->app))->down();
        }
    }

    protected function tearDown(): void
    {
        // Restore the applications table to exactly the state we found it in:
        // drop the whole stub if we created it, otherwise just remove our column.
        try {
            $schema = $this->db->schema();
            if ($this->createdApplicationsTable) {
                $schema->dropTableIfExists('applications');
            } elseif ($this->hadColumn) {
                (new AddAppReturnPageToApplications($this->app))->up();
            } else {
                (new AddAppReturnPageToApplications($this->app))->down();
            }
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    /**
     * up() adds the column, and a row inserted without it reads 0 — the 302 default.
     */
    public function testUpAddsTheColumnDefaultingToTheRedirect(): void
    {
        // Arrange
        $migration = new AddAppReturnPageToApplications($this->app);
        $schema    = $this->db->schema();
        $this->assertFalse($schema->hasColumn('applications', 'app_return_page'), 'pre-condition');

        // Act
        $migration->up();
        $this->db->queryBuilder()->table('applications')->insert(['name' => 'return-page-probe']);
        $row = $this->db->queryBuilder()->table('applications')->where('name', 'return-page-probe')->first();
        $this->db->queryBuilder()->table('applications')->where('name', 'return-page-probe')->delete();

        // Assert — the column exists, and an application that never chose reads 0 (a 302)
        $this->assertTrue($schema->hasColumn('applications', 'app_return_page'));
        $this->assertSame(0, (int) $row->fields['app_return_page']);
        $this->assertNotNull($row->fields['app_return_page'], 'NULL would not be the default on every engine');
    }

    /**
     * up() twice is a no-op, and down() removes the column (twice is a no-op too).
     */
    public function testUpAndDownAreIdempotent(): void
    {
        // Arrange
        $migration = new AddAppReturnPageToApplications($this->app);
        $schema    = $this->db->schema();

        // Act & Assert
        $migration->up();
        $migration->up();
        $this->assertTrue($schema->hasColumn('applications', 'app_return_page'));
        $migration->down();
        $migration->down();
        $this->assertFalse($schema->hasColumn('applications', 'app_return_page'));
    }
}
