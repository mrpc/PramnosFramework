<?php

declare(strict_types=1);

namespace Pramnos\Event\Transport;

use Pramnos\Event\Event;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;

/**
 * Reads the stream for a consumer group — a queued listener — from where it got to: what failed
 * and is due again first, then the entries after its position, each run as the queue would run
 * it (its meta, its chain, its idempotency key). `bin/pramnos events:consume` runs it.
 *
 * - **One consumer per group at a time**: a lease on the group's row, taken by a conditional
 *   update and renewed while it reads. A second consumer finds it held and passes.
 * - **The position moves past a failure**: the entry waits in `event_deliveries` to be tried
 *   again after 30 s, 1, 2, 4 … minutes (at most an hour), and past the listener's attempts is
 *   kept there as dead, so one bad event never holds up the rest.
 * - **At least once**: a consumer that dies after running an entry and before moving past it runs
 *   it again — what must happen once says so with its idempotency key.
 * - **Folded listeners keep their fold**: a listener with a debounce key is handed to the queue,
 *   whose window and ceiling fold it as before.
 */
final class StreamConsumer
{
    /** How long a consumer holds a group without renewing, in seconds. */
    public const LEASE = 60;

    /** The longest wait before a failed entry is tried again, in seconds. */
    public const MAX_BACKOFF = 3600;

    /** @param string $owner This consumer's name: host and process by default */
    public function __construct(private string $owner = '')
    {
        $this->owner = $owner !== '' ? $owner : gethostname() . ':' . getmypid();
    }

    /**
     * The groups this application reads from the stream: every queued listener whose events go by
     * it.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach (Event::events() as $event) {
            array_push($groups, ...StreamTransport::groupsOf($event));
        }

        return array_values(array_unique($groups));
    }

    /**
     * One pass for a group: what is due again, then up to `$limit` new entries.
     *
     * @return array{held: bool, ran: int, failed: int, retried: int, dead: int}
     */
    public function consume(string $group, int $limit = 100): array
    {
        $tally = ['held' => false, 'ran' => 0, 'failed' => 0, 'retried' => 0, 'dead' => 0];
        if (!is_a($group, QueuedListener::class, true)) {
            throw new \InvalidArgumentException('Not a queued listener: ' . $group);
        }
        if (!$this->lease($group)) {
            return ['held' => true] + $tally;
        }
        $db       = StreamTransport::db();
        $attempts = max(1, (new $group())->maxAttempts());
        $now      = time();

        // What failed and is due again.
        $due = $db->queryBuilder()->table('#PREFIX#' . StreamTransport::DELIVERIES_TABLE)->where('groupname', $group)
            ->where('status', 'pending')->where('nextat', '<=', $now)->orderBy('id')->limit($limit)->getAllOrFail();
        foreach ($due as $delivery) {
            $entry = $this->entry((int) $delivery['entryid']);
            $error = $entry === null ? 'The entry is no longer in the log.' : $this->run($group, $entry);
            $tally['retried']++;
            if ($error === null) {
                $db->queryBuilder()->table('#PREFIX#' . StreamTransport::DELIVERIES_TABLE)->where('id', (int) $delivery['id'])->delete();
                continue;
            }
            $tries = (int) $delivery['attempts'] + 1;
            $dead  = $tries >= $attempts || $entry === null;
            $tally[$dead ? 'dead' : 'failed']++;
            $db->queryBuilder()->table('#PREFIX#' . StreamTransport::DELIVERIES_TABLE)->where('id', (int) $delivery['id'])->update([
                'attempts' => $tries, 'status' => $dead ? 'dead' : 'pending', 'nextat' => $now + self::backoff($tries), 'error' => mb_substr($error, 0, 2000),
            ]);
        }

        // What came after the position.
        $position = (int) ($db->queryBuilder()->table('#PREFIX#' . StreamTransport::CONSUMERS_TABLE)->where('groupname', $group)
            ->limit(1)->getAllOrFail()[0]['position'] ?? 0);
        $entries = $db->queryBuilder()->table('#PREFIX#' . StreamTransport::LOG_TABLE)->where('entryid', '>', $position)
            ->orderBy('entryid')->limit($limit)->getAllOrFail();
        foreach ($entries as $entry) {
            if (in_array($group, (array) json_decode((string) $entry['groups'], true), true)) {
                $error = $this->run($group, $entry);
                if ($error === null) {
                    $tally['ran']++;
                } else {
                    $dead = $attempts <= 1;
                    $tally[$dead ? 'dead' : 'failed']++;
                    $db->queryBuilder()->table('#PREFIX#' . StreamTransport::DELIVERIES_TABLE)->insertOrIgnore([
                        'groupname' => $group, 'entryid' => (int) $entry['entryid'], 'attempts' => 1, 'status' => $dead ? 'dead' : 'pending',
                        'nextat' => time() + self::backoff(1), 'error' => mb_substr($error, 0, 2000), 'createdat' => time(),
                    ]);
                }
            }
            $db->queryBuilder()->table('#PREFIX#' . StreamTransport::CONSUMERS_TABLE)->where('groupname', $group)
                ->update(['position' => (int) $entry['entryid'], 'updatedat' => time(), 'leaseduntil' => time() + self::LEASE]);
        }

        return $tally;
    }

    /**
     * Give a group back: another consumer may take it at once.
     */
    public function release(string $group): void
    {
        StreamTransport::db()->queryBuilder()->table('#PREFIX#' . StreamTransport::CONSUMERS_TABLE)
            ->where('groupname', $group)->where('owner', $this->owner)->update(['leaseduntil' => 0]);
    }

    /**
     * Delete entries older than `$days` that every group has read; a group behind keeps its own.
     *
     * @return int How many were deleted
     */
    public static function prune(int $days): int
    {
        $db    = StreamTransport::db();
        $floor = $db->queryBuilder()->table('#PREFIX#' . StreamTransport::CONSUMERS_TABLE)
            ->select([new \Pramnos\Database\Expression('MIN(position) AS p')])->getAllOrFail()[0]['p'] ?? null;
        $old   = $db->queryBuilder()->table('#PREFIX#' . StreamTransport::LOG_TABLE)->where('createdat', '<', time() - max(1, $days) * 86400);
        if ($floor !== null) {
            $old->where('entryid', '<=', (int) $floor);
        }
        // What still waits to be tried again stays, whatever its age.
        $waiting = array_map('intval', array_column($db->queryBuilder()->table('#PREFIX#' . StreamTransport::DELIVERIES_TABLE)
            ->select(['entryid'])->where('status', 'pending')->getAllOrFail(), 'entryid'));
        if ($waiting !== []) {
            $old->whereIn('entryid', $waiting, 'and', true);
        }
        $count = (int) ((clone $old)->select([new \Pramnos\Database\Expression('COUNT(*) AS n')])->getAllOrFail()[0]['n'] ?? 0);
        if ($count > 0) {
            $old->delete();
        }

        return $count;
    }

    /** How long a failed entry waits before its next attempt. */
    public static function backoff(int $attempts): int
    {
        return (int) min(self::MAX_BACKOFF, 30 * 2 ** max(0, $attempts - 1));
    }

    /**
     * Take or renew the group's lease. A group with no row yet — no event was written for it —
     * starts at the end of the log.
     */
    private function lease(string $group): bool
    {
        $db    = StreamTransport::db();
        $now   = time();
        $owner = $this->owner;
        $end   = (int) ($db->queryBuilder()->table('#PREFIX#' . StreamTransport::LOG_TABLE)
            ->select([new \Pramnos\Database\Expression('MAX(entryid) AS m')])->getAllOrFail()[0]['m'] ?? 0);
        $db->queryBuilder()->table('#PREFIX#' . StreamTransport::CONSUMERS_TABLE)
            ->insertOrIgnore(['groupname' => $group, 'position' => $end, 'updatedat' => $now]);
        $db->queryBuilder()->table('#PREFIX#' . StreamTransport::CONSUMERS_TABLE)->where('groupname', $group)
            ->where(static function ($q) use ($now, $owner): void {
                $q->where('leaseduntil', '<', $now)->orWhere('owner', $owner);
            })->update(['owner' => $owner, 'leaseduntil' => $now + self::LEASE]);
        // Read back rather than count: MySQL counts a renewal within the same second as no change.
        $row = $db->queryBuilder()->table('#PREFIX#' . StreamTransport::CONSUMERS_TABLE)->where('groupname', $group)->limit(1)->getAllOrFail()[0] ?? [];

        return ($row['owner'] ?? null) === $owner;
    }

    /** @return array<string, mixed>|null An entry of the log */
    private function entry(int $id): ?array
    {
        return StreamTransport::db()->queryBuilder()->table('#PREFIX#' . StreamTransport::LOG_TABLE)
            ->where('entryid', $id)->limit(1)->getAllOrFail()[0] ?? null;
    }

    /**
     * Run one entry for a group as the queue runs a task: in its event's context, under its
     * idempotency key — or hand it to the queue, where the listener folds.
     *
     * @param array<string, mixed> $entry
     * @return string|null Why it failed, or null
     */
    private function run(string $group, array $entry): ?string
    {
        try {
            $job      = ['listener' => $group] + (array) json_decode((string) $entry['payload'], true);
            $listener = new $group();
            $args     = QueuedEvents::arguments($job);
            $fold     = $listener->debounceKey(...$args);
            if ($fold !== null && $fold !== '') {
                QueuedEvents::enqueue($group, (string) $job['event'], $args, (array) $job['meta']);

                return null;
            }
            $key = $listener->idempotencyKey(...$args);
            if ($key !== null && $key !== '') {
                $job['idempotency'] = ['key' => $key, 'ttl' => $listener->idempotencyTtl()];
            }
            // Events it fires are the queue's kind: caused by this one, from a worker.
            QueuedEvents::working(true);
            QueuedEvents::run($job, (int) $entry['entryid']);

            return null;
        } catch (\Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        } finally {
            QueuedEvents::working(false);
        }
    }
}
