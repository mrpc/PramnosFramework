<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth\OAuth2;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * The same connection store against a live PostgreSQL 14 / TimescaleDB database.
 *
 * Every test is inherited; only the connection changes. That is the point — the store is
 * written in the query builder precisely so the two dialects need no separate code, and a
 * subclass that had to override a test would be evidence they do.
 *
 * Three things here are dialect-sensitive and none of them is visible in a unit test:
 * the upsert compiles to `ON CONFLICT (…) DO UPDATE` rather than `ON DUPLICATE KEY UPDATE`;
 * the composite unique key it conflicts on has to exist with exactly those columns; and the
 * `expires_at IS NOT NULL AND expires_at <= ?` the scheduled job depends on has to select
 * the same rows on both. An upsert that silently inserted a second row on one engine would
 * leave two connections for one account, and the loser would be refreshed for ever against
 * a provider that had already reissued it.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class ConnectionStorePostgreSQLTest extends ConnectionStoreTest
{
    protected function connect(): Database
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
        // Without the schema the builder's information_schema lookups come back empty and
        // every column list is silently blank.
        $pgDb->schema   = $pgSettings->schema ?? 'public';

        try {
            $pgDb->connect(true);
        } catch (\RuntimeException $exception) {
            $this->markTestSkipped(
                'TimescaleDB container not reachable ('
                . $pgSettings->hostname . ':' . ($pgSettings->port ?? 5432) . '): '
                . $exception->getMessage()
            );
        }

        // The store resolves its own connection through the singleton when none is
        // injected, and other tests in the run will have pointed it at MySQL.
        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        return $pgDb;
    }
}
