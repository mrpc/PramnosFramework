<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Events;

use Pramnos\Event\QueueableEvent;

/**
 * Somebody came into a conversation: joined an open one, or was added — each time they come in,
 * not when they were in it already.
 */
final class MemberJoined implements QueueableEvent
{
    public const EVENT = 'conversation.member_joined';

    /**
     * @param int      $conversationId The conversation
     * @param int      $person         Who came in
     * @param int|null $addedBy        Who added them; null for somebody who joined
     */
    public function __construct(public readonly int $conversationId, public readonly int $person, public readonly ?int $addedBy = null)
    {
    }

    /** @return array<string, int|null> */
    public function jsonSerialize(): array
    {
        return ['conversationId' => $this->conversationId, 'person' => $this->person, 'addedBy' => $this->addedBy];
    }

    /** @param array<string, mixed> $data */
    public static function fromQueue(array $data): static
    {
        return new self((int) $data['conversationId'], (int) $data['person'], isset($data['addedBy']) ? (int) $data['addedBy'] : null);
    }
}
