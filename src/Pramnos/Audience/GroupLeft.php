<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * A person was taken out of a group — by an administrator, a bulk action or a rule.
 *
 * Dispatched by {@see Groups} for each person whose membership actually changed, so a rule can
 * listen to `group.left` and {@see Segments::refreshOn()} can recompute the person's segments
 * that ask about groups.
 */
final class GroupLeft implements \Pramnos\Event\QueueableEvent
{
    public const EVENT = 'group.left';

    /** @param string $source Who changed it: `admin`, `bulk`, `rule` or what the application passes */
    public function __construct(
        public readonly int $groupId,
        public readonly int $personId,
        public readonly ?int $by = null,
        public readonly string $source = 'admin',
    ) {
    }

    /** The person the event is about. */
    public function personId(): int
    {
        return $this->personId;
    }

    /** @return array{group: int, person: int, by: ?int, source: string} */
    public function jsonSerialize(): array
    {
        return ['group' => $this->groupId, 'person' => $this->personId, 'by' => $this->by, 'source' => $this->source];
    }

    /** @param array<string, mixed> $data */
    public static function fromQueue(array $data): static
    {
        $by = $data['by'] ?? null;

        return new self((int) ($data['group'] ?? 0), (int) ($data['person'] ?? 0), $by === null ? null : (int) $by, (string) ($data['source'] ?? 'admin'));
    }
}
