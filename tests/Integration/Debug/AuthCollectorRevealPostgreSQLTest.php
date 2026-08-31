<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Debug\Collectors\AuthCollector;

/**
 * The same second-factor assertions, on PostgreSQL/TimescaleDB.
 *
 * `authserver.twofactor_setup` is a schema on this engine and a table prefix on the other, and
 * the mail lookup is a `LOWER(tomail) = ?` with an `ORDER BY` and a `LIMIT` — a shape that
 * compiles differently and had only ever run on one of them.
 */
#[CoversClass(AuthCollector::class)]
class AuthCollectorRevealPostgreSQLTest extends AuthCollectorRevealTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
