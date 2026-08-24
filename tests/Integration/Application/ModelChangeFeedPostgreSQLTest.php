<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * The change-feed integration tests, against PostgreSQL / TimescaleDB.
 *
 * Every test is inherited unchanged; only the connection and the DDL dialect differ.
 *
 * Running the same assertions against both engines is not ceremony. `_save()` discovers
 * the table's columns differently per backend — `SHOW COLUMNS` on MySQL,
 * `information_schema` on PostgreSQL — and reads the generated key differently too: a
 * `RETURNING` clause against `getInsertId()`. The emitted key comes from whichever of
 * those ran, so a feed that is correct on one engine is not thereby correct on the other.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class ModelChangeFeedPostgreSQLTest extends ModelChangeFeedMySQLTest
{
    protected static string $table = 'pramnos_changefeed_probe_pg';

    protected static function openConnection(): ?Database
    {
        $settings = Settings::getSetting('postgresql');
        if (!$settings) {
            return null;
        }

        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 5432;
        $db->schema   = $settings->schema ?? 'public';

        try {
            $db->connect(true);
        } catch (\Throwable) {
            return null;
        }

        return $db->connected ? $db : null;
    }

    protected static function createTableOn(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS ' . static::$table);
        $db->query(
            'CREATE TABLE ' . static::$table . ' ('
            . 'id SERIAL PRIMARY KEY, '
            . 'status VARCHAR(50) NOT NULL, '
            . 'label VARCHAR(100) NOT NULL, '
            . 'viewcache TEXT NOT NULL'
            . ')'
        );

        Model::$columnCache = [];
    }
}
