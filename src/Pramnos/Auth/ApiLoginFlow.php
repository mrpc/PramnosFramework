<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The login flow as an API sees it: same decisions, no browser session.
 *
 * `LoginFlow` is where the framework decides what a password entitles you to —
 * lockout first, then credentials, then a second factor if the account has one.
 * The JSON login endpoint used to skip all of it and go straight from password
 * to bearer token, which meant an account with 2FA enabled could be entered with
 * the password alone, and nothing counted failed attempts.
 *
 * The only thing an API needs to do differently is the last step: it issues a
 * token instead of establishing a session. That is one seam, so this subclass is
 * one method — everything else is inherited, and stays inherited when the rules
 * change.
 *
 * Credentials verification is forwarded to the caller when it supplies a
 * resolver, so a controller that overrode its own `verifyCredentials()` keeps
 * that hook.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ApiLoginFlow extends LoginFlow
{
    /** @var callable|null Caller-supplied credentials check. */
    private $credentialsResolver;

    /**
     * @param callable|null             $credentialsResolver fn(string $username, string $password): array|false
     * @param Auth|null                 $auth
     * @param Loginlockout|null         $lockout
     * @param TwoFactorAuthService|null $twoFactor
     */
    public function __construct(
        ?callable $credentialsResolver = null,
        ?Auth $auth = null,
        ?Loginlockout $lockout = null,
        ?TwoFactorAuthService $twoFactor = null
    ) {
        parent::__construct($auth, $lockout, $twoFactor);
        $this->credentialsResolver = $credentialsResolver;
    }

    /**
     * No session: the caller mints a bearer token for the verified user.
     *
     * Returning true says "the login is complete as far as this flow is
     * concerned" — lockout state is cleared and the result carries the user id,
     * which is all the caller needs.
     */
    protected function establishSession(int $userId, bool $remember): bool
    {
        return true;
    }

    /**
     * Use the caller's credentials check when it gave one.
     *
     * @return array<string, mixed>|false
     */
    protected function verifyCredentials(string $username, string $password, bool $remember): array|false
    {
        if ($this->credentialsResolver !== null) {
            return ($this->credentialsResolver)($username, $password);
        }

        return parent::verifyCredentials($username, $password, $remember);
    }
}
