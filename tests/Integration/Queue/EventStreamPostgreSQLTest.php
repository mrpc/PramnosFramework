<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * The event stream against PostgreSQL / TimescaleDB: every test of the MySQL class, on the other
 * engine — the transaction, the lease's conditional update and the insert-or-ignore above all.
 *
 * Requires the Docker TimescaleDB container.
 */
class EventStreamPostgreSQLTest extends EventStreamMySQLTest
{
    /** A PostgreSQL connection, made the process's database for the length of the test. */
    protected function connect(): Database
    {
        $pg = Settings::getSetting('postgresql');
        if (!$pg) {
            $this->markTestSkipped('PostgreSQL settings not found in settings.php');
        }
        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = $pg->hostname;
        $db->user     = $pg->user;
        $db->password = $pg->password;
        $db->database = $pg->database;
        $db->port     = $pg->port ?? 5432;
        $db->schema   = $pg->schema ?? 'public';
        try {
            $db->connect(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('TimescaleDB container not reachable: ' . $e->getMessage());
        }
        $singleton = &Factory::getDatabase();
        $singleton = $db;

        return $db;
    }

    /** Give the process its MySQL connection back. */
    protected function tearDown(): void
    {
        parent::tearDown();
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');
    }

    /** Drop the tables, and the status type the queue migration makes. */
    protected function dropTables(): void
    {
        parent::dropTables();
        $this->db->query('DROP TYPE IF EXISTS queue_status');
    }
}
