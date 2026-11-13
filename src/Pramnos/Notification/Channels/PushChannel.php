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

        if (!self::sends()) {
            $this->refuse($userId, Log::SWITCHED_OFF, $data, $name);

            return;
        }

        /*
         * The subscriptions of the app the notification is for (`pushApp()`, an assistant's app
         * on another origin), or the site's own where it names none — so neither reaches the
         * other's devices.
         */
        $app           = method_exists($notification, 'pushApp') ? (string) $notification->pushApp($notifiable) : '';
        $subscriptions = array_values(array_filter($this->subscriptionsFor($userId), static fn (array $one): bool => (string) ($one['app'] ?? '') === $app));

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

        /*
         * A notification that knows which client its person is in (`pushClient()`, a conversation's
         * message) goes to that client's subscription alone — and to any subscription made by a
         * page that names no client, which is told as before. Null: every subscription.
         */
        $client = method_exists($notification, 'pushClient') ? $notification->pushClient($notifiable) : null;
        if (is_string($client) && $client !== '') {
            $subscriptions = array_values(array_filter($subscriptions, static fn (array $one): bool => ($one['client'] ?? null) === null || $one['client'] === '' || $one['client'] === $client));
            if ($subscriptions === []) {
                $this->refuse($userId, Log::ELSEWHERE, $data, $name);

                return;
            }
        }

        /*
         * FW-495: a notification that opts in (`heldFor()`, a conversation's) is not sent to a
         * device that already has an earlier one of its own kind sitting there unopened —
         * piling more on top of one nobody has looked at is how Chrome on Android ends up
         * auto-revoking the permission.
         */
        if (method_exists($notification, 'heldFor')) {
            $subscriptions = array_values(array_filter($subscriptions, static fn (array $one): bool => !$notification->heldFor($one, $userId)));
            if ($subscriptions === []) {
                $this->refuse($userId, Log::HELD_UNOPENED, $data, $name);

                return;
            }
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
     * How soon the push service should wake the device: `normal` for news, `high` for
     * something a person is waiting on — a sign-in approval — which a phone in power saving
     * would otherwise hold back for minutes.
     */
    protected function urgency(): string
    {
        return 'normal';
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
        // Every app's: send() keeps the ones of the app the notification is for.
        return Subscriptions::forUser($userId, Subscriptions::EVERY_APP);
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

            $push->setDefaultOptions(['TTL' => static::TTL, 'urgency' => $this->urgency()]);

            $logged = json_decode($payload, true);
            $logged = is_array($logged) ? $logged : [];

            /*
             * One row per subscription, reserved **before** queueing — its id and a token of its
             * own have to already exist to ride inside the payload that subscription receives
             * (`withPushId()`). The token, generated here rather than read back, is what stops
             * `pushid` — a plain auto-increment — from letting a report against row 41 be made by
             * guessing 42: `Log::verifyToken()` is the only thing that accepts a report, and it
             * accepts nothing without one. Keyed by endpoint, which `flush()`'s reports are
             * matched back by below.
             */
            $reserved = [];
            foreach ($subscriptions as $row) {
                $endpoint = (string) $row['endpoint'];
                $token    = bin2hex(random_bytes(16));
                $pushId   = Log::reserve($userId, hash('sha256', $endpoint), $logged, $notification, $token);
                $reserved[$endpoint] = $pushId;

                $push->queueNotification(
                    $subscription::create([
                        'endpoint'        => $endpoint,
                        'publicKey'       => (string) $row['p256dh'],
                        'authToken'       => (string) $row['auth_secret'],
                        'contentEncoding' => (string) ($row['content_encoding'] ?: 'aes128gcm'),
                    ]),
                    $this->withPushId($payload, $pushId, $token)
                );
            }

            foreach ($push->flush() as $report) {
                $endpoint = (string) $report->getEndpoint();
                $status   = $this->statusOf($report);

                $this->record($endpoint, $status);
                Log::complete($reserved[$endpoint] ?? 0, $status);
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
            'icon'    => (string) ($data['icon'] ?? self::iconUrl('icon')),
            'badge'   => (string) ($data['badge'] ?? self::iconUrl('badge')),
            'tag'     => (string) ($data['tag'] ?? ''),
            'actions' => array_slice((array) ($data['actions'] ?? []), 0, 2),
            'data'    => (array) ($data['data'] ?? []),
        ], static fn ($value): bool => $value !== '' && $value !== []);

        if (!isset($payload['title'])) {
            // A notification with no title renders as the site's name and nothing else.
            return '';
        }
        // Without the system's sound, where the page or the application plays its own; and not
        // shown at all while a page of the site is in front, which tells of it itself.
        foreach (['silent', 'unlessFocused'] as $flag) {
            if (!empty($data[$flag])) {
                $payload[$flag] = true;
            }
        }
        // Quiet only while a page of the site is open to play its own sound: the worker decides.
        if (($data['silent'] ?? null) === 'ifOpen') {
            $payload['silent'] = 'ifOpen';
        }

        /*
         * Where the worker posts `notificationclick` / `notificationclose` back to (FW-495) — an
         * absolute URL, the same way `data.ack` already carries one for a sign-in approval: a
         * service worker has no page to build one from. `withPushId()` adds the row's own id
         * beside it once it exists, which is after this payload is built.
         */
        if (\Pramnos\Http\SiteUrl::isConfigured()) {
            $payload['data'] = (array) ($payload['data'] ?? []) + ['report' => \Pramnos\Http\SiteUrl::to('push/report')];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }

    /**
     * `$payload` with this attempt's own row id and its one-time token folded into
     * `data.pushid` / `data.token`, so the device that receives it can later say "this one was
     * opened" or "closed" back to {@see \Pramnos\Push\Log::opened()} / {@see
     * \Pramnos\Push\Log::dismissed()} — the only hook the server has, since nothing here keeps a
     * connection to the browser once this returns. The token is what the report endpoint checks
     * ({@see \Pramnos\Push\Log::verifyToken()}): `pushid` is a plain auto-increment, and without
     * it a report could be made against any row by guessing its neighbour's id.
     */
    protected function withPushId(string $payload, int $pushId, string $token = ''): string
    {
        if ($pushId < 1) {
            return $payload;
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return $payload;
        }
        $decoded['data'] = (array) ($decoded['data'] ?? []) + ['pushid' => $pushId] + ($token !== '' ? ['token' => $token] : []);
        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? $payload : $json;
    }

    /**
     * Whether this installation sends push at all: `'push' => ['send' => false]` in app.php
     * switches it off — for development and tests, where a subscribed browser would otherwise be
     * woken by every run. A callable is asked each time, so an application's own setting decides:
     * `'send' => static fn (): bool => Settings::getSetting('send_push') === 'yes'`. On by default.
     * What is not sent is recorded in the push log as switched off.
     */
    public static function sends(): bool
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $send = is_object($app) ? ($app->applicationInfo['push']['send'] ?? true) : true;

        return (bool) (is_callable($send) ? $send() : $send);
    }

    /** The defaults when app.php names none: under the web root, beside the worker's own. */
    public const ICONS = ['icon' => 'assets/img/icon-192.png', 'badge' => 'assets/img/badge-72.png'];

    /**
     * `push.icon` or `push.badge` in app.php — a path under the web root, or an address — or the
     * default of {@see ICONS}.
     */
    public static function iconSetting(string $which): string
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $value = is_object($app) ? ($app->applicationInfo['push'][$which] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : (self::ICONS[$which] ?? '');
    }

    /**
     * The icon a notification carries: the configured one, as an address of this site — the site
     * may live in a folder, where `/assets/…` would be somebody else's. '' when nothing is
     * configured, so the worker's own default, worked out from its scope, applies.
     */
    protected static function iconUrl(string $which): string
    {
        $app = \Pramnos\Application\Application::currentInstance();
        if (!is_object($app) || !is_string($app->applicationInfo['push'][$which] ?? null)) {
            return '';
        }
        $value = self::iconSetting($which);

        return preg_match('#^https?://#i', $value) === 1 || !\Pramnos\Http\SiteUrl::isConfigured() ? $value : \Pramnos\Http\SiteUrl::to(ltrim($value, '/'));
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
