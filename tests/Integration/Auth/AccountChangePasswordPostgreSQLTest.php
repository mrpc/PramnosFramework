<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Account;

/**
 * The same password change, on PostgreSQL/TimescaleDB.
 *
 * The history is an insert and a trimmed read, and the session revocation is an update scoped by
 * two columns — the query that decides whether somebody else keeps the account.
 */
#[CoversClass(Account::class)]
class AccountChangePasswordPostgreSQLTest extends AccountChangePasswordTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
