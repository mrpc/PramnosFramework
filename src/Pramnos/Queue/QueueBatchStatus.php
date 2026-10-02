<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * How far one batch has got: its tasks counted by status.
 *
 * Read by a pass deciding whether to run again, by a health check, and by the services
 * screen. `finished()` is the question those ask; the counts are what an operator reads to
 * see why it is not.
 */
final class QueueBatchStatus
{
    /**
     * @param string $id         The batch id this describes
     * @param int    $pending    Waiting to be claimed, including tasks waiting out a retry delay
     * @param int    $processing Claimed by a worker
     * @param int    $completed  Done
     * @param int    $warning    Done, with a warning
     * @param int    $failed     Out of attempts
     */
    public function __construct(
        public readonly string $id,
        public readonly int $pending = 0,
        public readonly int $processing = 0,
        public readonly int $completed = 0,
        public readonly int $warning = 0,
        public readonly int $failed = 0,
    ) {
    }

    /** Every task in the batch, whatever its state. */
    public function total(): int
    {
        return $this->pending + $this->processing + $this->completed + $this->warning + $this->failed;
    }

    /**
     * Whether no task is waiting or running.
     *
     * A batch with no tasks at all is finished: nothing it queued is left to do.
     */
    public function finished(): bool
    {
        return $this->pending === 0 && $this->processing === 0;
    }

    /**
     * The counts as an array, for a JSON endpoint.
     *
     * @return array{id: string, pending: int, processing: int, completed: int, warning: int,
     *               failed: int, total: int, finished: bool}
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'pending'    => $this->pending,
            'processing' => $this->processing,
            'completed'  => $this->completed,
            'warning'    => $this->warning,
            'failed'     => $this->failed,
            'total'      => $this->total(),
            'finished'   => $this->finished(),
        ];
    }
}
