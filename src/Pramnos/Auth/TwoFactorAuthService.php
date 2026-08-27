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
 */
class TwoFactorAuthService
{
    /** @var \Pramnos\Database\Database */
    private $database;

    /** Session key holding the codes from an enrolment that just completed. */
    private const NEW_BACKUP_CODES_KEY = '_2fa_new_backup_codes';

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
     * **No backup codes.** This used to return a generated set, and the setup
     * screen listed them with "save these, they will not be shown again" — but
     * {@see completeSetup()} generates and stores its *own* set, so the ones on
     * screen were dead before the user finished reading them. Codes now come
     * from {@see takeNewBackupCodes()} after enrolment is verified, which is also
     * the right moment: a user who abandons setup halfway should not be walking
     * away with recovery codes for an account that has no second factor.
     *
     * @return array{secret: string, qr_code_url: string, qr_code_data_uri: string|null, manual_entry_key: string}
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
            'secret'            => $secret,
            'qr_code_data_uri'  => TOTPHelper::getQRCodeDataUri($secret, $userLabel, $issuer),
            'qr_code_url'       => TOTPHelper::getQRCodeUrl($secret, $userLabel, $issuer),
            'manual_entry_key'  => $secret,
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

        // Generate and hash backup codes, and keep the plain ones so the page
        // this enrolment lands on can show them. Without that they were
        // generated, hashed, stored and dropped — see takeNewBackupCodes().
        $plainCodes  = TOTPHelper::generateBackupCodes();
        $hashedCodes = array_map([TOTPHelper::class, 'hashBackupCode'], $plainCodes);
        $this->rememberNewBackupCodes($plainCodes);

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

        $this->logAttempt($userId, true, 'SETUP', \Pramnos\Http\Request::clientIp() ?: null);

        // Told to the account, when the application asks for such notices. Adding a second
        // factor is the change an attacker with a session makes to keep it — so the owner
        // hearing about one they did not add is the point.
        SecurityChangeNotifier::notify(
            $userId,
            SecurityChangeNotifier::FACTOR_ADDED,
            'authenticator app'
        );

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
                $this->logAttempt($userId, false, $code, \Pramnos\Http\Request::clientIp() ?: null);
                return false;
            }

            /**
             * And reject a code already used by *another request*, when asked to.
             *
             * `isRecentlyUsed()` compares a timestamp on the account, which stops the same
             * code being reused one request after another. It cannot stop two requests
             * inside the same 30-second window: both read the same `last_used`, both
             * decide the code is fresh, and both are let in. That is not theoretical —
             * it is what happens when a code is phished and replayed immediately, or when
             * somebody double-submits a form on a slow connection.
             *
             * Answering it needs a store both requests can see, atomically, which is what
             * {@see \Pramnos\Cache\Cache::increment()} is. A count of 1 means this
             * request is the one that claimed the code.
             *
             * Opt-in: it needs a cache backend that can count (Redis, memcached). Without
             * one the claim cannot be made, and the code is allowed through on the older
             * guard rather than refused — a login that fails because the cache is missing
             * is a worse outcome than the narrow window this closes.
             */
            if (SecurityPolicy::cachesTotpReplays() && !$this->claimCode($userId, $code)) {
                $this->logAttempt($userId, false, 'REPLAY', \Pramnos\Http\Request::clientIp() ?: null);

                return false;
            }

            $this->updateLastUsed($userId);
            $this->logAttempt($userId, true, $code, \Pramnos\Http\Request::clientIp() ?: null);
            return true;
        }

        if ($this->verifyAndConsumeBackupCode($userId, $code)) {
            $this->logAttempt($userId, true, 'BACKUP', \Pramnos\Http\Request::clientIp() ?: null);
            return true;
        }

        $this->logAttempt($userId, false, $code, \Pramnos\Http\Request::clientIp() ?: null);
        return false;
    }

    // ── Management operations ─────────────────────────────────────────────────

    /**
     * Disable 2FA for a user, after checking their password.
     *
     * Clears the secret and backup codes and marks the account as disabled.
     *
     * **The password is required, and that is the point.** The controller in
     * front of this has always collected it and passed it — and this method used
     * to take one parameter, so PHP dropped the extra argument on the floor and
     * the check never happened. Any signed-in session could turn 2FA off with an
     * arbitrary password, and the controller's "That password is not correct"
     * branch was unreachable: it only ever returned false when the account had
     * no 2FA row at all.
     *
     * An optional parameter would have fixed that call site and left the hole
     * open for the next one: omit the argument and the check silently does not
     * happen. A step-up check in front of *removing* the second factor is not
     * something to skip by accident, so skipping it now has a name —
     * {@see disableForOperator()}.
     *
     * An empty password is wrong, not absent: a form that submitted nothing must
     * not pass.
     *
     * @param int    $userId
     * @param string $password The account's own password.
     */
    public function disable(int $userId, string $password): bool
    {
        if (!$this->passwordMatches($userId, $password)) {
            $this->logAttempt($userId, false, 'DISABLE', \Pramnos\Http\Request::clientIp() ?: null);
            return false;
        }

        return $this->clearTwoFactor($userId);
    }

    /**
     * Disable 2FA without a password — the administrative path.
     *
     * For an operator clearing 2FA off an account whose owner cannot reach it: a
     * lost phone with no backup codes left, a departing employee. There is no
     * password to check, and the authority is the caller's own — so the caller
     * has to say that, in the name of the method it calls.
     *
     * Whoever exposes this is responsible for deciding who may. It is not the
     * user's own action.
     */
    public function disableForOperator(int $userId): bool
    {
        return $this->clearTwoFactor($userId);
    }

    /**
     * Clear the second factor. Shared by both entry points above; the difference
     * between them is the authorisation, not the work.
     */
    private function clearTwoFactor(int $userId): bool
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

        $this->logAttempt($userId, true, 'DISABLE', \Pramnos\Http\Request::clientIp() ?: null);

        // Every removal path arrives here — the owner's own, and the operator's — so the
        // notice is sent once, from the place that actually clears the factor rather than
        // from each caller that meant to.
        SecurityChangeNotifier::notify(
            $userId,
            SecurityChangeNotifier::FACTOR_REMOVED,
            'authenticator app'
        );

        return true;
    }

    /**
     * Generate and store a fresh set of backup codes, after checking the password.
     *
     * Returns the plain-text codes for display to the user (show once).
     *
     * Required for the same reason as {@see disable()}, and with the same
     * history: the controller collected a password and passed it, this method
     * took a single parameter, and the argument was discarded. So any signed-in
     * session could rotate the codes — which both invalidates every code the
     * account's owner had written down and prints ten new ones to whoever asked.
     *
     * The unchecked path has a name: {@see regenerateBackupCodesForOperator()}.
     *
     * @param int    $userId
     * @param string $password The account's own password.
     * @return string[]|false New plain-text backup codes, false when 2FA is not
     *                        enabled or the password does not match
     */
    public function regenerateBackupCodes(int $userId, string $password)
    {
        if (!$this->isEnabled($userId)) {
            return false;
        }

        if (!$this->passwordMatches($userId, $password)) {
            $this->logAttempt($userId, false, 'REGEN_BACKUP', \Pramnos\Http\Request::clientIp() ?: null);
            return false;
        }

        return $this->issueBackupCodes($userId);
    }

    /**
     * Reissue backup codes without a password — the administrative path.
     *
     * Destructive: it invalidates every code the account's owner holds. Whoever
     * exposes it decides who may, and the codes it returns have to reach the
     * account's owner rather than the operator.
     *
     * @return string[]|false New plain-text backup codes, false when 2FA is off
     */
    public function regenerateBackupCodesForOperator(int $userId)
    {
        if (!$this->isEnabled($userId)) {
            return false;
        }

        return $this->issueBackupCodes($userId);
    }

    /**
     * Replace the stored backup codes and return the new plain ones.
     *
     * Shared by both entry points above; the difference is the authorisation.
     *
     * @return string[]
     */
    private function issueBackupCodes(int $userId): array
    {
        $plainCodes  = TOTPHelper::generateBackupCodes();
        $hashedCodes = array_map([TOTPHelper::class, 'hashBackupCode'], $plainCodes);

        $this->database->queryBuilder()
            ->table('authserver.user_twofactor')
            ->where('userid', $userId)
            ->update([
                'backup_codes' => json_encode($hashedCodes),
                'updated_at'   => time(),
            ]);

        $this->logAttempt($userId, true, 'REGEN_BACKUP', \Pramnos\Http\Request::clientIp() ?: null);
        return $plainCodes;
    }

    /**
     * The backup codes from the enrolment that just completed, once.
     *
     * Enrolment generated ten codes, hashed them, stored the hashes and threw
     * the plain codes away — so the account's recovery codes were known to
     * nobody. The page enrolment redirects to says "Setup complete. Save your
     * backup codes before leaving this page" and had none to show, while the set
     * the setup screen had displayed *before* verification was a different set
     * entirely, already overwritten. A user who followed the instructions
     * exactly ended up with ten codes that could never work, and found out the
     * first time they lost their phone.
     *
     * Kept in the session because the codes have to survive one redirect, and
     * cleared on read: they are shown once, by design.
     *
     * @return string[] The codes, or an empty array when there are none to show.
     */
    public function takeNewBackupCodes(): array
    {
        if (!isset($_SESSION[self::NEW_BACKUP_CODES_KEY])) {
            return [];
        }

        $codes = $_SESSION[self::NEW_BACKUP_CODES_KEY];
        unset($_SESSION[self::NEW_BACKUP_CODES_KEY]);

        return is_array($codes) ? array_values($codes) : [];
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
     * Stash the plain backup codes for the page that will display them.
     *
     * @param string[] $codes
     */
    private function rememberNewBackupCodes(array $codes): void
    {
        \Pramnos\Http\Session::getInstance()->ensureStarted();
        if (isset($_SESSION)) {
            $_SESSION[self::NEW_BACKUP_CODES_KEY] = array_values($codes);
        }
    }

    /**
     * Does this password belong to this account?
     *
     * An empty password counts as wrong, not as absent: absent is `null`, and it
     * means an administrative call with no password to check. Collapsing the two
     * would make a form that submitted nothing pass the step-up check.
     */
    private function passwordMatches(int $userId, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $user = new \Pramnos\User\User();
        if (!$user->load($userId)) {
            return false;
        }

        return $user->verifyPassword($password);
    }

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
    /**
     * Claim a code for this request, once, across every request in the window.
     *
     * True when this request is the first to present this code for this account. False
     * when somebody has already used it — and false is only returned when the store could
     * actually answer: an unavailable or non-counting cache returns true, because refusing
     * every login when Redis is down is a larger failure than the replay window.
     *
     * The key is a hash of the code rather than the code, so a cache dump does not hand
     * out live second factors, and it expires with the TOTP window it belongs to.
     */
    private function claimCode(int $userId, string $code): bool
    {
        try {
            $cache = \Pramnos\Cache\Cache::getInstance('auth');
            if (!$cache->supportsAtomicCounter()) {
                return true;
            }

            $key = 'totpclaim_' . $userId . '_' . hash('sha256', $code);

            // 90 seconds: the current 30-second window plus the drift TOTPHelper accepts
            // on either side, so a code cannot be replayed anywhere it would still verify.
            $count = $cache->increment($key, 90);

            return $count === false || (int) $count === 1;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'TOTP replay claim failed for ' . $userId . ': ' . $exception->getMessage(),
                'auth'
            );

            return true;
        }
    }

    private function logAttempt(int $userId, bool $success, string $codeUsed, ?string $ipAddress): void
    {
        $this->database->queryBuilder()
            ->table('authserver.twofactor_attempts')
            ->insert([
                'userid'       => $userId,
                'success'      => $success,
                'ip_address'   => $ipAddress,
                'code_used'    => sprintf('%08x', crc32($codeUsed)),
                'user_agent'   => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'attempt_time' => gmdate('Y-m-d H:i:s', time()),
            ]);
    }
}
