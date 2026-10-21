<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * A person left a segment: the filter stopped matching them.
 *
 * Dispatched by {@see Segments} when a full or a one-person recomputation finds the change, with
 * `Event::dispatch()`, so a rule listens to `segment.left` and a queued listener receives this
 * class again in the worker. A segment's first recomputation records its members without
 * dispatching: the people who were already in it did not just arrive.
 */
final class SegmentLeft implements \Pramnos\Event\QueueableEvent
{
    public const EVENT = 'segment.left';

    public function __construct(
        public readonly int $segmentId,
        public readonly int $personId,
        public readonly int $at,
    ) {
    }

    /** The person the event is about: what a person-event recorder and a rule read. */
    public function personId(): int
    {
        return $this->personId;
    }

    /** @return array{segment: int, person: int, at: int} */
    public function jsonSerialize(): array
    {
        return ['segment' => $this->segmentId, 'person' => $this->personId, 'at' => $this->at];
    }

    /** @param array<string, mixed> $data */
    public static function fromQueue(array $data): static
    {
        return new self((int) ($data['segment'] ?? 0), (int) ($data['person'] ?? 0), (int) ($data['at'] ?? 0));
    }
}
