<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The one place the framework turns a secret into a hash.
 *
 * Every call site used `password_hash($plain, PASSWORD_DEFAULT)` directly, which is the
 * correct default and stays the default here. What was missing was a way to say
 * *deliberately* that a particular environment wants a different cost — and one place to
 * say it, rather than four.
 *
 * **The cost is meant to be slow.** On PHP 8.5, `PASSWORD_DEFAULT` is bcrypt at cost 12,
 * which is **143 ms per hash** in this framework's own container. That is the point: it is
 * what makes an offline attack on a stolen hash expensive, and nothing here should be read
 * as an invitation to lower it in production.
 *
 * It also makes a test suite crawl, and for a reason that has nothing to do with what the
 * tests assert. Enabling 2FA hashes ten backup codes, so one call costs 1.4 s; the two
 * `TwoFactorAuthService` integration classes spent **42 s** between them, almost all of it
 * inside bcrypt. The algorithm under test is not bcrypt.
 *
 * ```
 * cost  4:  0.71 ms
 * cost  6:  2.29 ms
 * cost  8:  9.05 ms
 * cost 12: 142.9 ms   ← PASSWORD_DEFAULT on PHP 8.5
 * ```
 *
 * Set `PRAMNOS_BCRYPT_COST` to override it. The framework's own `tests/bootstrap.php` sets
 * `4`. A production deployment should leave it unset.
 *
 * Anything outside bcrypt's valid range of 4–31, or not a number at all, is **ignored
 * rather than raising** — a typo in an environment variable must not be able to stop
 * people logging in, and a hash at the default cost is never the unsafe outcome.
 *
 * @see TOTPHelper::hashBackupCode() for the call site that made this measurable
 */
final class PasswordHash
{
    /**
     * Environment variable that overrides the bcrypt cost.
     *
     * @var string
     */
    public const COST_ENV = 'PRAMNOS_BCRYPT_COST';

    /**
     * Lowest cost bcrypt accepts.
     *
     * @var int
     */
    private const MIN_COST = 4;

    /**
     * Highest cost bcrypt accepts.
     *
     * @var int
     */
    private const MAX_COST = 31;

    /**
     * Hashes a secret with the framework's password algorithm.
     *
     * @param string $plain The secret to hash
     * @return string A hash `password_verify()` accepts, in PHP's portable format
     */
    public static function make(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT, self::options());
    }

    /**
     * Options for `password_hash()` — empty unless a valid cost is configured.
     *
     * Returning an empty array rather than `['cost' => PASSWORD_BCRYPT_DEFAULT_COST]` is
     * deliberate: with no override, this must behave *exactly* as the bare
     * `password_hash($plain, PASSWORD_DEFAULT)` calls it replaced, including whatever PHP
     * decides the default is in a future version.
     *
     * The cost is only applied when the default algorithm actually is bcrypt. If PHP ever
     * changes `PASSWORD_DEFAULT` to Argon2, a bcrypt cost is meaningless there, and
     * silently passing it would be worse than ignoring it.
     *
     * @return array<string, int> Either `[]` or `['cost' => <4..31>]`
     */
    public static function options(): array
    {
        if (PASSWORD_DEFAULT !== PASSWORD_BCRYPT) {
            return []; // @codeCoverageIgnore — PASSWORD_DEFAULT is bcrypt on every PHP this framework supports
        }

        $configured = getenv(self::COST_ENV);
        if ($configured === false || !is_numeric($configured)) {
            return [];
        }

        $cost = (int) $configured;
        if ($cost < self::MIN_COST || $cost > self::MAX_COST) {
            return [];
        }

        return ['cost' => $cost];
    }
}
