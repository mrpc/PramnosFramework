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
    // Claiming, under contention
    // =========================================================================

    /**
     * Eleven workers, eleven tasks, eleven different rows, nobody empty-handed.
     *
     * The outcome the whole claim path exists for, and the one the reporting installation
     * measured both ways. Selection is deterministic — `ORDER BY priority ASC, createdat
     * ASC` hands every worker the same oldest row — so:
     *
     *  - before the claim was atomic, all eleven "won" it and ran the same task eleven
     *    times, which looked fast because it was the same work repeated;
     *  - with an atomic claim and a lost race spelled `false`, ten of eleven were told the
     *    queue was empty and went to sleep with ten tasks waiting.
     *
     * Sequential here, which is the honest limit of a PHP test — but not a weak assertion:
     * each manager is a separate `QueueManager` with its own worker id, and the invariant
     * that fails under either bug above is *distinct rows, none refused*.
     */
    public function testElevenWorkersTakeElevenDifferentTasks(): void
    {
        // Arrange
        for ($i = 1; $i <= 11; $i++) {
            $this->manager->addTask('spread', array('n' => $i));
        }

        // Act
        $taken = array();

        for ($i = 1; $i <= 11; $i++) {
            $task = (new QueueManager($this->controller, 'worker-' . $i))->getNextTask('spread');

            $this->assertNotFalse($task, 'worker ' . $i . ' was told the queue was empty');

            $taken[] = (int) $task->taskid;
        }

        // Assert
        $this->assertCount(11, array_unique($taken), 'two workers were handed the same task');

        // and the twelfth finds it genuinely empty
        $this->assertFalse(
            (new QueueManager($this->controller, 'worker-12'))->getNextTask('spread'),
            'an empty queue answered with a task'
        );
    }

    /**
     * A lost race asks again instead of reporting an empty queue.
     *
     * The distinction that cost the reporting installation most of its throughput: `false`
     * travels up through `processNextTask()` to `processBatch()`, which reads it as *nothing
     * left*, breaks out of the batch and sleeps. Ten of eleven workers spent their lives
     * losing races and sleeping, with work waiting.
     *
     * Driven through the **real** `attemptClaim()` with `claimRow()` refusing everything —
     * which is what a worker that loses every race sees — because that is the line the fix
     * is on. Refusing from `attemptClaim()` instead would have the test supplying the very
     * answer it is meant to be checking, and a first version of it did exactly that: the
     * production line could be replaced with `return false` and the test stayed green.
     */
    public function testALostRaceRetriesRatherThanReportingEmpty(): void
    {
        // Arrange — work waiting, and a worker that wins none of it
        $this->manager->addTask('contended', array('n' => 1));
        $this->manager->addTask('contended', array('n' => 2));

        $manager = new class ($this->controller) extends QueueManager {
            public int $passes = 0;

            public function __construct($controller)
            {
                parent::__construct($controller, 'unlucky-worker');
            }

            /** The single statement cannot be made to lose from here; this is about the loop. */
            protected function claimWithSkipLocked(
                string|array|null $taskTypes,
                int $lockSeconds
            ): ?\Pramnos\Queue\QueueItem {
                return null;
            }

            protected function attemptClaim(
                string|array|null $taskTypes,
                string $typeClause,
                int $lockSeconds,
                bool $reverse,
                int $startfrom
            ): \Pramnos\Queue\QueueItem|false|null {
                $this->passes++;

                return parent::attemptClaim($taskTypes, $typeClause, $lockSeconds, $reverse, $startfrom);
            }

            /** Somebody else got there first, every time. */
            protected function claimRow(
                \Pramnos\Queue\QueueItem $candidate,
                string $now,
                string $lockExpiry
            ): bool {
                return false;
            }
        };

        // Act
        $task = $manager->getNextTask('contended');

        // Assert — every pass used, because rows were on offer and none was won
        $this->assertSame(
            10,
            $manager->passes,
            'a pass that lost every row was read as an empty queue and not retried'
        );
        $this->assertFalse($task, 'a worker that won nothing was handed a task');
    }

    /**
     * And a worker that loses one pass wins on the next.
     *
     * The other half: the winner has already marked its row `processing`, so the next
     * selection skips it and offers the one below. The pool converges on distinct rows
     * instead of colliding for ever — which is why a bounded retry is enough and a backoff
     * is not needed.
     */
    public function testAWorkerThatLosesOnePassWinsOnTheNext(): void
    {
        // Arrange
        $this->manager->addTask('contended', array('n' => 1));
        $this->manager->addTask('contended', array('n' => 2));

        $manager = new class ($this->controller) extends QueueManager {
            public int $passes = 0;

            public function __construct($controller)
            {
                parent::__construct($controller, 'second-time-lucky');
            }

            protected function claimWithSkipLocked(
                string|array|null $taskTypes,
                int $lockSeconds
            ): ?\Pramnos\Queue\QueueItem {
                return null;
            }

            protected function attemptClaim(
                string|array|null $taskTypes,
                string $typeClause,
                int $lockSeconds,
                bool $reverse,
                int $startfrom
            ): \Pramnos\Queue\QueueItem|false|null {
                $this->passes++;

                return parent::attemptClaim($taskTypes, $typeClause, $lockSeconds, $reverse, $startfrom);
            }

            protected function claimRow(
                \Pramnos\Queue\QueueItem $candidate,
                string $now,
                string $lockExpiry
            ): bool {
                // Lost the first pass, won the second.
                return $this->passes > 1
                    && parent::claimRow($candidate, $now, $lockExpiry);
            }
        };

        // Act
        $task = $manager->getNextTask('contended');

        // Assert
        $this->assertSame(2, $manager->passes);
        $this->assertNotFalse($task, 'a lost pass was not retried');
    }

    /**
     * And an empty queue is still answered immediately, not ten times over.
     *
     * The control for the retry. A loop that could not tell "nothing there" from "somebody
     * else took it" would run every idle poll ten times — and an idle poll is what a worker
     * does most of the time.
     */
    public function testAnEmptyQueueIsNotRetried(): void
    {
        // Arrange
        $manager = new class ($this->controller) extends QueueManager {
            public int $passes = 0;

            public function __construct($controller)
            {
                parent::__construct($controller, 'idle-worker');
            }

            protected function attemptClaim(
                string|array|null $taskTypes,
                string $typeClause,
                int $lockSeconds,
                bool $reverse,
                int $startfrom
            ): \Pramnos\Queue\QueueItem|false|null {
                $this->passes++;

                return parent::attemptClaim($taskTypes, $typeClause, $lockSeconds, $reverse, $startfrom);
            }
        };

        // Act
        $task = $manager->getNextTask('nothing-of-this-type');

        // Assert
        $this->assertFalse($task);
        $this->assertLessThanOrEqual(1, $manager->passes, 'an empty queue was polled repeatedly');
    }

    /**
     * The single-statement path is the one that actually runs on the ordinary claim.
     *
     * Asserted because everything above passes with it disabled — select-then-claim is a
     * complete fallback, so the fast path could be dead code and nothing would fail. And
     * dead is what it becomes on a server without `SKIP LOCKED`: the first refusal is
     * remembered for the process, which is right, and would also hide a mistake that makes
     * every claim throw.
     */
    public function testTheSingleStatementPathIsUsed(): void
    {
        // Arrange
        QueueManager::resetSkipLockedSupport();
        $this->manager->addTask('fastpath', array('n' => 1));

        $manager = new class ($this->controller) extends QueueManager {
            public bool $fellBack = false;

            public function __construct($controller)
            {
                parent::__construct($controller, 'fast-worker');
            }

            protected function attemptClaim(
                string|array|null $taskTypes,
                string $typeClause,
                int $lockSeconds,
                bool $reverse,
                int $startfrom
            ): \Pramnos\Queue\QueueItem|false|null {
                $this->fellBack = true;

                return parent::attemptClaim($taskTypes, $typeClause, $lockSeconds, $reverse, $startfrom);
            }
        };

        // Act
        $task = $manager->getNextTask('fastpath');

        // Assert
        $this->assertNotFalse($task, 'nothing was claimed at all');
        $this->assertFalse($this->fellBackOrUnsupported($manager), 'the fast path did not run');
        $this->assertSame('processing', (string) $task->status);
        $this->assertSame(1, (int) $task->attempts, 'the fast path did not count the attempt');
    }

    /**
     * Did that claim come from the fallback, or is the fast path simply unavailable here?
     *
     * Reported as one answer so the assertion above stays readable, and separated in the
     * message so a failure says which of the two it was.
     */
    private function fellBackOrUnsupported(object $manager): bool
    {
        $unavailable = (new \ReflectionProperty(QueueManager::class, 'skipLockedUnavailable'))
            ->getValue();

        $this->assertFalse(
            (bool) $unavailable,
            'this server refused FOR UPDATE SKIP LOCKED, so the fast path could not be tested'
        );

        return $manager->fellBack;
    }

    /**
     * A claim is refused when the row moved between the read and the write.
     *
     * The race the old code had, reproduced deterministically rather than with threads: read
     * a candidate, let "another worker" change it, then try to claim with the snapshot we
     * hold. `SELECT` then `$task->save()` wrote every column back regardless, so both
     * workers ran the task, the second one's `lockedby` won, and the row recorded a single
     * `attempts` increment for two executions — nothing in the data said it had happened.
     */
    public function testAClaimIsRefusedWhenTheRowMovedUnderIt(): void
    {
        // Arrange — a candidate as a worker would have read it
        $this->manager->addTask('contended', array('n' => 1));
        $candidates = $this->readPending('contended');
        $this->assertCount(1, $candidates, 'the fixture produced no candidate');

        // Act — somebody else claims it first, then we try with our snapshot
        $this->db->query(
            "UPDATE queueitems SET status = 'processing', lockedby = 'other-worker',"
            . " lockexpires = '" . date('Y-m-d H:i:s', time() + 300) . "'"
            . " WHERE type = 'contended'"
        );

        $won = $this->exposedClaim($candidates[0]);

        // Assert
        $this->assertFalse($won, 'the claim overwrote another worker');
        $this->assertSame('other-worker', (string) $this->rowByType('contended')['lockedby']);
    }

    /**
     * Losing a race is not an empty queue.
     *
     * With one candidate per pass, a worker that loses a race answers "nothing to do" and
     * sleeps while work is waiting — which on a busy queue is most of its polls. It looks at
     * the next few instead.
     *
     * Driven by refusing the first candidate through the `claimRow()` seam, because the real
     * cause is another process and a test has only one.
     */
    public function testLosingARaceMovesToTheNextCandidate(): void
    {
        // Arrange — two waiting tasks, and a worker that loses the first race
        $first  = $this->manager->addTask('contended', array('n' => 1));
        $second = $this->manager->addTask('contended', array('n' => 2));

        $manager = new class ($this->controller, (int) $first) extends QueueManager {
            public function __construct($controller, private readonly int $loseThis)
            {
                parent::__construct($controller, 'unlucky-worker');
            }

            /**
             * This test is about the select-then-claim loop, so the single-statement path is
             * turned off — otherwise it claims the row before `claimRow()` is ever consulted
             * and the test asserts nothing. Which is what it did the moment the fast path
             * landed, on PostgreSQL only, because that is where the fast path works.
             */
            protected function claimWithSkipLocked(string|array|null $taskTypes, int $lockSeconds): ?QueueItem
            {
                return null;
            }

            protected function claimRow(QueueItem $candidate, string $now, string $lockExpiry): bool
            {
                if ((int) $candidate->taskid === $this->loseThis) {
                    return false;
                }

                return parent::claimRow($candidate, $now, $lockExpiry);
            }
        };

        // Act
        $task = $manager->getNextTask('contended');

        // Assert
        $this->assertNotFalse($task, 'a lost race was reported as an empty queue');
        $this->assertSame((int) $second, (int) $task->taskid);
    }

    /**
     * A stalled row is not taken from a worker that re-claimed it in the meantime.
     *
     * The subtler half of the guard. Status is `processing` either way, so only the lock says
     * whose the row is — and it is compared **by value** against the snapshot rather than
     * re-tested against `NOW()`, because the question is "is this still the claim I read?",
     * not "is some claim expired?".
     */
    public function testAStalledRowIsNotTakenFromItsNewOwner(): void
    {
        // Arrange — an abandoned row, read as a worker would read it
        $this->abandonedRow('contended', 1, 3, 60);
        $stale = $this->readStalled('contended');
        $this->assertCount(1, $stale, 'the fixture produced no stalled candidate');

        // Act — a live worker takes it, then we try with the expired snapshot
        $this->db->query(
            "UPDATE queueitems SET lockedby = 'fresh-worker', lockexpires = '"
            . date('Y-m-d H:i:s', time() + 300) . "' WHERE type = 'contended'"
        );

        $won = $this->exposedClaim($stale[0]);

        // Assert
        $this->assertFalse($won);
        $this->assertSame('fresh-worker', (string) $this->rowByType('contended')['lockedby']);
    }

    /**
     * `attempts` is incremented by the database, once, and the caller sees the new value.
     *
     * Two workers reading `attempts` and both writing `read + 1` lose one of the increments —
     * the same race one level down. And the value has to come back from the database, because
     * `markTaskAsFailed()` compares `attempts` against `maxattempts` to choose between a retry
     * and a permanent failure: off by one there is a task that retries for ever, or one that
     * never retries at all.
     */
    public function testAttemptsIsIncrementedByTheDatabaseAndReturned(): void
    {
        // Arrange
        $this->manager->addTask('contended', array('n' => 1));

        // Act
        $task = $this->manager->getNextTask('contended');

        // Assert
        $this->assertNotFalse($task);
        $this->assertSame(1, (int) $task->attempts, 'the claimed task carries a stale attempts');
        $this->assertSame(1, (int) $this->rowByType('contended')['attempts']);
        $this->assertSame('processing', (string) $task->status);
    }

    /**
     * Two workers polling one task: exactly one gets it.
     *
     * The outcome, asserted at the level somebody cares about. Sequential — the interleaving
     * is covered above — but it pins the thing the whole change is for.
     */
    public function testOnlyOneWorkerGetsAGivenTask(): void
    {
        // Arrange
        $this->manager->addTask('contended', array('n' => 1));

        $a = new QueueManager($this->controller, 'worker-a');
        $b = new QueueManager($this->controller, 'worker-b');

        // Act
        $first  = $a->getNextTask('contended');
        $second = $b->getNextTask('contended');

        // Assert
        $this->assertNotFalse($first);
        $this->assertFalse($second, 'two workers were handed the same task');
        // `hostname:workerid`, which is what the column documents — so the id is the tail
        $this->assertStringEndsWith(
            ':worker-a',
            (string) $this->rowByType('contended')['lockedby'],
            'the loser wrote its own id over the winner\'s'
        );
    }

    /**
     * Pending candidates, as a worker reads them.
     *
     * @return array<int, QueueItem>
     */
    private function readPending(string $type): array
    {
        return array_values((array) (new QueueItem($this->controller))->getList(
            "WHERE status = 'pending' AND type = '" . $type . "'",
            'ORDER BY priority ASC, createdat ASC LIMIT 5'
        ));
    }

    /**
     * Stalled candidates, as a worker reads them.
     *
     * @return array<int, QueueItem>
     */
    private function readStalled(string $type): array
    {
        return array_values((array) (new QueueItem($this->controller))->getList(
            "WHERE status = 'processing' AND type = '" . $type . "'",
            'ORDER BY priority ASC, createdat ASC LIMIT 5'
        ));
    }

    /** `claimRow()` reachable from a test, with the manager's own worker id. */
    private function exposedClaim(QueueItem $candidate): bool
    {
        $manager = new class ($this->controller) extends QueueManager {
            public function __construct($controller)
            {
                parent::__construct($controller, 'test-worker');
            }

            public function exposeClaim(QueueItem $candidate): bool
            {
                return $this->claimRow(
                    $candidate,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s', time() + 300)
                );
            }
        };

        return $manager->exposeClaim($candidate);
    }

    /** @return array<string, mixed> */
    private function rowByType(string $type): array
    {
        $result = $this->db->query(
            $this->db->prepareQuery('SELECT * FROM queueitems WHERE type = %s LIMIT 1', $type)
        );

        return (array) $result->fields;
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
    // Telling waiting from working
    // =========================================================================

    /**
     * The pair of numbers that separates a slow handler from a waiting one.
     *
     * The one thing the reporting installation asked for and could not build. `execution_time`
     * is wall clock around the handler, so a task taking 0.9 seconds reads the same whether it
     * is working or waiting — and four wrong diagnoses all fitted it over four hours: an atomic
     * claim, a missing index, TimescaleDB compression, a CPU-bound handler. The tasks were at
     * **1.6% CPU**, waiting on a cache invalidation that scanned the whole redis keyspace per
     * model save.
     *
     * Their conclusion: *"A queue that recorded CPU time beside wall time per task would have
     * said 'these tasks are not computing' in the first ten minutes."*
     *
     * Written straight into the rows here rather than run through a handler, because a test
     * cannot spend a second of wall clock waiting to prove the arithmetic — and the arithmetic
     * is what is under test.
     */
    public function testItReportsHowMuchOfTheWallClockWasComputing(): void
    {
        // Arrange — a second of wall clock, 20ms of it computing: the reported shape
        $this->completedRow('effort', 1.000, 0.020);
        $this->completedRow('effort', 1.000, 0.020);

        // Act
        $numbers = $this->manager->throughput(300, 'effort');

        // Assert
        $this->assertSame(2.0, round((float) $numbers['wall'], 3));
        $this->assertSame(0.04, round((float) $numbers['cpu'], 3));
        $this->assertSame(0.02, round((float) $numbers['computing'], 3), 'the ratio is the diagnosis');
    }

    /**
     * A busy handler reads as busy, which is the other half of the diagnosis.
     *
     * The control, and it matters because the two answers point in opposite directions:
     * computing means faster code or more cores, waiting means adding workers will not help
     * because whatever they wait for is already the limit. A measure that always said
     * "waiting" would send everybody the same wrong way.
     */
    public function testAComputingHandlerReadsAsComputing(): void
    {
        // Arrange
        $this->completedRow('effort', 0.500, 0.480);

        // Act
        $numbers = $this->manager->throughput(300, 'effort');

        // Assert
        $this->assertGreaterThan(0.9, (float) $numbers['computing']);
    }

    /**
     * Unmeasured is reported as unknown, not as zero.
     *
     * `null` where nothing recorded CPU — a row from before the column existed, a platform
     * with no `getrusage()`. Zero would read as *this task did no work*, which is the opposite
     * conclusion from *nobody measured*, and it is the reading that would send somebody
     * hunting for a phantom bottleneck.
     */
    public function testUnmeasuredEffortIsUnknownRatherThanZero(): void
    {
        // Arrange — wall clock recorded, CPU not
        $this->completedRow('effort', 1.000, null);

        // Act
        $numbers = $this->manager->throughput(300, 'effort');

        // Assert
        $this->assertNull($numbers['computing'], 'unmeasured CPU was reported as no work');
        $this->assertNull($numbers['cpu']);
    }

    /**
     * `Worker` measures it, which is the half that has to actually happen.
     *
     * Everything above tests the arithmetic over rows somebody else wrote. This drives a real
     * handler through `processNextTask()` and asserts the number arrives — on the task, in the
     * returned info, and in the row.
     */
    public function testTheWorkerRecordsTheCpuItUsed(): void
    {
        if (!function_exists('getrusage')) {
            $this->markTestSkipped('No getrusage() here, so nothing can be measured.');
        }

        // Arrange — a handler that burns a measurable amount of CPU
        $this->manager->addTask('effort', array('n' => 1));

        $worker = new \Pramnos\Queue\Worker($this->controller, 'cpu-worker');
        $worker->registerTaskHandler('effort', BusyProbeTask::class);

        // Act
        $info = $worker->processNextTask('effort');

        // Assert
        $this->assertIsArray($info);
        $this->assertSame('completed', $info['status'], (string) ($info['message'] ?? ''));
        $this->assertArrayHasKey('cpu_time', $info, 'the worker measured no CPU');
        $this->assertGreaterThan(0.0, (float) $info['cpu_time']);

        // and it is on the row, not only in the return value
        $row = $this->db->query("SELECT cpu_time FROM queueitems WHERE type = 'effort' LIMIT 1");
        $this->assertNotNull($row->fields['cpu_time'], 'the CPU time was not stored');
        $this->assertGreaterThan(0.0, (float) $row->fields['cpu_time']);
    }

    /**
     * An honest completion time: a number when it will clear, nothing when it will not.
     *
     * **"never" is the useful answer**, and an estimate usually refuses to give it. Dividing
     * the backlog by the completion rate alone produces a figure that recedes every refresh,
     * so a queue reads as nearly finished right until it obviously is not. The rate has to be
     * the *net* rate, because work arriving during the drain has to be drained too.
     */
    public function testItGivesNoCompletionTimeForAQueueThatIsLosing(): void
    {
        // Arrange — three in, one out: losing
        $this->manager->addTask('eta', array('n' => 1));
        $this->manager->addTask('eta', array('n' => 2));
        $this->manager->addTask('eta', array('n' => 3));
        $task = $this->manager->getNextTask('eta');
        $this->manager->markTaskAsCompleted($task, 'done', 0.1);

        // Act
        $numbers = $this->manager->throughput(300, 'eta');

        // Assert
        $this->assertNull($numbers['clears_in'], 'a losing queue was given a completion time');
    }

    /**
     * And a draining queue gets one, from the net rate.
     *
     * The control. An estimate that is always "never" is as useless as one that always
     * recedes.
     */
    public function testADrainingQueueGetsACompletionTime(): void
    {
        // Arrange — two waiting, two completed in the window, nothing new arriving
        $this->manager->addTask('eta', array('n' => 1));
        $this->manager->addTask('eta', array('n' => 2));
        $this->completedRow('eta', 0.1, 0.1);
        $this->completedRow('eta', 0.1, 0.1);

        // Backdate the arrivals out of the window so completions win
        $this->db->query(
            $this->db->prepareQuery(
                "UPDATE queueitems SET createdat = %s WHERE type = 'eta' AND status = 'pending'",
                date('Y-m-d H:i:s', time() - 7200)
            )
        );

        // Act
        $numbers = $this->manager->throughput(300, 'eta');

        // Assert
        $this->assertNotNull($numbers['clears_in'], 'a draining queue was given no estimate');
        $this->assertGreaterThan(0, (int) $numbers['clears_in']);
    }

    /**
     * An empty queue clears now, which is neither null nor an arithmetic error.
     *
     * Zero pending over any rate is zero seconds; the naive expression divides by a rate that
     * may itself be zero.
     */
    public function testAnEmptyQueueClearsImmediately(): void
    {
        // Act
        $numbers = $this->manager->throughput(300, 'nothing-here');

        // Assert
        $this->assertSame(0, $numbers['clears_in']);
    }

    /**
     * A finished row with the timings somebody else's worker would have written.
     *
     * **`createdat` is backdated out of the window on purpose.** It is the realistic shape —
     * a task completed now was enqueued earlier — and without it these rows count as
     * *arrivals* too, so two completions and two arrivals net to zero and a draining queue
     * looks like a level one. Which is exactly how the first version of the completion-time
     * test failed.
     */
    private function completedRow(string $type, float $wall, ?float $cpu): void
    {
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO queueitems (type, payload, status, priority, attempts, maxattempts,'
                . ' createdat, completedat, execution_time, cpu_time)'
                . " VALUES (%s, '{}', 'completed', 10, 1, 3, %s, %s, %f, "
                . ($cpu === null ? 'NULL' : '%f') . ')',
                ...($cpu === null
                    ? array($type, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s'), $wall)
                    : array($type, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s'), $wall, $cpu))
            )
        );
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
        // The roll-up table the purge writes before deleting; dropped here so a bucket from
        // one test cannot be added to by the next — the upsert is additive by design.
        $this->db->query('DROP TABLE IF EXISTS `queuestats`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    // -------------------------------------------------------------------------
    // The roll-up: purgeOldTasks() summarises before it deletes
    // -------------------------------------------------------------------------

    /**
     * Write a terminal task with times of our choosing, old enough to be purged.
     *
     * The columns are set directly rather than through the lifecycle, because what is being
     * tested is the aggregate over them and driving a worker to produce a chosen wait is
     * both slower and less exact.
     *
     * @param  string $status    Terminal status
     * @param  float  $execution Seconds the handler took
     * @param  float  $wait      Seconds the task sat before a worker took it
     * @param  int    $hoursAgo  How long ago it completed
     * @param  int    $attempts  Attempt count; > 1 makes it a retry
     * @return int    The taskid
     */
    protected function seedFinishedTask(
        string $type,
        string $status,
        float $execution,
        float $wait,
        int $hoursAgo = 48,
        int $attempts = 1,
        ?float $cpu = null
    ): int {
        $completed = time() - ($hoursAgo * 3600);
        $started   = $completed - (int) ceil($execution);
        $created   = $started - (int) ceil($wait);

        $id = $this->manager->addTask($type, array('probe' => $type . $status . $hoursAgo . $wait));

        $quote = $this->db->type === 'postgresql' ? '"' : '`';
        $set = array(
            $quote . 'status' . $quote . " = '" . $status . "'",
            $quote . 'attempts' . $quote . ' = ' . $attempts,
            $quote . 'execution_time' . $quote . ' = ' . $execution,
            $quote . 'cpu_time' . $quote . ' = ' . ($cpu === null ? 'NULL' : (string) $cpu),
            $quote . 'createdat' . $quote . " = '" . date('Y-m-d H:i:s', $created) . "'",
            $quote . 'startedat' . $quote . " = '" . date('Y-m-d H:i:s', $started) . "'",
            $quote . 'completedat' . $quote . " = '" . date('Y-m-d H:i:s', $completed) . "'",
        );

        $this->db->query(
            'UPDATE ' . $quote . 'queueitems' . $quote . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $quote . 'taskid' . $quote . ' = ' . (int) $id
        );

        return (int) $id;
    }

    /** Every row of the roll-up, newest bucket first. */
    protected function statsRows(): array
    {
        $quote  = $this->db->type === 'postgresql' ? '"' : '`';
        $result = $this->db->query(
            'SELECT * FROM ' . $quote . 'queuestats' . $quote . ' ORDER BY bucket DESC, type ASC'
        );

        return ($result && $result->numRows) ? $result->fetchAll() : array();
    }

    /**
     * The purge summarises the rows it deletes instead of losing them.
     *
     * The whole filing in one test: the queue recorded `execution_time` and `cpu_time` per
     * task and `queue:cleanup` deleted them an hour later, so an installation could not
     * answer "is this task type slower than last week" about either.
     */
    public function testPurgeSummarisesBeforeDeleting(): void
    {
        // Arrange — two tasks of one type in the same hour, both old enough to purge
        $this->seedFinishedTask('roll_a', 'completed', 2.0, 4.0, 48, 1, 1.5);
        $this->seedFinishedTask('roll_a', 'completed', 4.0, 8.0, 48, 1, 2.5);

        // Act
        $deleted = $this->manager->purgeOldTasks(24, array('completed', 'failed'));

        // Assert — the rows are gone
        $this->assertSame(2, $deleted);

        // and the measurements are not
        $rows = $this->statsRows();
        $this->assertCount(1, $rows, 'two tasks of one type in one hour is one bucket');
        $this->assertSame('roll_a', $rows[0]['type']);
        $this->assertSame(2, (int) $rows[0]['tasks']);
        $this->assertEqualsWithDelta(6.0, (float) $rows[0]['sum_exec'], 0.01, 'sum of execution_time');
        $this->assertEqualsWithDelta(4.0, (float) $rows[0]['max_exec'], 0.01);
        $this->assertEqualsWithDelta(4.0, (float) $rows[0]['sum_cpu'], 0.01);
    }

    /**
     * Wait time is recorded, which it was not anywhere before.
     *
     * The most useful number a queue has — how long a task sat before a worker took it —
     * existed only as `startedat - createdat` on rows about to be deleted. One installation
     * found a nightly burst with it: 16,130 tasks at 01:00 averaging 9.48 s against ~0.60 s
     * every other hour, which nothing would have shown and nothing had recorded.
     */
    public function testWaitTimeIsRecorded(): void
    {
        // Arrange — one quick task and one that sat for a while
        $this->seedFinishedTask('roll_wait', 'completed', 1.0, 2.0);
        $this->seedFinishedTask('roll_wait', 'completed', 1.0, 40.0);

        // Act
        $this->manager->purgeOldTasks(24);

        // Assert
        $rows = $this->statsRows();
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(42.0, (float) $rows[0]['sum_wait'], 1.0, 'sum of waits');
        $this->assertEqualsWithDelta(40.0, (float) $rows[0]['max_wait'], 1.0, 'the burst is the max');
    }

    /**
     * Failed, warning and retried are counted separately.
     *
     * Not decoration: `queue:cleanup` keeps warnings for `$hours * 10`, so on one
     * installation `warning` was the **dominant** population — 38,041 rows against 25,861
     * completed. A roll-up that counted only "tasks" would describe the small half.
     */
    public function testStatusesAndRetriesAreBrokenOut(): void
    {
        // Arrange
        $this->seedFinishedTask('roll_mix', 'completed', 1.0, 1.0);
        $this->seedFinishedTask('roll_mix', 'failed', 1.0, 1.0);
        $this->seedFinishedTask('roll_mix', 'completed', 1.0, 1.0, 48, 3);

        // Act — the statuses the framework's own cleanup purges together
        $this->manager->purgeOldTasks(24, array('completed', 'failed'));

        // Assert
        $rows = $this->statsRows();
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['tasks']);
        $this->assertSame(1, (int) $rows[0]['failed']);
        $this->assertSame(1, (int) $rows[0]['retried'], 'attempts > 1 is a retry');
    }

    /**
     * A second purge inside the same hour adds to the bucket rather than replacing it.
     *
     * The property that makes sums and maxima the right thing to store and averages the
     * wrong one: an average cannot be added to another average. `max` takes the greater of
     * the two, which is what `GREATEST` in the upsert is for.
     */
    public function testASecondPurgeAddsToTheSameBucket(): void
    {
        // Arrange & Act — the **larger** task first, purged
        $this->seedFinishedTask('roll_twice', 'completed', 6.0, 9.0);
        $this->manager->purgeOldTasks(24);

        // and a smaller one in the same hour, purged separately
        $this->seedFinishedTask('roll_twice', 'completed', 2.0, 3.0);
        $this->manager->purgeOldTasks(24);

        // Assert — one bucket, both tasks
        $rows = $this->statsRows();
        $this->assertCount(1, $rows, 'the second purge created a second bucket');
        $this->assertSame(2, (int) $rows[0]['tasks']);
        $this->assertEqualsWithDelta(8.0, (float) $rows[0]['sum_exec'], 0.01);

        /*
         * The larger value goes **first** deliberately.
         *
         * A first version of this test purged the small one first, and then
         * `max_exec = VALUES(max_exec)` — replacing rather than taking the greater — gave
         * the same answer, so removing `GREATEST` reddened nothing. Ordering the arrival
         * this way is the only way the assertion means what it says: a maximum that is
         * overwritten by a later, smaller purge is a maximum that reports the last hour
         * instead of the worst.
         */
        $this->assertEqualsWithDelta(
            6.0,
            (float) $rows[0]['max_exec'],
            0.01,
            'GREATEST, not the last write'
        );
        $this->assertEqualsWithDelta(9.0, (float) $rows[0]['max_wait'], 1.0, 'and the same for wait');
    }

    /**
     * A counted row is a deleted row, so nothing is summarised twice.
     *
     * The transaction's whole purpose. Purging again with nothing left must add nothing —
     * if the aggregate ran over rows the DELETE did not take, a second call would
     * double-count the bucket.
     */
    public function testPurgingAgainWithNothingLeftAddsNothing(): void
    {
        // Arrange
        $this->seedFinishedTask('roll_once', 'completed', 1.0, 1.0);
        $this->manager->purgeOldTasks(24);
        $first = $this->statsRows();

        // Act
        $this->assertSame(0, $this->manager->purgeOldTasks(24));

        // Assert
        $this->assertSame($first, $this->statsRows(), 'an empty purge changed the roll-up');
    }

    /**
     * Types are separate buckets, because "slower than last week" is asked per type.
     */
    public function testEachTypeGetsItsOwnBucket(): void
    {
        // Arrange
        $this->seedFinishedTask('roll_x', 'completed', 1.0, 1.0);
        $this->seedFinishedTask('roll_y', 'completed', 5.0, 1.0);

        // Act
        $this->manager->purgeOldTasks(24);

        // Assert
        $rows = $this->statsRows();
        $this->assertCount(2, $rows);
        $types = array_map(static fn(array $r): string => (string) $r['type'], $rows);
        sort($types);
        $this->assertSame(array('roll_x', 'roll_y'), $types);
    }

    /**
     * A task that is not old enough is neither deleted nor counted.
     *
     * The predicate is shared between the aggregate and the DELETE precisely so these two
     * cannot disagree — a row counted but not deleted is one that gets counted again.
     */
    public function testARecentTaskIsNeitherPurgedNorCounted(): void
    {
        // Arrange — completed an hour ago, against a 24-hour cutoff
        $this->seedFinishedTask('roll_recent', 'completed', 1.0, 1.0, 1);

        // Act
        $this->assertSame(0, $this->manager->purgeOldTasks(24));

        // Assert
        $this->assertSame(array(), $this->statsRows());
    }

    /**
     * `history()` answers a window longer than the retention, which `throughput()` cannot.
     *
     * Consequence 1 of the filing: `throughput()` counts arrivals and completions among
     * rows that are still there, so `queue:health --window=86400` on an hourly purge counts
     * the ninety minutes that survived, reports the rate as if it were the day, and exits 0.
     * The buckets outlive the rows.
     */
    public function testHistoryReadsTheBucketsAfterTheRowsAreGone(): void
    {
        // Arrange — purged, so no live row remains
        $this->seedFinishedTask('roll_hist', 'completed', 3.0, 6.0, 48, 2, 1.0);
        $this->seedFinishedTask('roll_hist', 'completed', 1.0, 2.0, 48, 1, 1.0);
        $this->manager->purgeOldTasks(24);

        // Act
        $history = $this->manager->history(168, 'roll_hist');

        // Assert — the means are derived, not stored
        $this->assertCount(1, $history);
        $this->assertSame('roll_hist', $history[0]['type']);
        $this->assertSame(2, $history[0]['tasks']);
        $this->assertEqualsWithDelta(2.0, $history[0]['exec_mean'], 0.01, '(3 + 1) / 2');
        $this->assertEqualsWithDelta(3.0, $history[0]['exec_max'], 0.01);
        $this->assertEqualsWithDelta(4.0, $history[0]['wait_mean'], 1.0, '(6 + 2) / 2');
        $this->assertEqualsWithDelta(0.5, $history[0]['retry_rate'], 0.01, 'one of two retried');

        // and `throughput()` cannot see any of it, which is the contrast being asserted
        $live = $this->manager->throughput(86400, 'roll_hist');
        $this->assertSame(0, (int) ($live['completions'] ?? -1));
    }

    /**
     * `cpu_mean` is null when nothing measured CPU, and not zero.
     *
     * `cpu_time` is nullable because it is unknowable where `getrusage()` is unavailable,
     * and a zero would read as "these tasks did no work" — which is exactly the diagnosis
     * the column was added to make possible, and the wrong one to hand somebody for free.
     */
    public function testCpuMeanIsNullWhenNothingMeasuredIt(): void
    {
        // Arrange — no cpu_time
        $this->seedFinishedTask('roll_nocpu', 'completed', 1.0, 1.0, 48, 1, null);

        // Act
        $this->manager->purgeOldTasks(24);
        $history = $this->manager->history(168, 'roll_nocpu');

        // Assert
        $this->assertCount(1, $history);
        $this->assertNull($history[0]['cpu_mean'], 'unmeasured CPU must not read as no work');
    }

    /**
     * A bounded purge deletes without summarising, and says so.
     *
     * `DELETE … LIMIT` takes an arbitrary subset of what the predicate matches, so an
     * aggregate over the predicate would count rows that are still there — and the next
     * call would count them again. Saying so beats summarising it wrongly; `$limit` exists
     * to keep one DELETE off a lock, not as the normal path.
     */
    public function testABoundedPurgeIsNotSummarised(): void
    {
        // Arrange
        $this->seedFinishedTask('roll_limit', 'completed', 1.0, 1.0);
        $this->seedFinishedTask('roll_limit', 'completed', 1.0, 1.0);

        // Act — MySQL supports DELETE … LIMIT; PostgreSQL does not, so only assert where it runs
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('DELETE … LIMIT is a MySQL extension; the limit path cannot run here.');
        }

        $deleted = $this->manager->purgeOldTasks(24, array('completed', 'failed'), 1);

        // Assert — something went, and nothing was rolled up
        $this->assertSame(1, $deleted);
        $this->assertSame(array(), $this->statsRows());
    }

    /**
     * With no `queuestats` table the purge still works.
     *
     * An installation that has not run the migration keeps deleting: the roll-up is
     * additive to what the queue already did, not a precondition for it.
     */
    public function testThePurgeWorksWithoutTheRollUpTable(): void
    {
        // Arrange
        $this->seedFinishedTask('roll_nostats', 'completed', 1.0, 1.0);

        $quote = $this->db->type === 'postgresql' ? '"' : '`';
        $this->db->query('DROP TABLE IF EXISTS ' . $quote . 'queuestats' . $quote
            . ($this->db->type === 'postgresql' ? ' CASCADE' : ''));

        // Act & Assert
        $this->assertSame(1, $this->manager->purgeOldTasks(24));
        $this->assertSame(array(), $this->manager->history());
    }
}

/**
 * A handler that spends real CPU, so `cpu_time` has something to measure.
 *
 * Busy rather than asleep, which is the whole point: a `usleep()` would accrue wall clock
 * and no CPU and prove the opposite of what this fixture is for.
 */
class BusyProbeTask extends \Pramnos\Queue\AbstractTask
{
    public function getDescription(\Pramnos\Queue\QueueItem $queueItem): string
    {
        return 'Burns a measurable amount of CPU so cpu_time has something to record';
    }

    public function execute(\Pramnos\Queue\QueueItem $task): mixed
    {
        $sum = 0.0;

        for ($i = 0; $i < 200000; $i++) {
            $sum += sqrt($i);
        }

        return array('message' => 'burned ' . (int) $sum);
    }
}
