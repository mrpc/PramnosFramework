<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Controllers\Oauth;

/**
 * The same registration, on PostgreSQL/TimescaleDB.
 *
 * `apisecret` is written as SQL NULL and `is_confidential` as a smallint, and the token endpoint
 * decides from both whether the client may authenticate with nothing — so the read-back has to
 * agree on this engine too.
 */
#[CoversClass(Oauth::class)]
class DynamicClientRegistrationPostgreSQLTest extends DynamicClientRegistrationTest
{
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
    }
}
