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
