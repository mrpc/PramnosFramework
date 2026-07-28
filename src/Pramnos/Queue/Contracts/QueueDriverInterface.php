<?php

declare(strict_types=1);

namespace Pramnos\Queue\Contracts;

use Pramnos\Queue\ReservedJob;

/**
 * Backend contract for the delayed-queue capability.
 *
 * A delayed queue is the "run this later, don't lose it" primitive: a job is
 * pushed with an optional delay, and a worker claims jobs once they are due.
 * Semantically it is claim-and-remove — a claimed job is atomically removed from
 * the backend and handed to the caller, so two competing workers never process
 * the same job. Re-scheduling a failed job is a fresh {@see push()} (with an
 * incremented attempt count), never an in-place mutation.
 *
 * This is a distinct capability from the durable, status-based work queue served
 * by {@see \Pramnos\Queue\QueueManager} / {@see \Pramnos\Queue\QueueItem}: that
 * one keeps an auditable row per task with a pending→processing→completed
 * lifecycle; this one is a lightweight, low-latency delayed dispatcher. They
 * share the "Queue" capability family but answer different needs, and Redis /
 * database are interchangeable drivers of *this* contract.
 *
 * Drivers must NOT assume any ambient prefixing (e.g. phpredis OPT_PREFIX): a
 * driver owns its own key namespacing so the same logical queue maps to the same
 * physical keys regardless of how the underlying connection was configured.
 *
 * All methods are additive to the framework — no existing class implements or
 * consumes this interface prior to its introduction, so adding it cannot break
 * other applications.
 */
interface QueueDriverInterface
{
    /**
     * Machine name of the driver (e.g. "redis", "database"). Useful for logging
     * and for the capability layer to report which backend is in use.
     */
    public function name(): string;

    /**
     * Schedule a job to become due after $delaySeconds (0 = immediately due).
     *
     * @param string              $type         Job type name
     * @param array<string,mixed> $payload      Job payload
     * @param int                 $delaySeconds Seconds from now until the job is due
     * @param int                 $attempts     Attempts already made (>0 when re-scheduling a retry)
     *
     * @return string The assigned job id
     */
    public function push(string $type, array $payload, int $delaySeconds = 0, int $attempts = 0): string;

    /**
     * Atomically claim up to $limit jobs whose due time has passed.
     *
     * Each returned job has been removed from the backend; the caller owns it.
     *
     * @return list<ReservedJob>
     */
    public function claimDue(int $limit = 20): array;

    /**
     * Number of jobs currently scheduled (including ones already due).
     */
    public function size(): int;

    /**
     * Seconds until the next job is due: null when the queue is empty, 0 when
     * work is already pending.
     */
    public function secondsUntilNext(): ?int;

    /**
     * Remove every scheduled job. Returns how many were removed.
     */
    public function flush(): int;
}
