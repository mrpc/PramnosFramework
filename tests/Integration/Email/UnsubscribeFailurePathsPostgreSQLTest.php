<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Email\Unsubscribe;

/**
 * The same failure paths, on PostgreSQL/TimescaleDB.
 *
 * The first coverage this service has had on this engine: the existing record test declares itself
 * MySQL only. `pramnos.emailoptouts` is a real schema here and a table prefix there, and the
 * lookup is a `LOWER(email) = ?` with a `whereIn` beside it.
 */
#[CoversClass(Unsubscribe::class)]
class UnsubscribeFailurePathsPostgreSQLTest extends UnsubscribeFailurePathsTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
