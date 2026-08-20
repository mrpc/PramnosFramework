<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

/**
 * A driver that can carry "everyone on this channel except one connection".
 *
 * This is what `toOthers()` needs. An application that has already rendered a
 * change optimistically does not want the broadcast of that change back — it would
 * render it twice — and the only thing that identifies the originating connection
 * is the socket id its client got at handshake.
 *
 * ## Why a separate interface rather than a fourth parameter
 *
 * {@see DriverInterface::broadcast()} cannot grow one. The Realtime guide invites
 * applications to write their own drivers — "implementing SubscribableDriverInterface
 * is all a KafkaDriver needs" — and PHP requires an overriding declaration to stay
 * compatible, so a new parameter would fatal every one of those on upgrade.
 *
 * ## Why the exclusion travels inside the envelope
 *
 * A driver that implements this adds an `except` key to the `{event, payload,
 * timestamp}` envelope it already writes. That is what lets the exclusion survive
 * the hop: the process that publishes and the process that fans out to browsers are
 * not the same one, and anything held only in PHP memory is gone by the time the
 * WebSocket edge sees the event. Consumers that predate the key ignore it, because
 * envelope decoding reads by key.
 *
 * A driver that does **not** implement this simply broadcasts to everyone — the
 * behaviour it has today. {@see \Pramnos\Broadcasting\BroadcastingManager} logs
 * when an exclusion was asked for and could not be honoured, because the visible
 * symptom is a duplicated item in one user's UI and nothing else.
 */
interface ExcludesSocketInterface extends DriverInterface
{
    /**
     * Broadcast to a channel, excluding one connection.
     *
     * @param string               $channel
     * @param string               $event
     * @param array<string, mixed> $payload
     * @param string|null          $exceptSocketId Null behaves exactly like
     *                                             {@see DriverInterface::broadcast()}.
     */
    public function broadcastExcept(
        string $channel,
        string $event,
        array $payload,
        ?string $exceptSocketId
    ): void;
}
