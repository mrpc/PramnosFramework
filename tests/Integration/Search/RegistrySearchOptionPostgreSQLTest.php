<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Search;

/** The same, on PostgreSQL — where `LIKE` becomes `ILIKE` and identifiers are double-quoted. */
#[\PHPUnit\Framework\Attributes\Group('serial')]
class RegistrySearchOptionPostgreSQLTest extends RegistrySearchOptionTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
