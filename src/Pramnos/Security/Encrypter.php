<?php

declare(strict_types=1);

namespace Pramnos\Security;

/**
 * Authenticated encryption for values that have to be stored and read back.
 *
 * The credentials an application keeps on behalf of its operator — an SMTP
 * password, a webhook signing key, a TOTP seed — cannot be hashed, because the
 * application needs the original to authenticate outbound, compute an HMAC, or
 * verify a code. Hashing is the right answer for a secret you only ever
 * *verify*; this class is for the ones you have to *use*.
 *
 * ## What it defends against, stated honestly
 *
 * The key lives in `APP_KEY`, which sits in `.env` on the same host as the
 * application. So this protects against every way a database is read *without*
 * the filesystem: a leaked backup, a dump handed to a contractor, an SQL
 * injection in some unrelated endpoint, a hosting neighbour, a DBA reading rows
 * they should not. It does not protect against an attacker who owns the host —
 * they read `.env` and decrypt at leisure.
 *
 * That is worth having and worth not overstating. "Encrypted at rest" in a
 * compliance answer means the first list, never the second.
 *
 * ## The scheme
 *
 * NaCl secretbox (XSalsa20-Poly1305) via libsodium, the same primitive
 * {@see \Pramnos\Broadcasting\Encryption\ChannelEncrypter} already uses, rather
 * than a second cipher for the same job. Authenticated: a modified ciphertext
 * fails to open instead of decrypting to rubbish.
 *
 * The stored form is `enc:v1:` followed by base64 of nonce ‖ ciphertext. The
 * prefix is what makes adoption possible without a migration window — see below.
 *
 * ## Adopting it on a table that already has plaintext in it
 *
 * {@see maybeDecrypt()} returns anything without the marker unchanged. So a
 * column can be read through it immediately: rows written before the change come
 * back as they are, rows written after come back decrypted, and the column
 * converts itself as values are rewritten. No migration, no downtime, no window
 * where half the rows are unreadable.
 *
 * ```php
 * // write
 * $row['smtp_pass'] = Encrypter::encrypt($password);
 * // read
 * $password = Encrypter::maybeDecrypt($row['smtp_pass']);
 * ```
 *
 * ## Rotating APP_KEY
 *
 * Everything encrypted with the old key becomes unreadable — {@see decrypt()}
 * throws rather than returning nonsense. Re-encrypt before rotating, or accept
 * that the operator re-enters those credentials.
 */
final class Encrypter
{
    /** Marks a value as produced by this class, and which format it is in. */
    public const PREFIX = 'enc:v1:';

    /**
     * Encrypt a value for storage.
     *
     * @param string $plaintext The value to protect. The empty string is returned
     *                          as-is: an empty column means "nothing set here",
     *                          and encrypting it would turn that into a value.
     * @return string `enc:v1:<base64>`, safe for any text column.
     * @throws \RuntimeException When no usable APP_KEY is configured.
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::key());

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a value produced by {@see encrypt()}.
     *
     * @param string $value The stored value, marker included.
     * @return string The original plaintext.
     * @throws \RuntimeException When the value is not in this format, when no
     *         usable APP_KEY is configured, or when the ciphertext does not
     *         authenticate — a wrong key, a truncated column, or tampering. All
     *         three are refused rather than guessed at, because a credential that
     *         silently decrypts to the wrong bytes fails somewhere far away from
     *         the cause.
     */
    public static function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) {
            throw new \RuntimeException(
                'Encrypter::decrypt() was given a value that is not encrypted. '
                . 'Use maybeDecrypt() for a column that may still hold plaintext.'
            );
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted value is malformed or truncated.');
        }

        $nonce      = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::key());

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Encrypted value failed authentication: wrong APP_KEY, or the '
                . 'stored value has been altered.'
            );
        }

        return $plaintext;
    }

    /**
     * Decrypt when the value is encrypted, and hand it back untouched when it is not.
     *
     * This is what a column mid-conversion is read through. It is deliberately not
     * the same method as {@see decrypt()}: a caller that knows the value must be
     * encrypted should get an exception when it is not, rather than a plaintext
     * secret that looks like it worked.
     */
    public static function maybeDecrypt(string $value): string
    {
        return self::isEncrypted($value) ? self::decrypt($value) : $value;
    }

    /**
     * Has this value been through {@see encrypt()}?
     *
     * A marker rather than a guess at the shape of the data: any heuristic over
     * base64-looking strings eventually misreads a password that happens to look
     * like one.
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Is encryption usable in this installation?
     *
     * For a caller that wants to degrade rather than fail — an admin screen that
     * warns "credentials will be stored unencrypted until APP_KEY is set" is more
     * use than a 500.
     */
    public static function isAvailable(): bool
    {
        try {
            self::key();
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * The 32-byte secretbox key, derived from APP_KEY.
     *
     * `key:generate` writes `base64:` + base64 of 32 random bytes, and that form is
     * decoded and used directly. Anything else — a key set by hand, a passphrase, a
     * hex string from an older installation — is hashed to 32 bytes with SHA-256 so
     * it still produces a usable key rather than an error the operator cannot act
     * on. Whichever branch is taken, the same APP_KEY always yields the same key,
     * which is what makes stored values readable on the next request.
     *
     * Read from the environment, not from Settings: the settings table is in the
     * database, and a key stored beside the data it protects protects nothing.
     *
     * @throws \RuntimeException When APP_KEY is unset or empty.
     */
    private static function key(): string
    {
        $appKey = getenv('APP_KEY');

        if (!is_string($appKey) || $appKey === '') {
            $fromEnv = $_ENV['APP_KEY'] ?? null;
            $appKey  = is_string($fromEnv) ? $fromEnv : '';
        }

        if ($appKey === '') {
            throw new \RuntimeException(
                'APP_KEY is not set, so stored credentials cannot be encrypted or '
                . 'read. Run: php pramnos key:generate'
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if ($decoded !== false
                && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ) {
                return $decoded;
            }
        }

        // Any other shape: hashed to the right length rather than refused.
        return hash('sha256', $appKey, true);
    }
}
