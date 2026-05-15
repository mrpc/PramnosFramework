<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Integration tests for QueueManager against a live PostgreSQL 14 / TimescaleDB database.
 *
 * Extends the MySQL test class and overrides only the DB connection and table
 * teardown so all operation tests run identically on PostgreSQL.
 *
 * PostgreSQL differences verified here:
 * - queueitems.status is VARCHAR(20) with a CHECK constraint
 * - The queue_status ENUM type is created by the migration and dropped on down()
 * - All QueueManager string-based queries must work identically
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class QueueManagerPostgreSQLTest extends QueueManagerMySQLTest
{
    // -------------------------------------------------------------------------
    // Connection override
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        // Ensure settings are loaded so getSetting('postgresql') returns the config.
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found in settings.php');
        }

        // Connect to TimescaleDB using the settings from the fixtures file.
        $pgDb = new Database();
        $pgDb->type     = 'postgresql';
        $pgDb->server   = $pgSettings->hostname;
        $pgDb->user     = $pgSettings->user;
        $pgDb->password = $pgSettings->password;
        $pgDb->database = $pgSettings->database;
        $pgDb->port     = $pgSettings->port ?? 5432;
        // schema must be set so Model::_save() builds the correct information_schema
        // WHERE table_schema = 'public' query — without it the column list is empty.
        $pgDb->schema   = $pgSettings->schema ?? 'public';

        try {
            $pgDb->connect(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('TimescaleDB container not reachable (' . $pgSettings->hostname . ':' . ($pgSettings->port ?? 5432) . '): ' . $e->getMessage());
        }

        // Replace the Database::getInstance() singleton with the PostgreSQL connection
        // so that Model CRUD methods (which call getInstance() internally) use the PG DB.
        // The MySQL test's Factory::getDatabase() set the singleton to MySQL; we override it.
        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        $this->db         = $pgDb;
        $this->app        = $this->makeApp();
        $this->controller = $this->makeController();

        $this->dropQueueTable();
        $this->runQueueMigration();

        $this->manager = new \Pramnos\Queue\QueueManager($this->controller);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore the Database singleton to MySQL so subsequent tests in the full
        // suite don't inherit this PostgreSQL connection.
        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $singleton = null;
        \Pramnos\Application\Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );
    }

    protected function dropQueueTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "queueitems" CASCADE');
        $this->db->query('DROP TYPE  IF EXISTS queue_status');
    }
}
