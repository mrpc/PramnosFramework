<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Webhooks;

/**
 * Where the WebSocket server sends its lifecycle notifications.
 *
 * The server emits five events — `channel_occupied`, `channel_vacated`,
 * `member_added`, `member_removed`, `client_event` — and knows nothing about how
 * they leave the process. That separation is not tidiness: **the daemon is a
 * single-threaded `stream_select()` loop, and an outbound HTTP request inside it
 * stalls every connected client for the duration of that request.** A slow or
 * unreachable webhook endpoint would become a realtime outage.
 *
 * So a dispatcher must hand the events off without waiting. {@see QueueWebhookDispatcher}
 * pushes them onto a Redis queue for a worker to deliver, which is the shipped
 * answer. A dispatcher that calls `curl` synchronously would work in development
 * and take the server down in production.
 *
 * ## What these are for
 *
 * They are how an application learns things it otherwise cannot: that a room has
 * become empty and its state can be torn down, that a user's last connection went
 * away, that somebody is typing. Before them, the only route was polling from an
 * `onTick` callback, which counts channels rather than observing transitions and
 * fires on a timer rather than on the event.
 */
interface WebhookDispatcherInterface
{
    /**
     * Hand off a batch of events.
     *
     * Batched because several can happen in one loop iteration — a client
     * disconnecting from three channels produces three — and because Pusher's own
     * payload format carries a list. Implementations must return promptly.
     *
     * @param list<array<string,mixed>> $events Each has a `name` and a `channel`;
     *        member events add `user_id`, client events add `event` and `data`.
     */
    public function dispatch(array $events): void;
}
