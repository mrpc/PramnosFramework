<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * A delayed job that a {@see Contracts\QueueDriverInterface} has claimed and
 * handed to the caller for processing.
 *
 * This is the driver-agnostic currency of the delayed-queue capability: whether
 * the backend is Redis (a sorted set), a database table, or a future driver, a
 * claimed job is normalised into this immutable value object. It deliberately
 * carries only what a worker needs to dispatch and, on failure, re-schedule the
 * work — the type, its payload, how many times it has already been attempted,
 * and when it was due.
 *
 * Unlike {@see QueueItem} (the ActiveRecord row of the durable, status-based
 * work queue), a ReservedJob is not persisted and has no lifecycle methods: the
 * delayed-queue model is claim-and-remove, so once claimed the job no longer
 * exists in the backend. Re-scheduling a failed job is done by pushing a fresh
 * job (see {@see DelayedQueue::retry()}), not by mutating this object.
 */
final class ReservedJob
{
    /**
     * @param string               $id       Opaque job identifier assigned at push time
     * @param string               $type     Job type name (the handler selector)
     * @param array<string,mixed>  $payload  Job payload
     * @param int                  $attempts How many times this job has been attempted so far
     * @param int                  $runAt    Unix timestamp the job became due
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $runAt
    ) {
    }

    /**
     * Return the job as a plain associative array.
     *
     * The keys match the wire shape used by the classic RadioChatBox JobQueue
     * (`id`, `type`, `payload`, `attempts`, `run_at`) so existing worker loops
     * consuming that shape keep working byte-for-byte.
     *
     * @return array{id:string,type:string,payload:array<string,mixed>,attempts:int,run_at:int}
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'type'     => $this->type,
            'payload'  => $this->payload,
            'attempts' => $this->attempts,
            'run_at'   => $this->runAt,
        ];
    }
}
