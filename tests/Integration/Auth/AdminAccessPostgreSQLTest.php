<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

/**
 * {@see AdminAccessTest} on PostgreSQL, where `authserver.permissions` is a table in a schema
 * rather than a prefixed name — the same grants have to resolve through a different dialect.
 */
#[\PHPUnit\Framework\Attributes\Group('serial')]
class AdminAccessPostgreSQLTest extends AdminAccessTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
