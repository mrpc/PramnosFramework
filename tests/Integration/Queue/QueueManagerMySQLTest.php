<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Queue\QueueItem;
use Pramnos\Queue\QueueManager;

/**
 * Integration tests for QueueManager against a live MySQL 8.0 database.
 *
 * These tests verify the full task lifecycle — addTask, getNextTask, claim,
 * complete, fail, retry, and deduplication — using the real queueitems table
 * created by the framework migration.  They are the ground-truth that proves
 * the migration schema and the QueueManager string-based status API are
 * compatible (the VARCHAR status column must accept 'pending', 'processing',
 * etc. directly without any type mapping).
 *
 * Isolation: setUp runs the migration's up() and tearDown drops the table,
 * so every test starts from a clean empty queueitems table.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class QueueManagerMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;
    protected Controller $controller;
    protected QueueManager $manager;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        // Load settings so Database::getInstance() builds the correct MySQL connection.
        // Model::_save()/_getList() call getInstance() internally, so they must reach
        // the same DB as QueueManager's direct $controller->application->database queries.
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        // The singleton now resolves to the MySQL DB configured in settings.php (host: db).
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->app        = $this->makeApp();
        $this->controller = $this->makeController();

        // Create queueitems table via the framework migration
        $this->dropQueueTable();
        $this->runQueueMigration();

        $this->manager = new QueueManager($this->controller);
    }

    protected function tearDown(): void
    {
        $this->dropQueueTable();
    }

    // -------------------------------------------------------------------------
    // addTask
    // -------------------------------------------------------------------------

    /**
     * addTask() inserts a row with status='pending' and returns a positive taskid.
     *
     * This is the entry point to the queue; if addTask() does not write to the
     * real table, no downstream operation will work.
     */
    public function testAddTaskInsertsPendingRowAndReturnsId(): void
    {
        // Act
        $id = $this->manager->addTask('send_email', ['to' => 'a@b.com']);

        // Assert – valid auto-increment PK returned
        $this->assertGreaterThan(0, $id, 'addTask must return the new taskid');

        // Assert – row is in the table with correct status
        $result = $this->db->query(
            "SELECT status, type, priority FROM queueitems WHERE taskid = {$id}"
        );
        $this->assertSame('pending', $result->fields['status'],
            'status must be the string "pending" after addTask');
        $this->assertSame('send_email', $result->fields['type']);
    }

    /**
     * addTask() with $unique=true rejects a second task that has the same
     * type+payload hash while the first is still pending or processing.
     *
     * This prevents duplicate background work when an event fires multiple
     * times before the worker drains the queue.
     */
    public function testAddTaskDeduplicatesWhenUniqueIsTrue(): void
    {
        // Arrange – add the first task
        $id1 = $this->manager->addTask('sync_report', ['date' => '2026-01-01'], unique: true);
        $this->assertGreaterThan(0, $id1);

        // Act – add an identical task
        $id2 = $this->manager->addTask('sync_report', ['date' => '2026-01-01'], unique: true);

        // Assert – second call must return null (rejected) not a new taskid
        // addTask() returns ?int — null signals deduplication rejection.
        $this->assertNull($id2, 'duplicate task must be rejected (null) when unique=true');

        // Assert – only one row in the table
        $result = $this->db->query("SELECT COUNT(*) as cnt FROM queueitems WHERE type = 'sync_report'");
        $this->assertSame(1, (int)$result->fields['cnt']);
    }

    /**
     * addTask() with $unique=false allows duplicate type+payload combinations,
     * which is the default (fire-and-forget tasks).
     */
    public function testAddTaskAllowsDuplicatesWhenUniqueFalse(): void
    {
        // Act
        $id1 = $this->manager->addTask('cleanup', ['scope' => 'tmp'], unique: false);
        $id2 = $this->manager->addTask('cleanup', ['scope' => 'tmp'], unique: false);

        // Assert – two distinct rows inserted
        $this->assertNotSame($id1, $id2);
        $result = $this->db->query("SELECT COUNT(*) as cnt FROM queueitems WHERE type = 'cleanup'");
        $this->assertSame(2, (int)$result->fields['cnt']);
    }

    // -------------------------------------------------------------------------
    // getNextTask — claim and lock
    // -------------------------------------------------------------------------

    /**
     * getNextTask() returns the highest-priority pending task, sets its status
     * to 'processing', and records lockedby + lockexpires.
     *
     * This is the atomic claim operation that workers rely on.  If status does
     * not transition to 'processing' the worker would repeatedly re-claim the
     * same task.
     */
    public function testGetNextTaskClaimsHighestPriorityTask(): void
    {
        // Arrange – add two tasks with different priorities
        $lowId  = $this->manager->addTask('low',  [], priority: 50);
        $highId = $this->manager->addTask('high', [], priority: 5);

        // Act – claim next task
        $task = $this->manager->getNextTask();

        // Assert – highest-priority (lowest number) task returned
        // getNextTask() returns QueueItem|false (not null) — assertNotFalse covers both.
        $this->assertNotFalse($task, 'getNextTask must return a task when queue is non-empty');
        $this->assertSame($highId, (int)$task->taskid,
            'getNextTask must return the task with the lowest priority number');

        // Assert – status transitioned to 'processing' in the DB
        $row = $this->db->query("SELECT status, lockedby FROM queueitems WHERE taskid = {$highId}");
        $this->assertSame('processing', $row->fields['status'],
            "status must be 'processing' after getNextTask claims the task");
        $this->assertNotEmpty($row->fields['lockedby'],
            'lockedby must be set after getNextTask claims the task');
    }

    /**
     * getNextTask() returns false when the table has no rows.
     *
     * Note: getNextTask() signature is getNextTask(?taskTypes, lockSeconds, ...).
     * The first parameter is a task-type filter (string|array|null), NOT a worker ID.
     * Call with no args or null to claim any pending task.
     */
    public function testGetNextTaskReturnsNullOnEmptyQueue(): void
    {
        // Act — no tasks added
        $task = $this->manager->getNextTask();

        // Assert — getNextTask() returns false (not null) when no tasks are available.
        $this->assertFalse($task, 'getNextTask must return false when queue is empty');
    }

    // -------------------------------------------------------------------------
    // markTaskAsCompleted
    // -------------------------------------------------------------------------

    /**
     * markTaskAsCompleted() sets status='completed', records completedat, and
     * stores the success message so the admin dashboard can display it.
     */
    public function testMarkTaskAsCompletedSetsStatusAndMessage(): void
    {
        // Arrange – add and claim a task
        $this->manager->addTask('export_csv', []);
        $task = $this->manager->getNextTask();
        $this->assertNotFalse($task, 'getNextTask must claim the exported task');

        // Act
        $this->manager->markTaskAsCompleted($task, 'Exported 500 rows');

        // Assert – verify DB state directly
        $row = $this->db->query("SELECT status, completedat, success_message FROM queueitems WHERE taskid = {$task->taskid}");
        $this->assertSame('completed', $row->fields['status']);
        $this->assertNotNull($row->fields['completedat']);
        $this->assertSame('Exported 500 rows', $row->fields['success_message']);
    }

    // -------------------------------------------------------------------------
    // markTaskAsFailed — retry and permanent failure
    // -------------------------------------------------------------------------

    /**
     * markTaskAsFailed() resets status to 'pending' when attempts < maxattempts,
     * allowing automatic retry.  Only when all attempts are exhausted is the
     * task permanently marked 'failed'.
     *
     * This retry mechanic is what makes the queue fault-tolerant: transient
     * errors (network timeout, DB lock) are automatically retried without
     * developer intervention.
     */
    public function testMarkTaskAsFailedRetriesUntilMaxAttempts(): void
    {
        // Arrange – task with maxattempts=2
        $id = $this->manager->addTask('flaky_task', [], maxAttempts: 2);

        // Attempt 1 — should reset to pending for retry
        $task = $this->manager->getNextTask();
        $this->assertNotFalse($task, 'getNextTask must claim the task for attempt 1');
        $this->manager->markTaskAsFailed($task, 'timeout');

        $row = $this->db->query("SELECT status, attempts FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('pending', $row->fields['status'],
            "status must be 'pending' after first failure when retries remain");
        $this->assertSame(1, (int)$row->fields['attempts']);

        // Attempt 2 — now permanently failed
        $task2 = $this->manager->getNextTask();
        $this->assertNotFalse($task2, 'getNextTask must claim the task for attempt 2');
        $this->manager->markTaskAsFailed($task2, 'still failing');

        $row2 = $this->db->query("SELECT status, attempts FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('failed', $row2->fields['status'],
            "status must be 'failed' after exhausting all attempts");
        $this->assertSame(2, (int)$row2->fields['attempts']);
    }

    // -------------------------------------------------------------------------
    // getStats
    // -------------------------------------------------------------------------

    /**
     * getStats() returns accurate per-status counts after mixed operations.
     *
     * The stats query uses GROUP BY status, so it depends on the VARCHAR status
     * column working correctly — any TINYINT coercion bug would cause all
     * statuses to collapse to the same bucket.
     */
    public function testGetStatsReturnsAccurateCounts(): void
    {
        // Arrange – seed known state
        $id1 = $this->manager->addTask('t1', []);
        $id2 = $this->manager->addTask('t2', []);
        $id3 = $this->manager->addTask('t3', []);

        // Complete t1
        $t1 = $this->manager->getNextTask();
        $this->manager->markTaskAsCompleted($t1, 'ok');

        // Claim t2 (leave as processing)
        $this->manager->getNextTask();

        // t3 stays pending

        // Act
        $stats = $this->manager->getStats();

        // Assert – each status bucket has the right count
        $this->assertSame(1, (int)$stats['pending'],    'one task must be pending');
        $this->assertSame(1, (int)$stats['processing'], 'one task must be processing');
        $this->assertSame(1, (int)$stats['completed'],  'one task must be completed');
        $this->assertSame(0, (int)$stats['failed'],     'no failed tasks');
    }

    // =========================================================================
    // reclaimAbandonedTasks
    // =========================================================================

    /**
     * Put a row straight into the state a dead worker leaves behind.
     *
     * Written with SQL rather than through the manager, because the state under test is one
     * no code path produces on purpose: a claim with no ending. A worker reaches it by
     * dying, and a test cannot die.
     *
     * @param  int    $attempts    How many attempts the row has already used
     * @param  int    $expiredAgo  Seconds since the lock expired; negative for a live lock
     * @return int    The task id
     */
    private function abandonedRow(
        string $type = 'orphan',
        int $attempts = 1,
        int $maxattempts = 3,
        int $expiredAgo = 60
    ): int {
        $expires = date('Y-m-d H:i:s', time() - $expiredAgo);
        $started = date('Y-m-d H:i:s', time() - $expiredAgo - 300);

        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO queueitems (type, payload, status, priority, attempts, maxattempts,'
                . ' createdat, startedat, lockedby, lockexpires)'
                . " VALUES (%s, '{}', 'processing', 10, %d, %d, %s, %s, 'dead-worker', %s)",
                $type,
                $attempts,
                $maxattempts,
                $started,
                $started,
                $expires
            )
        );

        return (int) $this->db->getInsertId();
    }

    /** The row as the database now holds it. */
    private function rowById(int $taskId): array
    {
        $result = $this->db->query(
            $this->db->prepareQuery('SELECT * FROM queueitems WHERE taskid = %d', $taskId)
        );

        return (array) $result->fields;
    }

    /**
     * A stalled task with attempts left goes back to the queue, lock cleared.
     *
     * The ordinary case. `getNextTask()` also picks these up, but only when somebody is
     * asking for work of that type — so a type whose workers are all dead reclaims nothing,
     * which is exactly the moment it is needed.
     */
    public function testAnExpiredLockWithAttemptsLeftIsRequeued(): void
    {
        // Arrange
        $taskId = $this->abandonedRow('orphan', 1, 3, 60);

        // Act
        $counts = $this->manager->reclaimAbandonedTasks();
        $row    = $this->rowById($taskId);

        // Assert
        $this->assertSame(1, $counts['requeued']);
        $this->assertSame(0, $counts['failed']);
        $this->assertSame('pending', (string) $row['status']);
        $this->assertNull($row['lockedby'], 'the dead worker still holds the lock');
        $this->assertNull($row['lockexpires']);

        // and attempts are untouched, so it still walks up to maxattempts
        $this->assertSame(1, (int) $row['attempts'], 'reclaiming spent an attempt');
    }

    /**
     * Out of attempts, it is recorded as failed rather than left in progress.
     *
     * The row the framework never resolved: `getNextTask()`'s stalled branch requires
     * `attempts < maxattempts`, so this one was never claimed again, never failed, counted
     * as *processing* by `getStats()`, and invisible to `purgeOldTasks()`, which reads only
     * terminal states.
     *
     * On the installation that filed it, a fatal killing workers leaked 101 such rows and
     * only 34 were recorded as failed — a process that dies cannot write its own failure,
     * so the ledger under-reported the damage threefold.
     */
    public function testWithNoAttemptsLeftItIsRecordedAsFailed(): void
    {
        // Arrange
        $taskId = $this->abandonedRow('orphan', 3, 3, 60);

        // Act
        $counts = $this->manager->reclaimAbandonedTasks();
        $row    = $this->rowById($taskId);

        // Assert
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['requeued']);
        $this->assertSame('failed', (string) $row['status']);
        $this->assertStringContainsString('Abandoned', (string) $row['error']);
        $this->assertNotNull($row['completedat'], 'a terminal row with no end time');
    }

    /**
     * A lock that has **not** expired is left alone, which is the whole safety condition.
     *
     * The assertion that makes this safe to run against a live queue: a worker that is
     * alive holds an unexpired lock, so nothing running can have its task taken away. Get
     * this wrong and the reclaimer becomes a machine for running every task twice.
     */
    public function testALiveLockIsNotTouched(): void
    {
        // Arrange — expires 10 minutes from now
        $taskId = $this->abandonedRow('orphan', 1, 3, -600);

        // Act
        $counts = $this->manager->reclaimAbandonedTasks();
        $row    = $this->rowById($taskId);

        // Assert
        $this->assertSame(array('requeued' => 0, 'failed' => 0), $counts);
        $this->assertSame('processing', (string) $row['status']);
        $this->assertSame('dead-worker', (string) $row['lockedby'], 'a live worker lost its lock');
    }

    /**
     * A row with no lock expiry at all is left alone too.
     *
     * `markTaskAsProcessing()` sets a status and no expiry, so this state exists in the
     * wild. With no expiry there is no evidence the holder is gone, and the expiry is the
     * only evidence this method accepts.
     */
    public function testARowWithNoExpiryIsNotTouched(): void
    {
        // Arrange
        $taskId = $this->abandonedRow('orphan', 1, 3, 60);
        $this->db->query(
            $this->db->prepareQuery(
                'UPDATE queueitems SET lockexpires = NULL WHERE taskid = %d',
                $taskId
            )
        );

        // Act
        $counts = $this->manager->reclaimAbandonedTasks();

        // Assert
        $this->assertSame(array('requeued' => 0, 'failed' => 0), $counts);
        $this->assertSame('processing', (string) $this->rowById($taskId)['status']);
    }

    /**
     * The grace period holds back a row whose lock only just expired.
     *
     * For installations whose worker clocks disagree with the database's: a lock that
     * expired two seconds ago may belong to a worker that is about to renew it.
     */
    public function testTheGracePeriodHoldsBackARecentExpiry(): void
    {
        // Arrange — expired 30 seconds ago
        $taskId = $this->abandonedRow('orphan', 1, 3, 30);

        // Act + Assert — a 60-second grace leaves it, none takes it
        $this->assertSame(0, $this->manager->reclaimAbandonedTasks(60)['requeued']);
        $this->assertSame('processing', (string) $this->rowById($taskId)['status']);

        $this->assertSame(1, $this->manager->reclaimAbandonedTasks(0)['requeued']);
    }

    /**
     * Restricted to named types, it leaves the others where they are.
     *
     * So a reclaim can be scheduled per queue, beside the workers that serve it.
     */
    public function testItCanBeRestrictedToTypes(): void
    {
        // Arrange
        $mine   = $this->abandonedRow('mine', 1, 3, 60);
        $theirs = $this->abandonedRow('theirs', 1, 3, 60);

        // Act
        $counts = $this->manager->reclaimAbandonedTasks(0, 'mine');

        // Assert
        $this->assertSame(1, $counts['requeued']);
        $this->assertSame('pending', (string) $this->rowById($mine)['status']);
        $this->assertSame('processing', (string) $this->rowById($theirs)['status']);
    }

    /**
     * And a pending or completed row is never reclaimed, whatever its lock columns say.
     *
     * The control for the status condition: a statement matching on the expiry alone would
     * pass every test above and drag finished work back into the queue.
     */
    public function testOnlyProcessingRowsAreConsidered(): void
    {
        // Arrange — a completed row carrying a stale expiry, which nothing clears on purpose
        $taskId = $this->abandonedRow('orphan', 1, 3, 60);
        $this->db->query(
            $this->db->prepareQuery(
                "UPDATE queueitems SET status = 'completed' WHERE taskid = %d",
                $taskId
            )
        );

        // Act
        $counts = $this->manager->reclaimAbandonedTasks();

        // Assert
        $this->assertSame(array('requeued' => 0, 'failed' => 0), $counts);
        $this->assertSame('completed', (string) $this->rowById($taskId)['status']);
    }

    /**
     * The backlog count has an index to answer it.
     *
     * `count(*) WHERE status = ? AND type = ?` is what a backlog check and an orchestrator
     * both run, repeatedly. Measured on the installation that reported it: a parallel
     * sequential scan, 98 ms and 50,365 buffers on 175,000 rows, five times a minute.
     *
     * Asserted through the schema rather than by timing anything — a test that measures
     * milliseconds on a five-row table measures the container.
     */
    public function testTheBacklogCountIsIndexed(): void
    {
        // Assert
        $this->assertTrue(
            $this->db->schema()->hasIndex('queueitems', 'idx_queueitems_status_type'),
            'the (status, type) index the backlog count needs is missing'
        );
    }

    /**
     * The types the queue has carried, which is not the types that have handlers.
     *
     * `getTaskTypes()` scans a directory of handler classes and answers a different
     * question. For "which queues are moving" this is the right one — and a type with
     * traffic and **no** handler is exactly what somebody needs to see, because the worker
     * fails every one of those and a directory scan cannot show it.
     */
    public function testItReportsTheTypesTheQueueHasCarried(): void
    {
        // Arrange
        $this->manager->addTask('alpha', array('n' => 1));
        $this->manager->addTask('beta', array('n' => 2));
        $this->manager->addTask('beta', array('n' => 3));

        // Act
        $types = $this->manager->activeTaskTypes();

        // Assert — one entry per type, not per task
        $this->assertSame(array('alpha', 'beta'), $types);
    }

    /**
     * And an old type drops out of the window.
     *
     * So a per-type report is about the queues that are moving now rather than every type
     * the installation has ever run.
     */
    public function testAnOldTypeLeavesTheActiveList(): void
    {
        // Arrange
        $this->manager->addTask('ancient', array('n' => 1));
        $this->db->query(
            $this->db->prepareQuery(
                'UPDATE queueitems SET createdat = %s WHERE type = %s',
                date('Y-m-d H:i:s', time() - 172800),
                'ancient'
            )
        );

        // Act + Assert
        $this->assertSame(array(), $this->manager->activeTaskTypes(86400));
        $this->assertSame(array('ancient'), $this->manager->activeTaskTypes(0));
    }

    // =========================================================================
    // throughput
    // =========================================================================

    /**
     * Arrivals against completions: the number that says which way the queue is going.
     *
     * A depth is a level and this is a rate. The installation that filed it watched "a few
     * thousand pending" for hours while the queue grew by 1.7 items a second — the level
     * moved slowly enough to read as steady, and the rate was on no screen at all.
     */
    public function testItReportsArrivalsAgainstCompletions(): void
    {
        // Arrange — three in, one out
        $this->manager->addTask('metric', array('n' => 1));
        $this->manager->addTask('metric', array('n' => 2));
        $this->manager->addTask('metric', array('n' => 3));

        $task = $this->manager->getNextTask('metric');
        $this->manager->markTaskAsCompleted($task, 'done', 0.1);

        // Act
        $numbers = $this->manager->throughput(300, 'metric');

        // Assert
        $this->assertSame(3, $numbers['arrivals']);
        $this->assertSame(1, $numbers['completions']);
        $this->assertSame(-2, $numbers['net'], 'the queue is losing and net does not say so');
        $this->assertTrue($numbers['losing']);
        $this->assertSame(2, $numbers['pending']);
    }

    /**
     * Keeping up reads as not losing, and `net` is zero rather than merely non-negative.
     *
     * The control. A signal that always says "losing" is as useless as one that never does,
     * and this is the state a healthy queue is in most of the time.
     */
    public function testAQueueThatKeepsUpIsNotLosing(): void
    {
        // Arrange — one in, one out
        $this->manager->addTask('metric', array('n' => 1));
        $task = $this->manager->getNextTask('metric');
        $this->manager->markTaskAsCompleted($task, 'done', 0.1);

        // Act
        $numbers = $this->manager->throughput(300, 'metric');

        // Assert
        $this->assertSame(0, $numbers['net']);
        $this->assertFalse($numbers['losing']);
    }

    /**
     * A permanent failure counts as work leaving the queue; a retry does not.
     *
     * Both halves are deliberate, and the distinction is `markTaskAsFailed()`'s own: with
     * attempts remaining it returns the task to `pending`, so the queue is still carrying
     * it and counting it as departed would report a queue that is keeping up while the
     * backlog grows. Once attempts are exhausted the row is terminal and gone.
     *
     * Counting only *successes* would be the other error: during an outage where everything
     * fails permanently the work really is draining away, and reporting that as a losing
     * queue sends somebody to add workers for a problem more workers cannot touch.
     */
    public function testAPermanentFailureCountsAsWorkLeavingTheQueue(): void
    {
        // Arrange — a task with one attempt allowed, so the first failure is terminal
        $this->manager->addTask('metric', array('n' => 1), 10, 1);
        $task = $this->manager->getNextTask('metric');
        $this->manager->markTaskAsFailed($task, 'no', 0.1);

        // Act
        $terminal = $this->manager->throughput(300, 'metric');

        // Assert
        $this->assertSame(1, $terminal['completions']);
        $this->assertFalse($terminal['losing']);

        // and a retryable failure is still in the queue, not a departure
        $this->manager->addTask('retryable', array('n' => 2), 10, 3);
        $retried = $this->manager->getNextTask('retryable');
        $this->manager->markTaskAsFailed($retried, 'again', 0.1);

        $pendingAgain = $this->manager->throughput(300, 'retryable');

        $this->assertSame(0, $pendingAgain['completions'], 'a retry was counted as a departure');
        $this->assertSame(1, $pendingAgain['pending']);
        $this->assertTrue($pendingAgain['losing']);
    }

    /**
     * The window bounds it, so an old arrival stops counting.
     *
     * Without this the measure degenerates into a total, which is the level again.
     */
    public function testTheWindowBoundsWhatCounts(): void
    {
        // Arrange — one arrival, backdated an hour
        $this->manager->addTask('metric', array('n' => 1));
        $this->db->query(
            $this->db->prepareQuery(
                'UPDATE queueitems SET createdat = %s WHERE type = %s',
                date('Y-m-d H:i:s', time() - 3600),
                'metric'
            )
        );

        // Act + Assert
        $this->assertSame(0, $this->manager->throughput(300, 'metric')['arrivals']);
        $this->assertSame(1, $this->manager->throughput(7200, 'metric')['arrivals']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    /**
     * Build a Controller mock that wraps the Application mock.
     *
     * QueueManager stores the controller and passes it to QueueItem, which
     * extends Model. Model::__construct() has a hard Controller type-hint, so
     * passing an Application directly would throw a TypeError. The controller
     * also needs ->application->database so that QueueManager's direct SQL
     * queries (prepareInput, getStats, checkConnection) reach the test DB.
     */
    protected function makeController(): Controller
    {
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $ctrl */
        $ctrl = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ctrl->application = $this->app;
        return $ctrl;
    }

    protected function runQueueMigration(): void
    {
        $dir = dirname(__DIR__, 3)
            . '/database/migrations/framework/queue';
        $migrations = MigrationLoader::loadFromDirectory($dir, $this->app);
        foreach ($migrations as $m) {
            $m->up();
        }
    }

    protected function dropQueueTable(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `queueitems`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
