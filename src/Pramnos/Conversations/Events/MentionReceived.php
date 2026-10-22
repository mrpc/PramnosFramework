<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Events;

use Pramnos\Event\QueueableEvent;

/**
 * Somebody was mentioned — by name, or as one of everybody (`@all`) or of whoever is online
 * (`@online`): what tells them.
 */
final class MentionReceived implements QueueableEvent
{
    public const EVENT = 'conversation.mention.received';

    /**
     * @param int    $messageId The message
     * @param int    $personId  Who was mentioned
     * @param string $kind      person | all | online
     */
    public function __construct(public readonly int $messageId, public readonly int $personId, public readonly string $kind = 'person')
    {
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return ['messageId' => $this->messageId, 'personId' => $this->personId, 'kind' => $this->kind];
    }

    /** @param array<string, mixed> $data */
    public static function fromQueue(array $data): static
    {
        return new self((int) $data['messageId'], (int) $data['personId'], (string) ($data['kind'] ?? 'person'));
    }
}
