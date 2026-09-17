<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

/**
 * The same widening against real PostgreSQL.
 *
 * The lane the bug was found on, and the one where the statement is `ALTER COLUMN … TYPE
 * text` rather than `MODIFY`. It is also the engine that *refuses* an overlong value instead
 * of truncating it, which is why the overflow ended a session here and would have quietly
 * shortened a URL on the other.
 */
class WidenSessionUrlAndAgentPostgreSQLTest extends WidenSessionUrlAndAgentTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
