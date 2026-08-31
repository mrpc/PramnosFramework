<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\TimescaleEnsure;

/**
 * The other half of `timescale:ensure` — the half with the extension.
 *
 * Not a duplicate run of the same assertions: the two lanes take **different branches**, and
 * each skips the tests that belong to the other. This is the lane where the hypertable plan is
 * built at all.
 */
#[CoversClass(TimescaleEnsure::class)]
class TimescaleEnsureCommandPostgreSQLTest extends TimescaleEnsureCommandTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
