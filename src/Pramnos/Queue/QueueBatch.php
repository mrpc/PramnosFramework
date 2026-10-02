<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * What one {@see QueueManager::addMany()} call queued.
 *
 * The id is what {@see QueueManager::batchStatus()} reads back; the name is what the batch
 * is for, so the next run of the same pass can ask whether this one is still going.
 */
final class QueueBatch
{
    /**
     * @param string    $id      Written to every task's `batchid`
     * @param string    $name    Written to `batchname`; '' when none was given
     * @param list<int> $taskIds The tasks this call created, in the order given
     * @param int       $skipped Payloads not queued because an identical task was still pending
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $taskIds,
        public readonly int $skipped,
    ) {
    }

    /** How many tasks this call created. */
    public function queued(): int
    {
        return count($this->taskIds);
    }
}
