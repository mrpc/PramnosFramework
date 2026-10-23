<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * A message's links read for their cards in the worker — {@see Unfurls}. Registered for
 * `conversation_unfurl` by default.
 */
class UnfurlTask extends AbstractTask
{
    public string $name = Unfurls::TASK;

    /** Read the message's links and tell its conversation. */
    public function execute(QueueItem $queueItem): mixed
    {
        Unfurls::run((int) (((array) json_decode((string) $queueItem->payload, true))['message'] ?? 0));

        return true;
    }

    /** What the queue's screen says it is. */
    public function getDescription(QueueItem $queueItem): string
    {
        return 'Read the links of message ' . (int) (((array) json_decode((string) $queueItem->payload, true))['message'] ?? 0) . ' for their previews';
    }
}
