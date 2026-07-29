<?php

declare(strict_types=1);

namespace Pramnos\Queue;

use Pramnos\Queue\Contracts\QueueDriverInterface;

/**
 * Application-facing accessor for the delayed-queue capability.
 *
 * A delayed queue answers "run this later, don't lose it" with low latency:
 * push a job (optionally delayed), and workers claim jobs once they are due. The
 * backend is a pluggable {@see QueueDriverInterface} — {@see Drivers\RedisQueueDriver}
 * (a sorted set; the low-latency default) or {@see Drivers\DatabaseQueueDriver}
 * (a table; where a database backend is preferred) — so an application depends on
 * the *capability*, never on Redis directly. Redis is one driver of the queue,
 * beside database and (future) others.
 *
 * This is intentionally not controller-coupled: unlike {@see QueueManager} (the
 * durable, status-based work queue that needs a database Controller), a delayed
 * queue only needs its driver, so it can be constructed anywhere — a bot, a CLI
 * worker, a service — without an application Controller. The two are siblings in
 * the "Queue" capability family, each fit to a different shape of work.
 *
 * Re-scheduling a failed job is a fresh push with an incremented attempt count
 * and linear backoff (see {@see retry()}); the queue never mutates a claimed job
 * in place, matching the claim-and-remove semantics of the driver contract.
 */
class DelayedQueue
{
    public function __construct(private readonly QueueDriverInterface $driver)
    {
    }

    /**
     * A Redis-backed delayed queue for $namespace, bound to the shared
     * {@see \Pramnos\Redis\ConnectionManager} (its per-install prefix and pooled
     * connection). Lets an application obtain the queue capability for a namespace
     * without wiring the {@see Drivers\RedisQueueDriver} itself. Resolved lazily so
     * an app that configures the manager during bootstrap is already in effect.
     *
     * The keyspace is `<prefix><namespace>:delayed` / `:data`, identical to a
     * hand-wired RedisQueueDriver — a queue is namespaced per use (not a process
     * singleton), so this is a factory rather than a shared instance.
     */
    public static function redis(string $namespace): self
    {
        return new self(new Drivers\RedisQueueDriver(
            [
                'prefix'    => \Pramnos\Redis\ConnectionManager::getInstance()->prefix(),
                'namespace' => $namespace,
            ],
            static fn (): object => \Pramnos\Redis\ConnectionManager::getInstance()->connection()
        ));
    }

    /**
     * The backing driver (e.g. to read its name or namespace).
     */
    public function driver(): QueueDriverInterface
    {
        return $this->driver;
    }

    /**
     * Schedule a job to run after $delaySeconds (0 = as soon as a worker claims).
     *
     * @param array<string,mixed> $payload
     *
     * @return string The job id
     */
    public function push(string $type, array $payload, int $delaySeconds = 0): string
    {
        return $this->driver->push($type, $payload, $delaySeconds, 0);
    }

    /**
     * Atomically claim up to $limit jobs whose due time has passed.
     *
     * @return list<ReservedJob>
     */
    public function claimDue(int $limit = 20): array
    {
        return $this->driver->claimDue($limit);
    }

    /**
     * Re-schedule a failed job with linear backoff, unless it has exhausted its
     * attempts.
     *
     * The re-scheduled job carries an incremented attempt count and is delayed
     * by $baseDelaySeconds × the new attempt number (e.g. 10s, 20s, …). Returns
     * null when the job has reached $maxAttempts and should be dropped.
     *
     * @param  int $maxAttempts     Total attempts allowed before the job is dropped
     * @param  int $baseDelaySeconds Backoff unit multiplied by the attempt number
     * @return string|null New job id, or null when the job was dropped
     */
    public function retry(ReservedJob $job, int $maxAttempts = 3, int $baseDelaySeconds = 10): ?string
    {
        $attempts = $job->attempts + 1;

        if ($attempts >= $maxAttempts) {
            return null;
        }

        return $this->driver->push($job->type, $job->payload, $baseDelaySeconds * $attempts, $attempts);
    }

    /**
     * Number of jobs currently scheduled (including ones already due).
     */
    public function size(): int
    {
        return $this->driver->size();
    }

    /**
     * Seconds until the next job is due; null when empty, 0 when work is pending.
     */
    public function secondsUntilNext(): ?int
    {
        return $this->driver->secondsUntilNext();
    }

    /**
     * Remove every scheduled job. Returns how many were removed.
     */
    public function flush(): int
    {
        return $this->driver->flush();
    }
}
