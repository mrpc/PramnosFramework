<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;

/**
 * The same preparation on PostgreSQL/TimescaleDB.
 *
 * `prepareQuery()` rewrites the statement per backend before it formats it — backticks to double
 * quotes, single-quoted aliases requoted, `DELETE … LIMIT` restructured — and each of those rewrites
 * runs a `preg_replace` over a string that contains the literal percents. A pattern that survived on
 * one backend and was mangled on the other would be a `LIKE` that silently matches the wrong rows.
 */
#[CoversClass(Database::class)]
class PrepareQueryLiteralPercentPostgreSQLTest extends PrepareQueryLiteralPercentTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
