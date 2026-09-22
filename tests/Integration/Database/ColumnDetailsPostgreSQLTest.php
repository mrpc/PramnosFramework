<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

/**
 * The same column shape against real PostgreSQL.
 *
 * The lane the seeder broke on, and the one where a generated key reports
 * `nextval('…')` rather than anything auto-increment-shaped — which is the difference the
 * normalisation exists to absorb.
 */
class ColumnDetailsPostgreSQLTest extends ColumnDetailsTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
