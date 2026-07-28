<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Queue\CreateDelayedJobsTable;
use Pramnos\Queue\Drivers\DatabaseQueueDriver;

/**
 * Integration tests for the database delayed-queue driver against a live
 * PostgreSQL 14 / TimescaleDB database.
 *
 * Extends the MySQL test and overrides only the DB connection so every
 * operation test runs identically on PostgreSQL, proving the driver's SQL
 * (integer run_at comparisons, JSON payload round-trip, atomic claim-by-DELETE)
 * is dialect-transparent. Requires the Docker TimescaleDB container.
 */
class DelayedQueueDatabasePostgreSQLTest extends DelayedQueueDatabaseMySQLTest
{
    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found in settings.php');
        }

        $pgDb = new Database();
        $pgDb->type     = 'postgresql';
        $pgDb->server   = $pgSettings->hostname;
        $pgDb->user     = $pgSettings->user;
        $pgDb->password = $pgSettings->password;
        $pgDb->database = $pgSettings->database;
        $pgDb->port     = $pgSettings->port ?? 5432;
        $pgDb->schema   = $pgSettings->schema ?? 'public';

        try {
            $pgDb->connect(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('TimescaleDB container not reachable: ' . $e->getMessage());
        }

        // Point the singleton at PG so any getInstance()-based paths use it too.
        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        $this->db  = $pgDb;
        $this->app = $this->makeApp();

        $this->dropTable();
        $this->migrate();

        $this->driver = new DatabaseQueueDriver($this->db);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore the Database singleton to MySQL so subsequent tests in the full
        // suite don't inherit this PostgreSQL connection.
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );
    }
}
