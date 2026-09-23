<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

/**
 * The same exclusion against real PostgreSQL.
 *
 * The atomicity is the engine's, and the two report a duplicate key differently — a unique
 * violation here, error 1062 there — so a test on one lane proves nothing about the other.
 */
class SharedLockPostgreSQLTest extends SharedLockTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
