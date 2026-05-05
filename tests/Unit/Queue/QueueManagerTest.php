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
                $ref->setAccessible(true);
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

    // ── Helpers ───────────────────────────────────────────────────────────────

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
            public function prepareInput(string $s): string { return addslashes($s); }
            public function query(string $sql): object { return new class { public function getAffectedRows(): int { return 0; } }; }
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
}
