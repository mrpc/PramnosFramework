<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Events;

use Pramnos\Event\QueueableEvent;

/**
 * A conversation was made: a channel, a direct conversation, a group.
 */
final class ConversationCreated implements QueueableEvent
{
    public const EVENT = 'conversation.created';

    /**
     * @param int      $conversationId The conversation
     * @param string   $kind           channel | private | dm | group | system
     * @param int|null $createdBy      Who made it
     */
    public function __construct(public readonly int $conversationId, public readonly string $kind, public readonly ?int $createdBy)
    {
    }

    /** @return array<string, int|string|null> */
    public function jsonSerialize(): array
    {
        return ['conversationId' => $this->conversationId, 'kind' => $this->kind, 'createdBy' => $this->createdBy];
    }

    /** @param array<string, mixed> $data */
    public static function fromQueue(array $data): static
    {
        return new self((int) $data['conversationId'], (string) $data['kind'], isset($data['createdBy']) ? (int) $data['createdBy'] : null);
    }
}
