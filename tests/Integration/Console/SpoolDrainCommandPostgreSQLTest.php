<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\SpoolDrain;

/**
 * The same drain assertions, on PostgreSQL/TimescaleDB.
 *
 * The failing case is a write to a table that does not exist, and what a driver does with that is
 * exactly what this framework has found differing between the two: one throws, the other answers
 * false. The exit code depends on it.
 */
#[CoversClass(SpoolDrain::class)]
class SpoolDrainCommandPostgreSQLTest extends SpoolDrainCommandTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
