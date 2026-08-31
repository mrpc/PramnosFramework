<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\AuthUnlock;

/**
 * The same unlock assertions, on PostgreSQL/TimescaleDB.
 *
 * `authserver.loginlockouts` is created by **hand-written DDL per engine** in its migration, and
 * `lockoutuntil` is `TIMESTAMPTZ` there against `DATETIME` on MySQL — so "how much longer is this
 * locked" and "is this one expired" are claims about two different comparisons.
 */
#[CoversClass(AuthUnlock::class)]
class AuthUnlockCommandPostgreSQLTest extends AuthUnlockCommandTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
