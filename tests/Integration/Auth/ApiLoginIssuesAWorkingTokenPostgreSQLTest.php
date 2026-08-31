<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\ApiAccount;

/**
 * The same end-to-end login, on PostgreSQL/TimescaleDB.
 *
 * The token write is an insert with a nullable expiry, and reading it back compares that column
 * against the clock — a comparison whose column type differs between the engines, on the query
 * that decides whether an API request is authenticated.
 */
#[CoversClass(ApiAccount::class)]
class ApiLoginIssuesAWorkingTokenPostgreSQLTest extends ApiLoginIssuesAWorkingTokenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
