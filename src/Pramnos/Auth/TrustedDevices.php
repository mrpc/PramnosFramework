<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\Settings;
use Pramnos\User\Token;

/**
 * "Don't ask again on this device" — browsers trusted to skip the second factor.
 *
 * Offered as a checkbox on the two-step page, ticked by default as Google ticks it. A
 * browser that completes a second factor with it ticked gets a cookie holding a random
 * token, and `authserver.trusted_devices` gets the token's lookup hash. For the next
 * `auth_trusted_device_days` days a password sign-in from that browser is let through
 * without the second step — and, when the browser also receives notifications, it is one
 * of the devices a sign-in somewhere else can be approved from
 * ({@see PushApprovals}).
 *
 * The trust is the **browser's**, bound to one account: the cookie means nothing for any
 * other account, and a row whose account signs in elsewhere is untouched. It is never
 * extended by use — the days are the days the person was told.
 *
 * **Known outlives trusted.** The cookie lives {@see KNOWN_DAYS} days, longer than the trust.
 * Once the trust expires the browser takes the second step again, but it is still a browser
 * that once completed one for this account — a fact nobody can forge by choosing a
 * User-Agent — and {@see known()} says so. A phone prompt for a known browser may be a plain
 * Yes ({@see PushApprovals}). Forgetting the device, or a new password, ends that too.
 *
 * Settings (all editable in the administration's System Settings):
 *   - `auth_trusted_devices`                 off unless set to 1
 *   - `auth_trusted_device_days`             30, between 1 and 365
 *   - `auth_trusted_devices_exclude_admins`  off; on = administrators always take the second step
 */
class TrustedDevices
{
    public const TABLE = 'authserver.trusted_devices';

    public const COOKIE = 'pf_trusted_device';

    public const ENABLED_SETTING = 'auth_trusted_devices';

    public const DAYS_SETTING = 'auth_trusted_device_days';

    public const EXCLUDE_ADMINS_SETTING = 'auth_trusted_devices_exclude_admins';

    /** The usertype from which an account counts as an administrator for the exclusion. */
    public const ADMIN_USERTYPE = 90;

    public const DEFAULT_DAYS = 30;

    /** How long the cookie keeps the browser {@see known()}, trusted or not. */
    public const KNOWN_DAYS = 365;

    /** The factor the next {@see trust()} is for — set by {@see trustVia()}. */
    private ?string $via = null;

    /**
     * Whether the checkbox is offered at all. Off until the administration turns it on:
     * it changes what a password alone can do, and that is the site's decision.
     */
    public function enabled(): bool
    {
        return (string) Settings::getSetting(self::ENABLED_SETTING, '0') === '1';
    }

    /** How long a browser stays trusted. */
    public function days(): int
    {
        $days = (int) Settings::getSetting(self::DAYS_SETTING, (string) self::DEFAULT_DAYS);

        return $days < 1 ? self::DEFAULT_DAYS : min(365, $days);
    }

    /**
     * May this account trust a browser?
     *
     * Everybody while the feature is on — except administrators, when the site has asked
     * that the most valuable accounts always take the second step.
     */
    public function allowedFor(int $userId): bool
    {
        if ($userId < 1 || !$this->enabled()) {
            return false;
        }

        if ((string) Settings::getSetting(self::EXCLUDE_ADMINS_SETTING, '0') === '1'
            && $this->usertypeOf($userId) >= self::ADMIN_USERTYPE
        ) {
            return false;
        }

        return true;
    }

    /**
     * Trust the browser making this request, recording which second factor it was trusted
     * with — what decides whether it counts towards the account's enrolment
     * ({@see Factors\PushApprovalSecondFactor::countsForEnrolment()}).
     *
     * @return int|null The new device's id, or null when trusting is not allowed or failed
     */
    public function trustVia(int $userId, string $method): ?int
    {
        $this->via = $method;

        try {
            return $this->trust($userId);
        } finally {
            $this->via = null;
        }
    }

    /**
     * Trust the browser making this request, for this account.
     *
     * @return int|null The new device's id, or null when trusting is not allowed or failed
     */
    public function trust(int $userId): ?int
    {
        if (!$this->allowedFor($userId)) {
            return null;
        }

        $token   = bin2hex(random_bytes(32));
        $now     = $this->now();
        $expires = $now + $this->days() * 86400;

        $values = [
            'userid'       => $userId,
            'token_lookup' => Token::lookup($token),
            'user_agent'   => substr($this->userAgent(), 0, 255),
            'fingerprint'  => substr(SignInFingerprint::fromUserAgent($this->userAgent()), 0, 64),
            'ip'           => substr($this->ip(), 0, 45),
            'country'      => substr(SignInRisk::country(), 0, 2),
            'created_at'   => $now,
            'last_used_at' => $now,
            'expires_at'   => $expires,
        ];

        if ($this->via !== null) {
            $values['trusted_via'] = substr($this->via, 0, 32);
        }

        try {
            $db = \Pramnos\Framework\Factory::getDatabase();
            $db->queryBuilder()->table(self::TABLE)->insert($values);

            $row = $db->queryBuilder()->table(self::TABLE)
                ->where('token_lookup', Token::lookup($token))
                ->first();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Could not trust a device: ' . $exception->getMessage(), 'auth');

            return null;
        }

        if (!$row || ($row->numRows ?? 0) < 1) {
            return null;
        }

        $this->writeCookie($token, max($expires, $now + self::KNOWN_DAYS * 86400));
        ActivityLog::record($userId, 'device_trusted', ['days' => $this->days()]);

        return (int) $row->fields['device_id'];
    }

    /**
     * The trusted device this browser is for this account, when it is one.
     *
     * Only while the account may trust a device at all ({@see allowedFor()}): an
     * administrator's browser trusted before the site excluded administrators stops counting —
     * for skipping the second step, and for answering a phone prompt.
     *
     * @return array<string, mixed>|null
     */
    public function current(int $userId): ?array
    {
        $token = $this->readCookie();

        if ($userId < 1 || $token === '' || !$this->allowedFor($userId)) {
            return null;
        }

        try {
            $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('token_lookup', Token::lookup($token))
                ->where('userid', $userId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $this->now())
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $row && ($row->numRows ?? 0) > 0 ? (array) $row->fields : null;
    }

    /**
     * Has this browser ever been trusted by this account — even if the trust has since expired?
     *
     * Proof of an earlier second factor, from a token only that browser holds. Not a reason to
     * skip anything: only to let a phone prompt for it be a plain Yes. A forgotten device, or
     * one from before a password change, is not known.
     */
    public function known(int $userId): bool
    {
        $token = $this->readCookie();

        if ($userId < 1 || $token === '') {
            return false;
        }

        try {
            return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('token_lookup', Token::lookup($token))
                ->where('userid', $userId)
                ->whereNull('revoked_at')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Is this browser trusted by this account, so the second step may be skipped?
     *
     * Asks {@see allowedFor()} again rather than trusting the row alone: an administrator
     * trusted a browser before the site excluded administrators, and that trust ends with
     * the setting rather than at its own expiry.
     */
    public function isTrusted(int $userId): bool
    {
        $device = $this->allowedFor($userId) ? $this->current($userId) : null;

        if ($device === null) {
            return false;
        }

        $this->touch((int) $device['device_id']);

        return true;
    }

    /** Record that the device just carried a sign-in or an approval. */
    public function touch(int $deviceId): void
    {
        try {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('device_id', $deviceId)
                ->update(['last_used_at' => $this->now()]);
        } catch (\Throwable) {
            // A missed "last used" is not worth failing a sign-in over.
        }
    }

    /**
     * Every device still trusted by this account, newest first, with a readable name.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('userid', $userId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $this->now())
                ->orderBy('created_at', 'desc')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $current = $this->current($userId);
        $rows    = [];

        while ($result && $result->fetch()) {
            $row                = (array) $result->fields;
            $row['name']        = SignInFingerprint::describe((string) $row['fingerprint']);
            $row['is_current']  = $current !== null && (int) $current['device_id'] === (int) $row['device_id'];
            $rows[]             = $row;
        }

        return $rows;
    }

    /**
     * Stop trusting one device of this account. Another account's device is not found.
     */
    public function revoke(int $userId, int $deviceId): bool
    {
        // Counted first: an UPDATE's own answer is "it ran", not how many rows it touched.
        $mine = fn () => \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table(self::TABLE)
            ->where('device_id', $deviceId)
            ->where('userid', $userId)
            ->whereNull('revoked_at');

        try {
            if (!$mine()->exists()) {
                return false;
            }

            $mine()->update(['revoked_at' => $this->now()]);
        } catch (\Throwable) {
            return false;
        }

        ActivityLog::record($userId, 'device_untrusted', ['device_id' => $deviceId]);

        return true;
    }

    /**
     * Stop trusting every device of this account — after a password change or reset (the
     * Account controller's `updatePassword()`), or when the person asks. Returns how many
     * were revoked.
     */
    public function revokeAll(int $userId): int
    {
        $live = fn () => \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table(self::TABLE)
            ->where('userid', $userId)
            ->whereNull('revoked_at');

        try {
            $changed = $live()->count();

            if ($changed > 0) {
                $live()->update(['revoked_at' => $this->now()]);
            }
        } catch (\Throwable) {
            return 0;
        }

        if ($changed > 0) {
            ActivityLog::record($userId, 'devices_untrusted', ['count' => $changed]);
        }

        return $changed;
    }

    // ── Seams ──────────────────────────────────────────────────────────────────

    protected function now(): int
    {
        return time();
    }

    protected function readCookie(): string
    {
        return is_string($_COOKIE[self::COOKIE] ?? null) ? (string) $_COOKIE[self::COOKIE] : '';
    }

    /**
     * The cookie: HTTP-only, same-site Lax so the link in a mail still arrives signed in,
     * secure whenever the request is. Also written into `$_COOKIE`, so the request that
     * trusted the browser already reads as trusted — the subscription it sends next
     * links to the device without a reload.
     */
    protected function writeCookie(string $token, int $expires): void
    {
        $_COOKIE[self::COOKIE] = $token;

        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $token, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => \Pramnos\Http\Request::getInstance()->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    protected function userAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    protected function ip(): string
    {
        return \Pramnos\Http\Request::clientIp();
    }

    protected function usertypeOf(int $userId): int
    {
        try {
            return (int) ((new \Pramnos\User\User($userId))->usertype ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
