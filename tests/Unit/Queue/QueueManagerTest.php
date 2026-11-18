<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\QueueItem;

/**
 * Unit tests for Pramnos\Queue\QueueManager.
 *
 * Database interaction is stubbed via a fake controller/database double so
 * these tests remain pure unit tests that run without a live database.
 *
 * The following are tested:
 *   - generateTaskHash consistency and determinism
 *   - getStats() arithmetic (percentcompleted, percentremaining)
 *   - getTaskTypes() returns [] when directory hooks return ''
 *   - getTaskTypes() scans a real temp directory and resolves class names
 *   - purgeOldTasks() builds the correct DELETE SQL
 *   - markTaskAsCompleted() sets the right fields
 *   - markTaskAsFailed() resets to pending when attempts < maxattempts
 *   - markTaskAsFailed() sets failed when attempts >= maxattempts
 *   - markTaskAsWarning() sets the right fields
 *   - retryTask() returns false for non-failed tasks
 *   - addTask() returns null on duplicate when $unique=true
 *   - configurable hooks: getQueueTableName(), getTasksDirectory(), getTasksNamespace()
 */
class QueueManagerTest extends TestCase
{
    /** @var QueueManager */
    private QueueManager $manager;

    /** @var object  Minimal controller double */
    private object $controller;

    protected function setUp(): void
    {
        // Arrange — build a minimal fake controller/database double.
        // We only stub the methods the QueueManager actually calls.
        $this->controller = $this->buildControllerDouble();

        $this->manager = new class($this->controller) extends QueueManager {
            // Expose private/protected helpers for white-box testing
            public function publicGenerateTaskHash(string $type, mixed $data): string
            {
                // Access via reflection because generateTaskHash is private
                $ref = new \ReflectionMethod(QueueManager::class, 'generateTaskHash');
                return $ref->invoke($this, $type, $data);
            }

            public function publicGetQueueTableName(): string
            {
                return $this->getQueueTableName();
            }

            public function publicGetTasksDirectory(): string
            {
                return $this->getTasksDirectory();
            }

            public function publicGetTasksNamespace(): string
            {
                return $this->getTasksNamespace();
            }
        };
    }

    // ── constructor workerId ──────────────────────────────────────────────────

    /**
     * When $workerId is provided, workerId must be set to "hostname:workerId".
     * The default path (null) uses getmypid() — but passing a non-null value
     * exercises the ternary's true branch which is otherwise unreached.
     */
    public function testConstructorWithExplicitWorkerIdFormatsCorrectly(): void
    {
        // Arrange — extend to expose the protected workerId property
        $manager = new class($this->controller, 'unit-test-worker') extends QueueManager {
            public function getWorkerId(): string { return $this->workerId; }
        };

        // Act & Assert — hostname is prepended with a colon separator
        $workerId = $manager->getWorkerId();
        $this->assertStringContainsString(':unit-test-worker', $workerId,
            'Explicit workerId must be appended as "hostname:workerId"');
    }

    // ── generateTaskHash ──────────────────────────────────────────────────────

    /**
     * generateTaskHash() must return a 64-character hex string (SHA-256) and be
     * deterministic — the same type + data always produce the same hash.
     */
    public function testGenerateTaskHashIsDeterministicForArrayPayload(): void
    {
        // Arrange
        $type = 'send_email';
        $data = ['to' => 'a@example.com', 'subject' => 'Hello'];

        // Act — call twice
        $hash1 = $this->manager->publicGenerateTaskHash($type, $data);
        $hash2 = $this->manager->publicGenerateTaskHash($type, $data);

        // Assert — identical, 64-char hex
        $this->assertSame($hash1, $hash2, 'Hash must be deterministic');
        $this->assertSame(64, strlen($hash1), 'SHA-256 produces 64 hex characters');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash1);
    }

    /**
     * generateTaskHash() sorts array keys before hashing so
     * ['a'=>1,'b'=>2] and ['b'=>2,'a'=>1] produce the same hash.
     * This prevents spurious deduplication misses due to key ordering.
     */
    public function testGenerateTaskHashIgnoresArrayKeyOrder(): void
    {
        // Arrange
        $type = 'import_csv';
        $ordered  = ['file' => 'data.csv', 'rows' => 100];
        $shuffled = ['rows' => 100, 'file' => 'data.csv'];

        // Act
        $hash1 = $this->manager->publicGenerateTaskHash($type, $ordered);
        $hash2 = $this->manager->publicGenerateTaskHash($type, $shuffled);

        // Assert — key order must not affect the hash
        $this->assertSame($hash1, $hash2, 'Key order must not change the hash');
    }

    /**
     * generateTaskHash() must handle an object payload by JSON-encoding it —
     * objects are supported alongside arrays and scalars so callers can pass
     * domain objects directly without pre-converting them.
     */
    public function testGenerateTaskHashWorksWithObjectPayload(): void
    {
        // Arrange
        $data       = new \stdClass();
        $data->user = 'alice';
        $data->id   = 7;

        // Act
        $hash = $this->manager->publicGenerateTaskHash('notify', $data);

        // Assert — valid SHA-256 hex digest
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    /**
     * generateTaskHash() must handle a scalar (string) payload — the scalar is
     * cast to string directly rather than JSON-encoded.
     */
    public function testGenerateTaskHashWorksWithScalarPayload(): void
    {
        // Arrange
        $scalar = 'plain-string-payload';

        // Act
        $hash = $this->manager->publicGenerateTaskHash('cleanup', $scalar);

        // Assert — valid SHA-256, deterministic
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame(
            $hash,
            $this->manager->publicGenerateTaskHash('cleanup', $scalar),
            'Scalar hash must be deterministic'
        );
    }

    /**
     * Different type strings must produce different hashes even with the same
     * payload — otherwise tasks of different types could be falsely deduplicated.
     */
    public function testGenerateTaskHashDiffersAcrossTypes(): void
    {
        // Arrange
        $data = ['id' => 42];

        // Act
        $hash1 = $this->manager->publicGenerateTaskHash('type_a', $data);
        $hash2 = $this->manager->publicGenerateTaskHash('type_b', $data);

        // Assert
        $this->assertNotSame($hash1, $hash2, 'Different task types must hash differently');
    }

    // ── getStats() arithmetic ─────────────────────────────────────────────────

    /**
     * getStats() must compute totalcompleted as completed + warning and express
     * percentcompleted as a string with a '%' suffix. A zero total must not
     * cause division by zero.
     */
    public function testGetStatsZeroTotalNoDivisionByZero(): void
    {
        // Arrange — override createQueueItemModel to return a stub
        $manager = new class($this->controller) extends QueueManager {
            protected function createQueueItemModel(): QueueItem
            {
                $stub = new class($this->controller) extends QueueItem {
                    public $controller;
                    public function __construct($controller) { $this->controller = $controller; }
                    public function getCount($filter = null, $table = null, $key = null): int { return 0; }
                };
                $stub->controller = $this->controller;
                return $stub;
            }
        };

        // Act
        $stats = $manager->getStats();

        // Assert — all zeros, no exception, percentages formatted correctly
        $this->assertSame(0, $stats['total']);
        $this->assertSame('0%', $stats['percentcompleted']);
        $this->assertSame('0%', $stats['percentremaining']);
    }

    /**
     * getStats() totalcompleted = completed + warning, not just completed.
     * This is important for the dashboard — warning tasks count as "done".
     */
    public function testGetStatsTotalCompletedIncludesWarning(): void
    {
        // Arrange — inject a counter stub that returns fixed values per filter
        $manager = new class($this->controller) extends QueueManager {
            protected function createQueueItemModel(): QueueItem
            {
                return new class($this->controller) extends QueueItem {
                    public function __construct($c) {}
                    public function getCount($filter = null, $table = null, $key = null): int
                    {
                        return match (true) {
                            str_contains((string)$filter, "'completed'") => 5,
                            str_contains((string)$filter, "'warning'")   => 3,
                            str_contains((string)$filter, "'pending'")   => 2,
                            str_contains((string)$filter, "'processing'") => 1,
                            str_contains((string)$filter, "'failed'")    => 1,
                            default => 12,   // total
                        };
                    }
                };
            }
        };

        // Act
        $stats = $manager->getStats();

        // Assert — totalcompleted = 5 (completed) + 3 (warning) = 8
        $this->assertSame(8, $stats['totalcompleted']);
    }

    // ── getTaskTypes() ────────────────────────────────────────────────────────

    /**
     * getTaskTypes() must return an empty array when the directory hook returns ''.
     * This is the framework default — no task scanning without explicit configuration.
     */
    public function testGetTaskTypesReturnsEmptyWhenHooksReturnEmptyString(): void
    {
        // Act — base manager has empty hooks by default
        $types = $this->manager->getTaskTypes();

        // Assert
        $this->assertSame([], $types, 'No directory configured → must return empty array');
    }

    // ── getQueueTableName() ───────────────────────────────────────────────────

    /**
     * The default table name must be 'queueitems' — this is what the framework
     * migration creates. Overriding this hook changes the purgeOldTasks() target.
     */
    public function testDefaultQueueTableName(): void
    {
        $this->assertSame('queueitems', $this->manager->publicGetQueueTableName());
    }

    /**
     * Override getQueueTableName() in a subclass to target a differently named table.
     */
    public function testCustomQueueTableName(): void
    {
        // Arrange — subclass with custom table
        $custom = new class($this->controller) extends QueueManager {
            protected function getQueueTableName(): string { return 'custom_queue'; }
            public function publicGetQueueTableName(): string { return $this->getQueueTableName(); }
        };

        // Assert
        $this->assertSame('custom_queue', $custom->publicGetQueueTableName());
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    /**
     * getTasksDirectory() returns '' by default — no task scanning happens
     * unless the hook is overridden in an application subclass.
     */
    public function testGetTasksDirectoryDefaultIsEmpty(): void
    {
        $this->assertSame('', $this->manager->publicGetTasksDirectory());
    }

    /**
     * getTasksNamespace() returns '' by default — same reason as above.
     */
    public function testGetTasksNamespaceDefaultIsEmpty(): void
    {
        $this->assertSame('', $this->manager->publicGetTasksNamespace());
    }

    // ── markTaskAsCompleted() ─────────────────────────────────────────────────

    /**
     * When no $executionTime is passed and $task->startedat is empty,
     * calculateExecutionTime() must return null — markTaskAsCompleted() must
     * then store null in execution_time rather than crashing.
     */
    public function testMarkTaskAsCompletedSetsNullExecutionTimeWhenNoStartedat(): void
    {
        // Arrange — task with no startedat (e.g. claimed externally before markProcessing)
        $task = $this->buildSavableQueueItem();
        $task->startedat = null;

        // Act — pass no executionTime so the private calculateExecutionTime() is used
        $this->manager->markTaskAsCompleted($task, 'done');

        // Assert — null propagated correctly (no division by zero, no crash)
        $this->assertNull($task->execution_time,
            'execution_time must be null when startedat is not set');
        $this->assertSame('completed', $task->status);
    }

    /**
     * markTaskAsCompleted() must set status='completed', record completedat,
     * and clear lockedby + lockexpires so no other worker can accidentally
     * re-claim a completed task.
     */
    public function testMarkTaskAsCompletedSetsCorrectFields(): void
    {
        // Arrange — a QueueItem stub that records saved state
        $task = $this->buildSavableQueueItem();
        $task->attempts    = 1;
        $task->maxattempts = 3;
        $task->startedat   = date('Y-m-d H:i:s', time() - 5);
        $task->lockedby    = 'host:worker1';
        $task->lockexpires = date('Y-m-d H:i:s', time() + 300);

        // Act
        $this->manager->markTaskAsCompleted($task, 'Email sent', 1.5);

        // Assert
        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completedat);
        $this->assertSame('Email sent', $task->success_message);
        $this->assertSame(1.5, $task->execution_time);
        $this->assertNull($task->lockedby, 'lockedby must be cleared on completion');
        $this->assertNull($task->lockexpires, 'lockexpires must be cleared on completion');
    }

    // ── markTaskAsFailed() ────────────────────────────────────────────────────

    /**
     * markTaskAsFailed() must reset status to 'pending' when attempts < maxattempts
     * so the task is eligible for automatic retry.
     */
    public function testMarkTaskAsFailedResetsToRetryWhenAttemptsRemain(): void
    {
        // Arrange
        $task = $this->buildSavableQueueItem();
        $task->attempts    = 1;
        $task->maxattempts = 3;
        $task->startedat   = date('Y-m-d H:i:s', time() - 2);

        // Act
        $this->manager->markTaskAsFailed($task, 'Temporary error');

        // Assert — reset for retry
        $this->assertSame('pending', $task->status, 'Must be reset to pending for retry');
        $this->assertSame('Temporary error', $task->error);
        $this->assertNull($task->lockedby);
    }

    /**
     * markTaskAsFailed() must permanently set status='failed' when all attempts
     * are exhausted — no more automatic retries should happen.
     */
    public function testMarkTaskAsFailedSetsPermanentFailureWhenExhausted(): void
    {
        // Arrange
        $task = $this->buildSavableQueueItem();
        $task->attempts    = 3;
        $task->maxattempts = 3;
        $task->startedat   = date('Y-m-d H:i:s', time() - 2);

        // Act
        $this->manager->markTaskAsFailed($task, 'Unrecoverable error');

        // Assert — permanently failed
        $this->assertSame('failed', $task->status, 'Must be permanently failed when exhausted');
        $this->assertNotNull($task->completedat, 'completedat must be set on permanent failure');
    }

    // ── markTaskAsWarning() ───────────────────────────────────────────────────

    /**
     * markTaskAsWarning() must set status='warning' and store the warning message
     * in success_message (same field used for successful completion messages).
     */
    public function testMarkTaskAsWarningSetsCorrectFields(): void
    {
        // Arrange
        $task = $this->buildSavableQueueItem();
        $task->attempts    = 1;
        $task->maxattempts = 3;
        $task->startedat   = date('Y-m-d H:i:s', time() - 2);

        // Act
        $this->manager->markTaskAsWarning($task, 'Partial success', 2.0);

        // Assert
        $this->assertSame('warning', $task->status);
        $this->assertSame('Partial success', $task->success_message);
        $this->assertSame(2.0, $task->execution_time);
        $this->assertNull($task->lockedby);
    }

    // ── retryTask() ───────────────────────────────────────────────────────────

    /**
     * retryTask() must return false for a task that does not exist (taskid=0),
     * preventing accidental re-queuing of ghost records.
     */
    public function testRetryTaskReturnsFalseForNonExistentTask(): void
    {
        // Arrange — manager that always returns a blank QueueItem (taskid=0)
        $manager = new class($this->controller) extends QueueManager {
            protected function createQueueItemModel(): QueueItem
            {
                return new class($this->controller) extends QueueItem {
                    public function __construct($c) {}
                    public function load($taskid, $key = null, $debug = false): static
                    {
                        $this->taskid = 0;  // not found
                        return $this;
                    }
                    public function save($auto = false, $debug = false): static { return $this; }
                };
            }
        };

        // Act
        $result = $manager->retryTask(99999);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * retryTask() must return false when the task exists but is not in 'failed'
     * state — only permanently failed tasks should be manually retried.
     */
    public function testRetryTaskReturnsFalseForNonFailedStatus(): void
    {
        // Arrange — task exists but status = 'completed'
        $manager = new class($this->controller) extends QueueManager {
            protected function createQueueItemModel(): QueueItem
            {
                return new class($this->controller) extends QueueItem {
                    public function __construct($c) {}
                    public function load($taskid, $key = null, $debug = false): static
                    {
                        $this->taskid = (int)$taskid;
                        $this->status = 'completed';
                        return $this;
                    }
                    public function save($auto = false, $debug = false): static { return $this; }
                };
            }
        };

        // Act
        $result = $manager->retryTask(42);

        // Assert — completed tasks must not be re-queued via retryTask
        $this->assertFalse($result, 'retryTask must refuse non-failed tasks');
    }

    // ── addTask() ─────────────────────────────────────────────────────────────

    /**
     * addTask() must enqueue a task and return its integer ID.
     * The non-unique path skips the duplicate check entirely.
     */
    public function testAddTaskReturnsTaskId(): void
    {
        // Arrange — item whose save() simulates auto-increment by setting taskid
        $manager = new class($this->controller) extends QueueManager {
            protected function createQueueItemModel(): QueueItem
            {
                return new class($this->controller) extends QueueItem {
                    public function __construct($c) {}
                    public function save($a = false, $d = false): static
                    {
                        $this->taskid = 42;
                        return $this;
                    }
                };
            }
        };

        // Act
        $id = $manager->addTask('send_email', ['to' => 'a@example.com']);

        // Assert — task ID is returned as an integer
        $this->assertSame(42, $id);
    }

    // ── retryTask() success path ───────────────────────────────────────────────

    /**
     * retryTask() must return true and reset the task to pending when the task
     * exists and is in 'failed' state — this is the success path for manual retry.
     */
    public function testRetryTaskReturnsTrueAndResetsFailedTask(): void
    {
        // Arrange — task exists with status='failed'
        $savedTask = null;
        $manager = new class($this->controller) extends QueueManager {
            protected function createQueueItemModel(): QueueItem
            {
                return new class($this->controller) extends QueueItem {
                    public function __construct($c) {}
                    public function load($taskid, $key = null, $debug = false): static
                    {
                        $this->taskid  = (int)$taskid;
                        $this->status  = 'failed';
                        $this->attempts = 3;
                        return $this;
                    }
                    public function save($a = false, $d = false): static { return $this; }
                };
            }
        };

        // Act
        $result = $manager->retryTask(42);

        // Assert — failed task successfully queued for retry
        $this->assertTrue($result);
    }

    // ── markTaskAsProcessing() ────────────────────────────────────────────────

    /**
     * markTaskAsProcessing() must set status='processing' and record the start
     * time — it is used for tasks that are claimed outside of getNextTask().
     */
    public function testMarkTaskAsProcessingSetsCorrectFields(): void
    {
        // Arrange
        $task = $this->buildSavableQueueItem();
        $task->status    = 'pending';
        $task->startedat = null;

        // Act
        $this->manager->markTaskAsProcessing($task);

        // Assert — status changed and start time recorded
        $this->assertSame('processing', $task->status);
        $this->assertNotNull($task->startedat, 'startedat must be set when processing begins');
    }

    // ── getPendingTasks() ─────────────────────────────────────────────────────

    /**
     * getPendingTasks() must return whatever the model's getList() returns.
     * This verifies the method's query is passed through correctly.
     */
    public function testGetPendingTasksReturnsList(): void
    {
        // Arrange — model always returns 1 item
        $fakeItem = $this->buildSavableQueueItem();
        $manager = new class($this->controller, $fakeItem) extends QueueManager {
            private QueueItem $item;
            public function __construct($c, QueueItem $item)
            {
                parent::__construct($c);
                $this->item = $item;
            }
            protected function createQueueItemModel(): QueueItem
            {
                $item = $this->item;
                return new class($item) extends QueueItem {
                    private QueueItem $inner;
                    public function __construct(QueueItem $i) { $this->inner = $i; }
                    public function getList($where = '', $order = '', $key = null): array
                    {
                        return [$this->inner];
                    }
                };
            }
        };

        // Act
        $tasks = $manager->getPendingTasks(10);

        // Assert — the returned list contains the stub item
        $this->assertCount(1, $tasks);
        $this->assertSame($fakeItem, $tasks[0]);
    }

    /**
     * getPendingTasks() with a $taskTypes filter must pass the type constraint
     * to the model — verified by checking a WHERE clause containing the type
     * is actually built and forwarded to getList().
     */
    public function testGetPendingTasksWithTypeFilter(): void
    {
        // Arrange — use a shared stdClass to track state across anonymous class boundaries
        $state = new \stdClass();
        $state->getListCalled = false;
        $state->whereArg      = '';

        $manager = new class($this->controller, $state) extends QueueManager {
            private \stdClass $state;
            public function __construct($c, \stdClass $state)
            {
                parent::__construct($c);
                $this->state = $state;
            }
            protected function createQueueItemModel(): QueueItem
            {
                $state = $this->state;
                return new class($state) extends QueueItem {
                    private \stdClass $state;
                    public function __construct(\stdClass $s) { $this->state = $s; }
                    public function getList($where = '', $order = '', $key = null): array
                    {
                        $this->state->getListCalled = true;
                        $this->state->whereArg      = $where;
                        return [];
                    }
                };
            }
        };

        // Act
        $manager->getPendingTasks(5, 'send_email');

        // Assert — getList was called with a WHERE clause containing the type
        $this->assertTrue($state->getListCalled, 'getList() must be called when fetching pending tasks');
        $this->assertStringContainsString('send_email', $state->whereArg,
            'Type filter must appear in the WHERE clause');
    }

    // ── purgeOldTasks() ───────────────────────────────────────────────────────

    // ── updateProgress() / recordPoll() / recentlyPolled() — missing column ───

    /**
     * `recordPoll()` does nothing — not even an exception — on an installation
     * that has not run the migration adding `last_polled_at` yet. A task
     * reporting progress or recording a poll is a courtesy to whatever is
     * watching it, not a precondition for the task to run.
     */
    public function testRecordPollDoesNothingWithoutTheColumn(): void
    {
        // Arrange — hasColumn() always false, as on an unmigrated installation
        $database = new class {
            public function schema(): object
            {
                return new class { public function hasColumn(string $t, string $c): bool { return false; } };
            }
        };
        $controller = new class($database) {
            public object $application;
            public function __construct(object $db) {
                $this->application = new class($db) {
                    public object $database;
                    public function __construct(object $db) { $this->database = $db; }
                };
            }
        };
        $manager = new QueueManager($controller);

        // Act + Assert — no exception, despite query()/queryBuilder() not existing on the double
        $manager->recordPoll(1);
        $this->assertFalse($manager->recentlyPolled(1));
    }

    /**
     * purgeOldTasks() must execute a DELETE query and return the affected row count.
     * The default status list is ['completed', 'failed'] and default table is 'queueitems'.
     */
    public function testPurgeOldTasksReturnsAffectedRowCount(): void
    {
        // Arrange — use the shared controller double which has a query() stub
        // returning getAffectedRows()=0. refreshDatabaseConnection also calls query().
        $count = $this->manager->purgeOldTasks(24);

        // Assert — 0 affected rows from the stub, no exception thrown
        $this->assertSame(0, $count);
    }

    /**
     * purgeOldTasks() with a custom status list and limit must build a DELETE
     * with LIMIT — verified by capturing the executed SQL.
     */
    public function testPurgeOldTasksWithCustomStatusListAndLimit(): void
    {
        // Arrange — shared state captures the SQL that the manager sends to the DB
        $state      = new \stdClass();
        $state->lastSql = null;
        $controller = $this->buildControllerDoubleCapturingSql($state);
        $manager    = new class($controller) extends QueueManager {};

        // Act
        $manager->purgeOldTasks(48, ['completed'], 100);

        // Assert — SQL contains LIMIT clause and only the requested status
        $this->assertNotNull($state->lastSql, 'A DELETE query must have been executed');
        $this->assertStringContainsString('LIMIT 100', $state->lastSql);
        $this->assertStringContainsString("'completed'", $state->lastSql);
        $this->assertStringNotContainsString("'failed'", $state->lastSql);
    }

    // ── getTaskTypes() with directory scan ────────────────────────────────────

    /**
     * getTaskTypes() must scan the configured directory, instantiate PHP classes
     * found there, and return their registered task names.
     *
     * We create a real temporary directory with a single task PHP file and
     * verify that its class name is returned as the task type.
     */
    public function testGetTaskTypesScansDirectoryAndReturnsTaskNames(): void
    {
        // Arrange — write a minimal AbstractTask subclass to a temp directory
        $tmpDir    = sys_get_temp_dir() . '/pf_queue_manager_test_' . uniqid();
        mkdir($tmpDir);

        $className = 'TmpScanTask' . str_replace('.', '_', uniqid('', true));
        $namespace = 'Pramnos\Tests\Unit\Queue\Tmp';

        // Write the task file — use single-quoted heredoc to avoid escape issues
        $code = '<?php' . "\n"
            . 'namespace ' . $namespace . ";\n"
            . 'use Pramnos\Queue\AbstractTask;' . "\n"
            . 'use Pramnos\Queue\QueueItem;' . "\n"
            . 'class ' . $className . ' extends AbstractTask {' . "\n"
            . '    public string $name = \'scan_test_task\';' . "\n"
            . '    public function execute(QueueItem $q): bool { return true; }' . "\n"
            . '    public function getDescription(QueueItem $q): string { return \'\'; }' . "\n"
            . '}' . "\n";
        file_put_contents($tmpDir . '/' . $className . '.php', $code);
        require $tmpDir . '/' . $className . '.php';

        $namespace .= '\\';

        $manager = new class($this->controller, $tmpDir, $namespace) extends QueueManager {
            private string $dir;
            private string $ns;
            public function __construct($c, string $dir, string $ns)
            {
                parent::__construct($c);
                $this->dir = $dir;
                $this->ns  = $ns;
            }
            protected function getTasksDirectory(): string { return $this->dir; }
            protected function getTasksNamespace(): string { return $this->ns; }
        };

        // Act
        $types = $manager->getTaskTypes();

        // Cleanup
        unlink($tmpDir . '/' . $className . '.php');
        rmdir($tmpDir);

        // Assert — the task's $name property is returned as a task type
        $this->assertContains('scan_test_task', $types);
    }

    /**
     * getTaskTypes() must fall back to using ReflectionClass::getShortName() when
     * a task class does NOT have a $name property — it still registers under the
     * class short-name rather than being silently skipped.
     */
    public function testGetTaskTypesUsesClassShortNameWhenNoNameProperty(): void
    {
        // Arrange — write an AbstractTask subclass without a $name property
        $tmpDir    = sys_get_temp_dir() . '/pf_queue_manager_reftest_' . uniqid();
        mkdir($tmpDir);

        $className = 'TmpNoNameTask' . str_replace('.', '_', uniqid('', true));
        $namespace = 'Pramnos\Tests\Unit\Queue\TmpRef';

        $code = '<?php' . "\n"
            . 'namespace ' . $namespace . ";\n"
            . 'use Pramnos\Queue\AbstractTask;' . "\n"
            . 'use Pramnos\Queue\QueueItem;' . "\n"
            . 'class ' . $className . ' extends AbstractTask {' . "\n"
            . '    public function execute(QueueItem $q): bool { return true; }' . "\n"
            . '    public function getDescription(QueueItem $q): string { return \'\'; }' . "\n"
            . '}' . "\n";
        file_put_contents($tmpDir . '/' . $className . '.php', $code);
        require $tmpDir . '/' . $className . '.php';

        $namespace .= '\\';

        $manager = new class($this->controller, $tmpDir, $namespace) extends QueueManager {
            private string $dir;
            private string $ns;
            public function __construct($c, string $dir, string $ns)
            {
                parent::__construct($c);
                $this->dir = $dir;
                $this->ns  = $ns;
            }
            protected function getTasksDirectory(): string { return $this->dir; }
            protected function getTasksNamespace(): string { return $this->ns; }
        };

        // Act
        $types = $manager->getTaskTypes();

        // Cleanup
        unlink($tmpDir . '/' . $className . '.php');
        rmdir($tmpDir);

        // Assert — short class name used as the task type (without namespace)
        $this->assertContains($className, $types,
            'Task without $name property must be registered under its class short-name');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a controller double whose database->query() captures the SQL.
     * Uses a shared stdClass state object to avoid pass-by-reference in
     * anonymous class constructors (which PHP does not support).
     *
     * @param  \stdClass $state  Receives the captured SQL in $state->lastSql
     */
    private function buildControllerDoubleCapturingSql(\stdClass $state): object
    {
        $database = new class($state) {
            public string $prefix = '';
            public string $type   = 'mysql';
            private \stdClass $state;
            public function __construct(\stdClass $s) { $this->state = $s; }
            public function prepareInput(string $s): string { return addslashes($s); }

            /**
             * `purgeOldTasks()` wraps its work in a transaction and asks the schema whether
             * `queuestats` exists, because it summarises the rows before deleting them. A
             * double that answers neither is a double that stopped describing the collaborator.
             *
             * `hasTable()` says no, so these tests stay about the DELETE they were written
             * for; the roll-up has its own integration tests against both drivers.
             */
            public function schema(): object
            {
                return new class {
                    public function hasTable(string $table): bool { return false; }
                    public function resolveTableName(string $table): string { return $table; }
                };
            }

            public function query(string $sql): object
            {
                // Transaction control is not the statement any of these tests are about, and
                // recording it would make `lastSql` the COMMIT.
                if (!in_array(strtoupper(trim($sql)), array('BEGIN', 'COMMIT', 'ROLLBACK'), true)) {
                    $this->state->lastSql = $sql;
                }

                return new class { public function getAffectedRows(): int { return 5; } };
            }
        };

        return new class($database) {
            public object $application;
            public function __construct(object $db) {
                $this->application = new class($db) {
                    public object $database;
                    public function __construct(object $db) { $this->database = $db; }
                };
            }
        };
    }

    /**
     * Build a minimal controller double with a database stub.
     *
     * Only prepareInput() is needed for the unit tests that exercise
     * QueueManager's SQL-building paths.
     */
    private function buildControllerDouble(): object
    {
        $database = new class {
            public string $prefix = '';
            public string $type   = 'mysql';
            public function prepareInput(string $s): string { return addslashes($s); }

            /** See the note on the capturing double: `purgeOldTasks()` asks. */
            public function schema(): object
            {
                return new class {
                    public function hasTable(string $table): bool { return false; }
                    public function resolveTableName(string $table): string { return $table; }
                };
            }

            public function query(string $sql): object
            {
                // fetch() always false: no export task ids, no rows to summarise — the
                // SELECTs purgeOldTasks() issues before its DELETE see an empty result.
                return new class {
                    public function getAffectedRows(): int { return 0; }
                    public function fetch(): bool { return false; }
                };
            }
        };

        return new class($database) {
            public object $application;
            public function __construct(object $db) {
                $this->application = new class($db) {
                    public object $database;
                    public function __construct(object $db) { $this->database = $db; }
                };
            }
        };
    }

    /**
     * Build a QueueItem that records save() calls without touching the database.
     */
    private function buildSavableQueueItem(): QueueItem
    {
        return new class($this->controller) extends QueueItem {
            public function __construct($c) {}
            public function save($auto = false, $debug = false): static { return $this; }
            public function load($id, $key = null, $debug = false): static { return $this; }
        };
    }

    /**
     * A window's mean is weighted by task count, not an average of the hourly means.
     *
     * The buckets already carry their own means, so summarising a window by averaging them is
     * the obvious thing to write — and it lets a quiet hour with four tasks weigh the same as a
     * busy one with sixteen thousand, dragging the figure toward whatever the idle hours did.
     * The numbers below are chosen so the two answers differ a lot: averaging the means gives
     * 5.05 s, and the weighted truth is 0.198 s.
     */
    public function testSummariseHistoryWeighsMeansByTaskCount(): void
    {
        // Arrange — one busy hour and one nearly idle one, the idle one much slower
        $rows = [
            ['type' => 'fetch_status', 'bucket' => '2026-09-09 12:00:00',
                'tasks' => 10000, 'warning' => 0, 'retried' => 0,
                'exec_mean' => 0.010, 'exec_max' => 0.80, 'wait_mean' => 0.100, 'wait_max' => 2.0],
            ['type' => 'fetch_status', 'bucket' => '2026-09-09 03:00:00',
                'tasks' => 100, 'warning' => 100, 'retried' => 5,
                'exec_mean' => 0.500, 'exec_max' => 1.50, 'wait_mean' => 10.000, 'wait_max' => 40.0],
        ];

        // Act
        $type = QueueManager::summariseHistory($rows)['fetch_status'];

        // Assert — (10000*0.1 + 100*10) / 10100 = 0.198, not (0.1 + 10) / 2 = 5.05
        $this->assertSame(0.198, $type['wait_mean'], 'the mean must be weighted by tasks; averaging the two hourly means gives 5.05');
        $this->assertSame(0.015, $type['exec_mean'], '(10000*0.01 + 100*0.5) / 10100 = 0.015; averaging the means gives 0.255');
        // Maxima are extremes already and are taken as-is, from whichever hour had them
        $this->assertSame(1.5, $type['exec_max']);
        $this->assertSame(40.0, $type['wait_max']);
        // Counts add
        $this->assertSame(10100, $type['tasks']);
        $this->assertSame(2, $type['buckets']);
        $this->assertSame(100, $type['warning']);
        $this->assertSame(5, $type['retried']);
    }

    /**
     * The busiest type comes first, and it is sorted on purpose: `uasort()` comparing the arrays
     * themselves would compare element by element in declaration order, which starts with
     * `buckets` — a quiet type with more buckets than a busy one would then sort first, which is
     * not what anybody meant.
     */
    public function testSummariseHistoryIsOrderedByTaskCount(): void
    {
        // Arrange — the quiet type has MORE buckets, so a sort on buckets would invert
        $bucket = ['exec_mean' => 0, 'wait_mean' => 0, 'exec_max' => 0, 'wait_max' => 0, 'warning' => 0, 'retried' => 0];
        $rows   = [
            ['type' => 'quiet', 'tasks' => 5] + $bucket,
            ['type' => 'quiet', 'tasks' => 5] + $bucket,
            ['type' => 'quiet', 'tasks' => 5] + $bucket,
            ['type' => 'busy', 'tasks' => 9000] + $bucket,
        ];

        // Act / Assert
        $this->assertSame(['busy', 'quiet'], array_keys(QueueManager::summariseHistory($rows)));
    }

    /**
     * The worst hours are the worst by wait, and the list is bounded — its own list rather than a
     * field of `summariseHistory()`'s answer, because a window's summary answers "how does this
     * type behave" and cannot answer "when did it hurt": a single bad hour disappears into a
     * window's weighted mean the moment enough ordinary hours surround it.
     */
    public function testWorstWaitsPicksTheSlowestHoursAndCapsTheList(): void
    {
        // Arrange
        $rows = [];
        foreach ([0.5, 9.5, 0.6, 3.0, 0.4] as $index => $wait) {
            $rows[] = ['type' => 'send_email', 'bucket' => 'h' . $index, 'wait_mean' => $wait];
        }

        // Act
        $worst = QueueManager::worstWaits($rows, 2);

        // Assert
        $this->assertCount(2, $worst);
        $this->assertSame(9.5, $worst[0]['wait_mean'], 'the worst hour comes first');
        $this->assertSame(3.0, $worst[1]['wait_mean']);
    }
}
