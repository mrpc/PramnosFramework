<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * Contract for backplane drivers that can also be *consumed* from.
 *
 * A plain {@see DriverInterface} only publishes (fan-out to an external
 * transport such as Pusher, or to a log file). A subscribable driver is a true
 * message backplane: the same process can both broadcast() events and
 * subscribe() to receive them. This is what lets an SSE stream or the built-in
 * WebSocket server sit on top of Redis / a database table / Kafka without any of
 * them knowing which backplane is in use.
 *
 * Implementations MUST publish and consume a symmetric envelope so that an event
 * broadcast() on a channel is delivered to subscribe() callers on that channel
 * with the same ($channel, $event, $payload) it was sent with.
 */
interface SubscribableDriverInterface extends DriverInterface
{
    /**
     * Block and consume events from the given channels.
     *
     * For every event received, $onEvent is invoked as:
     *     fn(string $channel, string $event, array $payload): bool|void
     * Returning false from $onEvent stops the loop and returns from subscribe().
     *
     * The loop also honours {@see SubscriptionOptions}: it surfaces an idle tick
     * every readTimeout seconds (so callers can ping / check liveness), stops
     * when onIdle returns false or maxRuntime is exceeded, and transparently
     * reconnects on transient backplane errors.
     *
     * @param string[]                 $channels Logical channel names (no transport prefix).
     * @param callable                 $onEvent  fn(string $channel, string $event, array $payload): bool|void
     * @param SubscriptionOptions|null $options  Loop tuning; sensible defaults when null.
     */
    public function subscribe(array $channels, callable $onEvent, ?SubscriptionOptions $options = null): void;
}
