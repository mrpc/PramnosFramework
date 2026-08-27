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
     * The scheme a stored hash was written in — what {@see verify()} reports.
     *
     * `hmac` is what {@see make()} writes now; the other three are recognised so a
     * password that was correct yesterday is still correct today, and so
     * {@see needsUpgrade()} can say which rows are worth rewriting.
     */
    public const SCHEME_HMAC   = 'hmac';
    public const SCHEME_PEPPER = 'pepper';
    public const SCHEME_PLAIN  = 'plain';
    public const SCHEME_MD5    = 'md5';

    /** The scheme new hashes are written in. */
    public const PREFERRED = self::SCHEME_HMAC;

    /**
     * The per-user pepper: a secret from settings, bound to the account.
     *
     * Bound to the account so that two users with the same password do not have the same
     * hash even before bcrypt's own salt — and secret so that a stolen `users` table is
     * not enough to attack a hash offline. The framework has always done this; what
     * changes below is *how* the pepper reaches bcrypt.
     */
    public static function pepper(int $userId): string
    {
        return md5(
            (string) \Pramnos\Application\Settings::getSetting('securitySalt') . $userId
        );
    }

    /**
     * Hash a password for one account.
     *
     * **Why the HMAC.** The scheme this replaces was `password_hash($password . $pepper)`,
     * and bcrypt reads at most **72 bytes** of its input. The pepper is 32 of them, so a
     * password longer than 40 characters started losing pepper, and two different
     * passwords sharing their first 72 bytes hashed identically — a 90-character
     * passphrase and the same passphrase with a different ending were the same password as
     * far as the check was concerned. Longer passwords are supposed to be stronger.
     *
     * Pre-hashing with HMAC-SHA256 fixes both: every byte of the password reaches the
     * digest, the digest is a fixed 44 characters of base64, and the pepper is the HMAC
     * key rather than a suffix that can fall off the end.
     *
     * An account with no id yet cannot be peppered — the pepper is bound to the id — so
     * this is only used once the row exists. {@see \Pramnos\User\User::setPassword()} keeps
     * that arrangement.
     *
     * @param string   $plain  The password as typed
     * @param int|null $userId The account it belongs to; null hashes without a pepper
     */
    public static function make(string $plain, ?int $userId = null): string
    {
        // `> 0`, not `>= 2`. The framework's own convention is that ids 0 and 1 are the
        // guest and the built-in system account, and `setPassword()` uses that to decide
        // whether a row exists yet — but a row with id 1 that *does* have a password has
        // a peppered one, and a verifier that skipped the pepper for it refused a correct
        // password. That was a real failure on a fresh table, where the first account
        // inserted is id 1.
        if ($userId === null || $userId < 1) {
            // No pepper available. Still a real bcrypt — this is the shape an
            // application's own `password_hash($plain, PASSWORD_DEFAULT)` writes, and
            // {@see verify()} recognises it.
            return password_hash($plain, PASSWORD_DEFAULT, self::options());
        }

        return password_hash(self::digest($plain, self::pepper($userId)), PASSWORD_DEFAULT, self::options());
    }

    /**
     * Which scheme, if any, matches this password against this hash.
     *
     * Every scheme the framework has ever written is tried, plus the plain
     * `password_hash($password)` an **application** writes when it creates its own
     * accounts. That last one is not a courtesy: the `users` table is shared, either side
     * may have written a row, and a framework check that only understood its own scheme
     * refused every correct password on the other side's rows. It did — see the
     * *Authentication* guide, where the symptom is "the right password is refused" and
     * nothing in it points at hashing.
     *
     * **Trying several schemes cannot let a wrong password through.** Each is a comparison
     * against the same stored hash; a hash written by one scheme does not match the input
     * another scheme derives. What it does is recognise the writer.
     *
     * MD5 is behind its own switch, because it is not a password hash and an installation
     * that turned it off must not have it accepted here — one place accepting what the
     * front door rejects is a second front door.
     *
     * @param  string      $plain    The password as typed
     * @param  string      $hash     What is stored
     * @param  int|null    $userId   The account, for the pepper
     * @param  bool        $allowMd5 Whether legacy MD5 rows may still be accepted
     * @return string|null The matching scheme, or null when none matches
     */
    public static function verify(
        string $plain,
        string $hash,
        ?int $userId = null,
        bool $allowMd5 = false
    ): ?string {
        if ($hash === '') {
            return null;
        }

        if ($userId !== null && $userId >= 1) {
            $pepper = self::pepper($userId);

            if (password_verify(self::digest($plain, $pepper), $hash)) {
                return self::SCHEME_HMAC;
            }

            // The pepper-as-suffix scheme, which is what every row written before the
            // HMAC one looks like.
            if (password_verify($plain . $pepper, $hash)) {
                return self::SCHEME_PEPPER;
            }
        }

        // A plain bcrypt: what an application's own user service writes.
        if (password_verify($plain, $hash)) {
            return self::SCHEME_PLAIN;
        }

        if ($allowMd5 && strlen($hash) === 32 && hash_equals($hash, md5($plain))) {
            return self::SCHEME_MD5;
        }

        return null;
    }

    /**
     * Is a hash written in this scheme worth rewriting?
     *
     * True for every scheme that is not the preferred one, and for a preferred-scheme hash
     * whose bcrypt parameters have since changed — which is what makes raising the cost
     * mean anything for accounts that already exist.
     */
    public static function needsUpgrade(string $scheme, string $hash): bool
    {
        if ($scheme !== self::PREFERRED) {
            return true;
        }

        return password_needs_rehash($hash, PASSWORD_DEFAULT, self::options());
    }

    /**
     * The fixed-length input bcrypt actually hashes.
     *
     * base64 of a SHA-256 HMAC: 44 characters, well inside bcrypt's 72, with every byte of
     * the password contributing to it. Base64 rather than raw bytes because a raw digest
     * can contain a NUL, and bcrypt stops reading at one — which would throw away most of
     * the digest on roughly one hash in eight.
     */
    private static function digest(string $plain, string $pepper): string
    {
        return base64_encode(hash_hmac('sha256', $plain, $pepper, true));
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
