<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\Settings;
use Pramnos\Push\Subscriptions;
use Pramnos\User\Token;

/**
 * "Is it you trying to sign in?" — a sign-in approved from a trusted phone.
 *
 * Google's sign-in prompt, for any application on this framework. A sign-in waiting on its
 * second factor sends a notification to the account's **trusted** devices that can receive
 * one ({@see TrustedDevices}); the waiting page shows which devices, watches for the phone's
 * receipt, and finishes the sign-in once the phone says yes. It is one second factor among
 * the others, never the only door: the page always offers another way.
 *
 * The rules that make it safe rather than merely convenient:
 *
 *  - **Only trusted devices are asked.** A browser that merely granted notification permission
 *    may be a session that passed a password and nothing else; it does not get to let anybody
 *    in.
 *  - **The answer comes from the device, not the link.** Approving requires the answering
 *    browser to be signed in as the account *and* to carry its trust cookie. A forwarded
 *    notification link approves nothing.
 *  - **A number, unless the attempt is plainly the account's own.** The waiting page shows a
 *    number and the phone must pick it out of three, so a reflexive tap approves nothing. The
 *    one exception, under `auth_push_number_matching` = `risk` (the default): a browser this
 *    account trusted before ({@see TrustedDevices::known()} — its cookie, which nobody can
 *    forge by choosing a User-Agent) with nothing unusual about the attempt
 *    ({@see SignInRisk}) gets a plain Yes. `always` asks for the number every time. What
 *    never earns the Yes is an attempt that merely *looks* familiar — its User-Agent and
 *    country are what whoever holds the password chooses.
 *  - **Only the waiting browser can use the approval**, by the hash of its session id, once,
 *    and within {@see CONSUME_WINDOW} seconds of the phone's answer.
 *  - **Not for administrators, when the site excludes them** from trusted devices: their
 *    devices are neither asked nor able to answer ({@see TrustedDevices::allowedFor()}).
 *  - **Bounded.** Each ask lasts two minutes, and an account is asked at most three times in
 *    ten minutes — the answer to being bombarded with prompts until one is tapped.
 *  - **"No, it's not me" has consequences**: the attempt is refused, recorded, and the owner is
 *    told by mail that their password is known to somebody else.
 *
 * Setting: `auth_push_approval` (off unless set to 1). It also needs this installation to be
 * able to send push at all — VAPID keys and the encryption library.
 */
class PushApprovals
{
    public const TABLE = 'authserver.push_approvals';

    public const ENABLED_SETTING = 'auth_push_approval';

    /**
     * When the phone has to pick the number: `risk` (default) — unless the browser signing in
     * is {@see TrustedDevices::known()} and the attempt shows nothing unusual; `always`.
     */
    public const NUMBER_SETTING = 'auth_push_number_matching';

    public const NUMBER_POLICIES = ['risk', 'always'];

    /** Where the pending sign-in keeps the approval it is waiting on. */
    public const SESSION_KEY = 'pf_push_approval';

    /** How long an ask can be answered, in seconds. */
    public const TTL = 120;

    /** How many asks an account gets in {@see WINDOW} seconds. */
    public const MAX_PER_WINDOW = 3;

    public const WINDOW = 600;

    /**
     * How long an approval stays usable after the phone answers, in seconds. Counted from the
     * answer rather than the ask: a Yes at the end of the two minutes still has to reach the
     * waiting page and come back as its form.
     */
    public const CONSUME_WINDOW = 60;

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    /** Where the phone answers — the Account controller's action, relative to the site. */
    public const APPROVE_PATH = 'account/approve';

    public function __construct(private ?TrustedDevices $devices = null)
    {
    }

    /** Is the method switched on and able to reach anybody? */
    public function enabled(): bool
    {
        return (string) Settings::getSetting(self::ENABLED_SETTING, '0') === '1'
            && $this->canPush();
    }

    /**
     * The subscriptions an approval for this account would go to: browsers that are trusted
     * devices of the account, still trusted, and still subscribed.
     *
     * @return list<array<string, mixed>>
     */
    public function recipients(int $userId): array
    {
        if ($userId < 1 || !$this->devices()->allowedFor($userId)) {
            return [];
        }

        $devices = [];

        foreach ($this->devices()->forUser($userId) as $device) {
            $devices[(int) $device['device_id']] = $device;
        }

        if ($devices === []) {
            return [];
        }

        $rows = [];

        foreach ($this->subscriptionsFor($userId) as $row) {
            $deviceId = (int) ($row['trusted_device_id'] ?? 0);

            // The browser signing in is never asked to approve itself.
            if ($deviceId > 0 && isset($devices[$deviceId]) && !$devices[$deviceId]['is_current']) {
                $row['device_name'] = $devices[$deviceId]['name'];
                $row['trusted_via'] = (string) ($devices[$deviceId]['trusted_via'] ?? '');
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Could this account be asked right now? */
    public function availableFor(int $userId): bool
    {
        return $this->enabled() && $this->recipients($userId) !== [];
    }

    /**
     * Ask the account's trusted devices about the sign-in this browser is waiting on.
     *
     * Records the ask against this session, so the waiting page can follow it and only this
     * browser can finish with it. False when there is nobody to ask, when the account has
     * been asked too often, or when no notification could be sent.
     */
    public function start(int $userId): bool
    {
        $recipients = $this->enabled() ? $this->recipients($userId) : [];

        if ($recipients === [] || $this->recentAsks($userId) >= self::MAX_PER_WINDOW) {
            return false;
        }

        $token   = bin2hex(random_bytes(32));
        $now     = $this->now();
        $number  = $this->needsNumber($userId) ? random_int(10, 99) : null;
        $choices = $number === null ? [] : $this->choicesAround($number);

        $row = [
            'userid'         => $userId,
            'token_lookup'   => Token::lookup($token),
            'session_lookup' => $this->sessionLookup(),
            'number'         => $number,
            'choices'        => implode(',', $choices),
            'user_agent'     => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip'             => substr(\Pramnos\Http\Request::clientIp(), 0, 45),
            'country'        => substr(SignInRisk::country(), 0, 2),
            'created_at'     => $now,
            'expires_at'     => $now + self::TTL,
        ];

        try {
            $db = \Pramnos\Framework\Factory::getDatabase();
            $db->queryBuilder()->table(self::TABLE)->insert($row);
            $stored = $db->queryBuilder()->table(self::TABLE)
                ->where('token_lookup', $row['token_lookup'])
                ->first();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Could not record a sign-in approval: ' . $exception->getMessage(), 'auth');

            return false;
        }

        if (!$stored || ($stored->numRows ?? 0) < 1) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = (int) $stored->fields['approval_id'];

        $this->deliver($userId, $recipients, new Notifications\SignInApprovalNotification(
            SignInFingerprint::describe(SignInFingerprint::fromUserAgent($row['user_agent'])),
            $row['country'],
            $now,
            \Pramnos\Http\SiteUrl::to(self::APPROVE_PATH . '?token=' . $token),
            \Pramnos\Http\SiteUrl::to('push/ack?token=' . $token),
            \Pramnos\Http\SiteUrl::to('push/respond?token=' . $token),
            $number === null
        ));

        ActivityLog::record($userId, 'signin_approval_asked', [
            'devices' => count($recipients),
            'number'  => $number !== null,
        ]);

        return true;
    }

    /**
     * What the waiting page shows: whether the phone has it, and what it answered.
     *
     * `state` is `none` (nothing asked by this browser), `sent`, `delivered`, `approved`,
     * `denied` or `expired`. `number` is set when the phone has to pick it.
     *
     * @return array{state: string, number: int|null, devices: list<string>, expires_in: int}
     */
    public function status(int $userId): array
    {
        $row = $this->waiting($userId);

        if ($row === null) {
            return ['state' => 'none', 'number' => null, 'devices' => [], 'expires_in' => 0];
        }

        $state = match (true) {
            (string) $row['decision'] === self::APPROVED => self::APPROVED,
            (string) $row['decision'] === self::DENIED   => self::DENIED,
            (int) $row['expires_at'] <= $this->now()      => 'expired',
            $row['delivered_at'] !== null                 => 'delivered',
            default                                       => 'sent',
        };

        return [
            'state'      => $state,
            'number'     => $row['number'] === null ? null : (int) $row['number'],
            'devices'    => array_values(array_unique(array_map(
                static fn (array $recipient): string => (string) $recipient['device_name'],
                $this->recipients($userId)
            ))),
            'expires_in' => max(0, (int) $row['expires_at'] - $this->now()),
        ];
    }

    /**
     * The phone's receipt, sent by the service worker the moment the push arrives.
     *
     * The token is the credential: the worker may be running with no page open and no
     * guarantee of a session. All it can do is mark the ask as received.
     */
    public function acknowledge(string $token): bool
    {
        $row = $this->byToken($token);

        if ($row === null || (int) $row['expires_at'] <= $this->now() || $row['delivered_at'] !== null) {
            return $row !== null && $row['delivered_at'] !== null;
        }

        return $this->update((int) $row['approval_id'], ['delivered_at' => $this->now()]);
    }

    /**
     * An ask, as the answering phone shows it — or null when this browser may not answer it.
     *
     * @return array<string, mixed>|null
     */
    public function forAnswering(string $token, int $userId): ?array
    {
        $row = $this->byToken($token);

        if ($row === null || (int) $row['userid'] !== $userId || $this->devices()->current($userId) === null) {
            return null;
        }

        $row['browser']  = SignInFingerprint::describe(SignInFingerprint::fromUserAgent((string) $row['user_agent']));
        $row['open']     = $row['decision'] === null && (int) $row['expires_at'] > $this->now();
        $row['choices']  = $row['choices'] === '' ? [] : array_map('intval', explode(',', (string) $row['choices']));

        return $row;
    }

    /**
     * The phone's answer.
     *
     * Only from a browser signed in as the account and trusted by it. When the ask needs a
     * number, approving with the wrong one is taken as a refusal — somebody guessing is the
     * case the number exists for — and approving with none (the lock screen's button, which
     * such an ask does not carry) changes nothing.
     *
     * The first answer stands. Two arriving together — two devices, or the notification's
     * No and the page's Yes — are settled by the database, not by whichever writes last, so
     * a refusal cannot be overwritten by an approval.
     *
     * @return string `approved`, `denied`, `expired` or `invalid`
     */
    public function decide(string $token, int $userId, string $decision, ?int $picked = null): string
    {
        $row    = $this->byToken($token);
        $device = $userId > 0 ? $this->devices()->current($userId) : null;

        if ($row === null || $device === null || (int) $row['userid'] !== $userId
            || !in_array($decision, [self::APPROVED, self::DENIED], true)
        ) {
            return 'invalid';
        }

        if ($row['decision'] !== null) {
            return (string) $row['decision'];
        }

        if ((int) $row['expires_at'] <= $this->now()) {
            return 'expired';
        }

        if ($decision === self::APPROVED && $row['number'] !== null) {
            if ($picked === null) {
                return 'invalid';
            }

            if ($picked !== (int) $row['number']) {
                $decision = self::DENIED;
            }
        }

        $recorded = $this->claim((int) $row['approval_id'], 'decision', [
            'decision'          => $decision,
            'decided_at'        => $this->now(),
            'decided_device_id' => (int) $device['device_id'],
            // Answered means it arrived, whether or not the receipt got here first.
            'delivered_at'      => $row['delivered_at'] ?? $this->now(),
        ]);

        if (!$recorded) {
            // Somebody answered first, or the write failed: report what is stored.
            $stored = $this->byToken($token);

            return $stored !== null && $stored['decision'] !== null ? (string) $stored['decision'] : 'invalid';
        }

        $this->devices()->touch((int) $device['device_id']);

        if ($decision === self::DENIED) {
            ActivityLog::record($userId, 'signin_denied', [
                'ip'      => (string) $row['ip'],
                'country' => (string) $row['country'],
            ]);
            // The password is known to somebody who is not the owner. Mail, not push: it
            // has to be there tomorrow, and it has to reach the owner if the phone is gone.
            SecurityChangeNotifier::notify($userId, SecurityChangeNotifier::SIGNIN_DENIED);
        } else {
            ActivityLog::record($userId, 'signin_approved', ['device_id' => (int) $device['device_id']]);
        }

        return $decision;
    }

    /**
     * Use this browser's approval to finish its sign-in — once, and within
     * {@see CONSUME_WINDOW} seconds of the answer. Two submits racing each other get one
     * sign-in between them.
     */
    public function consume(int $userId): bool
    {
        $row = $this->waiting($userId);

        if ($row === null || (string) $row['decision'] !== self::APPROVED || $row['consumed_at'] !== null
            || (int) $row['decided_at'] + self::CONSUME_WINDOW <= $this->now()
        ) {
            return false;
        }

        unset($_SESSION[self::SESSION_KEY]);

        return $this->claim((int) $row['approval_id'], 'consumed_at', ['consumed_at' => $this->now()]);
    }

    // ── Internals and seams ──────────────────────────────────────────────────────

    /**
     * The ask this browser's session is waiting on, for this account.
     *
     * @return array<string, mixed>|null
     */
    protected function waiting(int $userId): ?array
    {
        $id = (int) ($_SESSION[self::SESSION_KEY] ?? 0);

        if ($id < 1 || $userId < 1) {
            return null;
        }

        try {
            $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('approval_id', $id)
                ->where('userid', $userId)
                ->where('session_lookup', $this->sessionLookup())
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $row && ($row->numRows ?? 0) > 0 ? (array) $row->fields : null;
    }

    /** @return array<string, mixed>|null */
    protected function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        try {
            $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('token_lookup', Token::lookup($token))
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $row && ($row->numRows ?? 0) > 0 ? (array) $row->fields : null;
    }

    /** @param array<string, mixed> $values */
    protected function update(int $approvalId, array $values): bool
    {
        try {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('approval_id', $approvalId)
                ->update($values);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Write $values only while $column is still empty, and say whether this call was the one
     * that wrote it — the row's own guard against two answers, or two uses, at once.
     *
     * @param array<string, mixed> $values
     */
    protected function claim(int $approvalId, string $column, array $values): bool
    {
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('approval_id', $approvalId)
                ->whereNull($column)
                ->update($values);
        } catch (\Throwable) {
            return false;
        }

        return $result instanceof \Pramnos\Database\Result && $result->getAffectedRows() === 1;
    }

    protected function recentAsks(int $userId): int
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::TABLE)
                ->where('userid', $userId)
                ->where('created_at', '>', $this->now() - self::WINDOW)
                ->count();
        } catch (\Throwable) {
            // Unknown is treated as too many: an ask that cannot be counted is not sent.
            return self::MAX_PER_WINDOW;
        }
    }

    /**
     * Three numbers, the right one among them, in no telling order.
     *
     * @return list<int>
     */
    protected function choicesAround(int $number): array
    {
        $choices = [$number];

        while (count($choices) < 3) {
            $other = random_int(10, 99);

            if (!in_array($other, $choices, true)) {
                $choices[] = $other;
            }
        }

        shuffle($choices);

        return $choices;
    }

    /** The site's {@see NUMBER_SETTING}, `risk` unless it says otherwise. */
    public static function numberPolicy(): string
    {
        $policy = (string) Settings::getSetting(self::NUMBER_SETTING, 'risk');

        return in_array($policy, self::NUMBER_POLICIES, true) ? $policy : 'risk';
    }

    /**
     * Must the phone pick a number for this attempt?
     *
     * Yes, unless the site allows the plain Yes and this is a browser the account trusted
     * before, signing in with nothing unusual about it.
     */
    protected function needsNumber(int $userId): bool
    {
        return self::numberPolicy() === 'always'
            || !$this->devices()->known($userId)
            || $this->looksRisky($userId);
    }

    /** Anything {@see SignInRisk} finds unusual about this sign-in. */
    protected function looksRisky(int $userId): bool
    {
        return SignInRisk::assess($userId) !== [];
    }

    protected function sessionLookup(): string
    {
        return hash('sha256', (string) session_id());
    }

    /** @return list<array<string, mixed>> */
    protected function subscriptionsFor(int $userId): array
    {
        return Subscriptions::forUser($userId);
    }

    /** @param list<array<string, mixed>> $recipients */
    protected function deliver(int $userId, array $recipients, Notifications\SignInApprovalNotification $notification): void
    {
        (new ApprovalPushChannel($recipients))->send((object) ['userid' => $userId], $notification);
    }

    protected function canPush(): bool
    {
        return \Pramnos\Push\Vapid::configured()
            && class_exists(ltrim(\Pramnos\Notification\Channels\PushChannel::LIBRARY, '\\'));
    }

    protected function devices(): TrustedDevices
    {
        return $this->devices ??= new TrustedDevices();
    }

    protected function now(): int
    {
        return time();
    }
}
