<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * The same generator against a PostgreSQL/TimescaleDB schema.
 *
 * This lane exists for one branch: the primary key is detected from a `PrimaryKey` flag here and
 * from a `Key` column reading `PRI` on MySQL — two implementations of the single derivation that
 * matters most, since a controller generated without its key has no working single-record route at
 * all. The column types come back differently named too (`double precision`, `boolean`), and the
 * type switch reads exactly that report.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedApiControllerPostgreSQLTest extends GeneratedApiControllerTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
