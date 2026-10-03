<?php

declare(strict_types=1);

namespace Pramnos\Push;

use Pramnos\Notification\Channels\PushChannel;
use Pramnos\Push\Notifications\TestPushNotification;

/**
 * Send a test notification to a person's devices, and learn which of them received it.
 *
 * ```php
 * $tests = (new TestPush())->send($userId);           // every browser the account has
 * $tests = (new TestPush())->send($userId, $hash);    // one of them
 * (new TestPush())->status($tests[0]['token']);       // ['received_at' => 1759474010, ...]
 * ```
 *
 * A push log `201` says the push service accepted a message, not that a phone showed it, and
 * "I got nothing" is ambiguous between the two. A test settles it. It goes through the same
 * {@see PushChannel} every notification uses — so it is recorded in the push log like any
 * other — and each device gets its own copy with its own receipt address. The service worker
 * posts that receipt the moment the push arrives, so a device that received it says so even
 * when it did not show it, which points at the phone's notification settings rather than at
 * the delivery.
 */
class TestPush
{
    /** The receipts. */
    public const TABLE = 'pramnos.pushtests';

    /** How long a device has to send its receipt. */
    public const RECEIPT_WINDOW = 3600;

    /**
     * Send one test to each of the account's browsers, or to the one with this hash.
     *
     * @return list<array{token: string, endpoint_hash: string, user_agent: string, status: int,
     *                    outcome: array{kind: string, label: string, explanation: string}}>
     *         One entry per device; empty when the account has no such browser subscribed.
     */
    public function send(int $userId, ?string $endpointHash = null): array
    {
        $tests = [];

        foreach ($this->subscriptions($userId) as $subscription) {
            $hash = (string) $subscription['endpoint_hash'];
            if ($endpointHash !== null && $hash !== $endpointHash) {
                continue;
            }

            $token = bin2hex(random_bytes(16));
            $this->db()->queryBuilder()->table(self::TABLE)->insert([
                'token_hash'    => hash('sha256', $token),
                'userid'        => $userId,
                'endpoint_hash' => $hash,
                'sent_at'       => time(),
                'expires_at'    => time() + self::RECEIPT_WINDOW,
            ]);

            $this->channelFor($subscription)->send(
                (object) ['userid' => $userId],
                new TestPushNotification($token, \Pramnos\Http\SiteUrl::to('push/testack?token=' . $token))
            );

            $logged = Log::recent(1, ['endpoint_hash' => $hash, 'tag' => TestPushNotification::TAG_PREFIX . $token]);
            $row    = $logged[0] ?? ['status' => 0, 'endpoint_hash' => $hash];

            $tests[] = [
                'token'         => $token,
                'endpoint_hash' => $hash,
                'user_agent'    => (string) ($subscription['user_agent'] ?? ''),
                'status'        => (int) ($row['status'] ?? 0),
                'outcome'       => Log::outcome($row),
            ];
        }

        return $tests;
    }

    /**
     * The device's receipt: true when it is recorded now or already was.
     *
     * The token is the credential, because the service worker posting it may have no page and
     * no session. All it can do is mark its own test as received.
     */
    public function acknowledge(string $token): bool
    {
        $row = $this->row($token);
        if ($row === null) {
            return false;
        }
        if ($row['received_at'] !== null) {
            return true;
        }
        if ((int) $row['expires_at'] < time()) {
            return false;
        }

        $this->db()->queryBuilder()->table(self::TABLE)
            ->where('token_hash', hash('sha256', $token))->update(['received_at' => time()]);

        return true;
    }

    /**
     * Where a test stands, or null when there is no such test.
     *
     * @return array{token: string, userid: int, endpoint_hash: string, sent_at: int, received_at: ?int, expired: bool}|null
     */
    public function status(string $token): ?array
    {
        $row = $this->row($token);
        if ($row === null) {
            return null;
        }

        return [
            'token'         => $token,
            'userid'        => (int) $row['userid'],
            'endpoint_hash' => (string) $row['endpoint_hash'],
            'sent_at'       => (int) $row['sent_at'],
            'received_at'   => $row['received_at'] === null ? null : (int) $row['received_at'],
            'expired'       => $row['received_at'] === null && (int) $row['expires_at'] < time(),
        ];
    }

    /**
     * The token of a test notification's push log row, or null when the row is not a test.
     *
     * @param array<string, mixed> $logRow
     */
    public static function tokenOf(array $logRow): ?string
    {
        $tag = (string) ($logRow['tag'] ?? '');

        return str_starts_with($tag, TestPushNotification::TAG_PREFIX)
            ? substr($tag, strlen(TestPushNotification::TAG_PREFIX))
            : null;
    }

    /**
     * The account's subscriptions, as a seam.
     *
     * @return list<array<string, mixed>>
     */
    protected function subscriptions(int $userId): array
    {
        return Subscriptions::forUser($userId);
    }

    /**
     * The push channel, limited to one subscription, as a seam.
     *
     * The channel sends to every subscription an account has; a test has to reach one device
     * at a time, with its own receipt address.
     *
     * @param array<string, mixed> $subscription
     */
    protected function channelFor(array $subscription): PushChannel
    {
        return new class ($subscription) extends PushChannel {
            /** @param array<string, mixed> $only */
            public function __construct(private array $only)
            {
            }

            /** @return list<array<string, mixed>> */
            protected function subscriptionsFor(int $userId): array
            {
                return [$this->only];
            }
        };
    }

    /** @return array<string, mixed>|null */
    private function row(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }

        try {
            // By its hash: the token itself is never stored.
            $result = $this->db()->queryBuilder()->table(self::TABLE)
                ->where('token_hash', hash('sha256', $token))->first();
        } catch (\Throwable) {
            return null;
        }

        return $result && $result->numRows > 0 ? (array) $result->fields : null;
    }

    private function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
