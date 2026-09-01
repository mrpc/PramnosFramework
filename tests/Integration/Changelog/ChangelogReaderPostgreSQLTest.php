<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Changelog\ChangelogReader;

/**
 * The same audit trail, read on PostgreSQL/TimescaleDB.
 *
 * This lane is the reason the reader decodes at all: MySQL hands the JSON columns back as strings,
 * and PostgreSQL may hand `jsonb` back either way depending on the driver — so a caller that
 * trusted one shape would break on the other backend and only there. The view is also a
 * `UNION ALL` over two tables that are hypertables here and plain tables there.
 */
#[CoversClass(ChangelogReader::class)]
class ChangelogReaderPostgreSQLTest extends ChangelogReaderTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
