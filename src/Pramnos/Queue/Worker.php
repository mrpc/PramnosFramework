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
