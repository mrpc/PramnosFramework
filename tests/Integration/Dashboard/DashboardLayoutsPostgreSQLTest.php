<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Dashboard;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/** The same dashboards on PostgreSQL. */
class DashboardLayoutsPostgreSQLTest extends DashboardLayoutsMySQLTest
{
    /** The connection the suite had, put back. */
    private ?Database $previous = null;

    /** The PostgreSQL connection, made the one the controller's store uses. */
    protected function connect(): Database
    {
        $pg = Settings::getSetting('postgresql');
        if (!$pg) {
            $this->markTestSkipped('PostgreSQL settings not found.');
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
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL is not reachable.');
        }
        $this->previous = Database::getInstance();
        $reference = &Database::getInstance();
        $reference = $db;

        return $db;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $reference = &Database::getInstance();
        $reference = $this->previous;
    }
}
