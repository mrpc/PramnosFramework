<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\User\User;

/**
 * The same revocation on PostgreSQL/TimescaleDB.
 *
 * The reason this lane exists rather than being a formality: the count that was wrong here came out
 * of `update()`'s return value, and how many rows an update reports affecting is the driver's
 * answer, not the query builder's. `whereIn` over a token list and an update scoped by three
 * columns are the other two — a session left live on one backend and not the other is the failure
 * nobody finds until it is somebody's account.
 */
#[CoversClass(User::class)]
class SessionRevocationPostgreSQLTest extends SessionRevocationTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
