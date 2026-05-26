<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Notification;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Integration tests for DatabaseChannel against a live PostgreSQL 14 / TimescaleDB database.
 *
 * Extends the MySQL test class and overrides only the DB connection and table
 * teardown so all operation tests run identically on PostgreSQL.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class DatabaseChannelPostgreSQLTest extends DatabaseChannelMySQLTest
{
    // -------------------------------------------------------------------------
    // Connection override
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
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
            $this->markTestSkipped(
                'TimescaleDB container not reachable (' . $pgSettings->hostname . ':' . ($pgSettings->port ?? 5432) . '): ' . $e->getMessage()
            );
        }

        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        $this->db  = $pgDb;
        $this->app = $this->makeApp();

        $this->dropNotificationsTable();
        $this->runNotificationsMigration();
    }

    protected function tearDown(): void
    {
        $this->dropNotificationsTable();

        // Restore the MySQL singleton so subsequent tests don't inherit PostgreSQL
        $singleton = &Factory::getDatabase();
        $singleton = null;
    }

    // -------------------------------------------------------------------------
    // PostgreSQL DROP override — no FOREIGN_KEY_CHECKS
    // -------------------------------------------------------------------------

    protected function dropNotificationsTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS notifications');
    }
}
