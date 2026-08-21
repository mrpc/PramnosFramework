<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * An event that knows how to broadcast itself.
 *
 * Without this, every call site repeats the same three decisions — which channel,
 * what the event is called, what shape the payload takes — and they drift. The
 * channel name in particular: one place builds `private-order.42`, another
 * `private-order-42`, and the subscriber that guessed wrong receives nothing with
 * no error anywhere. Naming those three things once, next to the data they describe,
 * is the whole point.
 *
 * ```php
 * final class OrderPaid implements BroadcastableEvent
 * {
 *     public function __construct(private Order $order)
 *     {
 *     }
 *
 *     public function broadcastOn(): array
 *     {
 *         return ['private-order.' . $this->order->id];
 *     }
 *
 *     public function broadcastAs(): string
 *     {
 *         return 'order.paid';
 *     }
 *
 *     public function broadcastWith(): array
 *     {
 *         return ['id' => $this->order->id, 'total' => $this->order->total];
 *     }
 * }
 *
 * $broadcasting->event(new OrderPaid($order));
 * ```
 *
 * {@see QueuedBroadcastableEvent} for one that should leave the request rather than
 * be published inline.
 */
interface BroadcastableEvent
{
    /**
     * The channels to publish on, prefixes included.
     *
     * A list rather than a single name because the same fact often belongs to more
     * than one audience — an order's own channel and an operations feed.
     *
     * @return list<string>
     */
    public function broadcastOn(): array;

    /**
     * The event name subscribers bind to.
     */
    public function broadcastAs(): string;

    /**
     * The payload.
     *
     * Resolved at dispatch time, which matters for a queued event: see
     * {@see QueuedBroadcastableEvent} for why that is deliberate.
     *
     * @return array<string,mixed>
     */
    public function broadcastWith(): array;
}
