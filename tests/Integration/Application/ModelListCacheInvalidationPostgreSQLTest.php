<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Model;

/**
 * The same invalidation on PostgreSQL/TimescaleDB.
 *
 * The bug was in `Model::save()` and is engine-independent, so this lane is about the parts
 * around it that are not: the `SERIAL` primary key comes back from a `RETURNING` clause rather
 * than from `getInsertId()`, and it is the primary key that decided which of the two flushes
 * ran. A model whose key is null after an insert takes the other branch, so "the update path
 * invalidated nothing" could be true on one backend and not the other.
 */
#[CoversClass(Model::class)]
class ModelListCacheInvalidationPostgreSQLTest extends ModelListCacheInvalidationTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
