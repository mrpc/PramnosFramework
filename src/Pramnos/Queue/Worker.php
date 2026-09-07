<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * Dispatches queue items to the appropriate TaskInterface handler.
 *
 * The Worker fetches the next available task from QueueManager, resolves the
 * handler class from its internal registry, and runs the full execute →
 * validate → markCompleted/Failed lifecycle.
 *
 * Register handlers before processing:
 *
 *   $worker = new Worker($controller, 'worker-1');
 *   $worker->registerTaskHandler('send_email', SendEmailTask::class);
 *   $worker->registerTaskHandler('import_csv', ImportCsvTask::class);
 *
 *   while (true) {
 *       $info = $worker->processNextTask();
 *       if (!$info) { sleep(5); }
 *   }
 *
 */
class Worker
{
    /**
     * The application controller — passed to QueueManager and task handlers.
     *
     * @var \Pramnos\Application\Controller
     */
    protected $controller;

    /**
     * The queue manager used to claim and update tasks.
     *
     * @var QueueManager
     */
    protected QueueManager $queueManager;

    /**
     * Map of task type name → fully-qualified handler class name.
     *
     * Populated via registerTaskHandler(). Applications override this property
     * or call registerTaskHandler() to wire up their task types.
     *
     * Framework default is empty — no tasks are registered out of the box.
     *
     * @var array<string, string>
     */
    protected array $taskHandlers = [];

    /**
     * @param \Pramnos\Application\Controller $controller
     * @param string|null                     $workerId   Optional worker identifier
     */
    public function __construct($controller, ?string $workerId = null)
    {
        $this->controller   = $controller;
        $this->queueManager = $this->createQueueManager($controller, $workerId);
    }

    // ── Processing ────────────────────────────────────────────────────────────

    /**
     * Claim the next available task and run it.
     *
     * @param  string|string[]|null $taskTypes        Restrict to these type(s)
     * @param  int|null             $startFromTimestamp  Only process tasks created at or after this timestamp
     * @param  bool                 $reverseOrder     Process newest-first
     * @return array{id:mixed,type:string,status:string,message?:string,execution_time?:float}|false
     *         Task result details, or false when no task was available
     */
    public function processNextTask(
        string|array|null $taskTypes = null,
        ?int $startFromTimestamp = null,
        bool $reverseOrder = false
    ): array|false {
        $task = $this->queueManager->getNextTask(
            $taskTypes,
            300,
            $reverseOrder,
            (int)($startFromTimestamp ?? 0)
        );

        if (!$task) {
            return false;
        }

        $taskInfo = [
            'id'     => $task->taskid,
            'type'   => $task->type,
            'status' => 'processing',
        ];

        $handlerClass = $this->getTaskHandler((string)$task->type);
        if (!$handlerClass) {
            $this->queueManager->markTaskAsFailed(
                $task,
                'No handler registered for task type: ' . $task->type
            );
            $taskInfo['status']  = 'failed';
            $taskInfo['message'] = 'No handler registered for task type: ' . $task->type;
            return $taskInfo;
        }

        $startTime = microtime(true);
        $startCpu  = $this->cpuSeconds();

        try {
            /** @var TaskInterface $handler */
            $handler = new $handlerClass($this->controller);

            if (!$handler->validate($task)) {
                $this->queueManager->markTaskAsFailed($task, 'Task validation failed');
                $taskInfo['status']  = 'failed';
                $taskInfo['message'] = 'Task validation failed';
                return $taskInfo;
            }

            $result        = $handler->execute($task);
            $executionTime = (float)(microtime(true) - $startTime);
            $this->recordCpu($task, $taskInfo, $startCpu);

            if (is_array($result)) {
                if (isset($result['warning'])) {
                    $taskInfo['status']         = 'warning';
                    $taskInfo['message']        = (string)$result['warning'];
                    $taskInfo['execution_time'] = $executionTime;
                    $this->queueManager->markTaskAsWarning($task, $taskInfo['message'], $executionTime);
                } else {
                    $taskInfo['status']         = 'completed';
                    $taskInfo['message']        = (string)($result['message'] ?? 'Task completed successfully');
                    $taskInfo['execution_time'] = $executionTime;
                    $this->queueManager->markTaskAsCompleted($task, $taskInfo['message'], $executionTime);
                }
            } elseif ($result === true) {
                $lastMsg = property_exists($handler, 'lastMessage') ? (string)$handler->lastMessage : '';
                $taskInfo['status']         = 'completed';
                $taskInfo['message']        = $lastMsg !== '' ? $lastMsg : 'Task completed successfully';
                $taskInfo['execution_time'] = $executionTime;
                $this->queueManager->markTaskAsCompleted($task, $taskInfo['message'], $executionTime);
            } else {
                $lastMsg = property_exists($handler, 'lastMessage') ? (string)$handler->lastMessage : '';
                $taskInfo['status']         = 'failed';
                $taskInfo['message']        = 'Task processing returned false. ' . $lastMsg;
                $taskInfo['execution_time'] = $executionTime;
                $this->queueManager->markTaskAsFailed($task, $taskInfo['message'], $executionTime);
            }
        } catch (\Throwable $e) {
            $executionTime = (float)(microtime(true) - $startTime);
            $this->recordCpu($task, $taskInfo, $startCpu);

            $shouldRetry = true;
            if (isset($handler)) {
                try {
                    $shouldRetry = $handler->handleFailure($task, $e);
                } catch (\Throwable) {
                    $shouldRetry = false;
                }
            }

            $errorMessage = get_class($e) . ': ' . $e->getMessage();
            $taskInfo['status']         = 'failed';
            $taskInfo['message']        = $errorMessage;
            $taskInfo['execution_time'] = $executionTime;

            $this->queueManager->markTaskAsFailed($task, $errorMessage, $executionTime);
        }

        return $taskInfo;
    }

    /**
     * CPU seconds this process has used, or null where that cannot be known.
     *
     * `getrusage()` is POSIX and absent on some builds; null rather than 0.0, because a zero
     * reads as *this task did no work* where the truth is *nobody measured*.
     *
     * User **and** system time, added: a task blocked in a `read()` accrues neither, which is
     * the whole point, but one that is busy in the kernel — writing a large payload, resolving
     * a name — is working and should say so.
     *
     * @return float|null
     */
    protected function cpuSeconds(): ?float
    {
        if (!function_exists('getrusage')) {
            return null;
        }

        $usage = getrusage();

        if (!is_array($usage)) {
            return null;
        }

        return ($usage['ru_utime.tv_sec'] ?? 0) + (($usage['ru_utime.tv_usec'] ?? 0) / 1e6)
            + ($usage['ru_stime.tv_sec'] ?? 0) + (($usage['ru_stime.tv_usec'] ?? 0) / 1e6);
    }

    /**
     * Note how much of this task's wall clock was actually computing.
     *
     * **Wall clock alone is why four wrong diagnoses all looked right.** A task taking 0.9
     * seconds looks the same whether it is working or waiting, and `execution_time` is wall
     * clock around the handler — so an installation chasing queue throughput ruled in an
     * atomic claim, a missing index, TimescaleDB compression and a CPU-bound handler, in that
     * order, over four hours. The tasks were at **1.6% CPU**, waiting on a cache invalidation
     * that scanned the whole redis keyspace per model save, and only `/proc/<pid>/wchan`
     * said so.
     *
     * Their conclusion, and it is right: a queue that recorded CPU time beside wall time
     * *"would have said 'these tasks are not computing' in the first ten minutes."*
     *
     * Written onto the task rather than passed to `markTaskAsCompleted()`, which is public
     * and overridable — a fifth argument there is a fatal at class load for every application
     * that has its own.
     *
     * @param array<string, mixed> $taskInfo
     */
    protected function recordCpu(QueueItem $task, array &$taskInfo, ?float $startCpu): void
    {
        $endCpu = $this->cpuSeconds();

        if ($startCpu === null || $endCpu === null) {
            return;
        }

        // Never negative: a counter that appears to go backwards is a measurement to discard,
        // not a number to store.
        $cpu = max(0.0, $endCpu - $startCpu);

        $task->cpu_time     = $cpu;
        $taskInfo['cpu_time'] = $cpu;
    }

    /**
     * Run a processing loop for a bounded time or task count.
     *
     * Intended for use in scripts that are managed externally (e.g. cron).
     * For long-running daemon workers, use processNextTask() directly inside
     * a loop managed by ProcessQueue (which handles signals, lock files, etc.).
     *
     * @param  int                  $maxRuntime  Seconds to run (0 = unlimited)
     * @param  int                  $maxTasks    Tasks to process (0 = unlimited)
     * @param  int                  $sleepTime   Seconds to sleep when queue is empty
     * @param  string|string[]|null $taskTypes   Restrict to these type(s)
     * @return int  Total tasks processed
     */
    public function run(
        int $maxRuntime = 60,
        int $maxTasks = 0,
        int $sleepTime = 5,
        string|array|null $taskTypes = null
    ): int {
        $startTime = time();
        $taskCount = 0;

        while (true) {
            if ($maxRuntime > 0 && (time() - $startTime) >= $maxRuntime) {
                break;
            }
            if ($maxTasks > 0 && $taskCount >= $maxTasks) {
                break;
            }

            $result = $this->processNextTask($taskTypes);
            if ($result !== false) {
                $taskCount++;
            } else {
                sleep($sleepTime);
            }
        }

        return $taskCount;
    }

    // ── Handler registry ──────────────────────────────────────────────────────

    /**
     * Register a handler class for a task type.
     *
     * @param  string $taskType     Task type name (matches QueueItem::$type)
     * @param  string $handlerClass Fully-qualified class name implementing TaskInterface
     * @return static
     */
    public function registerTaskHandler(string $taskType, string $handlerClass): static
    {
        $this->taskHandlers[$taskType] = $handlerClass;
        return $this;
    }

    /**
     * Look up the handler class for a given task type.
     *
     * @param  string $taskType
     * @return string|null  Class name, or null if no handler is registered
     */
    protected function getTaskHandler(string $taskType): ?string
    {
        return $this->taskHandlers[$taskType] ?? null;
    }

    // ── Factory hook ──────────────────────────────────────────────────────────

    /**
     * Create the QueueManager instance used by this Worker.
     *
     * Override to use an application-specific QueueManager subclass.
     *
     * @param  \Pramnos\Application\Controller $controller
     * @param  string|null                     $workerId
     * @return QueueManager
     */
    protected function createQueueManager($controller, ?string $workerId): QueueManager
    {
        return new QueueManager($controller, $workerId);
    }
}
