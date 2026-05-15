<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Pramnos\Queue\Worker;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\QueueItem;
use Pramnos\Queue\TaskInterface;
use Pramnos\Queue\AbstractTask;

/**
 * Unit tests for Pramnos\Queue\Worker.
 *
 * All database and filesystem I/O is stubbed. Tests cover:
 *   - processNextTask() returns false when the queue is empty
 *   - processNextTask() marks task as failed when no handler is registered
 *   - processNextTask() marks task as failed when validation fails
 *   - processNextTask() marks task as completed on true return
 *   - processNextTask() marks task as completed with message on array return
 *   - processNextTask() marks task as warning on ['warning'=> ...] return
 *   - processNextTask() marks task as failed on false return
 *   - processNextTask() catches exceptions and marks as failed
 *   - registerTaskHandler() makes a handler available
 *   - Task handlers registered after construction are used immediately
 */
class WorkerTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        $this->controller = $this->buildControllerDouble();
    }

    // ── processNextTask() — empty queue ───────────────────────────────────────

    /**
     * processNextTask() must return false when no task is available.
     * Workers rely on this to decide whether to sleep before the next poll.
     */
    public function testProcessNextTaskReturnsFalseWhenQueueEmpty(): void
    {
        // Arrange — QueueManager that never returns a task
        $worker = $this->buildWorkerWithManager(fn() => false);

        // Act
        $result = $worker->processNextTask();

        // Assert
        $this->assertFalse($result);
    }

    // ── processNextTask() — no handler ────────────────────────────────────────

    /**
     * processNextTask() must mark a task as failed and return a 'failed' info
     * array when no handler is registered for the task type.
     * This prevents tasks from silently disappearing.
     */
    public function testProcessNextTaskFailsWhenNoHandlerRegistered(): void
    {
        // Arrange — queue returns a task; no handler registered for its type
        $task       = $this->buildQueueItem('unregistered_type');
        $markedFail = false;

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            onFail: function () use (&$markedFail) { $markedFail = true; }
        );

        // Act
        $result = $worker->processNextTask();

        // Assert — task must be reported as failed, not silently ignored
        $this->assertIsArray($result);
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($markedFail, 'markTaskAsFailed() must be called');
    }

    // ── processNextTask() — validation failure ────────────────────────────────

    /**
     * processNextTask() must mark a task as failed when the handler's validate()
     * returns false — the task had an invalid payload from the start.
     */
    public function testProcessNextTaskFailsOnValidationFailure(): void
    {
        // Arrange — handler whose validate() always returns false
        $task       = $this->buildQueueItem('validate_fail');
        $markedFail = false;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool    { return false; }
            public function execute(QueueItem $q): mixed    { return true; }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            handler: $handler,
            onFail: function () use (&$markedFail) { $markedFail = true; }
        );
        $worker->registerTaskHandler('validate_fail', get_class($handler));

        // Act
        $result = $worker->processNextTask();

        // Assert
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($markedFail);
    }

    // ── processNextTask() — successful execution ──────────────────────────────

    /**
     * processNextTask() marks task as 'completed' and returns status='completed'
     * when the handler's execute() returns true.
     */
    public function testProcessNextTaskCompletesOnTrueReturn(): void
    {
        // Arrange
        $task          = $this->buildQueueItem('simple_task');
        $markedComplete = false;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool    { return true; }
            public function execute(QueueItem $q): mixed    { return true; }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            handler: $handler,
            onComplete: function () use (&$markedComplete) { $markedComplete = true; }
        );
        $worker->registerTaskHandler('simple_task', get_class($handler));

        // Act
        $result = $worker->processNextTask();

        // Assert
        $this->assertSame('completed', $result['status']);
        $this->assertTrue($markedComplete, 'markTaskAsCompleted() must be called');
    }

    /**
     * processNextTask() uses the 'message' key from the result array as the
     * success message when execute() returns ['message' => '...'].
     */
    public function testProcessNextTaskCompletesWithMessageFromArrayReturn(): void
    {
        // Arrange
        $task    = $this->buildQueueItem('array_task');
        $message = null;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool    { return true; }
            public function execute(QueueItem $q): mixed    { return ['message' => 'Processed 42 records']; }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            handler: $handler,
            onComplete: function (string $msg) use (&$message) { $message = $msg; }
        );
        $worker->registerTaskHandler('array_task', get_class($handler));

        // Act
        $result = $worker->processNextTask();

        // Assert
        $this->assertSame('completed', $result['status']);
        $this->assertSame('Processed 42 records', $result['message'] ?? null);
    }

    /**
     * processNextTask() sets status='warning' when execute() returns
     * ['warning' => '...'] — the task completed but with non-fatal issues.
     */
    public function testProcessNextTaskSetsWarningOnWarningReturn(): void
    {
        // Arrange
        $task           = $this->buildQueueItem('warn_task');
        $markedWarning  = false;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool    { return true; }
            public function execute(QueueItem $q): mixed    { return ['warning' => 'Partial import: 3 rows skipped']; }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            handler: $handler,
            onWarning: function () use (&$markedWarning) { $markedWarning = true; }
        );
        $worker->registerTaskHandler('warn_task', get_class($handler));

        // Act
        $result = $worker->processNextTask();

        // Assert
        $this->assertSame('warning', $result['status']);
        $this->assertTrue($markedWarning, 'markTaskAsWarning() must be called');
    }

    /**
     * processNextTask() marks as failed when execute() returns false.
     */
    public function testProcessNextTaskFailsOnFalseReturn(): void
    {
        // Arrange
        $task       = $this->buildQueueItem('fail_task');
        $markedFail = false;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool    { return true; }
            public function execute(QueueItem $q): mixed    { return false; }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            handler: $handler,
            onFail: function () use (&$markedFail) { $markedFail = true; }
        );
        $worker->registerTaskHandler('fail_task', get_class($handler));

        // Act
        $result = $worker->processNextTask();

        // Assert
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($markedFail);
    }

    /**
     * processNextTask() must catch exceptions thrown by execute() and mark the
     * task as failed. Uncaught exceptions would kill the worker daemon.
     */
    public function testProcessNextTaskCatchesExceptions(): void
    {
        // Arrange
        $task       = $this->buildQueueItem('throwing_task');
        $markedFail = false;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool    { return true; }
            public function execute(QueueItem $q): mixed    { throw new \RuntimeException('Something exploded'); }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            handler: $handler,
            onFail: function () use (&$markedFail) { $markedFail = true; }
        );
        $worker->registerTaskHandler('throwing_task', get_class($handler));

        // Act — must not throw
        $result = $worker->processNextTask();

        // Assert
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('exploded', $result['message'] ?? '');
        $this->assertTrue($markedFail);
    }

    // ── processNextTask() — handleFailure() also throws ──────────────────────

    /**
     * processNextTask() must still mark the task as failed even when both
     * execute() AND handleFailure() throw — the inner catch (lines 156-160 in
     * Worker.php) handles the nested exception and sets shouldRetry=false.
     */
    public function testProcessNextTaskFailsWhenHandleFailureAlsoThrows(): void
    {
        // Arrange — handler whose execute() and handleFailure() both throw
        $task       = $this->buildQueueItem('double_throw_task');
        $markedFail = false;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool  { return true; }
            public function execute(QueueItem $q): mixed  { throw new \RuntimeException('exec error'); }
            public function handleFailure(QueueItem $q, \Throwable $e): bool
            {
                throw new \RuntimeException('handleFailure also broke');
            }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            fn() => $task,
            handler: $handler,
            onFail: function () use (&$markedFail) { $markedFail = true; }
        );
        $worker->registerTaskHandler('double_throw_task', get_class($handler));

        // Act — must not propagate either exception
        $result = $worker->processNextTask();

        // Assert — task is still marked failed despite the double exception
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($markedFail, 'markTaskAsFailed() must be called even when handleFailure() throws');
    }

    /**
     * processNextTask() must expose $handler->lastMessage when execute() returns
     * true — the Worker reads it after success to pass a descriptive message to
     * markTaskAsCompleted().
     */
    public function testProcessNextTaskUsesHandlerLastMessageOnSuccess(): void
    {
        // Arrange — handler that sets $lastMessage and returns true
        $task = $this->buildQueueItem('msg_task');

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool  { return true; }
            public function execute(QueueItem $q): mixed
            {
                $this->lastMessage = 'Processed 99 records';
                return true;
            }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(fn() => $task, handler: $handler);
        $worker->registerTaskHandler('msg_task', get_class($handler));

        // Act
        $result = $worker->processNextTask();

        // Assert — lastMessage surfaces in taskInfo['message']
        $this->assertSame('completed', $result['status']);
        $this->assertSame('Processed 99 records', $result['message']);
    }

    // ── run() ─────────────────────────────────────────────────────────────────

    /**
     * run() must stop after processing maxTasks tasks, even when more tasks are
     * available. This prevents a single worker from monopolising the queue.
     */
    public function testRunStopsAfterMaxTasksReached(): void
    {
        // Arrange — queue returns 3 tasks in sequence; we cap at 2
        $taskQueue = [
            $this->buildQueueItem('run_task'),
            $this->buildQueueItem('run_task'),
            $this->buildQueueItem('run_task'),
        ];
        $idx = 0;

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool  { return true; }
            public function execute(QueueItem $q): mixed  { return true; }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            function () use (&$taskQueue, &$idx) {
                return $taskQueue[$idx++] ?? false;
            },
            handler: $handler
        );
        $worker->registerTaskHandler('run_task', get_class($handler));

        // Act — maxRuntime=60s gives plenty of wall time; maxTasks=2 is the real stopper
        $count = $worker->run(maxRuntime: 60, maxTasks: 2, sleepTime: 0);

        // Assert — exactly 2 tasks processed, not 3
        $this->assertSame(2, $count);
    }

    /**
     * run() must return 0 when the queue is empty and maxTasks=1 with
     * sleepTime=0 (sleep(0) is a no-op), breaking on maxTasks when tasks DO
     * arrive.  This variant tests the empty-queue branch with a task appearing
     * on the second poll.
     */
    public function testRunReturnsCountWhenOneTaskEventuallyAppears(): void
    {
        // Arrange — first poll empty, second poll has a task; maxTasks=1 stops then
        $calls  = 0;
        $task   = $this->buildQueueItem('deferred_task');

        $handler = new class($this->controller) extends AbstractTask {
            public function validate(QueueItem $q): bool  { return true; }
            public function execute(QueueItem $q): mixed  { return true; }
            public function getDescription(QueueItem $q): string { return 'test'; }
        };

        $worker = $this->buildWorkerWithManager(
            function () use (&$calls, $task) {
                $calls++;
                // First call returns false (empty queue), second returns the task
                return $calls >= 2 ? $task : false;
            },
            handler: $handler
        );
        $worker->registerTaskHandler('deferred_task', get_class($handler));

        // Act — sleepTime=0 so sleep(0) is harmless
        $count = $worker->run(maxRuntime: 60, maxTasks: 1, sleepTime: 0);

        // Assert — 1 task processed after 1 empty-queue poll
        $this->assertSame(1, $count);
    }

    // ── registerTaskHandler() ─────────────────────────────────────────────────

    /**
     * registerTaskHandler() makes a handler available for subsequent
     * processNextTask() calls — it returns $this for fluent chaining.
     */
    public function testRegisterTaskHandlerIsChainable(): void
    {
        // Arrange
        $worker = $this->buildWorkerWithManager(fn() => false);

        // Act — fluent chain
        $return = $worker
            ->registerTaskHandler('type_a', \stdClass::class)
            ->registerTaskHandler('type_b', \stdClass::class);

        // Assert — returns the same Worker instance
        $this->assertSame($worker, $return);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a Worker with a fully stubbed QueueManager.
     *
     * @param  callable            $getNextTask  Returns QueueItem|false
     * @param  object|null         $handler      AbstractTask instance to instantiate (class name used)
     * @param  callable|null       $onComplete
     * @param  callable|null       $onFail
     * @param  callable|null       $onWarning
     * @return Worker
     */
    private function buildWorkerWithManager(
        callable $getNextTask,
        ?object $handler = null,
        ?callable $onComplete = null,
        ?callable $onFail = null,
        ?callable $onWarning = null
    ): Worker {
        $controller = $this->controller;

        return new class(
            $controller,
            null,
            $getNextTask,
            $handler,
            $onComplete,
            $onFail,
            $onWarning
        ) extends Worker {
            private $getNextTaskFn;
            private $onCompleteFn;
            private $onFailFn;
            private $onWarningFn;

            public function __construct(
                $controller,
                $workerId,
                callable $getNextTask,
                ?object $handler,
                ?callable $onComplete,
                ?callable $onFail,
                ?callable $onWarning
            ) {
                $this->controller      = $controller;
                $this->getNextTaskFn   = $getNextTask;
                $this->onCompleteFn    = $onComplete;
                $this->onFailFn        = $onFail;
                $this->onWarningFn     = $onWarning;
                $this->queueManager    = $this->buildManager($controller, $getNextTask, $onComplete, $onFail, $onWarning);
            }

            private function buildManager($controller, $getNextTaskFn, $onComplete, $onFail, $onWarning): QueueManager
            {
                return new class($controller, $getNextTaskFn, $onComplete, $onFail, $onWarning) extends QueueManager {
                    private $getNextTaskFn;
                    private $onCompleteFn;
                    private $onFailFn;
                    private $onWarningFn;

                    public function __construct($c, $fn, $onC, $onF, $onW) {
                        $this->controller    = $c;
                        $this->workerId      = 'test';
                        $this->getNextTaskFn = $fn;
                        $this->onCompleteFn  = $onC;
                        $this->onFailFn      = $onF;
                        $this->onWarningFn   = $onW;
                    }

                    public function getNextTask($taskTypes = null, int $lockSeconds = 300, bool $reverse = false, int $startfrom = 0): QueueItem|false
                    {
                        return ($this->getNextTaskFn)();
                    }

                    public function markTaskAsCompleted(QueueItem $task, ?string $msg = null, ?float $time = null): void
                    {
                        if ($this->onCompleteFn) { ($this->onCompleteFn)((string)($msg ?? '')); }
                    }

                    public function markTaskAsFailed(QueueItem $task, ?string $msg = null, ?float $time = null): void
                    {
                        if ($this->onFailFn) { ($this->onFailFn)(); }
                    }

                    public function markTaskAsWarning(QueueItem $task, string $msg, ?float $time = null): void
                    {
                        if ($this->onWarningFn) { ($this->onWarningFn)(); }
                    }
                };
            }
        };
    }

    /**
     * Build a minimal QueueItem with a populated type field.
     */
    private function buildQueueItem(string $type): QueueItem
    {
        $item          = new class($this->controller) extends QueueItem {
            public function __construct($c) {}
            public function save($a = false, $d = false): static { return $this; }
        };
        $item->taskid     = 1;
        $item->type       = $type;
        $item->payload    = '{}';
        $item->attempts   = 1;
        $item->maxattempts = 3;
        return $item;
    }

    private function buildControllerDouble(): object
    {
        return new class {
            public object $application;
            public function __construct()
            {
                $this->application = new class {
                    public object $database;
                    public function __construct() {
                        $this->database = new class {
                            public function prepareInput(string $s): string { return $s; }
                        };
                    }
                };
            }
        };
    }
}
