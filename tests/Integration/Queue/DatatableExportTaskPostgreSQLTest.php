<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Same REPEATABLE READ snapshot proof as DatatableExportTaskMySQLTest, against a
 * live PostgreSQL 14 / TimescaleDB database — the dialect where the task sets the
 * isolation level *after* BEGIN rather than before START TRANSACTION.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class DatatableExportTaskPostgreSQLTest extends DatatableExportTaskMySQLTest
{
    protected string $dbType = 'postgresql';

    protected function connectMain(): Database
    {
        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found in settings.php');
        }

        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = $pgSettings->hostname;
        $db->user     = $pgSettings->user;
        $db->password = $pgSettings->password;
        $db->database = $pgSettings->database;
        $db->port     = $pgSettings->port ?? 5432;
        $db->schema   = $pgSettings->schema ?? 'public';

        try {
            $db->connect(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('TimescaleDB container not reachable: ' . $e->getMessage());
        }

        return $db;
    }

    protected function connectSecondary(): Database
    {
        return $this->connectMain();
    }

    protected function createSchema(): void
    {
        $this->db->query('DROP TABLE IF EXISTS dt_export_rows');
        $this->db->query('CREATE TABLE dt_export_rows (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        )');
    }

    protected function seedRows(array $names): void
    {
        foreach ($names as $name) {
            $this->db->query("INSERT INTO dt_export_rows (name) VALUES ('" . $name . "')");
        }
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS dt_export_rows');
        $singleton = &Factory::getDatabase();
        $singleton = null;
    }
}
