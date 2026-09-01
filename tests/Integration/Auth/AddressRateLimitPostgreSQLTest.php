<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Loginlockout;

/**
 * The same per-address limiter, on PostgreSQL/TimescaleDB.
 *
 * Every claim here is about a timestamp written and read back through `strtotime()`, and the
 * column is `TIMESTAMPTZ` here against `DATETIME` on the other lane — the difference that once
 * made `lockoutuntil` land in the past on a non-UTC host, so the lockout never engaged.
 */
#[CoversClass(Loginlockout::class)]
class AddressRateLimitPostgreSQLTest extends AddressRateLimitTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
