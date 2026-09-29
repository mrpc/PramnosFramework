<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

/**
 * {@see InvitationsTest} on PostgreSQL, where `authserver.invitations` is a table in a schema
 * rather than a prefixed name, and the claiming update has to report its row count the same way.
 */
class InvitationsPostgreSQLTest extends InvitationsTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
