<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\NewSignInAlert;

/**
 * The same sign-in decision, on PostgreSQL/TimescaleDB.
 *
 * The history read is an `ORDER BY created_at DESC LIMIT` over `authserver.user_activity_log`,
 * which is a hypertable here and a plain table there, and the preference is an upsert on a
 * composite key — `ON CONFLICT` against a read-then-branch.
 */
#[CoversClass(NewSignInAlert::class)]
class NewSignInAlertNotifyPostgreSQLTest extends NewSignInAlertNotifyTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
