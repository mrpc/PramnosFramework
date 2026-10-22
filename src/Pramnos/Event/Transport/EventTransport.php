<?php

declare(strict_types=1);

namespace Pramnos\Event\Transport;

use Pramnos\Event\QueuedListener;

/**
 * How a queued listener comes to hear of an event: the queue writes a task for it, a stream
 * appends the event once to a log its consumer group reads.
 *
 * Which transport a listener uses is the installation's choice, and a listener's own where it is
 * named ({@see Transports}); a listener's code is the same on every transport.
 */
interface EventTransport
{
    /** The transport's name, as `events.transport` names it. */
    public function name(): string;

    /**
     * Whether it can carry events here: its tables, its server. A transport that cannot is
     * passed over for the queue, with a line in the log.
     */
    public function available(): bool;

    /**
     * Carry one firing to one listener.
     *
     * @param QueuedListener|class-string<QueuedListener> $listener
     * @return int|string|null What the listener's place in `Event::fire()`'s results holds: a
     *                         task id, an entry id, or null where nothing was carried
     */
    public function deliver(Envelope $envelope, QueuedListener|string $listener): int|string|null;
}
