<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Framework\Migrations\Core\CreateMediaTables;

/**
 * The same migration on PostgreSQL/TimescaleDB.
 *
 * This lane is where the migration is most likely to be wrong, and in three separate ways: `order`
 * is a reserved word that has to be quoted, `tinyint` does not exist here at all so `otherusers` and
 * `othermodules` must map to something, and the cascading foreign key is declared across
 * `#PREFIX#`-prefixed names that the two backends qualify differently. A migration that only ever
 * ran on MySQL would look finished.
 */
#[CoversClass(CreateMediaTables::class)]
class MediaTablesMigrationPostgreSQLTest extends MediaTablesMigrationTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
