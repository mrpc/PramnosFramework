<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\ApplicationSettings;
use Pramnos\Database\Database;

/**
 * {@see ApplicationAccessAndLimitsTest} on PostgreSQL, where the policy tables live in the `applications` schema and lists are arrays.
 */
#[CoversClass(ApplicationSettings::class)]
class ApplicationAccessAndLimitsPostgresTest extends ApplicationAccessAndLimitsTest
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
