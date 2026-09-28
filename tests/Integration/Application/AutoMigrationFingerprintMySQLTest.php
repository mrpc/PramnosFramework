<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use Pramnos\Database\Database;

/**
 * The same request sequences, on MySQL.
 *
 * The fingerprint row is written by two dialect-specific statements — `INSERT … ON CONFLICT
 * DO NOTHING` and `INSERT IGNORE` — so the engine is part of what is being tested, not a
 * detail of the fixture. Every case is inherited; only the connection changes.
 */
class AutoMigrationFingerprintMySQLTest extends AutoMigrationFingerprintTest
{
    protected string $historyTable = 'am_fp_schemaversion_mysql';

    protected function connection(): Database
    {
        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 3306;

        if (!$db->connect(false)) {
            $this->markTestSkipped('MySQL container not reachable');
        }

        return $db;
    }
}
