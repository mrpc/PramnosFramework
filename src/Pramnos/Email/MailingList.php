<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Database\Database;

/**
 * Opt-in mailing lists: who asked to receive a list, and the proof that they did.
 *
 * `Unsubscribe` records who **left** a list, which is right for mail a person receives unless
 * they say stop. A newsletter is the other way round — marketing, under GDPR and ePrivacy, goes
 * only to those who agreed — so a list whose {@see MailType} is `optIn` is sent only to the
 * addresses confirmed here. Anybody can be a subscriber, account or not: the people waiting for
 * registration to open are exactly the ones a landing-page form is for.
 *
 * ```php
 * MailTypes::register(new MailType('newsletter', 'Newsletter', 'News about the product, monthly.',
 *     list: 'newsletter', optIn: true));
 *
 * (new MailingList())->subscribe('newsletter', $email, [
 *     'source' => 'landing', 'consent' => $sentenceShown, 'language' => 'el', 'ip' => $ip,
 * ]);                                   // pending, and a confirmation mail
 * ```
 *
 * The rules:
 *
 * - **Double opt-in** for an address nobody has proved. The confirmation mail is transactional,
 *   and its link is a signed, expiring {@see MailAction} token for the address and the list.
 * - `subscribe()` answers the same way whether the address was there or not, so a form built on
 *   it cannot be used to learn who is subscribed.
 * - A second request for a pending address sends at most one more mail an hour.
 * - Confirming clears any earlier opt-out of the list and, for an account, writes the consent
 *   trail `Unsubscribe` writes on the way out.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailingList
{
    public const TABLE = 'pramnos.mailing_list_subscribers';

    /** The {@see MailAction} action a confirmation token is signed for. */
    public const CONFIRM_ACTION = 'mailinglist-confirm';

    /**
     * Fired with the list and the address when a subscription is confirmed, wherever it is
     * confirmed from. The double opt-in is the moment a newsletter counts; an application
     * listens to it rather than to the page that happened to do it.
     *
     * ```php
     * Event::listen(MailingList::EVENT_CONFIRMED, function (string $list, string $email): void { … });
     * ```
     */
    public const EVENT_CONFIRMED = 'mailinglist.confirmed';

    /** How long a confirmation link works. */
    public const CONFIRM_TTL = 7 * 86400;

    /** The least time between two confirmation mails to one pending address. */
    public const RESEND_AFTER = 3600;

    public const PENDING      = 'pending';
    public const CONFIRMED    = 'confirmed';
    public const UNSUBSCRIBED = 'unsubscribed';

    public function __construct(private ?Database $database = null)
    {
    }

    /**
     * Ask for an address to be put on a list.
     *
     * @param array{source?: string, consent?: string, language?: string, ip?: string,
     *              userid?: int|null, confirmed?: bool} $options
     *        `confirmed` skips the confirmation mail, for an address that is already proved —
     *        an account's own verified address, or one arriving on a signed link to it.
     * @return string What happened, for the caller's log: `confirmation_sent`, `confirmed`,
     *                `already_pending` or `already_confirmed`. **Not** for a page — a public
     *                form must say the same thing whatever this returns.
     * @throws \InvalidArgumentException For a malformed address or a list no opt-in type names.
     */
    public function subscribe(string $list, string $email, array $options = []): string
    {
        $email = self::normalizeEmail($email);
        $this->assertOptInList($list);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('That is not an email address.');
        }

        $now  = time();
        $row  = $this->find($list, $email);
        $data = [
            'source'       => substr((string) ($options['source'] ?? ''), 0, 32),
            'consent_text' => (string) ($options['consent'] ?? ''),
            'language'     => substr((string) ($options['language'] ?? ''), 0, 10),
            'ip'           => substr((string) ($options['ip'] ?? ''), 0, 45),
            'userid'       => $options['userid'] ?? $this->accountFor($email),
        ];

        if ($row !== null && $row['status'] === self::CONFIRMED) {
            return 'already_confirmed';
        }

        if (!empty($options['confirmed'])) {
            $this->write($row, $list, $email, $data + [
                'status' => self::CONFIRMED, 'confirmed_at' => $now, 'unsubscribed_at' => null,
            ], $now);
            $this->clearOptOut($email, $list);

            return 'confirmed';
        }

        if ($row !== null && $row['status'] === self::PENDING
            && (int) ($row['confirmation_sent_at'] ?? 0) > $now - self::RESEND_AFTER) {
            return 'already_pending';
        }

        $this->write($row, $list, $email, $data + [
            'status' => self::PENDING, 'unsubscribed_at' => null, 'confirmation_sent_at' => $now,
        ], $now);
        $this->sendConfirmation($list, $email, $data['language']);

        return 'confirmation_sent';
    }

    /**
     * Confirm the address a confirmation token names.
     *
     * Only a **pending** row is confirmed: an address that left the list after the mail went
     * out is not put back by an old link still sitting in its inbox.
     *
     * @return array{list: string, email: string}|null What was confirmed, or null.
     */
    public function confirm(string $token): ?array
    {
        $verified = MailAction::verify($token);

        if ($verified === null || $verified['action'] !== self::CONFIRM_ACTION) {
            return null;
        }

        $list  = (string) ($verified['claim']['l'] ?? '');
        $email = self::normalizeEmail((string) ($verified['claim']['e'] ?? ''));
        $row   = $this->find($list, $email);

        if ($row === null || $row['status'] !== self::PENDING) {
            return null;
        }

        $this->db()->queryBuilder()->table(self::TABLE)
            ->where('subscriberid', (int) $row['subscriberid'])
            ->update(['status' => self::CONFIRMED, 'confirmed_at' => time()]);
        $this->clearOptOut($email, $list);

        \Pramnos\Event\Event::fire(self::EVENT_CONFIRMED, $list, $email);

        return ['list' => $list, 'email' => $email];
    }

    /**
     * Is this address a confirmed subscriber of the list?
     *
     * False when it cannot tell: the question decides whether marketing is sent, and a
     * database outage should suppress it rather than send to somebody who never agreed.
     */
    public function isSubscribed(string $list, string $email): bool
    {
        try {
            $row = $this->find($list, self::normalizeEmail($email));
        } catch (\Throwable) {
            return false;
        }

        return $row !== null && $row['status'] === self::CONFIRMED;
    }

    /**
     * The row's status — `pending`, `confirmed`, `unsubscribed` — or null when there is none.
     *
     * What a screen shows: a pending address is not subscribed, but "check your inbox" is a
     * different thing to tell somebody than "off".
     */
    public function statusOf(string $list, string $email): ?string
    {
        try {
            $row = $this->find($list, self::normalizeEmail($email));
        } catch (\Throwable) {
            return null;
        }

        return $row === null ? null : (string) $row['status'];
    }

    /**
     * Take an address off a list — through `Unsubscribe`, so the opt-out record, the handlers
     * and the consent trail are the same as for any other way of leaving.
     */
    public function unsubscribe(string $list, string $email, string $source = 'page'): bool
    {
        return Unsubscribe::optOut(self::normalizeEmail($email), $list, $source);
    }

    /**
     * Mark the address as having left a list, or every list for `all`.
     *
     * Called by `Unsubscribe::optOut()`, so leaving by any route — the footer link, one-click,
     * the preferences page — also ends an opt-in subscription. A no-op where the table does not
     * exist.
     */
    public function markUnsubscribed(string $email, string $list): void
    {
        if (!$this->db()->schema()->hasTable(self::TABLE)) {
            return;
        }

        $query = $this->db()->queryBuilder()->table(self::TABLE)
            ->where('email', self::normalizeEmail($email))
            ->where('status', '!=', self::UNSUBSCRIBED);

        if ($list !== Unsubscribe::LIST_ALL) {
            $query->where('list', $list);
        }

        $query->update(['status' => self::UNSUBSCRIBED, 'unsubscribed_at' => time()]);
    }

    /**
     * The confirmed subscribers of a list, one per address.
     *
     * @return list<array{email: string, userid: int|null, language: string}>
     */
    public function confirmedSubscribers(string $list): array
    {
        $result = $this->db()->queryBuilder()->table(self::TABLE)
            ->select(['email', 'userid', 'language'])
            ->where('list', $list)
            ->where('status', self::CONFIRMED)
            ->orderBy('subscriberid')
            ->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = [
                'email'    => (string) $result->fields['email'],
                'userid'   => $result->fields['userid'] !== null ? (int) $result->fields['userid'] : null,
                'language' => (string) $result->fields['language'],
            ];
        }

        return $rows;
    }

    // ── Administration ─────────────────────────────────────────────────────────

    /**
     * The lists there are: every list an opt-in {@see MailType} names, then any the table holds.
     *
     * A list whose type was unregistered still has subscribers, and they are still people who
     * asked; the screen shows them rather than losing them with the code.
     *
     * @return list<string>
     */
    public function lists(): array
    {
        $lists = [];
        foreach (MailTypes::all() as $type) {
            if ($type->optIn && $type->list !== '') {
                $lists[] = $type->list;
            }
        }

        foreach (array_keys($this->counts()) as $list) {
            $lists[] = (string) $list;
        }

        return array_values(array_unique($lists));
    }

    /**
     * How many addresses each list has in each state.
     *
     * @return array<string, array{pending: int, confirmed: int, unsubscribed: int}>
     */
    public function counts(): array
    {
        if (!$this->db()->schema()->hasTable(self::TABLE)) {
            return [];
        }

        $query  = $this->db()->queryBuilder()->table(self::TABLE);
        $result = $query->select(['list', 'status', $query->raw('COUNT(*) AS n')])
            ->groupBy(['list', 'status'])
            ->get();

        $counts = [];
        while ($result && $result->fetch()) {
            $list = (string) $result->fields['list'];
            $counts[$list] ??= [self::PENDING => 0, self::CONFIRMED => 0, self::UNSUBSCRIBED => 0];
            $status = (string) $result->fields['status'];
            if (isset($counts[$list][$status])) {
                $counts[$list][$status] = (int) $result->fields['n'];
            }
        }

        return $counts;
    }

    /**
     * One page of a list's subscribers, newest first.
     *
     * @param string $status One of the three states, or `''` for all
     * @param string $search Part of an address
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function subscribers(string $list, string $status = '', string $search = '', int $page = 1, int $perPage = 50): array
    {
        if (!$this->db()->schema()->hasTable(self::TABLE)) {
            return ['rows' => [], 'total' => 0];
        }

        $query = $this->db()->queryBuilder()->table(self::TABLE)->where('list', $list);
        if (in_array($status, [self::PENDING, self::CONFIRMED, self::UNSUBSCRIBED], true)) {
            $query->where('status', $status);
        }
        $search = self::normalizeEmail($search);
        if ($search !== '') {
            $query->where('email', 'LIKE', '%' . addcslashes($search, '%_\\') . '%');
        }

        $total  = (clone $query)->count();
        $result = $query->select(['subscriberid', 'email', 'userid', 'status', 'source', 'language',
                'created_at', 'confirmed_at', 'unsubscribed_at', 'confirmation_sent_at'])
            ->orderBy('subscriberid', 'desc')
            ->forPage(max(1, $page), max(1, $perPage))
            ->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * A list's confirmed subscribers, for an export: address, language, when they confirmed, and
     * the account where there is one.
     *
     * @return list<array{email: string, language: string, confirmed_at: int|null, userid: int|null}>
     */
    public function confirmedExport(string $list): array
    {
        $result = $this->db()->queryBuilder()->table(self::TABLE)
            ->select(['email', 'language', 'confirmed_at', 'userid'])
            ->where('list', $list)
            ->where('status', self::CONFIRMED)
            ->orderBy('subscriberid')
            ->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = [
                'email'        => (string) $result->fields['email'],
                'language'     => (string) $result->fields['language'],
                'confirmed_at' => $result->fields['confirmed_at'] !== null ? (int) $result->fields['confirmed_at'] : null,
                'userid'       => $result->fields['userid'] !== null ? (int) $result->fields['userid'] : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null One row, by its id. */
    public function subscriber(int $subscriberId): ?array
    {
        $row = $this->db()->queryBuilder()->table(self::TABLE)
            ->where('subscriberid', $subscriberId)
            ->first();

        return $row && $row->numRows > 0 ? $row->fields : null;
    }

    /**
     * Send a pending address its confirmation again, on an administrator's word.
     *
     * Not bound by {@see RESEND_AFTER}: that limit stops a public form being used to mail
     * somebody repeatedly, and an administrator pressing the button once is not that.
     *
     * @return bool False for a row that does not exist or is not pending
     */
    public function resendConfirmation(int $subscriberId): bool
    {
        $row = $this->subscriber($subscriberId);
        if ($row === null || $row['status'] !== self::PENDING) {
            return false;
        }

        $this->db()->queryBuilder()->table(self::TABLE)
            ->where('subscriberid', $subscriberId)
            ->update(['confirmation_sent_at' => time()]);
        $this->sendConfirmation((string) $row['list'], (string) $row['email'], (string) $row['language']);

        return true;
    }

    /**
     * Take an address off its list, by the row's id — the same way leaving by the footer does.
     *
     * @return bool False for a row that does not exist or has already left
     */
    public function unsubscribeSubscriber(int $subscriberId, string $source = 'admin'): bool
    {
        $row = $this->subscriber($subscriberId);
        if ($row === null || $row['status'] === self::UNSUBSCRIBED) {
            return false;
        }

        $this->unsubscribe((string) $row['list'], (string) $row['email'], $source);

        return true;
    }

    /**
     * Every row for an account or its address — its own export.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsFor(int $userId, string $email): array
    {
        if (!$this->db()->schema()->hasTable(self::TABLE)) {
            return [];
        }

        $result = $this->db()->queryBuilder()->table(self::TABLE)
            ->select(['list', 'email', 'status', 'source', 'consent_text', 'language', 'created_at', 'confirmed_at', 'unsubscribed_at'])
            ->where(function ($q) use ($userId, $email) {
                $q->where('userid', $userId)->orWhere('email', self::normalizeEmail($email));
            })
            ->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }

    /** Delete every row for an account or its address. Called by `Account::eraseUserData()`. */
    public function forgetUser(int $userId, string $email): void
    {
        if (!$this->db()->schema()->hasTable(self::TABLE)) {
            return;
        }

        $this->db()->queryBuilder()->table(self::TABLE)
            ->where(function ($q) use ($userId, $email) {
                $q->where('userid', $userId);
                if (trim($email) !== '') {
                    $q->orWhere('email', self::normalizeEmail($email));
                }
            })
            ->delete();
    }

    /** The link a confirmation mail carries. */
    public static function confirmationUrl(string $list, string $email): string
    {
        $token = MailAction::token(self::CONFIRM_ACTION, ['l' => $list, 'e' => self::normalizeEmail($email)], self::CONFIRM_TTL);

        return \Pramnos\Http\SiteUrl::to('mailinglist/confirm?t=' . rawurlencode($token));
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Mail the confirmation link. A seam, so the rest can be tested without a mailer.
     *
     * Sent in the subscriber's language when one is known; a failure to send is logged and not
     * thrown, because the answer to the person must not depend on it.
     */
    protected function sendConfirmation(string $list, string $email, string $language): void
    {
        $send = function () use ($list, $email): void {
            (new \Pramnos\Notification\Notifier())->sendNow(
                (object) ['email' => $email, 'userid' => 0],
                new MailingListConfirmation(self::confirmationUrl($list, $email), MailTypes::byList($list))
            );
        };

        try {
            $language !== '' ? \Pramnos\Translator\Language::using($language, $send) : $send();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::logError('Mailing list confirmation to ' . $email . ' failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Drop an earlier opt-out of **this** list, and record the consent for an account.
     *
     * Not `Unsubscribe::optIn()`, which also deletes an opt-out of `all`: somebody who left
     * everything and then asked for the newsletter asked for the newsletter, not for everything
     * else back.
     */
    protected function clearOptOut(string $email, string $list): void
    {
        if ($this->db()->schema()->hasTable('pramnos.emailoptouts')) {
            $this->db()->queryBuilder()->table('pramnos.emailoptouts')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('list', $list)
                ->delete();
        }

        Unsubscribe::recordListConsent($email, $list, true);
    }

    /** @return array<string, mixed>|null */
    protected function find(string $list, string $email): ?array
    {
        $row = $this->db()->queryBuilder()->table(self::TABLE)
            ->where('list', $list)
            ->where('email', $email)
            ->first();

        return $row && $row->numRows > 0 ? $row->fields : null;
    }

    /**
     * Insert or update the row for `(list, email)`.
     *
     * @param array<string, mixed>|null $row
     * @param array<string, mixed>      $fields
     */
    private function write(?array $row, string $list, string $email, array $fields, int $now): void
    {
        if ($row === null) {
            $this->db()->queryBuilder()->table(self::TABLE)
                ->insert($fields + ['list' => $list, 'email' => $email, 'created_at' => $now]);

            return;
        }

        $this->db()->queryBuilder()->table(self::TABLE)
            ->where('subscriberid', (int) $row['subscriberid'])
            ->update($fields);
    }

    private function accountFor(string $email): ?int
    {
        try {
            $row = $this->db()->queryBuilder()->table('#PREFIX#users')
                ->select('userid')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $row && $row->numRows > 0 ? (int) $row->fields['userid'] : null;
    }

    private function assertOptInList(string $list): void
    {
        $type = MailTypes::byList($list);

        if ($type === null || !$type->optIn) {
            throw new \InvalidArgumentException(
                'No opt-in mail type names the list "' . $list . '". Register one with '
                . "new MailType(…, list: '" . $list . "', optIn: true)."
            );
        }
    }

    private function db(): Database
    {
        return $this->database ?? \Pramnos\Framework\Factory::getDatabase();
    }
}
