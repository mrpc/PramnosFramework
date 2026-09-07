<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * Manages the background job queue.
 *
 * Provides high-level operations for enqueuing tasks, claiming the next
 * available task with an optimistic lock, updating task lifecycle states, and
 * purging old completed records.
 *
 * Usage:
 *
 *   $manager = new QueueManager($controller, 'worker-1');
 *
 *   // Enqueue
 *   $id = $manager->addTask('send_email', ['to' => 'a@b.com'], priority: 5);
 *
 *   // Claim + process
 *   $task = $manager->getNextTask();
 *   if ($task) {
 *       // … process …
 *       $manager->markTaskAsCompleted($task, 'Sent OK');
 *   }
 *
 */
class QueueManager
{
    /**
     * The application controller — provides database access.
     *
     * @var \Pramnos\Application\Controller
     */
    protected $controller;

    /**
     * How many rows to look at before giving up on this pass.
     *
     * One was enough while claiming was a blind write. Now that a claim can be refused,
     * a single candidate means a worker that loses a race reports "queue empty" and goes
     * to sleep with work waiting — so it looks at the next few instead. Small, because a
     * contended queue drains anyway on the next poll and a long list is a long lock-free
     * scan repeated per worker.
     */
    protected const CLAIM_CANDIDATES = 5;

    /**
     * Worker identifier written to the lockedby column so stalled tasks can
     * be attributed to the worker that held them.
     *
     * Format: hostname:workerid  or  hostname:pid  when no ID is supplied.
     *
     * @var string
     */
    protected string $workerId;

    /**
     * @param \Pramnos\Application\Controller $controller
     * @param string|null $workerId  Optional worker identifier; defaults to hostname:pid
     */
    public function __construct($controller, ?string $workerId = null)
    {
        $this->controller = $controller;
        $this->workerId   = $workerId !== null
            ? gethostname() . ':' . $workerId
            : gethostname() . ':' . getmypid();
    }

    // ── Enqueue ───────────────────────────────────────────────────────────────

    /**
     * Add a task to the queue.
     *
     * When $unique is true and a non-terminal task with the same type+payload
     * hash already exists, this method returns null instead of creating a
     * duplicate.
     *
     * @param  string     $taskType    Registered handler type name
     * @param  mixed      $data        Task payload (array, object, or scalar)
     * @param  int        $priority    Dispatch priority — lower = sooner
     * @param  int        $maxAttempts Maximum retry attempts before permanent failure
     * @param  bool       $unique      Reject duplicates that are still pending/processing
     * @return int|null   Task ID, or null if a duplicate was detected
     */
    public function addTask(
        string $taskType,
        mixed $data,
        int $priority = 10,
        int $maxAttempts = 3,
        bool $unique = false
    ): ?int {
        $taskHash = $this->generateTaskHash($taskType, $data);

        if ($unique) {
            $queueModel = $this->createQueueItemModel();
            $existing   = $queueModel->getList(
                "WHERE task_hash = '"
                    . $this->controller->application->database->prepareInput($taskHash)
                    . "' AND status NOT IN ('completed', 'failed')"
            );
            if (!empty($existing)) {
                return null;
            }
        }

        $item              = $this->createQueueItemModel();
        $item->type        = $taskType;
        $item->payload     = json_encode($data);
        $item->status      = 'pending';
        $item->priority    = $priority;
        $item->attempts    = 0;
        $item->maxattempts = $maxAttempts;
        $item->task_hash   = $taskHash;
        $item->createdat   = date('Y-m-d H:i:s');
        $item->save();

        return (int)$item->taskid;
    }

    // ── Claiming ──────────────────────────────────────────────────────────────

    /**
     * Return a list of pending tasks (without locking them).
     *
     * Prefer getNextTask() for actual processing — this method is intended
     * for monitoring and inspection only.
     *
     * @param  int                $limit
     * @param  string|string[]|null $taskTypes  Restrict to these type(s)
     * @return QueueItem[]
     */
    public function getPendingTasks(int $limit = 10, string|array|null $taskTypes = null): array
    {
        $model = $this->createQueueItemModel();
        $where = "WHERE status = 'pending'";

        if ($taskTypes !== null) {
            $where .= ' AND type IN (' . $this->buildTypeList($taskTypes) . ')';
        }

        return $model->getList($where, 'ORDER BY priority ASC, createdat ASC LIMIT ' . $limit);
    }

    /**
     * Claim and return the next available task, marking it as 'processing' with a lock
     * expiry.
     *
     * The method first looks for pending tasks (fast path) and only falls back
     * to scanning for stalled processing tasks (slow path) when nothing is
     * pending. This split avoids OR conditions that defeat composite indexes.
     *
     * **The claim is atomic, and for a long time the docblock said so without it being
     * true.** It read a row and then wrote every column of it back, so two workers could
     * read the same row and both save over it — both running the task, the second one's
     * `lockedby` winning, and a single `attempts` increment recorded for two executions.
     * {@see claimFirstOf()} for what replaced it and why not `FOR UPDATE SKIP LOCKED`.
     *
     * @param  string|string[]|null $taskTypes     Restrict to these type(s)
     * @param  int                  $lockSeconds   Lock duration in seconds
     * @param  bool                 $reverse       Process newest-first instead of oldest-first
     * @param  int                  $startfrom     Only consider tasks created at or after this Unix timestamp
     * @return QueueItem|false
     */
    public function getNextTask(
        string|array|null $taskTypes = null,
        int $lockSeconds = 300,
        bool $reverse = false,
        int $startfrom = 0
    ): QueueItem|false {
        $this->refreshDatabaseConnection();

        $model      = $this->createQueueItemModel();
        $now        = date('Y-m-d H:i:s');
        $lockExpiry = date('Y-m-d H:i:s', time() + $lockSeconds);
        $typeClause = $taskTypes !== null
            ? ' AND type IN (' . $this->buildTypeList($taskTypes) . ')'
            : '';

        $order = $reverse ? 'ORDER BY priority ASC, createdat DESC LIMIT ' . static::CLAIM_CANDIDATES
                          : 'ORDER BY priority ASC, createdat ASC LIMIT ' . static::CLAIM_CANDIDATES;

        // High-priority recent tasks if $startfrom is set
        if ($startfrom > 0) {
            $startDate = date('Y-m-d H:i:s', $startfrom);
            $claimed   = $this->claimFirstOf(
                $model->getList(
                    "WHERE status = 'pending' AND createdat >= '$startDate' AND priority <= 10" . $typeClause,
                    'ORDER BY priority ASC, createdat ASC LIMIT ' . static::CLAIM_CANDIDATES
                ),
                $now,
                $lockExpiry
            );

            if ($claimed !== false) {
                return $claimed;
            }
        }

        $claimed = $this->claimFirstOf(
            $model->getList("WHERE status = 'pending'" . $typeClause, $order),
            $now,
            $lockExpiry
        );

        if ($claimed !== false) {
            return $claimed;
        }

        // Stalled processing tasks (lock expired, attempts remaining)
        return $this->claimFirstOf(
            $model->getList(
                "WHERE status = 'processing' AND attempts < maxattempts AND lockexpires < '$now'"
                . $typeClause,
                'ORDER BY priority ASC, createdat ASC LIMIT ' . static::CLAIM_CANDIDATES
            ),
            $now,
            $lockExpiry
        );
    }

    /**
     * Claim the first of these candidates that is still in the state we read it in.
     *
     * **The claim used to be a blind write, and the docblock said "atomically".** It was
     * `SELECT`, then `$task->save()` — which writes every column of the loaded model. Two
     * workers polling the same queue could read the same row and both save over it, so
     * both ran the task, the second one's `lockedby` won, and the row carried a single
     * `attempts` increment for two executions. Nothing in the data said it had happened.
     *
     * The window is the gap between the read and the write, which is small and is entered
     * by every worker on every poll — so it closes on load rather than on luck, and the
     * more workers a queue has, the more often.
     *
     * A guarded `UPDATE` is the whole fix: the state the row was read in becomes the `WHERE`
     * clause, and the database decides. Zero rows affected means somebody else got there
     * first, which is not an error — it is the next candidate's turn.
     *
     * Chosen over `SELECT … FOR UPDATE SKIP LOCKED`, which both backends support: that
     * needs a transaction held across the claim, and this method is called from inside
     * whatever transaction the caller already has. A conditional update needs nothing and
     * cannot deadlock.
     *
     * @param  array<int, QueueItem> $candidates Rows as they were read
     * @return QueueItem|false
     */
    protected function claimFirstOf(array $candidates, string $now, string $lockExpiry): QueueItem|false
    {
        foreach ($candidates as $candidate) {
            $taskId = (int) $candidate->taskid;

            if ($taskId <= 0) {
                continue;
            }

            if (!$this->claimRow($candidate, $now, $lockExpiry)) {
                // Somebody else claimed it between the read and here. Ordinary, under load.
                continue;
            }

            /*
             * Reloaded rather than adjusted in memory.
             *
             * `attempts` is incremented by the database (`attempts + 1`), so the value the
             * caller gets has to come back from there — and `markTaskAsFailed()` compares
             * `attempts` against `maxattempts` to decide between a retry and a permanent
             * failure. An off-by-one there is a task that retries for ever or one that
             * never retries at all.
             */
            $claimed = $this->createQueueItemModel();
            $claimed->load($taskId);

            if ((int) $claimed->taskid === $taskId) {
                return $claimed;
            }
        }

        return false;
    }

    /**
     * One guarded claim. True when this worker got the row.
     */
    protected function claimRow(QueueItem $candidate, string $now, string $lockExpiry): bool
    {
        $database = $this->controller->application->database;

        $query = $database->queryBuilder()
            ->table($this->getQueueTableName())
            ->where('taskid', (int) $candidate->taskid)
            ->where('status', (string) $candidate->status);

        /*
         * For a stalled row, the lock we saw is part of the condition.
         *
         * Without it a worker could take a row from a *live* worker that claimed it in the
         * meantime: the status is `processing` either way, and only the lock says whose it
         * is and whether it has expired. Compared by value rather than re-tested against
         * `NOW()`, because the question is "is this still the claim I read?", not "is some
         * claim expired?".
         */
        if ((string) $candidate->status === 'processing') {
            if ($candidate->lockedby === null || (string) $candidate->lockedby === '') {
                $query->whereNull('lockedby');
            } else {
                $query->where('lockedby', (string) $candidate->lockedby);
            }

            if ($candidate->lockexpires === null) {
                $query->whereNull('lockexpires');
            } else {
                $query->where('lockexpires', (string) $candidate->lockexpires);
            }
        }

        $result = $query->update(array(
            'status' => 'processing',
            // In the database, not in PHP: two workers reading `attempts` and both writing
            // `read + 1` lose one of the increments, which is the same race one level down.
            'attempts'    => $database->queryBuilder()->raw('attempts + 1'),
            'startedat'   => $now,
            'updatedat'   => $now,
            'lockedby'    => $this->workerId,
            'lockexpires' => $lockExpiry,
        ));

        return is_object($result) && method_exists($result, 'getAffectedRows')
            && (int) $result->getAffectedRows() === 1;
    }

    // ── Status transitions ────────────────────────────────────────────────────

    /**
     * Mark a task as currently being processed (without claiming it via getNextTask).
     *
     * @param  QueueItem $task
     * @return void
     */
    public function markTaskAsProcessing(QueueItem &$task): void
    {
        $task->status   = 'processing';
        $task->startedat = date('Y-m-d H:i:s');
        $task->save();
    }

    /**
     * Mark a task as successfully completed.
     *
     * @param  QueueItem    $task
     * @param  string|null  $successMessage  Human-readable summary
     * @param  float|null   $executionTime   Wall-clock seconds; calculated from startedat when null
     * @return void
     */
    public function markTaskAsCompleted(
        QueueItem $task,
        ?string $successMessage = null,
        ?float $executionTime = null
    ): void {
        $task->status          = 'completed';
        $task->completedat     = date('Y-m-d H:i:s');
        $task->success_message = $successMessage;
        $task->execution_time  = $executionTime ?? $this->calculateExecutionTime($task);
        $task->lockedby        = null;
        $task->lockexpires     = null;
        $task->save();
    }

    /**
     * Mark a task as completed with a non-fatal warning.
     *
     * @param  QueueItem    $task
     * @param  string       $warningMessage
     * @param  float|null   $executionTime
     * @return void
     */
    public function markTaskAsWarning(
        QueueItem $task,
        string $warningMessage,
        ?float $executionTime = null
    ): void {
        $task->status          = 'warning';
        $task->completedat     = date('Y-m-d H:i:s');
        $task->success_message = $warningMessage;
        $task->execution_time  = $executionTime ?? $this->calculateExecutionTime($task);
        $task->lockedby        = null;
        $task->lockexpires     = null;
        $task->save();
    }

    /**
     * Mark a task as failed.
     *
     * If attempts < maxattempts the status is reset to 'pending' for automatic
     * retry. Only when all attempts are exhausted is the task permanently
     * marked as 'failed'.
     *
     * @param  QueueItem    $task
     * @param  string|null  $errorMessage
     * @param  float|null   $executionTime
     * @return void
     */
    public function markTaskAsFailed(
        QueueItem $task,
        ?string $errorMessage = null,
        ?float $executionTime = null
    ): void {
        if ((int)$task->attempts >= (int)$task->maxattempts) {
            $task->status      = 'failed';
            $task->completedat = date('Y-m-d H:i:s');
        } else {
            $task->status = 'pending';
        }

        $task->error          = $errorMessage;
        $task->execution_time = $executionTime ?? $this->calculateExecutionTime($task);
        $task->lockedby       = null;
        $task->lockexpires    = null;
        $task->updatedat      = date('Y-m-d H:i:s');
        $task->save();
    }

    // ── Administrative operations ─────────────────────────────────────────────

    /**
     * Reset a permanently-failed task so it will be retried.
     *
     * @param  int  $taskId
     * @return bool  False when the task does not exist or is not in 'failed' state
     */
    public function retryTask(int $taskId): bool
    {
        $task = $this->createQueueItemModel();
        $task->load($taskId);

        if ((int)$task->taskid === 0 || $task->status !== 'failed') {
            return false;
        }

        $task->status    = 'pending';
        $task->attempts  = 0;
        $task->error     = null;
        $task->updatedat = date('Y-m-d H:i:s');
        $task->save();

        return true;
    }

    /**
     * Return a summary of the queue depth by status.
     *
     * @return array{pending:int,processing:int,completed:int,warning:int,failed:int,
     *               total:int,totalcompleted:int,percentcompleted:string,percentremaining:string}
     */
    public function getStats(): array
    {
        $model = $this->createQueueItemModel();

        $stats = [
            'pending'    => $model->getCount("WHERE status = 'pending'"),
            'processing' => $model->getCount("WHERE status = 'processing'"),
            'completed'  => $model->getCount("WHERE status = 'completed'"),
            'warning'    => $model->getCount("WHERE status = 'warning'"),
            'failed'     => $model->getCount("WHERE status = 'failed'"),
            'total'      => $model->getCount(),
        ];

        $stats['totalcompleted']   = $stats['completed'] + $stats['warning'];
        $stats['percentcompleted'] = ($stats['total'] > 0
            ? round(($stats['totalcompleted'] / $stats['total']) * 100, 2)
            : 0) . '%';
        $stats['percentremaining'] = ($stats['total'] > 0
            ? round(($stats['pending'] + $stats['processing']) / $stats['total'] * 100, 2)
            : 0) . '%';

        return $stats;
    }

    /**
     * Give back the tasks whose worker died holding them.
     *
     * A worker sets `status = 'processing'` with a lock expiry and then, if it dies —
     * a fatal, an OOM kill, a `SIGKILL`, a machine going away — nothing writes the end
     * of that story. **A process that dies cannot record its own failure.**
     *
     * `getNextTask()` does pick up a stalled row, and that covers the ordinary case. It
     * does not cover the two that hurt:
     *
     *  - **Nobody is polling that type.** The reclaim there is a side effect of somebody
     *    asking for work, so a type whose workers are all dead reclaims nothing. That is
     *    exactly the moment it is needed.
     *  - **`attempts` has reached `maxattempts`.** The stalled branch requires attempts
     *    remaining, so a row that has exhausted them stays `processing` for ever: never
     *    claimed, never failed, counted as *in progress* by `getStats()`, and not looked at
     *    by `purgeOldTasks()`, which only reads terminal states.
     *
     * The measured cost of the second one, from the installation that filed it: a fatal
     * killing workers leaked **101 tasks** at one to three a minute, and only **34** were
     * recorded as failed. The ledger under-reported the damage threefold, so the incident
     * read as a slow queue rather than a queue losing work.
     *
     * **The lock expiry is the entire safety condition**, which is what makes this safe to
     * run against a live queue: a worker that is alive holds a lock that has not expired,
     * so nothing running can have its task taken away. `attempts` is left alone, so a task
     * whose worker keeps dying still walks up to `maxattempts` and stops rather than
     * looping for ever.
     *
     * @param  int                  $graceSeconds Extra seconds past expiry before a row is
     *                                            eligible. `0` is correct for a scheduled
     *                                            run; raise it if worker clocks disagree.
     * @param  string|string[]|null $taskTypes    Restrict to these type(s)
     * @return array{requeued:int,failed:int}
     */
    public function reclaimAbandonedTasks(
        int $graceSeconds = 0,
        string|array|null $taskTypes = null
    ): array {
        $this->refreshDatabaseConnection();

        $cutoff = date('Y-m-d H:i:s', time() - max(0, $graceSeconds));
        $now    = date('Y-m-d H:i:s');

        // Retries left: back to the queue, lock cleared, as if never claimed.
        $requeued = $this->reclaimWhere(
            $cutoff,
            $taskTypes,
            'attempts < maxattempts',
            array(
                'status'      => 'pending',
                'startedat'   => null,
                'lockedby'    => null,
                'lockexpires' => null,
                'updatedat'   => $now,
            )
        );

        /*
         * Out of retries: failed, with a reason.
         *
         * Said in the row rather than left as `processing`, because the difference between
         * "still working on it" and "the worker died three days ago" is the whole value of
         * the ledger — and this is the state nothing else in the framework resolves.
         */
        $failed = $this->reclaimWhere(
            $cutoff,
            $taskTypes,
            'attempts >= maxattempts',
            array(
                'status'      => 'failed',
                'error'       => 'Abandoned: the worker holding this task stopped without '
                    . 'recording an outcome, and no attempts remain.',
                'completedat' => $now,
                'updatedat'   => $now,
                'lockedby'    => null,
                'lockexpires' => null,
            )
        );

        return array('requeued' => $requeued, 'failed' => $failed);
    }

    /**
     * One reclaim statement, and the reason it is half query builder.
     *
     * The builder resolves the table, quotes per driver and binds the values — so the
     * status, the timestamps and the type list all go through it. What it cannot express is
     * `attempts < maxattempts`: a comparison between two **columns**, where a placeholder
     * would bind the string `'maxattempts'` rather than read the column. That one clause is
     * raw, and it names only column identifiers this class declares.
     *
     * @param  string|string[]|null $taskTypes
     * @param  array<string, mixed> $values
     */
    private function reclaimWhere(
        string $cutoff,
        string|array|null $taskTypes,
        string $attemptsClause,
        array $values
    ): int {
        $query = $this->controller->application->database->queryBuilder()
            ->table($this->getQueueTableName())
            ->where('status', 'processing')
            ->whereNotNull('lockexpires')
            ->where('lockexpires', '<', $cutoff)
            ->whereRaw($attemptsClause);

        if ($taskTypes !== null) {
            $query->whereIn('type', is_array($taskTypes) ? $taskTypes : array($taskTypes));
        }

        $result = $query->update($values);

        return is_object($result) && method_exists($result, 'getAffectedRows')
            ? (int) $result->getAffectedRows()
            : 0;
    }

    /**
     * Is this queue keeping up? Arrivals against completions, over a window.
     *
     * The one number the installation that filed this did not have. A queue served by one
     * worker against **6.1 arrivals a second**, where a single worker tops out at 4.4, grew
     * by 1.7 a second for four hours and reached a backlog of 175,000 — and the dashboard
     * said "a few thousand pending" for most of it, because a depth is a level and this is
     * a rate. A level tells you where you are; only the rate tells you which way you are
     * going.
     *
     * `net` is the answer: **below zero, the queue is losing**, whatever the depth happens
     * to be. Everything else — a screen, an alert, an autoscaler — can be built on that
     * number, and none of it can be built without it.
     *
     * Completions count every terminal state, failures included: a task that failed is one
     * the queue is no longer carrying, and counting only successes reports a losing queue
     * during an outage where the work is in fact draining away.
     *
     * @param  int                  $windowSeconds How far back to measure
     * @param  string|string[]|null $taskTypes     Restrict to these type(s)
     * @return array{window:int,arrivals:int,completions:int,net:int,pending:int,processing:int,losing:bool}
     */
    public function throughput(
        int $windowSeconds = 300,
        string|array|null $taskTypes = null
    ): array {
        $this->refreshDatabaseConnection();

        $since = date('Y-m-d H:i:s', time() - max(1, $windowSeconds));

        $arrivals    = $this->countWhere($taskTypes, 'createdat', $since);
        $completions = $this->countWhere(
            $taskTypes,
            'completedat',
            $since,
            array('completed', 'warning', 'failed')
        );
        $pending    = $this->countWhere($taskTypes, null, null, array('pending'));
        $processing = $this->countWhere($taskTypes, null, null, array('processing'));

        return array(
            'window'      => max(1, $windowSeconds),
            'arrivals'    => $arrivals,
            'completions' => $completions,
            'net'         => $completions - $arrivals,
            'pending'     => $pending,
            'processing'  => $processing,
            'losing'      => $completions < $arrivals,
        );
    }

    /**
     * The task types the queue has actually carried, newest activity first.
     *
     * Read from the table rather than from {@see getTaskTypes()}, which scans a directory
     * of handler classes. The two answer different questions, and for "which queues are
     * moving" this is the right one: a type with a handler and no traffic is noise, and a
     * type with traffic and **no** handler is the thing somebody needs to see — the worker
     * fails every one of those with "No handler registered", and a directory scan cannot
     * show it.
     *
     * @param  int $windowSeconds Only types touched within this many seconds; 0 for all
     * @return string[]
     */
    public function activeTaskTypes(int $windowSeconds = 86400): array
    {
        $this->refreshDatabaseConnection();

        $query = $this->controller->application->database->queryBuilder()
            ->table($this->getQueueTableName())
            ->groupBy('type')
            ->orderBy('type', 'ASC');

        if ($windowSeconds > 0) {
            $query->where('createdat', '>=', date('Y-m-d H:i:s', time() - $windowSeconds));
        }

        /*
         * `pluck()`, not `get()`.
         *
         * `get()` returns a `Result`, not rows — and casting one to an array yields the
         * object's own properties, so the first version of this answered with the driver
         * name in the middle of the list. `pluck()` fetches the rows and takes the column.
         */
        return array_values(array_filter(
            $query->pluck('type'),
            static fn($type): bool => is_string($type) && $type !== ''
        ));
    }

    /**
     * One counting query for {@see throughput()}.
     *
     * @param  string|string[]|null $taskTypes
     * @param  string|null          $column Timestamp column to bound, or none
     * @param  string[]|null        $statuses
     */
    private function countWhere(
        string|array|null $taskTypes,
        ?string $column,
        ?string $since,
        ?array $statuses = null
    ): int {
        $query = $this->controller->application->database->queryBuilder()
            ->table($this->getQueueTableName());

        if ($column !== null && $since !== null) {
            $query->where($column, '>=', $since);
        }

        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        if ($taskTypes !== null) {
            $query->whereIn('type', is_array($taskTypes) ? $taskTypes : array($taskTypes));
        }

        return $query->count();
    }

    /**
     * Delete old terminal-state tasks to keep the table lean.
     *
     * @param  int      $hours    Tasks completed more than this many hours ago are eligible
     * @param  string[] $statuses Status values to purge (default: completed and failed)
     * @param  int      $limit    Maximum rows to delete per call (0 = unlimited)
     * @return int      Number of deleted rows
     */
    public function purgeOldTasks(
        int $hours = 24,
        array $statuses = ['completed', 'failed'],
        int $limit = 0
    ): int {
        $this->refreshDatabaseConnection();

        $cutoff      = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $statusList  = implode(',', array_map(
            fn(string $s) => "'" . $this->controller->application->database->prepareInput($s) . "'",
            $statuses
        ));

        $sql = 'DELETE FROM ' . $this->getQueueTableName()
             . " WHERE status IN ($statusList) AND completedat < '$cutoff'";

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $result = $this->controller->application->database->query($sql);
        return $result->getAffectedRows();
    }

    /**
     * Return the registered task types by scanning the configured tasks directory.
     *
     * Returns an empty array when getTasksDirectory() or getTasksNamespace()
     * returns an empty string. Concrete subclasses (e.g. the reference application
     * QueueManager) override these hooks to point at the app-specific Tasks/
     * directory.
     *
     * @return string[]
     */
    public function getTaskTypes(): array
    {
        $directory = $this->getTasksDirectory();
        $namespace = $this->getTasksNamespace();

        if ($directory === '' || $namespace === '' || !is_dir($directory)) {
            return [];
        }

        $types = [];
        foreach (new \DirectoryIterator($directory) as $fileinfo) {
            if (!$fileinfo->isFile() || $fileinfo->getExtension() !== 'php') {
                continue;
            }
            $class = $namespace . $fileinfo->getBasename('.php');
            if (!class_exists($class)) {
                continue;
            }
            $instance = new $class($this->controller);
            if (property_exists($instance, 'name') && (string)$instance->name !== '') {
                $types[] = (string)$instance->name;
            } else {
                $reflection = new \ReflectionClass($class);
                if ($reflection->isSubclassOf(AbstractTask::class)) {
                    $types[] = $reflection->getShortName();
                }
            }
        }

        sort($types);
        return $types;
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    /**
     * Absolute path to the directory that contains task handler PHP files.
     *
     * Return '' (default) to disable the getTaskTypes() directory scan.
     * Override in application subclasses:
     *
     *   protected function getTasksDirectory(): string
     *   {
     *       return __DIR__ . '/Tasks';
     *   }
     *
     * @return string
     */
    protected function getTasksDirectory(): string
    {
        return '';
    }

    /**
     * Fully-qualified namespace prefix (with trailing backslash) for task
     * handler classes inside getTasksDirectory().
     *
     * Return '' (default) to disable the getTaskTypes() directory scan.
     * Override in application subclasses:
     *
     *   protected function getTasksNamespace(): string
     *   {
     *       return 'MyApp\\Services\\Queue\\Tasks\\';
     *   }
     *
     * @return string
     */
    protected function getTasksNamespace(): string
    {
        return '';
    }

    /**
     * Resolved database table name for raw SQL operations.
     *
     * The framework creates the table as 'queueitems' (no prefix). Override
     * if your application uses a different name or an explicit prefix.
     *
     * @return string
     */
    protected function getQueueTableName(): string
    {
        return 'queueitems';
    }

    /**
     * A controller for the queue's models to hold — the named one, or a plain one.
     *
     * `Application\Model` takes a `Controller` in its constructor and will not be constructed
     * without one, so every command that touches a queue model has to produce one. They asked
     * for `Queueitems`, which is the name of the *administration screen* for queued jobs — and
     * `Application::getController()` throws when a name does not resolve.
     *
     * So `queue:process` could not start at all in an application that had no such screen:
     * «Cannot find controller: Queueitems», from a background worker, about a UI class it was
     * never going to render anything with. Under a supervisor that is respawn, fail, repeat,
     * and the only visible symptom is a queue that never drains.
     *
     * A plain `Controller` is the right fallback: a command does not use it for the things the
     * screen-URL helpers on `QueueItem` are for. An application that *has* the controller still
     * gets it, so a subclass carrying task handlers is never quietly replaced.
     *
     * Constructed with no argument, because `Controller` resolves the current application
     * itself — which by this point in any command is the one that was just initialised.
     *
     * @param  object $application Whatever the command holds as its application
     * @param  string $name        Controller to prefer
     * @return \Pramnos\Application\Controller
     */
    public static function controllerOrPlain($application, string $name)
    {
        try {
            return $application->getController($name);
        } catch (\Throwable) {
            return new \Pramnos\Application\Controller();
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Create a fresh QueueItem model instance.
     *
     * Override in subclasses to use an application-specific subclass of QueueItem.
     *
     * @return QueueItem
     */
    protected function createQueueItemModel(): QueueItem
    {
        return new QueueItem($this->controller);
    }

    /**
     * Generate a deterministic hash for type + payload for deduplication.
     *
     * @param  string $type
     * @param  mixed  $data
     * @return string  SHA-256 hex digest
     */
    private function generateTaskHash(string $type, mixed $data): string
    {
        if (is_array($data)) {
            ksort($data);
            $dataStr = (string)json_encode($data);
        } elseif (is_object($data)) {
            $dataStr = (string)json_encode($data);
        } else {
            $dataStr = (string)$data;
        }

        return hash('sha256', $type . $dataStr);
    }

    /**
     * Build a safe SQL IN list for one or more task type strings.
     *
     * @param  string|string[] $taskTypes
     * @return string  e.g. 'send_email','process_import'
     */
    private function buildTypeList(string|array $taskTypes): string
    {
        $types = is_array($taskTypes) ? $taskTypes : [$taskTypes];
        return implode(',', array_map(
            fn(string $t) => "'" . $this->controller->application->database->prepareInput($t) . "'",
            $types
        ));
    }

    /**
     * Calculate wall-clock seconds from task->startedat to now.
     *
     * Returns null when startedat is not set or cannot be parsed.
     *
     * @param  QueueItem $task
     * @return float|null
     */
    private function calculateExecutionTime(QueueItem $task): ?float
    {
        if (empty($task->startedat)) {
            return null;
        }
        $start = strtotime((string)$task->startedat);
        return $start ? round(time() - $start, 3) : null;
    }

    /**
     * Verify the database connection is alive and attempt to reconnect if not.
     *
     * Designed to be called at the start of long-running operations to catch
     * stale connections before they cause mid-operation failures.
     *
     * @return void
     */
    private function refreshDatabaseConnection(): void
    {
        if (!$this->controller->application->database) {
            return;
        }
        try {
            $this->controller->application->database->query('SELECT 1');
        } catch (\Throwable $e) {
            if (!$this->controller->application->database->tryReconnect()) {
                throw new \RuntimeException('Database connection unavailable', 0, $e);
            }
        }
    }
}
