<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\NavRegistry;

/**
 * The same menu-visibility rules, on PostgreSQL/TimescaleDB.
 *
 * The lookup behind `isAllowed()` is a multi-column `WHERE` against the permissions table, and
 * "no row" is what the silence rule turns on — the answer that decides whether an installation
 * with no grants has a menu at all.
 */
#[CoversClass(NavRegistry::class)]
class NavPermissionStorePostgreSQLTest extends NavPermissionStoreTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
