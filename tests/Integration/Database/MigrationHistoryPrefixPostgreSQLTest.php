<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\MigrationRunner;

/**
 * {@see MigrationHistoryPrefixTest} against PostgreSQL.
 *
 * Not a formality. The adoption path renames with double-quoted identifiers and the
 * literal-existence check asks `to_regclass()` rather than `information_schema` — a
 * different statement on each side of the branch, and the reporting installation runs
 * MySQL, so this lane is the one nothing had exercised.
 */
#[CoversClass(MigrationRunner::class)]
class MigrationHistoryPrefixPostgreSQLTest extends MigrationHistoryPrefixTest
{
    protected function settingsFixture(): string
    {
        return ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'pg_settings.php';
    }
}
