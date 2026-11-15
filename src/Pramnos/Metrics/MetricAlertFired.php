<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * An alert fired, for one of the people it told ({@see Alerts::fire()}): what it said, and the
 * alert's own metric.
 *
 * Dispatched with `Event::dispatch()`, once per person in the rule's `notify.users` — so an
 * audience rule with `{"type": "event", "event": "metric.alert"}` as its trigger enters that
 * person, the same way it would for any other event. An alert told through a conversation only,
 * with no person, dispatches nothing: there is nobody for a rule to take in.
 */
final class MetricAlertFired implements \Pramnos\Event\QueueableEvent
{
    public const EVENT = 'metric.alert';

    public function __construct(
        public readonly int $ruleId,
        public readonly int $personId,
        public readonly string $metric,
        public readonly string $said,
        public readonly int $at,
    ) {
    }

    /** The person the event is about: what a rule's trigger reads. */
    public function personId(): int
    {
        return $this->personId;
    }

    /** @return array{rule: int, person: int, metric: string, said: string, at: int} */
    public function jsonSerialize(): array
    {
        return ['rule' => $this->ruleId, 'person' => $this->personId, 'metric' => $this->metric, 'said' => $this->said, 'at' => $this->at];
    }

    /** @param array<string, mixed> $data */
    public static function fromQueue(array $data): static
    {
        return new self((int) ($data['rule'] ?? 0), (int) ($data['person'] ?? 0), (string) ($data['metric'] ?? ''), (string) ($data['said'] ?? ''), (int) ($data['at'] ?? 0));
    }
}
