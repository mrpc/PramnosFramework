<?php

declare(strict_types=1);

namespace Pramnos\Event\Transport;

/**
 * One firing of an event, as a transport carries it: its name, its arguments and its `meta` — the
 * id, who fired it, from where, and the chain of events that led to it ({@see \Pramnos\Event\QueuedEvents::meta()}).
 *
 * The arguments are the caller's own until a transport writes them down: the queue asks each
 * listener for its keys with them, a stream encodes them once for every listener.
 */
final class Envelope
{
    /**
     * @param list<mixed>          $args
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $event,
        public readonly array $args,
        public readonly array $meta,
    ) {
    }

    /** This firing's id: the same for every listener it reaches. */
    public function id(): string
    {
        return (string) ($this->meta['eventId'] ?? '');
    }
}
