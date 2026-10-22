<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Events;

use Pramnos\Event\QueueableEvent;

/**
 * A message was written in a conversation — what notifications, rules and integrations listen to.
 */
final class MessagePosted implements QueueableEvent
{
    public const EVENT = 'conversation.message.posted';

    /**
     * @param int      $messageId      The message
     * @param int      $conversationId Its conversation
     * @param int|null $authorId       Who wrote it; null for the system
     * @param int|null $threadRootId   The message it answers, in a thread
     */
    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly ?int $authorId,
        public readonly ?int $threadRootId = null,
    ) {
    }

    /** @return array<string, int|null> */
    public function jsonSerialize(): array
    {
        return ['messageId' => $this->messageId, 'conversationId' => $this->conversationId, 'authorId' => $this->authorId, 'threadRootId' => $this->threadRootId];
    }

    /** @param array<string, mixed> $data */
    public static function fromQueue(array $data): static
    {
        return new self((int) $data['messageId'], (int) $data['conversationId'], isset($data['authorId']) ? (int) $data['authorId'] : null,
            isset($data['threadRootId']) ? (int) $data['threadRootId'] : null);
    }
}
