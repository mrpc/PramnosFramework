<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Account;

/**
 * The same second-factor screen, on PostgreSQL/TimescaleDB.
 *
 * Enabling and disabling go through an upsert on `authserver.user_twofactor` — a read-then-branch
 * on one engine and `ON CONFLICT` on the other — and the code store is deleted and rewritten on
 * every send.
 */
#[CoversClass(Account::class)]
class AccountEmailFactorPostgreSQLTest extends AccountEmailFactorTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
