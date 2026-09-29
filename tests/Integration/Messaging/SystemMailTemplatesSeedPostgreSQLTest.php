<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

/**
 * The same seeding, on PostgreSQL/TimescaleDB.
 *
 * The seeder looks for an existing row by category and type before it writes, and a
 * comparison that behaved differently between the engines would seed twice on one of them.
 */
class SystemMailTemplatesSeedPostgreSQLTest extends SystemMailTemplatesSeedTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
