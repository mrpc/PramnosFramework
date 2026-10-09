<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\OAuth2\JwtAssertion;
use Pramnos\Database\Database;

/**
 * {@see JwtAssertionTest} on PostgreSQL, where the replay store lives in the `authserver` schema.
 */
#[CoversClass(JwtAssertion::class)]
class JwtAssertionPostgresTest extends JwtAssertionTest
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
