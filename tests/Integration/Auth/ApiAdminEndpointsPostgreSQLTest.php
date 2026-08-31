<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\ApiAdmin;

/**
 * The same administration-endpoint assertions, on PostgreSQL/TimescaleDB.
 *
 * The user list goes through `_getApiList()` and the dashboard through `count()` — both compiled
 * per driver, and the list is the one that reads a `users` table whose columns this framework has
 * quoted differently on each engine.
 */
#[CoversClass(ApiAdmin::class)]
class ApiAdminEndpointsPostgreSQLTest extends ApiAdminEndpointsTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
