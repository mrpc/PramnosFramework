<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Account;

/**
 * The same registration screen, on PostgreSQL/TimescaleDB.
 *
 * The two duplicate checks are lookups whose "no row" answer decides whether an account is
 * created, and the insert is `User::save()` on a table with a sequence rather than an
 * auto-increment.
 */
#[CoversClass(Account::class)]
class AccountRegisterScreenPostgreSQLTest extends AccountRegisterScreenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
