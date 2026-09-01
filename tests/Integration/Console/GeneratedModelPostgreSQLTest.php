<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * The same model generator against a PostgreSQL/TimescaleDB schema.
 *
 * The generator's entire input on the live-table path is the driver's column report, and the primary
 * key comes out of it — reported as a flag here and as a `Key` value on MySQL. A model generated
 * without its key inserts a new row on every save instead of updating, on half the installations.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedModelPostgreSQLTest extends GeneratedModelTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
