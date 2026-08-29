<?php

declare(strict_types=1);

namespace Pramnos\Notification\Channels;

use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Push\Log;
use Pramnos\Push\Subscriptions;
use Pramnos\Push\Vapid;

/**
 * Web push: a notification on a device whose browser is closed.
 *
 * The framework holds **no connection** to anybody. The browser keeps one to its vendor's push
 * service — FCM, Mozilla autopush, APNs — shared across every site it has ever subscribed to, and
 * this sends one HTTPS request per subscription to an address that service gave out. Zero cost
 * while idle, works with the browser shut, about a second of latency. The opposite trade from the
 * WebSocket transport beside it, and the reason both exist.
 *
 * ### The encryption is not written here
 *
 * RFC 8291 is ECDH against the browser's key, HKDF, AES-128-GCM and a padding scheme, with RFC
 * 8292's JWT on top. `minishlink/web-push` implements all of it and is **suggested rather than
 * required**: a framework that pulled a push library into every application's `vendor/` would
 * impose it on every project that never sends a notification. Without the library this channel
 * says so, once, and does nothing — which is a better failure than a message that silently never
 * arrives.
 *
 * ### Two answers that must not be confused
 *
 * A `404` or `410` from the push service means the subscription is **dead** — uninstalled,
 * cleared, revoked — and the row is deleted. A `429` or a `5xx` means the service is busy, and
 * the row is kept. Treating the first as retryable fills the table with dead endpoints that cost
 * a full HTTPS round trip on every future send; treating the second as fatal silently unsubscribes
 * a live user, which is the failure nobody reports because it looks like nothing happening.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class PushChannel implements ChannelInterface
{
    /**
     * The library that does the encryption, and its subscription value object.
     *
     * Read through `libraryClass()` / `subscriptionClass()` rather than directly, so a test can
     * substitute a double for them.
     */
    public const LIBRARY = '\Minishlink\WebPush\WebPush';

    public const SUBSCRIPTION = '\Minishlink\WebPush\Subscription';

    /**
     * How long a push service should hold an undelivered message, in seconds.
     *
     * Four hours rather than the four weeks a service will default to. These are notifications:
     * «somebody signed in to your account» arriving three days late is not information, it is
     * confusion — and a notification that cannot be acted on is worse than none.
     */
    public const TTL = 14400;

    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        if (!method_exists($notification, 'toPush')) {
            return;
        }

        $userId = $this->resolveUserId($notifiable);

        if ($userId === null) {
            return;
        }

        /*
         * The name of the notification, for the log.
         *
         * Read before anything can return, because every one of the four refusals below is a
         * row somebody will later be reading to find out *which* notification never arrived.
         */
        $name = $notification::class;
        $data = $notification->toPush($notifiable);

        $subscriptions = $this->subscriptionsFor($userId);

        if ($subscriptions === []) {
            /*
             * Not a failure, and not nothing either.
             *
             * It is the ordinary case — most accounts have never granted permission — and it is
             * also the single commonest answer to "why did they not get the notification". A
             * silent return leaves that question unanswerable from any table.
             */
            $this->refuse($userId, Log::NO_SUBSCRIPTION, $data, $name);

            return;
        }

        $vapid = $this->vapid();

        if ($vapid === null) {
            \Pramnos\Logs\Logger::log(
                'A push notification was not sent: this installation has no VAPID key pair. '
                . 'Run `push:vapid-generate` once.',
                'push'
            );
            $this->refuse($userId, Log::NO_KEYS, $data, $name);

            return;
        }

        if (!class_exists($this->libraryClass())) {
            \Pramnos\Logs\Logger::log(
                'A push notification was not sent: the payload encryption library is not '
                . 'installed. `composer require minishlink/web-push:^11.0`. It is suggested rather '
                . 'than required so that applications which send no notifications do not carry '
                . 'it.',
                'push'
            );
            $this->refuse($userId, Log::NO_LIBRARY, $data, $name);

            return;
        }

        $payload = $this->payload($data);

        if ($payload === '') {
            $this->refuse($userId, Log::NO_PAYLOAD, $data, $name);

            return;
        }

        $this->deliver($subscriptions, $payload, $vapid, $userId, $name);
    }

    /**
     * The two class names the delivery goes through.
     *
     * Methods rather than the constants read directly, so a test can point them at a double.
     * Nothing else can reach `deliver()`: the library is a composer *suggestion*, so on a
     * checkout that has not installed it — which is this framework's own — every line that
     * queues and flushes is unreachable, and the batching those lines exist for is the part
     * most worth being sure of.
     */
    protected function libraryClass(): string
    {
        return ltrim(static::LIBRARY, '\\');
    }

    protected function subscriptionClass(): string
    {
        return ltrim(static::SUBSCRIPTION, '\\');
    }

    /**
     * The subscriptions to send to, and the key pair to sign with.
     *
     * Two seams over static calls to the filesystem and the database, so `send()`'s decisions —
     * which are all about what is *missing* — can be asserted without either.
     *
     * @return list<array<string, mixed>>
     */
    protected function subscriptionsFor(int $userId): array
    {
        return Subscriptions::forUser($userId);
    }

    /** @return array{publicKey: string, privateKey: string, subject: string}|null */
    protected function vapid(): ?array
    {
        return Vapid::load();
    }

    /**
     * Record what the push service said about one subscription.
     *
     * A seam for the same reason, and the one that matters most: this is where a 410 deletes a
     * row.
     */
    protected function record(string $endpoint, int $status): void
    {
        Subscriptions::recordResult($endpoint, $status);
    }

    /**
     * Write one attempt to the push log.
     *
     * A seam, and the reason it is one is that this is called from inside a send: a test that
     * asserts what was delivered must not need a `pushlog` table to do it.
     *
     * @param array<string, mixed> $payload
     */
    protected function log(
        int $userId,
        string $endpoint,
        array $payload,
        int $status,
        string $notification
    ): void {
        Log::record($userId, hash('sha256', $endpoint), $payload, $status, $notification);
    }

    /**
     * Write the reason a send stopped before it reached anybody.
     *
     * @param array<string, mixed> $payload
     */
    protected function refuse(
        int $userId,
        string $reason,
        array $payload,
        string $notification
    ): void {
        Log::refused($userId, $reason, $payload, $notification);
    }

    /**
     * Send one payload to every subscription, and act on each answer.
     *
     * @param list<array<string, mixed>> $subscriptions
     * @param array{publicKey: string, privateKey: string, subject: string} $vapid
     */
    protected function deliver(
        array $subscriptions,
        string $payload,
        array $vapid,
        int $userId = 0,
        string $notification = ''
    ): void {
        $library      = $this->libraryClass();
        $subscription = $this->subscriptionClass();

        try {
            /*
             * One `WebPush` instance for the whole batch, and `flush()` at the end.
             *
             * The library sends with `curl_multi`, so the requests go out in parallel. Sent one
             * at a time, ten thousand subscriptions is ten thousand sequential TLS handshakes —
             * and the handshakes, not the encryption, are what makes a large send slow.
             */
            $push = new $library(['VAPID' => [
                'subject'    => $vapid['subject'],
                'publicKey'  => $vapid['publicKey'],
                'privateKey' => $vapid['privateKey'],
            ]]);

            $push->setDefaultOptions(['TTL' => static::TTL, 'urgency' => 'normal']);

            foreach ($subscriptions as $row) {
                $push->queueNotification(
                    $subscription::create([
                        'endpoint'        => (string) $row['endpoint'],
                        'publicKey'       => (string) $row['p256dh'],
                        'authToken'       => (string) $row['auth_secret'],
                        'contentEncoding' => (string) ($row['content_encoding'] ?: 'aes128gcm'),
                    ]),
                    $payload
                );
            }

            $logged = json_decode($payload, true);
            $logged = is_array($logged) ? $logged : [];

            foreach ($push->flush() as $report) {
                $endpoint = (string) $report->getEndpoint();
                $status   = $this->statusOf($report);

                $this->record($endpoint, $status);
                $this->log($userId, $endpoint, $logged, $status, $notification);
            }
        } catch (\Throwable $exception) {
            // One failed batch must not take down whatever queued it.
            \Pramnos\Logs\Logger::log(
                'Push delivery failed: ' . $exception->getMessage(),
                'push'
            );
        }
    }

    /**
     * The status a report carries, whatever shape the library gives it in.
     *
     * `getResponse()` is null when the request never reached a server at all — a DNS failure, a
     * timeout — and that is emphatically not a `410`. Guessing it as one would delete a live
     * subscription because the network hiccuped.
     */
    protected function statusOf(object $report): int
    {
        if (method_exists($report, 'isSuccess') && $report->isSuccess()) {
            return 200;
        }

        if (method_exists($report, 'getResponse')) {
            $response = $report->getResponse();

            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                return (int) $response->getStatusCode();
            }
        }

        // Failed with no status: keep the subscription and count it as one bad attempt.
        return 0;
    }

    /**
     * The JSON the service worker receives.
     *
     * Capped, because a push payload is limited to about 4KB **after encryption** and a service
     * that receives more simply rejects it. `tag` is passed through deliberately: two
     * notifications with the same tag replace one another instead of stacking, which is the
     * difference between one "new sign-in" and fourteen.
     *
     * @param array<string, mixed> $data
     */
    protected function payload(array $data): string
    {
        $payload = array_filter([
            'title'   => substr((string) ($data['title'] ?? ''), 0, 120),
            'body'    => substr((string) ($data['body'] ?? ''), 0, 400),
            'url'     => (string) ($data['url'] ?? ''),
            'icon'    => (string) ($data['icon'] ?? ''),
            'badge'   => (string) ($data['badge'] ?? ''),
            'tag'     => (string) ($data['tag'] ?? ''),
            'actions' => array_slice((array) ($data['actions'] ?? []), 0, 2),
            'data'    => (array) ($data['data'] ?? []),
        ], static fn ($value): bool => $value !== '' && $value !== []);

        if (!isset($payload['title'])) {
            // A notification with no title renders as the site's name and nothing else.
            return '';
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }

    /**
     * Which account this notifiable is.
     *
     * The same order the database channel uses, so a notifiable that works for one works for the
     * other.
     */
    protected function resolveUserId(mixed $notifiable): ?int
    {
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            $routed = $notifiable->routeNotificationFor('push');

            if (is_numeric($routed)) {
                return (int) $routed;
            }
        }

        foreach (['userid', 'id'] as $property) {
            if (is_object($notifiable) && isset($notifiable->$property)) {
                return (int) $notifiable->$property;
            }
        }

        return null;
    }
}
