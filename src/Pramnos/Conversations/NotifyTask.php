<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * A new message's notifications, sent in the worker — {@see Notify}. Registered for
 * `conversation_notify` by default.
 */
class NotifyTask extends AbstractTask
{
    public string $name = Notify::TASK;

    /** Tell whoever the message notifies. */
    public function execute(QueueItem $queueItem): mixed
    {
        $payload = (array) json_decode((string) $queueItem->payload, true);
        Notify::run((int) ($payload['message'] ?? 0), array_map('strval', (array) ($payload['mentioned'] ?? [])));

        return true;
    }

    /** What the queue's screen says it is. */
    public function getDescription(QueueItem $queueItem): string
    {
        return 'Notify the people message ' . (int) (((array) json_decode((string) $queueItem->payload, true))['message'] ?? 0) . ' is for';
    }
}
