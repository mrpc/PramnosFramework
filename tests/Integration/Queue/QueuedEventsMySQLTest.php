<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Event\Event;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;
use Pramnos\Framework\Factory;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\Worker;

/**
 * A queued listener the integration tests watch: what it ran with, and whether to fail.
 */
final class IntegrationQueuedListener extends QueuedListener
{
    /** @var list<list<mixed>> The arguments of every run */
    public static array $runs = [];

    /** Whether the next run throws. */
    public static bool $fail = false;

    /** Record the run, or fail. */
    public function handle(mixed ...$args): mixed
    {
        if (self::$fail) {
            throw new \RuntimeException('provider down');
        }
        self::$runs[] = $args;

        return true;
    }

    /** Folded per account, when the event says so. */
    public function debounceKey(mixed ...$args): ?string
    {
        return ($args[1] ?? null) === 'fold' ? 'sync:' . $args[0] : null;
    }

    /** Once per account, when the event says so. */
    public function idempotencyKey(mixed ...$args): ?string
    {
        return ($args[1] ?? null) === 'once' ? 'welcome:' . $args[0] : null;
    }
}

/**
 * Queued listeners against a live MySQL database: the task is written in the caller's
 * transaction, folded by key, run once per idempotency key, and run by an ordinary worker.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
#[CoversClass(QueuedEvents::class)]
#[CoversClass(QueueManager::class)]
class QueuedEventsMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;
    protected Controller $controller;
    protected QueueManager $manager;

    /** The queue tables from the framework's migrations, empty, and the event bus clean. */
    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');
        $this->db = $this->connect();

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $this->app = $app;
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $controller->application = $app;
        $this->controller = $controller;

        $this->dropTables();
        foreach (MigrationLoader::loadFromDirectory(dirname(__DIR__, 3) . '/database/migrations/framework/queue', $app) as $migration) {
            $migration->up();
        }
        QueueManager::forgetSchemaCache();
        \Pramnos\Application\Model::$columnCache = [];

        $this->manager = new QueueManager($controller);
        Event::forget();
        QueuedEvents::reset();
        QueuedEvents::$queue = $this->manager;
        IntegrationQueuedListener::$runs = [];
        IntegrationQueuedListener::$fail = false;
        Event::listen('account.changed', IntegrationQueuedListener::class);
    }

    /** Nothing of this class left for the next. */
    protected function tearDown(): void
    {
        Event::forget();
        QueuedEvents::reset();
        $this->dropTables();
        QueueManager::forgetSchemaCache();
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(true);
        }

        return $db;
    }

    /** Drop the queue tables this class builds. */
    protected function dropTables(): void
    {
        foreach (['queueitems', 'queuestats', 'queue_idempotency'] as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
    }

    /** The event tasks in the queue, oldest first. @return list<array<string, mixed>> */
    private function tasks(): array
    {
        $result = $this->db->queryBuilder()->table('queueitems')->where('type', 'event.listener')->orderBy('taskid')->get();

        return array_map(static fn ($row) => (array) $row, $result ? $result->fetchAll() : []);
    }

    /**
     * The task is the worker's, not the request's: fire() writes it and runs nothing, and an
     * ordinary worker — with no handler registered by the application — runs the listener with
     * the event's arguments and completes the task.
     */
    public function testAWorkerRunsWhatFireQueued(): void
    {
        // Arrange
        Event::fireAs(null, 'account.changed', 42, 'plain');
        $queuedRuns = IntegrationQueuedListener::$runs;

        // Act
        $result = (new Worker($this->controller))->processNextTask();

        // Assert
        $this->assertSame([], $queuedRuns, 'fire() ran the listener in the request');
        $this->assertSame('completed', $result['status'] ?? null, (string) ($result['message'] ?? ''));
        $this->assertSame([[42, 'plain']], IntegrationQueuedListener::$runs);
        $task = $this->tasks()[0];
        $meta = json_decode((string) $task['payload'], true)['meta'];
        $this->assertNull($meta['userid'], 'fireAs(null) was not recorded as the system');
        $this->assertSame('cli', $meta['source']);
    }

    /**
     * The outbox: a task written inside the caller's transaction goes with it — none after a
     * rollback, one after a commit — and without a transaction it is written at once.
     */
    public function testTheTaskCommitsAndRollsBackWithTheCallersTransaction(): void
    {
        // Act
        $this->db->startTransaction();
        Event::fire('account.changed', 1, 'plain');
        $this->db->rollbackTransaction();
        $afterRollback = count($this->tasks());

        $this->db->startTransaction();
        Event::fire('account.changed', 2, 'plain');
        $this->db->commitTransaction();
        $afterCommit = count($this->tasks());

        Event::fire('account.changed', 3, 'plain');

        // Assert
        $this->assertSame(0, $afterRollback, 'a rolled-back change left its task behind');
        $this->assertSame(1, $afterCommit);
        $this->assertCount(2, $this->tasks(), 'without a transaction the task was not written');
    }

    /**
     * Five events with one key before a worker takes it are one task, carrying the last event's
     * arguments and counting the four it absorbed, due a window after the last.
     */
    public function testEventsWithOneKeyFoldIntoOneTask(): void
    {
        // Act
        $ids = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $version) {
            $ids[] = Event::fire('account.changed', 7, 'fold', $version)[0];
        }

        // Assert
        $tasks = $this->tasks();
        $this->assertCount(1, $tasks);
        $this->assertSame(1, count(array_unique($ids)), 'the events went to different tasks');
        $this->assertSame(4, (int) $tasks[0]['absorbed']);
        $this->assertSame('sync:7', $tasks[0]['dedupekey']);
        $this->assertSame([7, 'fold', 'e'], json_decode((string) $tasks[0]['payload'], true)['args']);
        $this->assertGreaterThan(time() + 50, strtotime((string) $tasks[0]['availableat']), 'the task is not held for the window');
    }

    /**
     * Events that never stop cannot hold the task back for ever: past the ceiling from its
     * first event, a new event no longer moves it.
     */
    public function testTheCeilingRunsAFoldedTaskWhileEventsContinue(): void
    {
        // Arrange — a folded task whose first event was 590 seconds ago
        $id      = Event::fire('account.changed', 8, 'fold', 'first')[0];
        $created = time() - 590;
        $this->db->queryBuilder()->table('queueitems')->where('taskid', $id)->update(['createdat' => date('Y-m-d H:i:s', $created)]);

        // Act
        Event::fire('account.changed', 8, 'fold', 'later');

        // Assert
        $task = $this->tasks()[0];
        $this->assertSame(1, (int) $task['absorbed']);
        // Due at the ceiling — first event + 600 — not a full window after the last one.
        $this->assertSame($created + 600, strtotime((string) $task['availableat']));
    }

    /**
     * Work done once per key: an event after the first one ran queues nothing; a run that fails
     * gives the key back, so the retry can still do it.
     */
    public function testAnIdempotencyKeyRunsTheWorkOnce(): void
    {
        // Arrange — the first run fails
        IntegrationQueuedListener::$fail = true;
        Event::fire('account.changed', 9, 'once');
        $worker = new Worker($this->controller);
        $failed = $worker->processNextTask();
        $heldAfterFailure = $this->db->queryBuilder()->table('queue_idempotency')->count();

        // Act — the retry runs it; then the same event again
        IntegrationQueuedListener::$fail = false;
        $this->db->queryBuilder()->table('queueitems')->update(['availableat' => null]);
        $retried = $worker->processNextTask();
        $again   = Event::fire('account.changed', 9, 'once');

        // Assert
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(0, $heldAfterFailure, 'a failed run kept the key');
        $this->assertSame('completed', $retried['status']);
        $this->assertSame([[9, 'once']], IntegrationQueuedListener::$runs);
        $this->assertSame([null], $again, 'work done once was queued again');
        $this->assertCount(1, $this->tasks());
    }

    /**
     * Two tasks for one key queued before either ran — the key was not used yet when the second
     * event came — run the work once: the second finds the key taken and completes as skipped.
     */
    public function testASecondTaskForAUsedKeyIsSkipped(): void
    {
        // Arrange
        Event::fire('account.changed', 10, 'once');
        Event::fire('account.changed', 10, 'once');
        $worker = new Worker($this->controller);

        // Act
        $first  = $worker->processNextTask();
        $second = $worker->processNextTask();

        // Assert
        $this->assertSame('completed', $first['status']);
        $this->assertSame('completed', $second['status']);
        $this->assertSame('Skipped: already done (welcome:10)', $second['message']);
        $this->assertCount(1, IntegrationQueuedListener::$runs);
    }

    /**
     * Before the migrations have run, a queued listener still queues — unfolded, with no
     * idempotency — rather than failing the request.
     */
    public function testWithoutTheNewColumnsAndTableTheTaskIsStillQueued(): void
    {
        // Arrange — the queue as it was before this feature's migrations
        foreach (MigrationLoader::loadFromDirectory(dirname(__DIR__, 3) . '/database/migrations/framework/queue', $this->app) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Queue\AddEventColumnsToQueueitems
                || $migration instanceof \Pramnos\Framework\Migrations\Queue\CreateQueueIdempotencyTable
            ) {
                $migration->down();
            }
        }
        QueueManager::forgetSchemaCache();
        QueuedEvents::reset();
        QueuedEvents::$queue = $this->manager;

        // Act
        Event::fire('account.changed', 11, 'fold');
        Event::fire('account.changed', 11, 'once');
        $ran = (new Worker($this->controller))->processNextTask();

        // Assert
        $this->assertCount(2, $this->tasks());
        $this->assertSame('completed', $ran['status']);
        $this->assertFalse($this->db->schema()->hasColumn('queueitems', 'dedupekey'));
    }
}
