<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Webhooks;

use Pramnos\Broadcasting\Apps\BroadcastApp;

/**
 * Builds and verifies the signed HTTP payload of a webhook delivery.
 *
 * The format is Pusher's, so an application that already verifies Pusher webhooks
 * needs no new code: a JSON body of `{time_ms, events}`, an `X-Pusher-Key` header
 * naming the app, and an `X-Pusher-Signature` header carrying
 * `hash_hmac('sha256', <raw body>, <app secret>)`.
 *
 * **The signature covers the raw body, and verification must use the raw body
 * too.** Re-encoding a decoded payload before checking it changes key order and
 * escaping, so a delivery nobody tampered with stops verifying — the same
 * canonicalisation trap as presence `channel_data`, and the reason
 * {@see verify()} takes a string rather than an array.
 */
final class WebhookSigner
{
    public function __construct(private readonly BroadcastApp $app)
    {
    }

    /**
     * The JSON body for a batch of events.
     *
     * `time_ms` is included because a receiver needs it to discard a delivery that
     * has been retried for so long that its meaning has expired — a `member_added`
     * from four minutes ago is not news.
     *
     * @param list<array<string,mixed>> $events
     * @param int|null $timeMs Injectable so a caller (or a test) can control the
     *                         stamp rather than have it read a clock here.
     */
    public function body(array $events, ?int $timeMs = null): string
    {
        return (string) json_encode([
            'time_ms' => $timeMs ?? (int) round(microtime(true) * 1000),
            'events'  => $events,
        ]);
    }

    /**
     * Headers for a POST carrying $body.
     *
     * @return array<string,string>
     */
    public function headers(string $body): array
    {
        return [
            'Content-Type'        => 'application/json',
            'X-Pusher-Key'        => $this->app->key,
            'X-Pusher-Signature'  => hash_hmac('sha256', $body, $this->app->secret),
        ];
    }

    /**
     * Verify a received delivery.
     *
     * @param string $body      The **raw** request body, exactly as received.
     * @param string $signature The X-Pusher-Signature header value.
     */
    public function verify(string $body, string $signature): bool
    {
        if ($signature === '' || !$this->app->canSign()) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $this->app->secret), $signature);
    }
}
