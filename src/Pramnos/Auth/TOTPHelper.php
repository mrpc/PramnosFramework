<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Time-based One-Time Password (TOTP) helper — RFC 6238.
 *
 * Pure static utility class. No database interaction. Compatible with
 * Google Authenticator, Authy, and any standard TOTP app.
 *
 * Algorithm:
 *   1. Decode the base32 secret.
 *   2. Compute HMAC-SHA1 over a big-endian 8-byte counter = floor(time / 30).
 *   3. Dynamic truncation of the 20-byte HMAC to a 31-bit integer.
 *   4. Take the integer mod 10^6 to produce a 6-digit code.
 *
 * @package     PramnosFramework
 * @subpackage  Auth
 */
class TOTPHelper
{
    /** Base32 alphabet (RFC 4648). */
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** TOTP window size in seconds (RFC 6238 default). */
    private const TIME_WINDOW = 30;

    /** Number of digits in the generated code. */
    private const CODE_LENGTH = 6;

    /** Number of adjacent windows to check for clock drift tolerance. */
    private const TIME_DRIFT = 1;

    // ── Key generation ────────────────────────────────────────────────────────

    /**
     * Generate a random base32-encoded TOTP secret.
     *
     * @param int $length Number of random bytes to use before encoding (default 20)
     * @return string Base32-encoded secret suitable for storage and QR codes
     */
    public static function generateSecret(int $length = 20): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(random_int(0, 255));
        }
        return self::base32Encode($bytes);
    }

    /**
     * Validate that a secret uses only valid base32 characters.
     *
     * @param string $secret Base32-encoded secret
     * @return bool True if the secret is non-empty and uses only A-Z + 2-7
     */
    public static function isValidSecret(string $secret): bool
    {
        if ($secret === '') {
            return false;
        }
        return preg_match('/^[A-Z2-7]+$/i', $secret) === 1;
    }

    // ── Code generation and verification ─────────────────────────────────────

    /**
     * Generate the TOTP code for a given secret and time.
     *
     * @param string   $secret Base32-encoded secret
     * @param int|null $time   Unix timestamp to generate for; defaults to time()
     * @return string 6-digit zero-padded code
     */
    public static function generateCode(string $secret, ?int $time = null): string
    {
        $time        ??= time();
        $counter      = intval($time / self::TIME_WINDOW);
        $secretBinary = self::base32Decode($secret);

        // HMAC-SHA1 over big-endian 8-byte counter
        $timeBytes = pack('N*', 0, $counter);
        $hash      = hash_hmac('sha1', $timeBytes, $secretBinary, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0xf;
        $code   = (
            ((ord($hash[$offset])     & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8)  |
             (ord($hash[$offset + 3]) & 0xff)
        ) % (10 ** self::CODE_LENGTH);

        return str_pad((string) $code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code with clock-drift tolerance.
     *
     * Checks the current window and TIME_DRIFT adjacent windows in each direction
     * to accommodate reasonable clock skew between client and server.
     *
     * @param string   $secret Base32-encoded secret
     * @param string   $code   6-digit code provided by the user
     * @param int|null $time   Reference time; defaults to time()
     * @param int|null $window Number of adjacent windows to check; defaults to TIME_DRIFT
     * @return bool True when the code matches any checked window
     */
    public static function verifyCode(
        string $secret,
        string $code,
        ?int $time = null,
        ?int $window = null
    ): bool {
        $time   ??= time();
        $window ??= self::TIME_DRIFT;

        for ($i = -$window; $i <= $window; $i++) {
            if (self::generateCode($secret, $time + ($i * self::TIME_WINDOW)) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds remaining in the current 30-second TOTP window.
     *
     * Useful for showing a countdown to the user in the UI.
     */
    public static function getRemainingTime(): int
    {
        return self::TIME_WINDOW - (time() % self::TIME_WINDOW);
    }

    // ── QR code URL ───────────────────────────────────────────────────────────

    /**
     * Build an otpauth:// URI for display in a QR code.
     *
     * The returned URL is a GET request to a public QR-code rendering API.
     * For production use, generate the QR code server-side to avoid leaking
     * the secret to a third party.
     *
     * @param string $secret  Base32-encoded secret
     * @param string $label   User identifier shown in the authenticator app (email/username)
     * @param string $issuer  Service name shown in the authenticator app
     * @return string QR code image URL (200×200 px)
     */
    public static function getQRCodeUrl(
        string $secret,
        string $label,
        string $issuer = 'Pramnos'
    ): string {
        $uri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($label),
            $secret,
            rawurlencode($issuer),
            self::CODE_LENGTH,
            self::TIME_WINDOW
        );
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri);
    }

    // ── Backup codes ──────────────────────────────────────────────────────────

    /**
     * Generate one-time backup codes for account recovery.
     *
     * Each code is 8 characters drawn from an unambiguous alphanumeric alphabet
     * (no 0, 1, O, I to avoid confusion).
     *
     * @param int $count Number of backup codes to generate (default 10)
     * @return string[] Array of plain-text backup codes for display to the user (store hashed)
     */
    public static function generateBackupCodes(int $count = 10): array
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $codes    = [];

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Hash a backup code for secure storage.
     *
     * Uses password_hash() with PASSWORD_DEFAULT so the hash is automatically
     * upgraded as PHP's recommended algorithm changes.
     *
     * @param string $code Plain-text backup code (as returned by generateBackupCodes())
     * @return string bcrypt/argon hash of the uppercased code
     */
    public static function hashBackupCode(string $code): string
    {
        return password_hash(strtoupper($code), PASSWORD_DEFAULT);
    }

    /**
     * Verify a plain-text backup code against a stored hash.
     *
     * @param string $code   Plain-text code provided by the user
     * @param string $hash   Hash as returned by hashBackupCode()
     * @return bool True when the code matches the hash
     */
    public static function verifyBackupCode(string $code, string $hash): bool
    {
        return password_verify(strtoupper($code), $hash);
    }

    // ── Base32 codec ──────────────────────────────────────────────────────────

    /**
     * Encode a binary string to base32 (RFC 4648, no padding).
     */
    private static function base32Encode(string $input): string
    {
        if ($input === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($input) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= self::BASE32_CHARS[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $result;
    }

    /**
     * Decode a base32-encoded string to binary.
     *
     * Invalid characters are silently skipped per RFC 4648 §6.
     */
    private static function base32Decode(string $input): string
    {
        if ($input === '') {
            return '';
        }

        $bits = '';
        foreach (str_split(strtoupper($input)) as $char) {
            $pos = strpos(self::BASE32_CHARS, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $result .= chr(bindec($chunk));
            }
        }

        return $result;
    }
}
