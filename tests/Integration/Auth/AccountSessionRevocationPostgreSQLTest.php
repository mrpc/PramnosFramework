<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Account;

/**
 * The same revocation assertions, on PostgreSQL/TimescaleDB.
 *
 * The revocation is a scoped `UPDATE` compiled per driver, and "somebody else's session cannot be
 * revoked" is a claim about the WHERE clause each engine actually received.
 */
#[CoversClass(Account::class)]
class AccountSessionRevocationPostgreSQLTest extends AccountSessionRevocationTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
