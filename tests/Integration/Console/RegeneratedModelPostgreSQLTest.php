<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

/**
 * The same regeneration against real PostgreSQL.
 *
 * Not symmetry for its own sake: the update path reads the live schema through `getColumns()` before it
 * decides anything, and «what does this table look like» is answered by two different drivers with two
 * different primary-key flags. A regression that made the update branch depend on that answer would
 * pass on one engine and destroy a model on the other.
 */
class RegeneratedModelPostgreSQLTest extends RegeneratedModelTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
