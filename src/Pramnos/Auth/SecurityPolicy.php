<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The account-security switches an application turns on for itself.
 *
 * Every one of these is **off by default**, and that is the contract rather than
 * timidity. This framework is shared by applications that did not ask for any of it, and
 * several of the behaviours here end sessions, refuse passwords or send mail — a silent
 * change to those on an upgrade is an incident, not an improvement. So each is declared:
 *
 * ```php
 * // app/app.php
 * 'auth' => [
 *     'security' => [
 *         'regenerate_session_on_login'          => true,
 *         'ip_rate_limit'                        => ['attempts' => 30, 'window' => 900],
 *         'notify_security_changes'              => true,
 *         'session_idle_timeout'                 => 3600,
 *         'session_absolute_timeout'             => 2592000,
 *         'revoke_sessions_on_password_change'   => true,
 *         'password_history'                     => 5,
 *         'totp_replay_cache'                    => true,
 *         'require_second_factor_from_usertype'  => 90,
 *     ],
 * ],
 * ```
 *
 * The default being "what you already had" also means the guide can describe each one as a
 * decision with a cost, which is the honest way to present a security control: every item
 * here trades something — a session ended sooner, a login refused, a mail sent — and an
 * application that has not thought about the trade should not be paying it by accident.
 *
 * Reading is deliberately cheap and total-order-free: each accessor reads
 * `applicationInfo` and applies its own default, so nothing has to be booted or
 * registered, and a console command with no application at all gets the defaults.
 */
class SecurityPolicy
{
    /**
     * Replace the session id when a login succeeds.
     *
     * Without it, an id that existed before authentication still works after — which is
     * session fixation: plant a cookie in somebody's browser, wait for them to sign in,
     * and the planted id is now an authenticated session.
     *
     * Off by default because the id is not only PHP's: this framework records
     * `md5(session_id())` in `sessions.sid`, and an application may key its own state on
     * it. The row is rewritten by the session tracker on the next request, so the effect
     * is a stale `sid` for the remainder of one request — but "an application may be
     * keying something on it" is exactly the kind of thing that must be opted into rather
     * than discovered.
     */
    public static function regeneratesSessionOnLogin(): bool
    {
        return (bool) self::value('regenerate_session_on_login', false);
    }

    /**
     * Failed-attempt limit per client address, or null when off.
     *
     * The existing lockout counts per *identifier*, which is the right shape for
     * protecting one account and no defence at all against the common attack: a list of
     * ten thousand usernames tried once each from one address never trips a
     * per-identifier counter.
     *
     * Returns `['attempts' => int, 'window' => int]`. `true` is accepted as shorthand for
     * the defaults, because a boolean is what somebody writes first.
     *
     * @return array{attempts: int, window: int}|null
     */
    public static function ipRateLimit(): ?array
    {
        $configured = self::value('ip_rate_limit', false);

        if ($configured === false || $configured === null) {
            return null;
        }

        $attempts = 30;
        $window   = 900;

        if (is_array($configured)) {
            $attempts = (int) ($configured['attempts'] ?? $attempts);
            $window   = (int) ($configured['window'] ?? $window);
        }

        if ($attempts < 1 || $window < 1) {
            return null;
        }

        return array('attempts' => $attempts, 'window' => $window);
    }

    /**
     * Mail the account when the things that protect it change.
     *
     * An email address, a password, a second factor, a passkey. Sent to the address on
     * record *before* the change as well as after, when they differ — which is the whole
     * point: a stolen session that changes the address and then the password leaves the
     * owner with no notification at all, and no way back in.
     */
    public static function notifiesSecurityChanges(): bool
    {
        return (bool) self::value('notify_security_changes', false);
    }

    /**
     * Seconds of inactivity after which a session stops being accepted, or 0 for none.
     */
    public static function sessionIdleTimeout(): int
    {
        return max(0, (int) self::value('session_idle_timeout', 0));
    }

    /**
     * Seconds after which a session stops being accepted however active, or 0 for none.
     *
     * Separate from the idle timeout because they answer different questions: idle is
     * "nobody is there", absolute is "this has been valid long enough". A session that is
     * used every day forever is the one an absolute limit exists for.
     */
    public static function sessionAbsoluteTimeout(): int
    {
        return max(0, (int) self::value('session_absolute_timeout', 0));
    }

    /**
     * End every other session when the password changes.
     *
     * People change a password *because* they think somebody else has it. Leaving the
     * other sessions alive means the attacker keeps the account and the owner believes
     * they have just fixed it — which is worse than not offering the change.
     */
    public static function revokesSessionsOnPasswordChange(): bool
    {
        return (bool) self::value('revoke_sessions_on_password_change', false);
    }

    /**
     * How many previous password hashes to remember, or 0 to remember none.
     *
     * Only useful with a reason to change: it stops "change it" from meaning "type the
     * same one again", which is what a forced rotation produces. Stored as hashes, and
     * compared with the same verifier the login uses.
     */
    public static function passwordHistory(): int
    {
        return max(0, (int) self::value('password_history', 0));
    }

    /**
     * Refuse a TOTP code that has already been used, across sessions.
     *
     * The existing replay guard is a `last_used` timestamp on the account, which stops the
     * same code being reused *in sequence*. A code submitted to two sessions inside the
     * same 30-second window is a different question, and the answer needs a store that two
     * requests can both see.
     */
    public static function cachesTotpReplays(): bool
    {
        return (bool) self::value('totp_replay_cache', false);
    }

    /**
     * Which public auth forms carry a proof-of-work human check.
     *
     * `auth.security.human_check`: `false` (default), `true` for all of them, or a list of
     * the ones you want — `['login' => true, 'register' => true, 'forgot' => false]`.
     *
     * `\Pramnos\Security\HumanCheck` prices automated submissions rather than blocking
     * them, which is the honest defence against volume and no defence against a targeted
     * attack. It is worth having on `register` and `forgotpassword` — both are public
     * writes that cost the site money in mail — and it is worth having on `login`, where
     * the cost falls on credential stuffing.
     *
     * Off by default because it burns a little battery on every visitor's device, and an
     * application with no spam problem should not be spending that. It also needs
     * `pf-humancheck.js` on the page, which a project that has not run `project:resync`
     * may not have.
     *
     * @return array{login: bool, register: bool, forgot: bool}
     */
    public static function humanCheckForms(): array
    {
        $configured = self::value('human_check', false);

        if ($configured === true) {
            return array('login' => true, 'register' => true, 'forgot' => true);
        }

        if (!is_array($configured)) {
            return array('login' => false, 'register' => false, 'forgot' => false);
        }

        return array(
            'login'    => (bool) ($configured['login'] ?? false),
            'register' => (bool) ($configured['register'] ?? false),
            'forgot'   => (bool) ($configured['forgot'] ?? false),
        );
    }

    /**
     * Does this form carry a human check?
     *
     * @param string $form `login`, `register` or `forgot`
     */
    public static function humanChecks(string $form): bool
    {
        return (bool) (self::humanCheckForms()[$form] ?? false);
    }

    /**
     * The usertype at and above which an account must hold a second factor, or 0 for none.
     *
     * An administrator with a password and nothing else is the single most valuable account
     * in an installation. This makes the second factor a condition of the privilege rather
     * than a preference of the person holding it.
     */
    public static function secondFactorFromUsertype(): int
    {
        return max(0, (int) self::value('require_second_factor_from_usertype', 0));
    }

    /**
     * One switch, from `auth.security` in the application's configuration.
     *
     * @param  string $key     The switch
     * @param  mixed  $default What this framework did before the switch existed
     * @return mixed
     */
    protected static function value(string $key, mixed $default): mixed
    {
        $application = \Pramnos\Application\Application::currentInstance();
        if (!is_object($application)) {
            return $default;
        }

        $security = $application->applicationInfo['auth']['security'] ?? null;
        if (!is_array($security) || !array_key_exists($key, $security)) {
            return $default;
        }

        return $security[$key];
    }
}
