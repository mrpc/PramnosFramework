<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware\OauthClientFixture;

/**
 * The application's user class for {@see \Pramnos\Tests\Unit\Http\Middleware\ApiAuthOauthClientTest}:
 * a token whose `jti` is `alice` belongs to account 7, any other to nobody — no database.
 */
class User extends \Pramnos\User\User
{
    /** No database: account 7 for the token `alice`. */
    public function __construct($userid = 0)
    {
    }

    /** No database: account 7 for the token `alice`. */
    public function loadByToken($token, $tokentype = 'auth', $setSessionApi = true)
    {
        $this->userid = $token === 'alice' ? 7 : 0;

        return $this->userid > 0;
    }
}
