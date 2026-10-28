<?php

declare(strict_types=1);

namespace Pramnos\Bulk;

use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * One queued chunk of a bulk action: {@see BulkActions::execute()} on its ids, as the person who
 * asked — whose right to it is asked again now, not taken from when it was queued.
 */
class BulkActionTask extends AbstractTask
{
    /** The queue's task type. */
    public const TASK = 'bulk.action';

    public string $name = self::TASK;

    /** Do the chunk; the message says how many it did. */
    public function execute(QueueItem $queueItem): mixed
    {
        $job    = (array) json_decode((string) $queueItem->payload, true);
        $result = BulkActions::execute((string) ($job['entity'] ?? ''), (string) ($job['key'] ?? ''), array_values((array) ($job['ids'] ?? [])),
            (array) ($job['params'] ?? []), (int) ($job['by'] ?? 0));

        return ['message' => $result['done'] . ' done' . ($result['says'] !== '' ? ': ' . $result['says'] : '')];
    }

    /** "group_add on 500 user". */
    public function getDescription(QueueItem $queueItem): string
    {
        $job = (array) json_decode((string) $queueItem->payload, true);

        return 'Bulk ' . ($job['key'] ?? '?') . ' on ' . count((array) ($job['ids'] ?? [])) . ' ' . ($job['entity'] ?? '?');
    }
}
