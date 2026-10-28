<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

/**
 * The same, on PostgreSQL — where the query builder's statements are server-side prepared, so
 * `execute()` is the path that has to stop before the server.
 */
#[\PHPUnit\Framework\Attributes\Group('serial')]
class MigratePretendPostgreSQLTest extends MigratePretendTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
