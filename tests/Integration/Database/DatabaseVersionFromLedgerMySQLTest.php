<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\Database;

/**
 * The same ledger questions, asked of MySQL.
 *
 * The history table is written by two dialect-specific statements — `ON CONFLICT DO UPDATE`
 * on PostgreSQL, `ON DUPLICATE KEY UPDATE` on MySQL — and is brought up to date by two
 * separate add-missing-column lists, because MySQL has no `ADD COLUMN IF NOT EXISTS`. A
 * column added to one pair and not the other costs nothing until an installation on the
 * other engine reports a version that never moves.
 *
 * Every case is inherited; only the connection changes.
 */
#[\PHPUnit\Framework\Attributes\Group('serial')]
class DatabaseVersionFromLedgerMySQLTest extends DatabaseVersionFromLedgerTest
{
    protected function connection(): Database
    {
        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        $db->port     = 3306;

        if (!$db->connect(false)) {
            $this->markTestSkipped('MySQL container not reachable');
        }

        return $db;
    }
}
