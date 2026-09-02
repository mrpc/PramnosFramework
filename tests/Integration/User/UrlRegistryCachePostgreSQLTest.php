<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\User\Token;

/**
 * The same registry assertions, on PostgreSQL/TimescaleDB.
 *
 * The insert's returned id comes from a sequence there rather than `LAST_INSERT_ID()`, and
 * `urlId()` returns exactly that value — so "the id I was given is the id in the table" is a claim
 * about two different mechanisms.
 */
#[CoversClass(Token::class)]
class UrlRegistryCachePostgreSQLTest extends UrlRegistryCacheTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
