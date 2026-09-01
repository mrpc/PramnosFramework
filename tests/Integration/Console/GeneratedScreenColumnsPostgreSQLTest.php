<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * The same column rules, on PostgreSQL/TimescaleDB.
 *
 * `getColumns()` reads `information_schema` here and `SHOW COLUMNS` there, so "which columns does
 * this table have" is two different queries that have to return the same answer — and the secrets
 * filter is only as good as the list it is given.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedScreenColumnsPostgreSQLTest extends GeneratedScreenColumnsTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
