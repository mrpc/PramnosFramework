<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\OAuth2\EndSession;
use Pramnos\Database\Database;

/**
 * {@see EndSessionTest} on PostgreSQL, where the client and token tables are the public schema's.
 */
#[CoversClass(EndSession::class)]
class EndSessionPostgresTest extends EndSessionTest
{
    /** The test database on the PostgreSQL server. */
    protected function connection(): Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('PostgreSQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }

        return $db;
    }
}
