<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Application as AuthApplication;

/**
 * The same machine account, on PostgreSQL/TimescaleDB.
 *
 * The id comes from a sequence there rather than `LAST_INSERT_ID()`, so "the id I was handed is the
 * row that exists" is a claim about two different mechanisms.
 */
#[CoversClass(AuthApplication::class)]
class SystemUserRowPostgreSQLTest extends SystemUserRowTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
