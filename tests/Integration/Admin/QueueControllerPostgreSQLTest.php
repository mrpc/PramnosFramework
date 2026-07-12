<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Admin;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Integration tests for QueueController against a live PostgreSQL 14 database.
 *
 * Extends QueueControllerMySQLTest — the same behavioural contracts must hold
 * on PostgreSQL because deployed stacks may use either backend.
 *
 * PostgreSQL notes:
 * - queueitems.status is VARCHAR(20) with a CHECK constraint
 * - `getInsertId()` uses `SELECT LASTVAL()` rather than `mysqli_insert_id()`
 *
 * Requires the Docker PostgreSQL / TimescaleDB container.
 */
class QueueControllerPostgreSQLTest extends QueueControllerMySQLTest
{
    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://localhost/');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found');
        }

        $pgDb           = new Database();
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
            $this->markTestSkipped('PostgreSQL container not reachable: ' . $e->getMessage());
        }

        // Swap the Factory singleton to PostgreSQL so QueryBuilder uses it.
        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        $this->db   = $pgDb;
        $this->app  = $this->makeApp();

        $this->dropQueueTable();
        $this->runQueueMigration();

        $this->ctrl = new TestableQueueController($this->app);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore singleton to MySQL so subsequent tests use the correct backend.
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );
    }

    protected function dropQueueTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "queueitems" CASCADE');
        $this->db->query('DROP TYPE IF EXISTS queue_status');
    }
}
