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
 * @package     PramnosFramework
 * @subpackage  Queue
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
     * Claim and return the next available task, atomically marking it as
     * 'processing' with a lock expiry.
     *
     * The method first looks for pending tasks (fast path) and only falls back
     * to scanning for stalled processing tasks (slow path) when nothing is
     * pending. This split avoids OR conditions that defeat composite indexes.
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

        $task = false;

        // High-priority recent tasks if $startfrom is set
        if ($startfrom > 0) {
            $startDate = date('Y-m-d H:i:s', $startfrom);
            $rows = $model->getList(
                "WHERE status = 'pending' AND createdat >= '$startDate' AND priority <= 10" . $typeClause,
                'ORDER BY priority ASC, createdat ASC LIMIT 1'
            );
            if (!empty($rows)) {
                $task = reset($rows);
            }
        }

        if ($task === false) {
            $order = $reverse ? 'ORDER BY priority ASC, createdat DESC LIMIT 1'
                              : 'ORDER BY priority ASC, createdat ASC LIMIT 1';
            $pending = $model->getList("WHERE status = 'pending'" . $typeClause, $order);
            if (!empty($pending)) {
                $task = reset($pending);
            }
        }

        // Stalled processing tasks (lock expired, attempts remaining)
        if ($task === false) {
            $stalled = $model->getList(
                "WHERE status = 'processing' AND attempts < maxattempts AND lockexpires < '$now'" . $typeClause,
                'ORDER BY priority ASC, createdat ASC LIMIT 1'
            );
            if (!empty($stalled)) {
                $task = reset($stalled);
            }
        }

        if ($task === false) {
            return false;
        }

        // Atomically claim the task
        $task->status      = 'processing';
        $task->attempts    = (int)$task->attempts + 1;
        $task->startedat   = $now;
        $task->lockedby    = $this->workerId;
        $task->lockexpires = $lockExpiry;
        $task->save();

        return $task;
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
     * returns an empty string. Concrete subclasses (e.g. the Urbanwater
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
