<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The credential a signed-in browser carries — `$_SESSION['auth']` and the `auth` cookie — one
 * per sign-in and device.
 *
 * It is `selector.validator`: random, unrelated to the password, stored only as a hash, with an
 * expiry, ended by signing out ({@see revoke()}), by "sign out everywhere"
 * ({@see revokeAll()}), and by a password change — each row remembers the password hash it was
 * issued under. It replaces the password hash these carried before, which lasted until the
 * password changed, could not be ended for one device, and handed the hash to whoever read it.
 *
 * Never print it into a page: it signs its holder in.
 */
final class RememberTokens
{
    /** Days a token lives, unless `'auth' => ['remember_days' => …]` says otherwise. */
    public const DAYS = 30;

    /**
     * Seconds a used token still signs in after it was replaced, unless
     * `'auth' => ['remember_grace' => …]` says otherwise: a browser that sends two requests at
     * once with the same cookie — a mail app opening a link twice — gets both signed in.
     */
    public const GRACE = 60;

    /** What a token looks like: a selector and a validator, in hex. */
    public const PATTERN = '/^[a-f0-9]{24}\.[a-f0-9]{64}$/';

    /** Whether a value is one of these tokens, rather than an old password-hash cookie. */
    public static function isToken(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * A new token for a person, stored hashed; null where it could not be stored — the table is
     * not there yet because `migrate` has not run. The caller then keeps what it had, so an
     * upgrade deployed before its migration signs nobody out.
     */
    public static function issue(int $userId): ?string
    {
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $now       = time();

        try {
            // Its answer, not only an exception: a driver may report a missing table as false.
            $stored = self::table()->insert([
                'userid'         => $userId,
                'selector'       => $selector,
                'validator_hash' => hash('sha256', $validator),
                'password_check' => self::passwordCheck($userId),
                'created_at'     => $now,
                'expires_at'     => $now + self::days() * 86400,
                'user_agent'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            if ($stored === false) {
                throw new \RuntimeException('the insert was refused');
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Remember token not stored — run migrate; the sign-in keeps the old credential until then: ' . $e->getMessage(), 'auth');

            return null;
        }

        return $selector . '.' . $validator;
    }

    /**
     * Whose a token is, or null: one that exists, has not expired, matches, and was issued under
     * the password the account has now. An expired or outdated one is deleted on the way.
     */
    public static function userFor(string $token): ?int
    {
        if (!self::isToken($token)) {
            return null;
        }
        [$selector, $validator] = explode('.', $token, 2);

        try {
            $row = self::table()->where('selector', $selector)->first();
        } catch (\Throwable) {
            return null;
        }
        if (!$row || (int) $row->numRows === 0) {
            return null;
        }
        $f = $row->fields;
        if (!hash_equals((string) $f['validator_hash'], hash('sha256', $validator))) {
            return null;
        }
        $userId = (int) $f['userid'];
        if ((int) $f['expires_at'] < time() || !hash_equals((string) $f['password_check'], self::passwordCheck($userId))) {
            self::table()->where('selector', $selector)->delete();

            return null;
        }
        self::table()->where('selector', $selector)->update(['last_used_at' => time()]);

        return $userId;
    }

    /** End one token — this browser's sign-in. */
    public static function revoke(string $token): void
    {
        if (!self::isToken($token)) {
            return;
        }
        try {
            self::table()->where('selector', explode('.', $token, 2)[0])->delete();
        } catch (\Throwable) {
            // No table: nothing was stored to end.
        }
    }

    /**
     * Retire a token that has just been replaced: it keeps signing in for {@see GRACE} seconds
     * more and then expires, so a request sent alongside the one that used it is not signed out.
     * Never lengthens a token's life. {@see revoke()} is the one that ends a token at once.
     */
    public static function retire(string $token): void
    {
        if (!self::isToken($token)) {
            return;
        }
        $selector = explode('.', $token, 2)[0];
        try {
            self::table()->where('selector', $selector)->where('expires_at', '>', time() + self::grace())
                ->update(['expires_at' => time() + self::grace()]);
        } catch (\Throwable) {
            // No table: nothing was stored to retire.
        }
    }

    /** End every token of a person — "sign out everywhere". How many there were. */
    public static function revokeAll(int $userId): int
    {
        try {
            $count = self::table()->where('userid', $userId)->count();
            self::table()->where('userid', $userId)->delete();

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** sha256 of the password hash the account has now: what a changed password changes. */
    private static function passwordCheck(int $userId): string
    {
        $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
            ->select(['password'])->where('userid', $userId)->first();

        return hash('sha256', $row && (int) $row->numRows > 0 ? (string) $row->fields['password'] : '');
    }

    /** The days a token lives. */
    private static function days(): int
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $days = is_object($app) ? ($app->applicationInfo['auth']['remember_days'] ?? null) : null;

        return is_numeric($days) && (int) $days > 0 ? (int) $days : self::DAYS;
    }

    /** The seconds a replaced token still signs in. */
    private static function grace(): int
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $grace = is_object($app) ? ($app->applicationInfo['auth']['remember_grace'] ?? null) : null;

        return is_numeric($grace) && (int) $grace >= 0 ? (int) $grace : self::GRACE;
    }

    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('authserver.remember_tokens');
    }
}
