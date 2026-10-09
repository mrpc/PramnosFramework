<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\OAuth2\Grants\DeviceCodeGrant;
use Pramnos\Auth\OAuth2\Grants\JwtBearerGrant;
use Pramnos\Auth\OAuth2\Grants\TokenExchangeGrant;
use Pramnos\Database\Database;

/**
 * {@see OAuthGrantsTest} on PostgreSQL, where the grant tables live in the `authserver` and `applications` schemas.
 */
#[CoversClass(DeviceCodeGrant::class)]
#[CoversClass(JwtBearerGrant::class)]
#[CoversClass(TokenExchangeGrant::class)]
class OAuthGrantsPostgresTest extends OAuthGrantsTest
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
