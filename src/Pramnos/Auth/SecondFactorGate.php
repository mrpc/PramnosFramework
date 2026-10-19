<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * A session-wide «second factor owed», for an application that turns
 * {@see SecurityPolicy::secondFactorEverywhere()} on.
 *
 * Checked by {@see \Pramnos\Http\Session::staticIsLogged()}, the one function every «is somebody
 * signed in» path goes through — pages, controllers' guards, `/oauth/authorize`, the API's session
 * path. A session the login flow finished is marked and passes. One signed in some other way, for
 * an account with a second factor, is not counted as signed in: it becomes a pending login of
 * {@see LoginFlow}, and the login page asks for the factor. An account without one, or a browser
 * it trusts, is marked and passes from then on. Nothing here is inside `Auth::auth()`.
 */
final class SecondFactorGate
{
    /** Session key: the account whose second factor this session has settled. */
    public const SESSION = 'loginflow_second_factor_for';

    /** Set while the gate decides, so a question it asks about the session does not ask it again. */
    private static bool $deciding = false;

    /**
     * Whether a session signed in as `$userId` may count as signed in; when it may not, it no
     * longer does — it is a pending login waiting for the second factor.
     */
    public static function passes(int $userId, ?LoginFlow $flow = null): bool
    {
        if (!SecurityPolicy::secondFactorEverywhere() || (int) ($_SESSION[self::SESSION] ?? 0) === $userId) {
            return true;
        }
        if (self::$deciding) {
            return true;
        }
        self::$deciding = true;
        try {
            $owed = ($flow ?? new LoginFlow())->demandSecondFactor($userId);
        } finally {
            self::$deciding = false;
        }
        if (!$owed) {
            return true;
        }
        // No longer signed in: the login waiting for the factor is all this session holds.
        $_SESSION['logged'] = false;
        $_SESSION['uid']    = 0;
        \Pramnos\User\User::forgetCurrentUser();

        return false;
    }

    /** Mark this session's second factor as settled for an account. */
    public static function settle(int $userId): void
    {
        $_SESSION[self::SESSION] = $userId;
    }
}
