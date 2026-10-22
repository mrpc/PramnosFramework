<?php

declare(strict_types=1);

namespace Pramnos\Event\Transport;

use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;

/**
 * The queue: one task per listener per event, written on the application's connection, in its
 * transaction — the transport every installation has and the default. A listener's keys, its
 * fold and its attempts are the queue's ({@see QueuedEvents::enqueue()}).
 */
final class QueueTransport implements EventTransport
{
    public const NAME = 'queue';

    public function name(): string
    {
        return self::NAME;
    }

    public function available(): bool
    {
        return true;
    }

    public function deliver(Envelope $envelope, QueuedListener|string $listener): int|string|null
    {
        return QueuedEvents::enqueue($listener, $envelope->event, $envelope->args, $envelope->meta);
    }
}
