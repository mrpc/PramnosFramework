<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;

/**
 * The same SQL result cache on PostgreSQL/TimescaleDB.
 *
 * The cache key is the statement's hash, and the PostgreSQL path rewrites a statement before it is
 * executed — so what gets hashed there is not what the caller wrote. Two statements that differ
 * only in what the rewrite normalises share an entry on one backend and not the other, which is a
 * cache serving one query's rows for another: a data leak rather than a performance bug, and the
 * only lane it would show up in is this one.
 */
#[CoversClass(Database::class)]
class QueryCacheStorePostgreSQLTest extends QueryCacheStoreTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
