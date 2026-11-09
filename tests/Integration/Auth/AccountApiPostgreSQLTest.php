<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\AccountApi;

/** The account under the API, on PostgreSQL. */
#[CoversClass(AccountApi::class)]
class AccountApiPostgreSQLTest extends AccountApiTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
