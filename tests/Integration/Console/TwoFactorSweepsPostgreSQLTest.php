<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\AuthTwoFactorCleanup;

/**
 * The same sweeps, on PostgreSQL/TimescaleDB.
 *
 * `authserver.` is a real schema there and a table-name prefix on MySQL, so "the sweep found its
 * table" is a claim about two different resolutions — and a sweep that silently matched no table
 * would look exactly like one that had nothing to delete.
 */
#[CoversClass(AuthTwoFactorCleanup::class)]
class TwoFactorSweepsPostgreSQLTest extends TwoFactorSweepsTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
