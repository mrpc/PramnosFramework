<?php

declare(strict_types=1);

namespace Pramnos\Queue\Drivers;

use Pramnos\Database\Database;
use Pramnos\Queue\Contracts\QueueDriverInterface;
use Pramnos\Queue\ReservedJob;

/**
 * Database-backed delayed-queue driver.
 *
 * An alternative to {@see RedisQueueDriver} for the same {@see \Pramnos\Queue\DelayedQueue}
 * capability, for deployments without Redis or where scheduled jobs must survive
 * a cache flush. Jobs live in the `delayed_jobs` table (see the shipped migration
 * CreateDelayedJobsTable) scored by an integer `run_at` Unix timestamp; a poll on
 * `run_at <= now` finds due jobs. Works on both MySQL and PostgreSQL via the
 * framework Database abstraction; values are escaped through Database::prepareInput.
 *
 * ## Claim-and-remove
 *
 * Claiming is atomic per job by delete-affected-rows: for each due row the driver
 * issues `DELETE ... WHERE id = ?` and only owns the job when exactly one row was
 * removed — the SQL analogue of the Redis driver's claim-by-ZREM. Two competing
 * pollers therefore never process the same job. Re-scheduling a failed job is a
 * fresh {@see push()} (a new row), never a mutation, matching the driver contract.
 *
 * Trade-off vs the Redis driver: dispatch latency is bounded by the caller's poll
 * interval rather than being event-driven, so prefer Redis for latency-sensitive
 * work and this driver where a database backend is required.
 */
class DatabaseQueueDriver implements QueueDriverInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly string $table = 'delayed_jobs'
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function push(string $type, array $payload, int $delaySeconds = 0, int $attempts = 0): string
    {
        $jobId = bin2hex(random_bytes(12));
        $now   = time();
        $runAt = $now + max(0, $delaySeconds);
        $json  = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        $sql = 'INSERT INTO ' . $this->table
            . ' (job_id, type, payload, attempts, run_at, created_at) VALUES ('
            . "'" . $this->db->prepareInput($jobId) . "', "
            . "'" . $this->db->prepareInput($type) . "', "
            . "'" . $this->db->prepareInput($json) . "', "
            . max(0, $attempts) . ', '
            . $runAt . ', '
            . $now . ')';

        $this->db->query($sql);

        return $jobId;
    }

    public function claimDue(int $limit = 20): array
    {
        $now = time();

        $result = $this->db->query(
            'SELECT id, job_id, type, payload, attempts, run_at FROM ' . $this->table
            . ' WHERE run_at <= ' . $now
            . ' ORDER BY run_at ASC, id ASC'
            . ' LIMIT ' . max(1, $limit)
        );

        if (!$result) {
            return [];
        }

        $rows = [];
        while ($result->fetch()) {
            $rows[] = [
                'id'       => (int) $result->fields['id'],
                'job_id'   => (string) $result->fields['job_id'],
                'type'     => (string) $result->fields['type'],
                'payload'  => (string) $result->fields['payload'],
                'attempts' => (int) $result->fields['attempts'],
                'run_at'   => (int) $result->fields['run_at'],
            ];
        }

        $claimed = [];
        foreach ($rows as $row) {
            // Whoever's DELETE removes the row owns the job (atomic claim).
            //
            // The id was interpolated into the statement until 2026-08-17. It comes from a row
            // this driver just read, so it was not reachable input — but the builder binds it,
            // which is the difference between safe and safe-for-now.
            // `delete()` returns a Result, not a row count — casting it to int would make
            // this comparison false for every job and the queue would claim nothing at all.
            $delete = $this->db->queryBuilder()
                ->table($this->table)
                ->where('id', $row['id'])
                ->delete();
            if (!$delete || (int) $delete->getAffectedRows() !== 1) {
                continue;
            }

            $payload = json_decode($row['payload'], true);

            $claimed[] = new ReservedJob(
                $row['job_id'],
                $row['type'],
                is_array($payload) ? $payload : [],
                $row['attempts'],
                $row['run_at']
            );
        }

        return $claimed;
    }

    public function size(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS cnt FROM ' . $this->table);
        return (int) ($row['cnt'] ?? 0);
    }

    public function secondsUntilNext(): ?int
    {
        $row = $this->db->selectOne('SELECT MIN(run_at) AS next_at FROM ' . $this->table);
        if ($row === null || $row['next_at'] === null) {
            return null;
        }

        return max(0, (int) $row['next_at'] - time());
    }

    public function flush(): int
    {
        $count = $this->size();
        $this->db->queryBuilder()->table($this->table)->delete();

        return $count;
    }
}
