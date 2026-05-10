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
 *   - `authserver.user_twofactor`   — one row per user; stores the secret and state
 *   - `authserver.twofactor_setup`  — temporary rows during the setup flow (15-min TTL)
 *   - `authserver.twofactor_attempts` — append-only attempt log (TimescaleDB hypertable
 *                                        on TimescaleDB, plain table otherwise)
 *
 * On MySQL the authserver schema is expressed as a table-name prefix
 * (e.g. `authserver_user_twofactor`). Schema resolution and dialect-appropriate
 * quoting is handled automatically by QueryBuilder::table() for all DML operations.
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
        $result = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select('enabled')
            ->where('userid', $userId)
            ->first();

        return $result->numRows > 0 && (bool) $result->fields['enabled'];
    }

    /**
     * Return the user's stored base32 TOTP secret, or null when not configured.
     *
     * @param int $userId
     */
    public function getSecret(int $userId): ?string
    {
        $result = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select('secret')
            ->where('userid', $userId)
            ->first();

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
        $result = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select(['enabled', 'secret'])
            ->where('userid', $userId)
            ->first();

        if ($result->numRows === 0) {
            return ['enabled' => false, 'setup' => false, 'backup_codes_remaining' => 0];
        }

        $enabled   = (bool) $result->fields['enabled'];
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
        $result = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select('backup_codes')
            ->where('userid', $userId)
            ->first();

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
        $this->database->queryBuilder()
            ->table('authserver.twofactor_setup')
            ->where('userid', $userId)
            ->delete();

        // Create the new setup session
        $this->database->queryBuilder()
            ->table('authserver.twofactor_setup')
            ->insert([
                'userid'     => $userId,
                'temp_secret'=> $secret,
                'used'       => 0,
                'expires_at' => $expires,
                'created_at' => time(),
            ]);

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
        $result = $this->database->queryBuilder()
            ->table('authserver.twofactor_setup')
            ->select(['id', 'temp_secret'])
            ->where('userid', $userId)
            ->where('used', 0)
            ->where('expires_at', '>', $now)
            ->orderBy('created_at', 'desc')
            ->first();

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
        $exists = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select('userid')
            ->where('userid', $userId)
            ->first();

        if ($exists->numRows > 0) {
            $this->database->queryBuilder()
                ->table('authserver.user_twofactor')
                ->where('userid', $userId)
                ->update([
                    'enabled'            => 1,
                    'secret'             => $tempSecret,
                    'backup_codes'       => json_encode($hashedCodes),
                    'setup_completed_at' => $now,
                    'updated_at'         => $now,
                ]);
        } else {
            $this->database->queryBuilder()
                ->table('authserver.user_twofactor')
                ->insert([
                    'userid'             => $userId,
                    'enabled'            => 1,
                    'secret'             => $tempSecret,
                    'backup_codes'       => json_encode($hashedCodes),
                    'last_used'          => 0,
                    'setup_completed_at' => $now,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
        }

        // Mark setup session as used
        $this->database->queryBuilder()
            ->table('authserver.twofactor_setup')
            ->where('id', $setupId)
            ->update(['used' => 1]);

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
        $result = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select('userid')
            ->where('userid', $userId)
            ->first();

        if ($result->numRows === 0) {
            return false;
        }

        $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->where('userid', $userId)
            ->update([
                'enabled'      => 0,
                'secret'       => null,
                'backup_codes' => null,
                'last_used'    => 0,
                'updated_at'   => time(),
            ]);

        $this->database->queryBuilder()
            ->table('authserver.twofactor_setup')
            ->where('userid', $userId)
            ->delete();

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

        $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->where('userid', $userId)
            ->update([
                'backup_codes' => json_encode($hashedCodes),
                'updated_at'   => time(),
            ]);

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
        $this->database->queryBuilder()
            ->table('authserver.twofactor_setup')
            ->where('used', 1)
            ->orWhere('expires_at', '<', time())
            ->delete();
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
        $result = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select('backup_codes')
            ->where('userid', $userId)
            ->first();

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

                $this->database->queryBuilder()
                    ->table('authserver.user_twofactor')
                    ->where('userid', $userId)
                    ->update([
                        'backup_codes' => json_encode(array_values($codes)),
                        'updated_at'   => time(),
                    ]);

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
        $result = $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select('last_used')
            ->where('userid', $userId)
            ->first();

        if ($result->numRows === 0) {
            return false;
        }

        $lastUsed       = (int) $result->fields['last_used'];
        $currentWindow  = intval(time() / 30);
        $lastUsedWindow = intval($lastUsed / 30);

        return abs($currentWindow - $lastUsedWindow) <= 1;
    }

    /**
     * Record the current time as the last-used timestamp.
     */
    private function updateLastUsed(int $userId): void
    {
        $now = time();
        $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->where('userid', $userId)
            ->update([
                'last_used'  => $now,
                'updated_at' => $now,
            ]);
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
        $this->database->queryBuilder()
            ->table('authserver.twofactor_attempts')
            ->insert([
                'userid'       => $userId,
                'success'      => $success ? 1 : 0,
                'ip_address'   => $ipAddress,
                'code_used'    => sprintf('%08x', crc32($codeUsed)),
                'user_agent'   => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'attempt_time' => gmdate('Y-m-d H:i:s', time()),
            ]);
    }
}
