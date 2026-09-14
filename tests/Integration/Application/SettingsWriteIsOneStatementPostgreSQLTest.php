<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use Pramnos\Database\Database;

/**
 * The same contract on PostgreSQL.
 *
 * The lane that matters most for this change: the upsert compiles to
 * `ON CONFLICT (setting) DO UPDATE` here and to `ON DUPLICATE KEY UPDATE` on MySQL, and the
 * two fail differently when the unique index is absent — PostgreSQL raises, MySQL inserts a
 * duplicate in silence. Only running both says the guard is right on each.
 */
class SettingsWriteIsOneStatementPostgreSQLTest extends SettingsWriteIsOneStatementTest
{
    protected function connect(): Database
    {
        $db = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->schema   = 'public';

        try {
            $db->connect(true);
        } catch (\Exception) {
            // Reported by the skip in setUp().
        }

        return $db;
    }
}
