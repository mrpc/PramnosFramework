<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

/**
 * The same, on PostgreSQL — where a model names its table `public.<table>`, which is the form
 * whose cache key the migration's flush did not reach.
 */
class ColumnCacheAfterMigratePostgreSQLTest extends ColumnCacheAfterMigrateTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
