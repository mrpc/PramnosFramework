<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\User\User;

/**
 * The same settings store on PostgreSQL/TimescaleDB.
 *
 * Two things differ here and only here. The `value` column is `text` holding JSON, and what a
 * `text` round-trip does to it is the driver's business, not the store's — a value that came back
 * with its quoting altered would decode to something else on one backend. And the upsert rests on a
 * composite unique index the two backends declare differently, so "one row for one setting" is a
 * claim that has to be made twice.
 */
#[CoversClass(User::class)]
class UserSettingsPostgreSQLTest extends UserSettingsTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
