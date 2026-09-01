<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Broadcasting\DatabaseEventStore;

/**
 * The same backplane on PostgreSQL/TimescaleDB.
 *
 * This lane is why the store decodes at all rather than handing the column through: MySQL returns
 * the JSON column as a string and PostgreSQL may return `json` either way depending on the driver,
 * so a consumer that trusted one shape would break here and nowhere else. `NOW()` and
 * `MAX(id)` are the other two — both spelled the same on either backend, which is exactly the kind
 * of claim that is only true until somebody checks.
 */
#[CoversClass(DatabaseEventStore::class)]
class DatabaseEventStorePostgreSQLTest extends DatabaseEventStoreTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
