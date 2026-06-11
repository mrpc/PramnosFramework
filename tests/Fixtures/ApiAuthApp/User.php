<?php

declare(strict_types=1);

namespace Pramnos\Tests\Fixtures\ApiAuthApp;

/**
 * Database-free User double for ApiAuthMiddleware tests.
 *
 * ApiAuthMiddleware::resolveUser() instantiates "<appNamespace>\User" when an
 * application namespace is configured. Pointing appNamespace at
 * 'Pramnos\Tests\Fixtures\ApiAuthApp' makes the middleware use this class, so
 * the token-authentication paths can be exercised without a database:
 * loadByToken() just assigns the userid staged in self::$loadByTokenUserid.
 */
class User extends \Pramnos\User\User
{
    /** @var int Userid that loadByToken() will assign (staged by each test). */
    public static int $loadByTokenUserid = 1;

    /** @var array<int,string> Tokens passed to loadByToken(), for assertions. */
    public static array $loadedTokens = [];

    /**
     * The parent constructor loads the user from the database when a userid
     * is given — this override just records it instead.
     *
     * @param mixed $userid
     */
    public function __construct($userid = 0)
    {
        if ($userid !== 0 && $userid !== null) {
            $this->userid = (int) $userid;
        }
    }

    /**
     * Database-free stand-in: records the token and assigns the staged userid.
     *
     * @param string $token
     * @param string $tokentype
     * @param bool   $setSessionApi
     * @return static
     */
    public function loadByToken($token, $tokentype = 'auth', $setSessionApi = true)
    {
        self::$loadedTokens[] = $token;
        $this->userid = self::$loadByTokenUserid;
        return $this;
    }

    /** Reset staged state between tests. */
    public static function reset(): void
    {
        self::$loadByTokenUserid = 1;
        self::$loadedTokens      = [];
    }
}
