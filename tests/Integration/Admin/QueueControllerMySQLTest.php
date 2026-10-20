<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Queue\Controllers\QueueController;

/**
 * Testable QueueController subclass.
 *
 * Bypasses the auth check in requireMinUserType() so integration tests
 * can call action methods without setting up a full session.
 */
class TestableQueueController extends QueueController
{
    protected function requireMinUserType(int $minType): bool
    {
        // Always grant access in test context — auth is not the focus here.
        return false;
    }

    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        // Suppress redirect output — tests verify DB state instead.
    }
}

/**
 * A wrapper as an application writes one: a column of its own in the list, and the view the
 * screen builds kept for the test to read.
 */
class WrappedQueueController extends TestableQueueController
{
    /** The view display() filled. */
    public ?object $view = null;

    /** The application's own column. */
    protected function extraColumns(): array
    {
        return ['Sent by' => 'note'];
    }

    /** A view that keeps what it is given and renders nothing. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $this->view = new #[\AllowDynamicProperties] class {
            /** The table the screen built. */
            public ?object $datatable = null;

            /** The statuses it offers. */
            public array $statuses = [];

            /** Which template was asked for, so a test can tell the pages apart. */
            public string $template = '';

            /** Nothing to render in a test. */
            public function display(string $template = ''): string
            {
                $this->template = $template;

                return 'rendered';
            }
        };

        return $this->view;
    }
}

/**
 * Integration tests for QueueController against a live MySQL 8.0 database.
 *
 * These tests verify that the controller's database operations — soft-delete,
 * retry, bulk-retry, and bulk-clear — actually take effect in the real queueitems
 * table.  Unit tests verify the controller's structural contracts (auth, methods);
 * these integration tests prove that the SQL mutations are correct.
 *
 * Isolation: setUp creates a fresh queueitems table; tearDown drops it.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class QueueControllerMySQLTest extends BaseTestCase
{
    protected Database $db;
    protected Application $app;
    protected TestableQueueController $ctrl;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('sURL')) {
            define('sURL', 'http://localhost/');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        // Discard the database singleton before asking for it. parent::setUp() boots the
        // application, which builds one *before* the settings above are loaded — so the
        // cached handle points at nothing and connecting reports "No such file or
        // directory" from a socket path it was never given.
        //
        // This class passed for as long as another class had already built a correct
        // singleton earlier in the run. On its own — `--testsuite 'Integration Tests'` —
        // all seven of its tests errored.
        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->dropQueueTable();
        $this->runQueueMigration();

        $this->app  = $this->makeApp();
        $this->ctrl = new TestableQueueController($this->app);
    }

    protected function tearDown(): void
    {
        $this->dropQueueTable();
    }

    // -------------------------------------------------------------------------
    // retry()
    // -------------------------------------------------------------------------

    /**
     * retry($id) must change status from 'failed' to 'pending' in the DB.
     *
     * This is the recovery path: a failed job can be rescheduled without
     * touching the queue from the CLI. If the UPDATE does not run, the job
     * stays failed indefinitely.
     */
    public function testRetryChangesFailedJobToPending(): void
    {
        // Arrange — insert a failed job directly
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('test_job', '{}', 'failed', NOW())"
        );
        $id = (int) $this->db->getInsertId();

        // Act
        $_GET['_option'] = $id;
        $this->ctrl->retry($id);

        // Assert — status must now be 'pending'
        $row = $this->db->query("SELECT status FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('pending', $row->fields['status'],
            "retry() must reset a failed job's status to 'pending'");
    }

    /**
     * retry() on a non-failed job must NOT change its status.
     *
     * A pending job that is accidentally retried must remain pending;
     * otherwise a processing job could be reset while a worker holds it.
     */
    public function testRetryDoesNotAffectNonFailedJob(): void
    {
        // Arrange — insert a pending job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('pending_job', '{}', 'pending', NOW())"
        );
        $id = (int) $this->db->getInsertId();

        // Act
        $_GET['_option'] = $id;
        $this->ctrl->retry($id);

        // Assert — status unchanged (the WHERE status='failed' guard must hold)
        $row = $this->db->query("SELECT status FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('pending', $row->fields['status'],
            "retry() must not change non-failed jobs — the WHERE status='failed' guard must hold");
    }

    // -------------------------------------------------------------------------
    // retryall()
    // -------------------------------------------------------------------------

    /**
     * retryall() must reset ALL failed jobs to 'pending' in one operation.
     *
     * Without this, an operator must retry each failed job individually,
     * which is unusable when a batch of 100 jobs fails due to a transient error.
     */
    public function testRetryAllResetsAllFailedJobs(): void
    {
        // Arrange — insert three failed and one pending job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES
             ('job_a', '{}', 'failed', NOW()),
             ('job_b', '{}', 'failed', NOW()),
             ('job_c', '{}', 'failed', NOW()),
             ('job_d', '{}', 'pending', NOW())"
        );

        // Act
        $this->ctrl->retryall();

        // Assert — all three failed jobs now pending
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM queueitems WHERE type IN ('job_a','job_b','job_c') AND status = 'pending'"
        );
        $this->assertSame(3, (int)$result->fields['cnt'],
            'retryall() must reset all three failed jobs to pending');

        // Assert — the originally-pending job is still pending (not touched)
        $result2 = $this->db->query(
            "SELECT status FROM queueitems WHERE type = 'job_d'"
        );
        $this->assertSame('pending', $result2->fields['status'],
            'retryall() must not touch already-pending jobs');
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    /**
     * delete($id) must soft-delete the job by setting status='deleted'.
     *
     * Hard DELETE is deliberately avoided so the row remains available for
     * audit and post-mortem analysis. Workers must skip 'deleted' items.
     */
    public function testDeleteSoftDeletesJobByStatus(): void
    {
        // Arrange
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('delete_me', '{}', 'completed', NOW())"
        );
        $id = (int) $this->db->getInsertId();

        // Act
        $_GET['_option'] = $id;
        $this->ctrl->delete($id);

        // Assert — row still exists, status changed to 'deleted'
        $row = $this->db->query("SELECT status FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('deleted', $row->fields['status'],
            "delete() must soft-delete (status='deleted'), not hard-delete");
    }

    /**
     * delete() with invalid id (0) must NOT modify any row.
     *
     * An invalid ID guard prevents accidentally deleting unintended rows
     * when the id parameter is missing from a crafted URL.
     */
    public function testDeleteWithInvalidIdIsNoop(): void
    {
        // Arrange — insert a job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('safe_job', '{}', 'pending', NOW())"
        );

        // Act — call with id=0 (invalid)
        $this->ctrl->delete(0);

        // Assert — the pending job is untouched
        $result = $this->db->query("SELECT status FROM queueitems WHERE type = 'safe_job'");
        $this->assertSame('pending', $result->fields['status'],
            "delete(0) must be a noop — no rows should be modified");
    }

    // -------------------------------------------------------------------------
    // clear()
    // -------------------------------------------------------------------------

    /**
     * clear() with status='failed' must soft-delete all failed jobs.
     *
     * This is the bulk cleanup path: operators can purge completed/failed
     * jobs to keep the table lean without removing pending work.
     */
    public function testClearSoftDeletesAllFailedJobs(): void
    {
        // Arrange — two failed, one pending
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES
             ('fail_1', '{}', 'failed', NOW()),
             ('fail_2', '{}', 'failed', NOW()),
             ('keep_me', '{}', 'pending', NOW())"
        );
        $_POST['status'] = 'failed';

        // Act
        $this->ctrl->clear();

        // Assert — both failed rows are now 'deleted'
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM queueitems WHERE status = 'deleted'"
        );
        $this->assertSame(2, (int)$result->fields['cnt'],
            'clear(failed) must soft-delete all failed jobs');

        // Assert — pending job unaffected
        $pending = $this->db->query("SELECT status FROM queueitems WHERE type = 'keep_me'");
        $this->assertSame('pending', $pending->fields['status'],
            'clear() must not touch pending jobs');

        unset($_POST['status']);
    }

    /**
     * clear() with status='pending' must be rejected — pending jobs cannot be
     * bulk-deleted because they represent work that has not yet been attempted.
     * Bulk-deleting pending jobs would silently discard unprocessed tasks.
     */
    public function testClearWithPendingStatusIsRejected(): void
    {
        // Arrange — one pending job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('protected_job', '{}', 'pending', NOW())"
        );
        $_POST['status'] = 'pending';

        // Act — clear() with invalid status
        $this->ctrl->clear();

        // Assert — the pending job must not be touched
        $row = $this->db->query("SELECT status FROM queueitems WHERE type = 'protected_job'");
        $this->assertSame('pending', $row->fields['status'],
            "clear() must refuse status='pending' — pending jobs are protected");

        unset($_POST['status']);
    }

    // -------------------------------------------------------------------------
    // stop() / duplicate() / display() / data()
    // -------------------------------------------------------------------------

    /**
     * stop() turns a pending or a processing task into `stopped`, its lock cleared and its time
     * of completion set for the retention purge — and leaves a finished task alone. On
     * PostgreSQL this is also the proof that the status constraint accepts `stopped`.
     */
    public function testStopStopsAPendingOrProcessingTaskOnly(): void
    {
        // Arrange
        $ids = [];
        foreach (['pending', 'processing', 'completed'] as $status) {
            $this->db->queryBuilder()->table('queueitems')->insert(['type' => 'job', 'payload' => '{}', 'status' => $status, 'lockedby' => 'host:1']);
            $ids[$status] = (int) $this->db->queryBuilder()->table('queueitems')->select(['taskid'])->where('status', $status)->first()->fields['taskid'];
        }

        // Act
        foreach ($ids as $id) {
            $_GET['_option'] = $id;
            $this->ctrl->stop($id);
        }

        // Assert
        $rows = [];
        foreach ($this->db->queryBuilder()->table('queueitems')->select(['taskid', 'status', 'lockedby', 'completedat'])->getAll() as $row) {
            $rows[(int) $row['taskid']] = $row;
        }
        $this->assertSame('stopped', $rows[$ids['pending']]['status']);
        $this->assertSame('stopped', $rows[$ids['processing']]['status']);
        $this->assertNull($rows[$ids['processing']]['lockedby'], 'the lock was kept');
        $this->assertNotNull($rows[$ids['pending']]['completedat'], 'the retention purge would never see it');
        $this->assertSame('completed', $rows[$ids['completed']]['status'], 'a finished task was stopped');
    }

    /**
     * duplicate() queues a new pending copy — the type, payload, priority and attempts allowed,
     * no attempts made, no lock, no error — and leaves the original as it was; an id with no
     * task queues nothing.
     */
    public function testDuplicateQueuesAPendingCopy(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('queueitems')->insert([
            'type' => 'report', 'payload' => '{"n":7}', 'status' => 'failed', 'priority' => 3,
            'attempts' => 3, 'maxattempts' => 5, 'error' => 'boom', 'lockedby' => 'host:1',
        ]);
        $original = (int) $this->db->queryBuilder()->table('queueitems')->select(['taskid'])->first()->fields['taskid'];

        // Act
        $_GET['_option'] = $original;
        $this->ctrl->duplicate($original);
        $_GET['_option'] = $original + 1000;
        $this->ctrl->duplicate($original + 1000);

        // Assert
        $rows = $this->db->queryBuilder()->table('queueitems')->orderBy('taskid')->getAll();
        $this->assertCount(2, $rows, 'one copy, and nothing for a task that is not there');
        $copy = $rows[1];
        $this->assertSame(['report', 'pending', 3, 0, 5], [$copy['type'], $copy['status'], (int) $copy['priority'], (int) $copy['attempts'], (int) $copy['maxattempts']]);
        $this->assertSame(['n' => 7], json_decode((string) $copy['payload'], true));
        $this->assertNull($copy['error']);
        $this->assertNull($copy['lockedby']);
        $this->assertSame('failed', $rows[0]['status'], 'the original was changed');
    }

    /**
     * The list is a DataTable fed by data(): a wrapper's own column is a column of the table and
     * a cell of every row; every value is escaped; a task carries the actions its status allows.
     */
    public function testTheListShowsAnApplicationsColumnEscaped(): void
    {
        // Arrange — a column of the application's own, and a type that would be markup
        $this->db->query('ALTER TABLE ' . ($this->db->type === 'postgresql' ? '"queueitems"' : '`queueitems`') . ' ADD note VARCHAR(50)');
        $this->db->queryBuilder()->table('queueitems')->insert(['type' => '<b>x</b>', 'payload' => '{}', 'status' => 'pending', 'note' => 'anna@example.com', 'error' => str_repeat('e', 200)]);
        $ctrl  = new WrappedQueueController($this->app);
        $_POST = ['sEcho' => 1, 'iDisplayStart' => 0, 'iDisplayLength' => 10];
        \Pramnos\Http\Request::resetInstance();

        try {
            // Act
            $page = $ctrl->display();
            $data = json_decode($ctrl->data()->getBody(), true);
        } finally {
            $_POST = [];
            \Pramnos\Http\Request::resetInstance();
        }

        // Assert
        $this->assertSame('rendered', $page);
        $this->assertArrayHasKey('Sent by', $ctrl->view->datatable->aoColumns, 'the application column is not in the table');
        $row = ($data['aaData'] ?? $data['data'])[0];
        $this->assertStringContainsString('queue/show/', $row[0], 'the id does not open the task');
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $row[1], 'the type was not escaped');
        $this->assertStringContainsString('pf-state', $row[2]);
        $this->assertSame('10', $row[3], 'the default priority');
        $this->assertSame('0 / 3', $row[4]);
        $this->assertStringContainsString('…', $row[9], 'a long error is shortened, its whole text in the title');
        $this->assertSame('', $row[10], 'the payload is searched, never shown');
        $this->assertSame('anna@example.com', $row[11]);
        $this->assertStringContainsString('queue/stop/', $row[12]);
        $this->assertStringNotContainsString('queue/retry/', $row[12], 'a pending task offered a retry');
        // The state above the table: one count per status, and the type that has work waiting.
        $this->assertSame(1, $ctrl->view->counts['pending']);
        $this->assertSame(1, $ctrl->view->counts['total']);
        $this->assertSame(['<b>x</b>'], array_column($ctrl->view->byType, 'type'));
        $this->assertSame('behind', $ctrl->view->byType[0]['health'], 'a pending task with nothing completing is not keeping up');
    }

    /**
     * The type filter under the Type column narrows the rows to that type — the filter is the
     * reason the column is searchable, and its dropdown offers the types the table carries.
     */
    public function testTheTypeFilterNarrowsTheList(): void
    {
        // Arrange — the wrapper's column, which the table needs to have
        $this->db->query('ALTER TABLE ' . ($this->db->type === 'postgresql' ? '"queueitems"' : '`queueitems`') . ' ADD note VARCHAR(50)');
        foreach (['mail', 'mail', 'report'] as $type) {
            $this->db->queryBuilder()->table('queueitems')->insert(['type' => $type, 'payload' => '{}', 'status' => 'pending']);
        }
        $ctrl  = new WrappedQueueController($this->app);
        $_POST = ['sEcho' => 1, 'iDisplayStart' => 0, 'iDisplayLength' => 10, 'sSearch_1' => 'report', 'bSearchable_1' => 'true'];
        \Pramnos\Http\Request::resetInstance();

        try {
            // Act
            $ctrl->display();
            $data = json_decode($ctrl->data()->getBody(), true);
        } finally {
            $_POST = [];
            \Pramnos\Http\Request::resetInstance();
        }

        // Assert
        $rows = $data['aaData'] ?? $data['data'];
        $this->assertSame(['report'], array_column($rows, 1), 'the filter did not narrow the rows to the type');
        $this->assertStringContainsString('value="mail"', $ctrl->view->datatable->render(), 'the dropdown does not offer a type the table carries');
    }

    /**
     * show($id) gives the detail page every field the task has a value for, its payload key by
     * key with a JSON string inside it decoded, and the actions its status allows; an id with no
     * task goes back to the list with an error instead of an empty page.
     */
    public function testShowListsATasksFieldsAndPayload(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('queueitems')->insert([
            'type' => 'report', 'status' => 'failed', 'error' => 'boom', 'lockedby' => 'host:1',
            'payload' => json_encode(['to' => 'a@example.com', 'inner' => '{"n":7}', 'list' => [1, 2]]),
        ]);
        $id   = (int) $this->db->queryBuilder()->table('queueitems')->select(['taskid'])->first()->fields['taskid'];
        $ctrl = new WrappedQueueController($this->app);

        // Act
        $_GET['_option'] = $id;
        $page            = $ctrl->show($id);
        $view            = $ctrl->view;
        $_GET['_option'] = $id + 1000;
        $missing         = $ctrl->show($id + 1000);
        unset($_GET['_option']);

        // Assert
        $this->assertSame('rendered', $page);
        $this->assertSame('show', $view->template);
        $this->assertSame('report', $view->fields['Type']);
        $this->assertSame('boom', $view->fields['Error']);
        $this->assertSame('host:1', $view->fields['Locked by']);
        $this->assertArrayNotHasKey('Started', $view->fields, 'a field with no value is listed empty');
        $this->assertSame('a@example.com', $view->payload['to']);
        $this->assertSame(json_encode(['n' => 7], JSON_PRETTY_PRINT), $view->payload['inner'], 'a JSON string in the payload was not decoded');
        $this->assertSame(json_encode([1, 2], JSON_PRETTY_PRINT), $view->payload['list']);
        $this->assertStringContainsString('queue/retry/', $view->actions, 'a failed task is not offered a retry');
        $this->assertNull($missing, 'an id with no task rendered a page');
    }

    /**
     * history() reads the hourly roll-up the cleanup keeps — the statistics page answers from
     * `queuestats`, not from the live rows a purge has already removed.
     */
    public function testHistoryShowsTheHourlyRollUp(): void
    {
        // Arrange
        $stats = $this->db->queryBuilder()->table('queuestats');
        $stats->where('type', 'qc_history')->delete();
        $this->db->queryBuilder()->table('queuestats')->insert([
            'type' => 'qc_history', 'bucket' => date('Y-m-d H:00:00'), 'tasks' => 4, 'failed' => 1,
            'sum_exec' => 2, 'max_exec' => 1, 'sum_wait' => 8, 'max_wait' => 3,
        ]);
        $ctrl = new WrappedQueueController($this->app);

        try {
            // Act
            $page = $ctrl->history();
        } finally {
            $this->db->queryBuilder()->table('queuestats')->where('type', 'qc_history')->delete();
        }

        // Assert
        $this->assertSame('rendered', $page);
        $this->assertSame('history', $ctrl->view->template);
        $this->assertSame(48, $ctrl->view->hours);
        $row = array_values(array_filter($ctrl->view->history, static fn (array $r): bool => $r['type'] === 'qc_history'))[0] ?? null;
        $this->assertNotNull($row, 'the roll-up row is not on the page');
        $this->assertSame([4, 1, 0.5, 2.0], [$row['tasks'], $row['failed'], $row['exec_mean'], $row['wait_mean']]);
    }

    /**
     * The three themes' queue pages render what the controller hands them — the counts, the
     * per-type table, the fields, the payload and the hourly roll-up — escaped, and none of them
     * links to the raw JSON of `stats()`, which is for a monitor, not a person.
     */
    public function testEveryThemeRendersTheQueuePages(): void
    {
        // Arrange
        $root   = ROOT . '/scaffolding/themes';
        $themes = glob($root . '/*/views/queue', GLOB_ONLYDIR) ?: [];
        $this->assertNotEmpty($themes);
        $view = static fn (array $vars): object => new #[\AllowDynamicProperties] class ($vars) {
            /** @param array<string, mixed> $vars What the controller hands the view */
            public function __construct(array $vars)
            {
                foreach ($vars as $name => $value) {
                    $this->{$name} = $value;
                }
            }
        };
        $list = $view([
            'datatable'  => new \Pramnos\Html\Datatable('dt-queue'),
            'statuses'   => ['pending' => 'Pending', 'completed' => 'Completed', 'failed' => 'Failed'],
            'counts'     => ['pending' => 3, 'completed' => 6, 'total' => 9],
            'byType'     => [['type' => '<i>mail</i>', 'running' => 1, 'pending' => 3, 'per_minute' => 0.5, 'net_per_minute' => 1.5, 'clears_in' => null, 'health' => 'behind']],
            'rateWindow' => 600,
        ]);
        $show = $view([
            'task' => ['taskid' => 7], 'fields' => ['Error' => '<b>boom</b>'], 'payload' => ['to' => '<x>'],
            'status' => '<span class="s">failed</span>', 'actions' => '',
        ]);
        $history = $view(['hours' => 48, 'history' => [[
            'bucket' => '2026-01-01 10:00:00', 'type' => '<i>mail</i>', 'tasks' => 4, 'failed' => 1, 'retried' => 0,
            'exec_mean' => 0.5, 'exec_max' => 1.0, 'cpu_mean' => null, 'wait_mean' => 2.0, 'wait_max' => 3.0,
        ]]]);
        $render = static function (object $view, string $file): string {
            ob_start();
            (function () use ($file): void {
                include $file;
            })->call($view);

            return (string) ob_get_clean();
        };

        foreach ($themes as $dir) {
            // Act
            $pages = [
                'queue'   => $render($list, $dir . '/queue.html.php'),
                'show'    => $render($show, $dir . '/show.html.php'),
                'history' => $render($history, $dir . '/history.html.php'),
            ];

            // Assert
            $theme = basename(dirname($dir, 2));
            $this->assertStringContainsString('data-field="counts"', $pages['queue'], $theme);
            $this->assertStringContainsString('&lt;i&gt;mail&lt;/i&gt;', $pages['queue'], $theme . ': a type in the per-type table was not escaped');
            $this->assertStringContainsString('66.7%', $pages['queue'], $theme . ': the completed share is missing');
            $this->assertStringContainsString('Queue/history', $pages['queue'], $theme . ': the statistics page is not linked');
            $this->assertStringNotContainsString('Queue/stats', implode('', $pages), $theme . ': a page links to the raw JSON');
            $this->assertStringContainsString('&lt;b&gt;boom&lt;/b&gt;', $pages['show'], $theme);
            $this->assertStringContainsString('&lt;x&gt;', $pages['show'], $theme . ': the payload was not escaped');
            $this->assertStringContainsString('data-field="history"', $pages['history'], $theme);
            $this->assertStringContainsString('&lt;i&gt;mail&lt;/i&gt;', $pages['history'], $theme);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    protected function runQueueMigration(): void
    {
        $dir = ROOT . \DS . 'database' . \DS . 'migrations' . \DS . 'framework' . \DS . 'queue';
        $migrations = MigrationLoader::loadFromDirectory($dir, $this->makeAppForMigration());
        foreach ($migrations as $m) {
            $m->up();
        }
    }

    private function makeAppForMigration(): Application
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    protected function dropQueueTable(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `queueitems`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
