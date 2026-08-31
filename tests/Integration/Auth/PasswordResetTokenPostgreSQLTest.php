<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Account;

/**
 * The same reset-token assertions, on PostgreSQL/TimescaleDB.
 *
 * `storeResetToken()` uses `upsert()`, which compiles to `ON DUPLICATE KEY UPDATE` on MySQL and
 * `ON CONFLICT … DO UPDATE` on PostgreSQL. "One live token per account" is therefore a claim
 * about two different statements, and only one of them was ever executed.
 */
#[CoversClass(Account::class)]
class PasswordResetTokenPostgreSQLTest extends PasswordResetTokenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
