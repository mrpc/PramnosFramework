<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Account;

/**
 * The same reset mail on PostgreSQL/TimescaleDB.
 *
 * The message is composed identically; what differs is the audit row it is read back through — a
 * `text` column holding HTML, written and re-read by a different driver. A body that came back with
 * its quoting altered would make the link in it unusable on one backend and only there, which is
 * the failure nobody finds until somebody cannot get back into their account.
 */
#[CoversClass(Account::class)]
class ResetEmailBodyPostgreSQLTest extends ResetEmailBodyTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
