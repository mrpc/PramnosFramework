<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Two-Factor Authentication service — TOTP setup, verification, and management.
 *
 * Implements the full 2FA lifecycle: secret generation, QR-code provisioning,
 * code verification with replay protection, backup-code management, and
 * attempt logging.
 *
 * Three database tables are used (created by corresponding migrations):
 *   - `user_twofactor`   — one row per user; stores the secret and state
 *   - `twofactor_setup`  — temporary rows during the setup flow (15-min TTL)
 *   - `twofactor_attempts` — append-only attempt log (TimescaleDB hypertable
 *                             on TimescaleDB, plain table otherwise)
 *
 * Password verification is intentionally NOT performed inside this service —
 * that concern belongs in the calling controller.
 *
 * @package     PramnosFramework
 * @subpackage  Auth
 */
class TwoFactorAuthService
{
    /** @var \Pramnos\Database\Database */
    private $database;

    public function __construct($database = null)
    {
        $this->database = $database ?: \Pramnos\Framework\Factory::getDatabase();
    }

    // ── State queries ─────────────────────────────────────────────────────────

    /**
     * Return true when the user has 2FA fully set up and enabled.
     *
     * @param int $userId
     */
    public function isEnabled(int $userId): bool
    {
        $sql    = $this->database->prepareQuery(
            "SELECT enabled FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $result = $this->database->query($sql);

        return $result->numRows > 0 && (bool) $result->fields['enabled'];
    }

    /**
     * Return the user's stored base32 TOTP secret, or null when not configured.
     *
     * @param int $userId
     */
    public function getSecret(int $userId): ?string
    {
        $sql    = $this->database->prepareQuery(
            "SELECT secret FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $result = $this->database->query($sql);

        return $result->numRows > 0 ? ($result->fields['secret'] ?: null) : null;
    }

    /**
     * Return setup status and backup-code count for the user.
     *
     * @param int $userId
     * @return array{enabled: bool, setup: bool, backup_codes_remaining: int}
     */
    public function getStatus(int $userId): array
    {
        $sql    = $this->database->prepareQuery(
            "SELECT enabled, secret FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $result = $this->database->query($sql);

        if ($result->numRows === 0) {
            return ['enabled' => false, 'setup' => false, 'backup_codes_remaining' => 0];
        }

        $enabled  = (bool) $result->fields['enabled'];
        $hasSecret = !empty($result->fields['secret']);

        return [
            'enabled'                => $enabled,
            'setup'                  => $hasSecret,
            'backup_codes_remaining' => $enabled ? $this->getRemainingBackupCodes($userId) : 0,
        ];
    }

    /**
     * Count how many backup codes the user has remaining.
     *
     * @param int $userId
     */
    public function getRemainingBackupCodes(int $userId): int
    {
        $sql    = $this->database->prepareQuery(
            "SELECT backup_codes FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $result = $this->database->query($sql);

        if ($result->numRows === 0) {
            return 0;
        }

        $codes = json_decode((string) ($result->fields['backup_codes'] ?? ''), true);
        return is_array($codes) ? count($codes) : 0;
    }

    // ── Setup flow ────────────────────────────────────────────────────────────

    /**
     * Begin the 2FA setup flow for a user.
     *
     * Generates a new TOTP secret, stores it in a temporary setup session
     * (15-minute TTL), and returns the information needed to display a QR code
     * and record the backup codes.
     *
     * The backup codes returned here are plain-text — display them once and
     * store the hashed versions (via TOTPHelper::hashBackupCode()) only after
     * the user confirms they have saved them.
     *
     * @param int    $userId    The user's ID
     * @param string $userLabel The identifier shown in the authenticator app (email/username)
     * @param string $issuer    The service name shown in the authenticator app
     * @return array{secret: string, qr_code_url: string, manual_entry_key: string, backup_codes: string[]}
     */
    public function startSetup(int $userId, string $userLabel, string $issuer = 'Pramnos'): array
    {
        $secret  = TOTPHelper::generateSecret();
        $expires = time() + 900; // 15-minute TTL

        // Remove any leftover incomplete setup sessions
        $this->database->query(
            $this->database->prepareQuery(
                "DELETE FROM twofactor_setup WHERE userid = %d",
                $userId
            )
        );

        // Create the new setup session
        $this->database->query(
            $this->database->prepareQuery(
                "INSERT INTO twofactor_setup (userid, temp_secret, used, expires_at, created_at)
                 VALUES (%d, %s, 0, %d, %d)",
                $userId,
                $secret,
                $expires,
                time()
            )
        );

        return [
            'secret'           => $secret,
            'qr_code_url'      => TOTPHelper::getQRCodeUrl($secret, $userLabel, $issuer),
            'manual_entry_key' => $secret,
            'backup_codes'     => TOTPHelper::generateBackupCodes(),
        ];
    }

    /**
     * Complete the setup flow by verifying the first TOTP code the user enters.
     *
     * Reads the temporary secret from the setup session, verifies the code, then
     * creates (or replaces) the `user_twofactor` row and marks the setup session
     * as used.
     *
     * @param int    $userId           The user's ID
     * @param string $verificationCode 6-digit TOTP code from the authenticator app
     * @return bool True when setup was completed successfully; false on invalid code
     *              or when no valid setup session is found
     */
    public function completeSetup(int $userId, string $verificationCode): bool
    {
        $now = time();

        // Load the active (unexpired, unused) setup session
        $sql    = $this->database->prepareQuery(
            "SELECT id, temp_secret FROM twofactor_setup
              WHERE userid = %d AND used = 0 AND expires_at > %d
              ORDER BY created_at DESC LIMIT 1",
            $userId,
            $now
        );
        $result = $this->database->query($sql);

        if ($result->numRows === 0) {
            return false;
        }

        $setupId    = (int) $result->fields['id'];
        $tempSecret = (string) $result->fields['temp_secret'];

        if (!TOTPHelper::verifyCode($tempSecret, $verificationCode)) {
            return false;
        }

        // Generate and hash backup codes
        $plainCodes  = TOTPHelper::generateBackupCodes();
        $hashedCodes = array_map([TOTPHelper::class, 'hashBackupCode'], $plainCodes);

        // Upsert user_twofactor: check for existing row first (cross-DB portable)
        $existsSql = $this->database->prepareQuery(
            "SELECT userid FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $exists = $this->database->query($existsSql);

        if ($exists->numRows > 0) {
            $this->database->query(
                $this->database->prepareQuery(
                    "UPDATE user_twofactor
                        SET enabled              = 1,
                            secret               = %s,
                            backup_codes         = %s,
                            setup_completed_at   = %d,
                            updated_at           = %d
                      WHERE userid = %d",
                    $tempSecret,
                    json_encode($hashedCodes),
                    $now,
                    $now,
                    $userId
                )
            );
        } else {
            $this->database->query(
                $this->database->prepareQuery(
                    "INSERT INTO user_twofactor
                         (userid, enabled, secret, backup_codes, last_used, setup_completed_at, created_at, updated_at)
                     VALUES (%d, 1, %s, %s, 0, %d, %d, %d)",
                    $userId,
                    $tempSecret,
                    json_encode($hashedCodes),
                    $now,
                    $now,
                    $now
                )
            );
        }

        // Mark setup session as used
        $this->database->query(
            $this->database->prepareQuery(
                "UPDATE twofactor_setup SET used = 1 WHERE id = %d",
                $setupId
            )
        );

        $this->logAttempt($userId, true, 'SETUP', $_SERVER['REMOTE_ADDR'] ?? null);

        return true;
    }

    // ── Code verification ─────────────────────────────────────────────────────

    /**
     * Verify a TOTP code or backup code for authentication.
     *
     * Tries the TOTP path first (6-digit time-based code), then the backup-code
     * path (8-character one-time code). Includes replay protection for TOTP codes
     * by checking whether the current 30-second window has already been used.
     *
     * @param int    $userId  The user's ID
     * @param string $code    Code provided by the user (TOTP or backup)
     * @return bool True when the code is valid and accepted
     */
    public function verifyCode(int $userId, string $code): bool
    {
        if (!$this->isEnabled($userId)) {
            return false;
        }

        $secret = $this->getSecret($userId);
        if (!$secret) {
            return false;
        }

        if (TOTPHelper::verifyCode($secret, $code)) {
            // Replay protection: reject if this window was already consumed
            if ($this->isRecentlyUsed($userId)) {
                $this->logAttempt($userId, false, $code, $_SERVER['REMOTE_ADDR'] ?? null);
                return false;
            }

            $this->updateLastUsed($userId);
            $this->logAttempt($userId, true, $code, $_SERVER['REMOTE_ADDR'] ?? null);
            return true;
        }

        if ($this->verifyAndConsumeBackupCode($userId, $code)) {
            $this->logAttempt($userId, true, 'BACKUP', $_SERVER['REMOTE_ADDR'] ?? null);
            return true;
        }

        $this->logAttempt($userId, false, $code, $_SERVER['REMOTE_ADDR'] ?? null);
        return false;
    }

    // ── Management operations ─────────────────────────────────────────────────

    /**
     * Disable 2FA for a user.
     *
     * Clears the secret and backup codes and marks the account as disabled.
     * Password verification is the caller's responsibility.
     *
     * @param int $userId
     */
    public function disable(int $userId): bool
    {
        $sql    = $this->database->prepareQuery(
            "SELECT userid FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $result = $this->database->query($sql);

        if ($result->numRows === 0) {
            return false;
        }

        $now = time();
        $this->database->query(
            $this->database->prepareQuery(
                "UPDATE user_twofactor
                    SET enabled            = 0,
                        secret             = NULL,
                        backup_codes       = NULL,
                        last_used          = 0,
                        updated_at         = %d
                  WHERE userid = %d",
                $now,
                $userId
            )
        );

        $this->database->query(
            $this->database->prepareQuery(
                "DELETE FROM twofactor_setup WHERE userid = %d",
                $userId
            )
        );

        $this->logAttempt($userId, true, 'DISABLE', $_SERVER['REMOTE_ADDR'] ?? null);
        return true;
    }

    /**
     * Generate and store a fresh set of backup codes for the user.
     *
     * Returns the plain-text codes for display to the user (show once).
     * Password verification is the caller's responsibility.
     *
     * @param int $userId
     * @return string[]|false New plain-text backup codes, or false if 2FA is not enabled
     */
    public function regenerateBackupCodes(int $userId)
    {
        if (!$this->isEnabled($userId)) {
            return false;
        }

        $plainCodes  = TOTPHelper::generateBackupCodes();
        $hashedCodes = array_map([TOTPHelper::class, 'hashBackupCode'], $plainCodes);

        $this->database->query(
            $this->database->prepareQuery(
                "UPDATE user_twofactor
                    SET backup_codes = %s, updated_at = %d
                  WHERE userid = %d",
                json_encode($hashedCodes),
                time(),
                $userId
            )
        );

        $this->logAttempt($userId, true, 'REGEN_BACKUP', $_SERVER['REMOTE_ADDR'] ?? null);
        return $plainCodes;
    }

    /**
     * Delete expired setup sessions.
     *
     * Intended for scheduled cleanup jobs.
     */
    public function cleanupExpiredSessions(): void
    {
        $sql = $this->database->prepareQuery(
            "DELETE FROM twofactor_setup WHERE used = 1 OR expires_at < %d",
            time()
        );
        $this->database->query($sql);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Verify and consume a backup code.
     *
     * Iterates the stored hashed codes, verifies the user-supplied code against
     * each, and removes the matching code from the JSON array on success.
     */
    private function verifyAndConsumeBackupCode(int $userId, string $code): bool
    {
        $sql    = $this->database->prepareQuery(
            "SELECT backup_codes FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $result = $this->database->query($sql);

        if ($result->numRows === 0) {
            return false;
        }

        $codes = json_decode((string) ($result->fields['backup_codes'] ?? ''), true);
        if (!is_array($codes)) {
            return false;
        }

        foreach ($codes as $index => $hash) {
            if (TOTPHelper::verifyBackupCode($code, (string) $hash)) {
                unset($codes[$index]);

                $this->database->query(
                    $this->database->prepareQuery(
                        "UPDATE user_twofactor
                            SET backup_codes = %s, updated_at = %d
                          WHERE userid = %d",
                        json_encode(array_values($codes)),
                        time(),
                        $userId
                    )
                );

                return true;
            }
        }

        return false;
    }

    /**
     * Return true when the current 30-second window was already used by this user.
     *
     * Prevents replay attacks: if `last_used` falls within the same window as now
     * (or the immediately preceding window), the code has already been consumed.
     */
    private function isRecentlyUsed(int $userId): bool
    {
        $sql    = $this->database->prepareQuery(
            "SELECT last_used FROM user_twofactor WHERE userid = %d LIMIT 1",
            $userId
        );
        $result = $this->database->query($sql);

        if ($result->numRows === 0) {
            return false;
        }

        $lastUsed        = (int) $result->fields['last_used'];
        $currentWindow   = intval(time() / 30);
        $lastUsedWindow  = intval($lastUsed / 30);

        return abs($currentWindow - $lastUsedWindow) <= 1;
    }

    /**
     * Record the current time as the last-used timestamp.
     */
    private function updateLastUsed(int $userId): void
    {
        $this->database->query(
            $this->database->prepareQuery(
                "UPDATE user_twofactor SET last_used = %d, updated_at = %d WHERE userid = %d",
                time(),
                time(),
                $userId
            )
        );
    }

    /**
     * Insert a row into the twofactor_attempts log.
     *
     * The `code_used` column stores an 8-char CRC32 hex hash, not the plain
     * code, to avoid storing sensitive data in the log.
     *
     * @param int    $userId
     * @param bool   $success
     * @param string $codeUsed  The code or a label ('SETUP', 'BACKUP', etc.)
     * @param string|null $ipAddress
     */
    private function logAttempt(int $userId, bool $success, string $codeUsed, ?string $ipAddress): void
    {
        $hashedCode = sprintf('%08x', crc32($codeUsed));
        $now        = gmdate('Y-m-d H:i:s', time());
        $userAgent  = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $sql = $this->database->prepareQuery(
            "INSERT INTO twofactor_attempts
                 (userid, success, ip_address, code_used, user_agent, attempt_time)
             VALUES (%d, %d, %s, %s, %s, %s)",
            $userId,
            $success ? 1 : 0,
            $ipAddress,
            $hashedCode,
            $userAgent,
            $now
        );
        $this->database->query($sql);
    }
}
