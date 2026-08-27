<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * A single-use link that authorises a sign-in from a device the account has not used.
 *
 * The strictest of the new-device actions and the only one that is satisfiable by every
 * account, because it needs nothing but the mailbox: the login does not complete until
 * somebody who can read the account's mail opens the link.
 *
 * ## A link in an authentication email, deliberately
 *
 * {@see Notifications\NewSignInNotification} refuses to carry a link, and says why: a
 * link in an unexpected security email is the shape of the attack it warns about. This
 * one carries one anyway, and the difference is *expected*:
 *
 *   - the person submitted a password seconds ago and is looking at a page that says the
 *     link is coming. An unsolicited mail asking somebody to click is phishing; a mail
 *     they are waiting for is the reply to something they just did;
 *   - it is useless to an attacker who has the password but not the mailbox, which is the
 *     whole point of the action;
 *   - and it expires in fifteen minutes, once.
 *
 * What it must never become is a link that arrives without the person having asked. That
 * is why {@see LoginFlow::sendAuthLink()} refuses without a pending login: no pending
 * login, no mail, so the endpoint cannot be used to mail somebody a sign-in link on
 * request.
 *
 * ## Storage
 *
 * The same shape the password-reset link uses, in the same place — `userdetails`, a hash
 * of the token and an expiry. Deliberately not a new table: it is the same kind of thing
 * with the same lifetime, and a second mechanism for it would be a second place to get
 * the expiry check wrong.
 *
 * Only the hash is stored. A leaked `userdetails` row therefore hands out no working
 * links, which matters more here than for a reset token: this one signs the holder in
 * rather than asking them to choose a password.
 */
class NewDeviceAuthLink
{
    /** The step-up method name. */
    public const METHOD = 'authlink';

    /** How long a link lives, in seconds. */
    public const TTL = 900;

    /** The shortest gap between two links, in seconds — see {@see EmailSecondFactor}. */
    public const RESEND_INTERVAL = 60;

    /** How many links may be sent inside {@see SEND_WINDOW}. */
    public const MAX_SENDS = 5;

    /** The window the send count applies to, in seconds. */
    public const SEND_WINDOW = 900;

    /** `userdetails.fieldname` holding the hash. */
    public const FIELD_HASH = 'newdevice_authlink_hash';

    /** `userdetails.fieldname` holding the expiry. */
    public const FIELD_EXPIRES = 'newdevice_authlink_expires';

    /** @var \Pramnos\Database\Database */
    private $database;

    public function __construct($database = null)
    {
        $this->database = $database ?: \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * Issue a link for this account and mail it. False when there was nothing to send to.
     *
     * Issuing again replaces the previous link — as with the emailed code, a person who
     * clicks "send it again" must not end up with two live ways in.
     */
    public function send(int $userId, string $returnUrl = ''): bool
    {
        if ($userId < 2) {
            return false;
        }

        $user = new \Pramnos\User\User($userId);
        $address = (string) ($user->email ?? '');
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        // Refused before a new token is generated, so a refused send leaves the link the
        // person is already holding alive. Same two limits as the mailed code, for the same
        // reason: the button sits where anybody with a correct password can reach it.
        if (!$this->maySend($userId)) {
            return false;
        }

        $token = bin2hex(random_bytes(32));

        if (!$this->store($userId, hash('sha256', $token), time() + self::TTL)) {
            return false;
        }

        try {
            (new \Pramnos\Notification\Notifier())->sendNow(
                $user,
                new Notifications\NewDeviceAuthLinkNotification(
                    $this->url($token, $returnUrl),
                    self::TTL
                )
            );
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'NewDeviceAuthLink could not mail a link to ' . $userId . ': '
                . $exception->getMessage(),
                'auth'
            );

            return false;
        }

        ActivityLog::record($userId, 'newdevice_authlink_sent');

        return true;
    }

    /**
     * The user id a raw token authorises, or null.
     *
     * Single use: the stored hash is cleared before this returns an id, so the same link
     * cannot sign two sessions in — a mail sitting in an inbox, or in a mail provider's
     * link-preview cache, must not stay usable.
     *
     * An expired token is cleared as well rather than left to be found later.
     */
    public function consume(string $token): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        try {
            $row = $this->database->queryBuilder()
                ->table('#PREFIX#userdetails')
                ->select(['userid'])
                ->where('fieldname', self::FIELD_HASH)
                ->where('value', hash('sha256', $token))
                ->first();
        } catch (\Throwable $exception) {
            return null;
        }

        if ($row === null || ($row->numRows ?? 0) === 0) {
            return null;
        }

        $userId = (int) ($row->fields['userid'] ?? 0);
        if ($userId < 2) {
            return null;
        }

        $expires = (int) $this->detail($userId, self::FIELD_EXPIRES);
        $this->forget($userId);

        if ($expires > 0 && $expires < time()) {
            ActivityLog::record($userId, 'newdevice_authlink_expired');

            return null;
        }

        return $userId;
    }

    /**
     * Is another link allowed right now?
     *
     * A minimum gap and a count per window, read from the same activity rows that record
     * the sends — the audit trail and the accounting are one record on purpose.
     */
    public function maySend(int $userId): bool
    {
        $sends = $this->recentSends($userId);

        if ($sends === array()) {
            return true;
        }

        if ((time() - $sends[0]) < self::RESEND_INTERVAL) {
            return false;
        }

        return count($sends) < self::MAX_SENDS;
    }

    /**
     * Timestamps of recent link sends, newest first.
     *
     * @return list<int>
     */
    private function recentSends(int $userId): array
    {
        try {
            // No time filter in SQL, and that is deliberate. `ActivityLog` writes
            // `created_at` with `date('c')` — an ISO-8601 string carrying an offset — while
            // the column is a datetime, and a string comparison between the two formats is
            // right by accident on some rows and wrong on others. Taking the newest few for
            // this action and filtering by a parsed timestamp needs no assumption about
            // either format or the server's timezone.
            $result = $this->database->queryBuilder()
                ->table('authserver.user_activity_log')
                ->select(['created_at'])
                ->where('userid', $userId)
                ->where('action', 'newdevice_authlink_sent')
                ->orderBy('id', 'desc')
                ->limit(self::MAX_SENDS + 20)
                ->get();
        } catch (\Throwable $exception) {
            // No log, no accounting — and allowing the send, because refusing every link
            // when a log table is missing would refuse the login itself.
            return array();
        }

        $sends = array();
        if ($result === null) {
            return $sends;
        }

        while (($row = $result->fetch()) !== null) {
            $when = (int) strtotime((string) ($row['created_at'] ?? ''));
            if ($when > 0 && $when > time() - self::SEND_WINDOW) {
                $sends[] = $when;
            }
        }

        return $sends;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * The absolute URL the mail carries.
     *
     * The return path travels in the link so the person lands where they were going. It is
     * encoded and read back through the controller's own return-url handling, which is
     * where open-redirect filtering already lives — a second implementation here would be
     * a second thing to get wrong.
     */
    private function url(string $token, string $returnUrl): string
    {
        $base = (defined('sURL') ? (string) sURL : '/') . 'login/authlink?token=' . urlencode($token);

        return $returnUrl === '' ? $base : $base . '&return=' . urlencode($returnUrl);
    }

    private function store(int $userId, string $hash, int $expires): bool
    {
        try {
            foreach ([self::FIELD_HASH => $hash, self::FIELD_EXPIRES => (string) $expires] as $field => $value) {
                $this->database->queryBuilder()->table('#PREFIX#userdetails')->upsert(
                    ['userid' => $userId, 'fieldname' => $field, 'value' => $value],
                    ['userid', 'fieldname'],
                    ['value']
                );
            }

            return true;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'NewDeviceAuthLink could not store a link for ' . $userId . ': '
                . $exception->getMessage(),
                'auth'
            );

            return false;
        }
    }

    private function detail(int $userId, string $field): string
    {
        try {
            $row = $this->database->queryBuilder()
                ->table('#PREFIX#userdetails')
                ->select(['value'])
                ->where('userid', $userId)
                ->where('fieldname', $field)
                ->first();
        } catch (\Throwable $exception) {
            return '';
        }

        return $row === null ? '' : (string) ($row->fields['value'] ?? '');
    }

    private function forget(int $userId): void
    {
        try {
            $this->database->queryBuilder()
                ->table('#PREFIX#userdetails')
                ->where('userid', $userId)
                ->whereIn('fieldname', [self::FIELD_HASH, self::FIELD_EXPIRES])
                ->delete();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'NewDeviceAuthLink could not clear a spent link: ' . $exception->getMessage(),
                'auth'
            );
        }
    }
}
