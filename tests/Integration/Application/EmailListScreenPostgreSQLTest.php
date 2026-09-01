<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Controllers\EmailsController;

/**
 * The same email list, on PostgreSQL/TimescaleDB.
 *
 * The reason this lane exists is one line: `=` is case-sensitive here and not on a default MySQL
 * collation, so an address filter written without `LOWER()` would work in development and quietly
 * match nothing in production.
 */
#[CoversClass(EmailsController::class)]
class EmailListScreenPostgreSQLTest extends EmailListScreenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
