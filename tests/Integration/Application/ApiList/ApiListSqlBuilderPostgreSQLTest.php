<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application\ApiList;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\ApiList\ApiListSqlBuilder;

/**
 * Every list-SQL assertion again, on PostgreSQL/TimescaleDB.
 *
 * The one class in the list engine that branches on the driver in nine places — identifier
 * quoting, and `LIKE` becoming `ILIKE`. Running it on one engine tests half of it.
 */
#[CoversClass(ApiListSqlBuilder::class)]
class ApiListSqlBuilderPostgreSQLTest extends ApiListSqlBuilderTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
