<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\User\Token;

/**
 * Confirming a self-registered address before the account may sign in.
 *
 * {@see begin()} marks the account `validated = 0`, records that it is waiting, and mails a link;
 * {@see confirm()} follows it. Only accounts that were marked here are held back at sign-in —
 * an existing account with `validated = 0` from before this existed is not locked out by it.
 *
 * The token is stored as {@see Token::lookup()} in `userdetails`, like the reset-password and
 * new-device links, and expires after `auth_email_verify_ttl_hours` (48).
 *
 * `validated` is also what the ID token's `email_verified` claim is read from, so a relying party
 * is no longer told an address was verified when nobody checked it.
 */
class EmailVerification
{
    public const TTL_SETTING = 'auth_email_verify_ttl_hours';

    public const DEFAULT_TTL_HOURS = 48;

    public const FIELD_PENDING = 'email_verification_pending';

    public const FIELD_HASH = 'email_verification_hash';

    public const FIELD_EXPIRES = 'email_verification_expires';

    /** A new link is sent again from the sign-in form at most this often. */
    public const RESEND_AFTER = 600;

    public function __construct(private ?Database $database = null)
    {
    }

    public static function ttlSeconds(): int
    {
        $hours = (int) Settings::getSetting(self::TTL_SETTING, self::DEFAULT_TTL_HOURS);

        return ($hours > 0 ? $hours : self::DEFAULT_TTL_HOURS) * 3600;
    }

    /** Hold the account back until its address is confirmed, and mail the link. */
    public function begin(int $userId): bool
    {
        if ($userId <= 1) {
            return false;
        }

        $this->db()->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)->update(['validated' => 0]);
        $this->store($userId, self::FIELD_PENDING, '1');

        return $this->issue($userId);
    }

    /** Is this account waiting to confirm its address? */
    public function isPending(int $userId): bool
    {
        // Asked at sign-in, right after the credential check read the account through this
        // same connection — so on a real sign-in it is always open. It is closed only where no
        // credential check touched a database (a sign-in flow built on a stand-in), and there
        // is no row to find; opening one to look would be the only thing that could fail.
        if (!$this->db()->connected) {
            return false;
        }

        return $this->detail($userId, self::FIELD_PENDING) === '1';
    }

    /**
     * Follow a link. Returns the account it confirmed, or null — unknown, expired, or already used.
     */
    public function confirm(string $token): ?int
    {
        $token = trim($token);
        if ($token === '' || !ctype_xdigit($token)) {
            return null;
        }

        $row = $this->db()->queryBuilder()->table('#PREFIX#userdetails')
            ->select(['userid'])
            ->where('fieldname', self::FIELD_HASH)
            ->where('value', Token::lookup($token))
            ->first();
        $userId = $row && $row->numRows > 0 ? (int) $row->fields['userid'] : 0;
        if ($userId <= 1) {
            return null;
        }

        if ((int) $this->detail($userId, self::FIELD_EXPIRES) < time()) {
            return null;
        }

        $this->db()->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)->update(['validated' => 1]);
        foreach ([self::FIELD_PENDING, self::FIELD_HASH, self::FIELD_EXPIRES] as $field) {
            $this->db()->queryBuilder()->table('#PREFIX#userdetails')
                ->where('userid', $userId)->where('fieldname', $field)->delete();
        }
        ActivityLog::record($userId, 'email_verified');

        return $userId;
    }

    /**
     * Mail a fresh link to a waiting account, unless one went out in the last
     * {@see RESEND_AFTER} seconds. Called when somebody who is still waiting signs in.
     */
    public function resendIfDue(int $userId): bool
    {
        if (!$this->isPending($userId)) {
            return false;
        }

        $expires = (int) $this->detail($userId, self::FIELD_EXPIRES);
        if ($expires - self::ttlSeconds() + self::RESEND_AFTER > time()) {
            return false;
        }

        return $this->issue($userId);
    }

    public function linkFor(string $token): string
    {
        return \Pramnos\Http\SiteUrl::to('register/confirmemail?token=' . rawurlencode($token));
    }

    /** A new token, stored and mailed. */
    protected function issue(int $userId): bool
    {
        $token = bin2hex(random_bytes(32));
        $this->store($userId, self::FIELD_HASH, Token::lookup($token));
        $this->store($userId, self::FIELD_EXPIRES, (string) (time() + self::ttlSeconds()));

        try {
            $user = new \Pramnos\User\User();
            $user->load($userId);
            (new \Pramnos\Notification\Notifier())->sendNow(
                $user,
                new Notifications\VerifyEmailNotification($this->linkFor($token), self::ttlSeconds(), (string) $user->username)
            );

            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::logError('Email verification mail for ' . $userId . ' failed: ' . $e->getMessage(), $e);

            return false;
        }
    }

    protected function store(int $userId, string $field, string $value): void
    {
        $this->db()->queryBuilder()->table('#PREFIX#userdetails')->upsert(
            ['userid' => $userId, 'fieldname' => $field, 'value' => $value],
            ['userid', 'fieldname'],
            ['value']
        );
    }

    protected function detail(int $userId, string $field): string
    {
        $row = $this->db()->queryBuilder()->table('#PREFIX#userdetails')
            ->select(['value'])
            ->where('userid', $userId)
            ->where('fieldname', $field)
            ->first();

        return $row && $row->numRows > 0 ? (string) $row->fields['value'] : '';
    }

    protected function db(): Database
    {
        return $this->database ?? \Pramnos\Framework\Factory::getDatabase();
    }
}
