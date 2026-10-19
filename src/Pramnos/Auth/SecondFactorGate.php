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

    /** Session key: the page a session was on when it was stopped, to go back to. */
    public const RETURN = 'loginflow_second_factor_return';

    /**
     * Session key: the account this session was stopped for, and until when — every page it
     * opens is sent to the second step while its login waits there.
     */
    public const OWED = 'loginflow_second_factor_owed';

    /** Session key: until when the stopped account is locked out, for the step to say so. */
    public const LOCKED = 'loginflow_second_factor_locked';

    /** How long a stopped session is sent to the step, at most, in seconds. */
    public const OWED_FOR = 900;

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
            $owed = ($flow ?? LoginFlow::make())->demandSecondFactor($userId);
        } finally {
            self::$deciding = false;
        }
        if (!$owed) {
            return true;
        }
        // No longer signed in: the login waiting for the factor is all this session holds — and
        // remembered, so the next page is sent to the step too, not shown to a guest.
        $_SESSION['logged']   = false;
        $_SESSION['uid']      = 0;
        $_SESSION[self::OWED] = ['user' => $userId, 'until' => time() + self::OWED_FOR];
        \Pramnos\User\User::forgetCurrentUser();
        self::sendToTheSecondStep();

        return false;
    }

    /**
     * Whether this session was stopped for its second factor and still owes it: while its login
     * waits for the step, or its account is locked out, every page it opens is sent to the step
     * again — {@see \Pramnos\Http\Session::staticIsLogged()} asks before anything else, as the
     * session reads as signed out. Forgotten once the login is finished, abandoned or expired.
     */
    public static function stillOwed(): bool
    {
        $owed = $_SESSION[self::OWED] ?? null;
        if ($owed === null) {
            return false;
        }
        // Asked before the application exists — a front controller deciding on its page cache —
        // the policy cannot be read: still owed, nothing forgotten and nobody sent anywhere yet.
        // The question asked once the application is up decides.
        if (\Pramnos\Application\Application::currentInstance() === null) {
            return is_array($owed);
        }
        $userId  = is_array($owed) ? (int) ($owed['user'] ?? 0) : 0;
        $waiting = $userId > 1 && SecurityPolicy::secondFactorEverywhere() && (int) ($owed['until'] ?? 0) >= time()
            && (self::lockedFor() > 0 || LoginFlow::make()->pendingUserId() === $userId);
        if (!$waiting) {
            unset($_SESSION[self::OWED], $_SESSION[self::LOCKED]);

            return false;
        }
        self::sendToTheSecondStep();

        return true;
    }

    /** How many more seconds the stopped account is locked out: 0 when it is not. */
    public static function lockedFor(): int
    {
        return max(0, (int) ($_SESSION[self::LOCKED] ?? 0) - time());
    }

    /**
     * A page opened in a browser goes to the second step, and comes back once it is done: the page
     * is remembered, and where `auth.security.second_factor_url` names the step — `login`, or an
     * area's own — the browser is sent there.
     */
    protected static function sendToTheSecondStep(): void
    {
        $step = self::secondStep();
        $app  = \Pramnos\Application\Application::currentInstance();
        if ($step !== null && PHP_SAPI !== 'cli' && is_object($app) && !headers_sent()) {
            $app->redirect($step); // @codeCoverageIgnore — exits
        }
    }

    /**
     * Where this request goes for its second factor, remembering the page it was on; null when
     * it goes nowhere. An API call, a form post or a script is only refused: a redirect would
     * answer them with a page they did not ask for. So is the step's own page, or none named.
     */
    public static function secondStep(): ?string
    {
        $here = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($here === '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || !str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? 'text/html'), 'text/html')) {
            return null;
        }
        $step = SecurityPolicy::secondFactorUrl();
        if ($step !== '' && trim((string) parse_url($here, PHP_URL_PATH), '/') === trim((string) parse_url($step, PHP_URL_PATH), '/')) {
            return null;
        }
        $_SESSION[self::RETURN] = $here;
        if ($step === '') {
            return null;
        }

        return preg_match('#^https?://#', $step) === 1 ? $step : (defined('sURL') ? sURL : '/') . ltrim($step, '/');
    }

    /** Mark this session's second factor as settled for an account: nothing is owed any more. */
    public static function settle(int $userId): void
    {
        $_SESSION[self::SESSION] = $userId;
        unset($_SESSION[self::OWED], $_SESSION[self::LOCKED]);
    }
}
