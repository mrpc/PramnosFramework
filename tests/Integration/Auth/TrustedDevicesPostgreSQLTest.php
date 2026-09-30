<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

/** The same, on PostgreSQL, where `authserver` is a real schema rather than a name prefix. */
class TrustedDevicesPostgreSQLTest extends TrustedDevicesTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
