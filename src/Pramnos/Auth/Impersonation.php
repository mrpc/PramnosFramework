<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Signing in as somebody else — support looking at what a customer sees — and coming back.
 *
 * ```php
 * // the administration's "log in as"
 * $why = \Pramnos\Auth\Impersonation::start($targetUserId);   // null, or why not
 *
 * // the impersonated page's "Return to my account", a POST with the session's token
 * \Pramnos\Auth\Impersonation::end();                         // signed in as the administrator again
 * ```
 *
 * Nothing that could sign the administrator in stays in the browser while they are somebody
 * else: their sign-in token ends when the other sign-in begins. The way back is a one-time key
 * held only in the impersonated session, whose hash is kept in `impersonations` with an expiry —
 * eight hours, or `'auth' => ['impersonation' => ['hours' => …]]`. Coming back ends the
 * impersonated sign-in's token and gives the administrator a fresh one, without a password.
 * Signing out while impersonating ends both. Both events go to the activity log.
 *
 * Who may: from the `super` role up (`'auth' => ['impersonation' => ['min_role' => …]]`), and only
 * as an account of a lower usertype — never one's own, never an equal or a higher one.
 */
final class Impersonation
{
    /** Hours the way back stays open, unless app.php says otherwise. */
    public const HOURS = 8;

    /** Where the impersonated session keeps the way back. */
    private const SESSION = 'impersonation';

    /**
     * Sign in as another account. Null when it was done, or why not — the person signed in may
     * not, the account is not one they may become, or it cannot sign in.
     */
    public static function start(int $targetUserId, ?Auth $auth = null): ?string
    {
        $actor = \Pramnos\User\User::getCurrentUser();
        if (!is_object($actor) || (int) ($actor->userid ?? 0) <= 1) {
            return 'Sign in first.';
        }
        if (self::current() !== null) {
            return 'Return to your own account first.';
        }
        $actorId = (int) $actor->userid;
        if (!\Pramnos\User\Roles::atLeast($actor, self::minRole())) {
            return 'You may not sign in as somebody else.';
        }
        $target = new \Pramnos\User\User($targetUserId);
        if ((int) ($target->userid ?? 0) !== $targetUserId || $targetUserId <= 1 || $targetUserId === $actorId) {
            return 'There is no such account to sign in as.';
        }
        if ((int) ($target->usertype ?? 0) >= (int) ($actor->usertype ?? 0)) {
            return 'You may sign in only as an account of a lower rank.';
        }

        $key      = bin2hex(random_bytes(32));
        $remember = \Pramnos\Http\Request::getInstance()->cookieget('auth') !== null;
        $now      = time();
        try {
            // Its answer, not only an exception: PostgreSQL reports a missing table as false.
            $stored = self::table()->insert([
                'actor_userid'  => $actorId,
                'target_userid' => $targetUserId,
                'return_hash'   => hash('sha256', $key),
                'remember'      => $remember,
                'created_at'    => $now,
                'expires_at'    => $now + self::hours() * 3600,
            ]);
            if ($stored === false) {
                throw new \RuntimeException('the insert was refused');
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Impersonation not started — run migrate: ' . $e->getMessage(), 'auth');

            return 'Signing in as somebody else is not set up here (run migrate).';
        }
        $id = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();

        // The sign-in as the other account ends the administrator's token on its way in.
        $auth ??= Auth::getInstance();
        if (!$auth->loginById($targetUserId, false)) {
            self::table()->where('id', $id)->update(['ended_at' => time(), 'ended_by' => 'refused']);

            return 'That account cannot sign in.';
        }
        $_SESSION[self::SESSION] = ['id' => $id, 'key' => $key, 'actor' => $actorId];
        // The administrator settled their own second factor; the account is not asked for its own.
        SecondFactorGate::settle($targetUserId);

        ActivityLog::record($actorId, 'impersonate', ['target' => $targetUserId]);
        ActivityLog::record($targetUserId, 'impersonated', ['by' => $actorId]);

        return null;
    }

    /**
     * Back to the administrator: the impersonated sign-in ends, its token with it, and the
     * administrator is signed in again with a fresh one. False when there is no way back — not
     * impersonating, the key spent or expired — and then nothing changes.
     */
    public static function end(?Auth $auth = null): bool
    {
        $way = self::current();
        $row = $way === null ? null : self::row((int) $way['id']);
        if ($row === null || $row['ended_at'] !== null || !hash_equals((string) $row['return_hash'], hash('sha256', (string) $way['key']))) {
            return false;
        }
        if ((int) $row['expires_at'] < time()) {
            self::close((int) $way['id'], 'expired');
            unset($_SESSION[self::SESSION]);

            return false;
        }

        // Spent first: a second request with the same session cannot come back twice.
        self::close((int) $way['id'], 'return');
        unset($_SESSION[self::SESSION]);
        $auth ??= Auth::getInstance();
        $back = $auth->loginById((int) $row['actor_userid'], in_array($row['remember'], [true, 1, '1', 't'], true));
        if ($back) {
            SecondFactorGate::settle((int) $row['actor_userid']);
        }
        ActivityLog::record((int) $row['actor_userid'], 'impersonation_end', ['target' => (int) $row['target_userid']]);

        return $back;
    }

    /**
     * Signing out while impersonating: the way back is closed as well, so neither account stays
     * signed in. Called by {@see Auth::logout()}.
     */
    public static function closeOnLogout(): void
    {
        $way = self::current();
        if ($way !== null) {
            self::close((int) $way['id'], 'logout');
            unset($_SESSION[self::SESSION]);
        }
    }

    /**
     * Whether this session is somebody signed in as somebody else: `['actor' => userid, 'id' =>
     * …]`, or null — for a banner saying so, with the way back.
     *
     * @return array{id: int, key: string, actor: int}|null
     */
    public static function current(): ?array
    {
        $way = $_SESSION[self::SESSION] ?? null;

        return is_array($way) && isset($way['id'], $way['key'], $way['actor']) ? $way : null;
    }

    /**
     * The banner an impersonated page shows: who this is, and a button back — a POST to `$action`
     * with the session's token. '' when this session is nobody else's.
     */
    public static function banner(string $action): string
    {
        $way  = self::current();
        $user = \Pramnos\User\User::getCurrentUser();
        if ($way === null || !is_object($user)) {
            return '';
        }
        $t = static fn (string $words, string ...$args): string => function_exists('t') ? (string) t($words, ...$args) : vsprintf($words, $args);
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<div class="pf-impersonation" role="status">' . $e($t('You are signed in as %s.', (string) ($user->username ?? '')))
            . ' <form method="post" action="' . $e($action) . '" style="display:inline;margin:0">'
            . \Pramnos\Http\Session::getInstance()->getTokenField()
            . '<button type="submit">' . $e($t('Return to your account')) . '</button></form></div>';
    }

    /** @return array<string, mixed>|null */
    private static function row(int $id): ?array
    {
        try {
            $result = self::table()->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }

        return $result && (int) $result->numRows > 0 ? $result->fields : null;
    }

    private static function close(int $id, string $how): void
    {
        self::table()->where('id', $id)->whereNull('ended_at')->update(['ended_at' => time(), 'ended_by' => $how]);
    }

    /** The role from which a person may sign in as somebody else. */
    private static function minRole(): string
    {
        $role = self::config()['min_role'] ?? null;

        return is_string($role) && $role !== '' ? $role : \Pramnos\User\Roles::SUPER;
    }

    private static function hours(): int
    {
        $hours = self::config()['hours'] ?? null;

        return is_numeric($hours) && (int) $hours > 0 ? (int) $hours : self::HOURS;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['auth']['impersonation'] ?? []) : [];

        return is_array($config) ? $config : [];
    }

    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#impersonations');
    }
}
