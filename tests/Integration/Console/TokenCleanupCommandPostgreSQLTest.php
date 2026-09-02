<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\AuthTokenCleanup;

/**
 * The same retirement assertions, on PostgreSQL/TimescaleDB.
 *
 * `status` is a `SMALLINT` there against MySQL's `TINYINT`, and the retirement is an `UPDATE` whose
 * predicate compares three integer columns — so this is the same claim about a different set of
 * types, on the backend where the query builder's placeholder handling differs.
 */
#[CoversClass(AuthTokenCleanup::class)]
class TokenCleanupCommandPostgreSQLTest extends TokenCleanupCommandTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
