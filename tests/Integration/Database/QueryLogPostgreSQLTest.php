<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;

/**
 * The same query logs on PostgreSQL/TimescaleDB.
 *
 * Not a formality: the logging sits in `runQuery()` *after* the backend branch, and the PostgreSQL
 * path rewrites the statement before executing it — backticks to double quotes, aliases requoted,
 * `DELETE … LIMIT` restructured. So the text that reaches the log and gets fingerprinted is not the
 * text the caller passed, and two statements that differ only in what the rewrite normalises would
 * be duplicates here and not on the other backend.
 */
#[CoversClass(Database::class)]
class QueryLogPostgreSQLTest extends QueryLogTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
