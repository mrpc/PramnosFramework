<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Account;

/**
 * The same two screens, on PostgreSQL/TimescaleDB.
 *
 * Every step is a write and a read of `userdetails` — a table with a composite key and an upsert
 * behind it — and the token's expiry is compared against the clock.
 */
#[CoversClass(Account::class)]
class AccountPasswordResetScreenPostgreSQLTest extends AccountPasswordResetScreenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
