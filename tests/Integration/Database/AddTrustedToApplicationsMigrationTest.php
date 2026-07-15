<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\AddTrustedToApplications;

/**
 * Integration test for the 2026_07_15_000001_add_trusted_to_applications migration.
 *
 * WHAT: verifies the `trusted` column is actually created on / dropped from the
 *       real `applications` table (not just that the SQL string is well-formed).
 * WHY: the migration is the DB half of the trusted / silent-consent feature and
 *      must be strictly additive and idempotent — safe to run on installations
 *      whose `applications` table already exists (framework rule §8).
 *
 * Runs against whichever engine DB_TYPE selects (MySQL / PostgreSQL /
 * TimescaleDB); the CI matrix exercises all three.
 */
class AddTrustedToApplicationsMigrationTest extends TestCase
{
    /** @var Database Live test-database connection. */
    private Database $db;

    /** @var Application Application carrying the live connection for the migration. */
    private Application $app;

    /** @var bool True for any PostgreSQL variant. */
    private bool $isPg;

    /** @var bool True when this test created the applications stub (so tearDown fully drops it). */
    private bool $createdApplicationsTable = false;

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
        $this->db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

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
        if ($schema->hasColumn('applications', 'trusted')) {
            (new AddTrustedToApplications($this->app))->down();
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
            } elseif ($schema->hasColumn('applications', 'trusted')) {
                (new AddTrustedToApplications($this->app))->down();
            }
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    /**
     * up() adds the column; down() removes it. This is the core DDL round-trip
     * that proves the migration takes effect in the real database.
     */
    public function testUpAddsTrustedColumnAndDownRemovesIt(): void
    {
        // Arrange
        $migration = new AddTrustedToApplications($this->app);
        $schema    = $this->db->schema();

        // Assert pre-condition — column absent before up().
        $this->assertFalse(
            $schema->hasColumn('applications', 'trusted'),
            'Pre-condition: trusted column must not exist yet'
        );

        // Act — apply the migration.
        $migration->up();

        // Assert — column now exists in the real table.
        $this->assertTrue(
            $schema->hasColumn('applications', 'trusted'),
            'up() must create the trusted column'
        );

        // Act — roll back.
        $migration->down();

        // Assert — column gone again.
        $this->assertFalse(
            $schema->hasColumn('applications', 'trusted'),
            'down() must drop the trusted column'
        );
    }

    /**
     * up() is idempotent: running it when the column already exists is a no-op
     * (guarded by hasColumn), so it is safe on installations that already have
     * the column — the key non-breaking guarantee.
     */
    public function testUpIsIdempotent(): void
    {
        // Arrange
        $migration = new AddTrustedToApplications($this->app);
        $schema    = $this->db->schema();

        // Act — apply twice.
        $migration->up();
        $migration->up(); // second call must not error

        // Assert — still exactly one column, present.
        $this->assertTrue(
            $schema->hasColumn('applications', 'trusted'),
            'Column must exist after a second, no-op up()'
        );
    }

    /**
     * down() is idempotent: safe to call when the column is already absent.
     */
    public function testDownIsIdempotentWhenColumnAbsent(): void
    {
        // Arrange — ensure column is absent.
        $migration = new AddTrustedToApplications($this->app);
        $schema    = $this->db->schema();
        $this->assertFalse($schema->hasColumn('applications', 'trusted'));

        // Act — down() on an absent column must be a silent no-op.
        $migration->down();

        // Assert
        $this->assertFalse(
            $schema->hasColumn('applications', 'trusted'),
            'down() on an absent column must remain a no-op'
        );
    }
}
