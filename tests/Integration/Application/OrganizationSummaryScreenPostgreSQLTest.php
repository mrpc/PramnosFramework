<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Controllers\OrganizationsController;

/**
 * The same summary screen on PostgreSQL/TimescaleDB.
 *
 * The membership query joins across a schema boundary — `authserver.user_organizations` to `users` —
 * and the two backends qualify a schema differently, MySQL treating it as a database name. A join
 * that resolved on one and not the other would show an organisation as empty when it is not, which
 * is the failure that looks like missing data rather than a broken query.
 */
#[CoversClass(OrganizationsController::class)]
class OrganizationSummaryScreenPostgreSQLTest extends OrganizationSummaryScreenTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
