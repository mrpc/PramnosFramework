<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Concerns\DatatableExportActions;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Exportable;
use Pramnos\Http\RequestIdentity;
use Pramnos\Queue\Tasks\DatatableExportTask;

/**
 * Integration tests for Pramnos\Application\Concerns\DatatableExportActions.
 *
 * Every action here either writes/reads a real `queueitems` row or decides
 * access from one, so this is an integration test (real MySQL, the framework's
 * own queue migration) rather than a mocked unit test — the allow-list and
 * ownership checks are the one thing standing between a signed-in user and
 * queueing or reading an arbitrary export, and a mock of the DB row they read
 * would not prove the check actually reads the row.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class DatatableExportActionsMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;

    protected function setUp(): void
    {
        // Arrange
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $this->app->database = $this->db;

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `queueitems`');
        $this->db->query('DROP TABLE IF EXISTS `queuestats`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $migrations = MigrationLoader::loadFromDirectory(
            dirname(__DIR__, 3) . '/database/migrations/framework/queue',
            $this->app
        );
        foreach ($migrations as $migration) {
            $migration->up();
        }
        require_once dirname(__DIR__, 3) . '/database/migrations/framework/core/2026_09_23_000001_create_locks_table.php';
        (new \Pramnos\Framework\Migrations\Core\CreateLocksTable($this->app))->up();

        \Pramnos\Queue\QueueManager::forgetSchemaCache();
        \Pramnos\Application\Model::$columnCache = [];
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `queueitems`');
        $this->db->query('DROP TABLE IF EXISTS `queuestats`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        RequestIdentity::reset();
        $_POST = [];
        $_GET  = [];
    }

    private function makeController(): Controller
    {
        $controller = new class ($this->app) extends Controller {
            use DatatableExportActions;

            protected function exportableModels(): array
            {
                return [Fixtures\ExportActionsFakeModel::class];
            }
        };

        return $controller;
    }

    private function signIn(int $userid, int $usertype = 10, string $email = ''): void
    {
        RequestIdentity::seal((object) ['userid' => $userid, 'usertype' => $usertype, 'email' => $email], 'session');
    }

    private function captureJson(callable $action): array
    {
        ob_start();
        $action();
        $body = ob_get_clean();

        return (array) json_decode((string) $body, true);
    }

    // ── queueexport() ────────────────────────────────────────────────────────

    /** Nobody signed in: no task is queued, and the response says so. */
    public function testQueueexportRefusesWhenSignedOut(): void
    {
        // Arrange
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->queueexport());

        // Assert: refused before anything touched the queue table.
        $this->assertSame(401, http_response_code());
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM `queueitems`')->fields['c']);
    }

    /** A model not on the controller's allow-list must not be queued. */
    public function testQueueexportRefusesAModelNotOnTheAllowList(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = $this->makeController();
        $_POST = ['class' => \stdClass::class, 'columns' => [['name' => 'id', 'label' => 'Id']]];

        // Act
        $body = $this->captureJson(fn () => $controller->queueexport());

        // Assert
        $this->assertSame(403, http_response_code());
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM `queueitems`')->fields['c']);
    }

    /**
     * `format` is deliberately only ever `'csv'` today — a future Excel/other
     * format is additive ground, not a redesign of the modal or the task — so
     * anything else is refused before it ever reaches the queue.
     */
    public function testQueueexportRefusesAnUnsupportedFormat(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = $this->makeController();
        $_POST = [
            'class'   => Fixtures\ExportActionsFakeModel::class,
            'columns' => [['name' => 'id', 'label' => 'Id']],
            'format'  => 'xlsx',
        ];

        // Act
        $body = $this->captureJson(fn () => $controller->queueexport());

        // Assert
        $this->assertSame(422, http_response_code());
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM `queueitems`')->fields['c']);
    }

    /** A `scope` outside {'all','page'} is refused before it ever reaches the queue. */
    public function testQueueexportRefusesAnUnsupportedScope(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = $this->makeController();
        $_POST = [
            'class'   => Fixtures\ExportActionsFakeModel::class,
            'columns' => [['name' => 'id', 'label' => 'Id']],
            'scope'   => 'everything-ever',
        ];

        // Act
        $body = $this->captureJson(fn () => $controller->queueexport());

        // Assert
        $this->assertSame(422, http_response_code());
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM `queueitems`')->fields['c']);
    }

    /**
     * The modal's extra fields (`scope`, `notify_email`, `exportBase`) land in the
     * queued task's own payload — `DatatableExportTask` and its email notice both
     * read them from there, not from the request a worker will never see again.
     */
    public function testQueueexportStoresScopeNotifyAndExportBaseInThePayload(): void
    {
        // Arrange
        $this->signIn(7, email: 'owner@example.com');
        $controller = $this->makeController();
        $_POST = [
            'class'        => Fixtures\ExportActionsFakeModel::class,
            'columns'      => [['name' => 'id', 'label' => 'Id']],
            'scope'        => 'page',
            'notify_email' => '1',
            'exportBase'   => 'users',
        ];

        // Act
        $body = $this->captureJson(fn () => $controller->queueexport());

        // Assert
        $row     = $this->db->queryBuilder()->table('queueitems')->where('taskid', (int) $body['taskid'])->first();
        $payload = (array) json_decode((string) $row->fields['payload'], true);
        $this->assertSame('csv', $payload['format']);
        $this->assertSame('page', $payload['scope']);
        // notify_to is captured now, from the signed-in user, not reloaded later
        $this->assertSame('owner@example.com', $payload['notify_to']);
        $this->assertTrue($payload['notify_email']);
        $this->assertSame('users', $payload['exportBase']);
    }

    /** No exportable columns posted at all: nothing to queue. */
    public function testQueueexportRefusesWhenNoColumnsPosted(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = $this->makeController();
        $_POST = ['class' => Fixtures\ExportActionsFakeModel::class, 'columns' => []];

        // Act
        $body = $this->captureJson(fn () => $controller->queueexport());

        // Assert
        $this->assertSame(422, http_response_code());
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM `queueitems`')->fields['c']);
    }

    /** A valid request queues a real DatatableExportTask row the requester owns. */
    public function testQueueexportQueuesATaskOwnedByTheRequester(): void
    {
        // Arrange
        $this->signIn(7);
        $controller = $this->makeController();
        $_POST = [
            'class'   => Fixtures\ExportActionsFakeModel::class,
            'columns' => [['name' => 'id', 'label' => 'Id'], ['name' => 'name', 'label' => 'Name']],
            'filters' => ['search' => 'x'],
        ];

        // Act
        $body = $this->captureJson(fn () => $controller->queueexport());

        // Assert: a taskid came back, and the row behind it is this task type,
        // owned by the signed-in user, with the posted columns/filters intact.
        $this->assertArrayHasKey('taskid', $body);
        $row = $this->db->queryBuilder()->table('queueitems')->where('taskid', (int) $body['taskid'])->first();
        $this->assertSame(DatatableExportTask::TASK, (string) $row->fields['type']);
        $payload = (array) json_decode((string) $row->fields['payload'], true);
        $this->assertSame(7, (int) $payload['requested_by']);
        $this->assertSame(['search' => 'x'], $payload['filters']);
    }

    // ── exportstatus() ───────────────────────────────────────────────────────

    /** The requester can poll their own export's status. */
    public function testExportstatusAnswersTheOwner(): void
    {
        // Arrange
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'processing', message: '');
        $this->signIn(7);
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->exportstatus($taskId));

        // Assert
        $this->assertSame('processing', $body['status']);
    }

    /** Nobody signed in may poll any export's status. */
    public function testExportstatusRefusesWhenSignedOut(): void
    {
        // Arrange
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'processing', message: '');
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->exportstatus($taskId));

        // Assert
        $this->assertSame(401, http_response_code());
        $this->assertArrayHasKey('error', $body);
    }

    /** A taskid that names no row (or not this task type) answers 404, not someone else's data. */
    public function testExportstatusRefusesAnUnknownTask(): void
    {
        // Arrange
        $this->signIn(7);
        $_GET['_option'] = '999999';
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->exportstatus(999999));

        // Assert
        $this->assertSame(404, http_response_code());
        $this->assertArrayHasKey('error', $body);
    }

    /** Someone who did not request it, and is not super+, may not poll it. */
    public function testExportstatusRefusesSomeoneElse(): void
    {
        // Arrange
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'processing', message: '');
        $this->signIn(8); // a different, non-super user
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        $this->captureJson(fn () => $controller->exportstatus($taskId));

        // Assert
        $this->assertSame(403, http_response_code());
    }

    /** A super+ user may poll an export they did not request. */
    public function testExportstatusAllowsASuperUser(): void
    {
        // Arrange: usertype 98 is the framework's default "super" floor (Roles::SUPER).
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'completed', message: 'var/exports/x.csv');
        $this->signIn(99, 98);
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->exportstatus($taskId));

        // Assert
        $this->assertSame('completed', $body['status']);
    }

    // ── myExports() ──────────────────────────────────────────────────────────

    /**
     * `myExports()` answers only the signed-in user's own `datatable.export`
     * rows — another user's are left out, with no `super`+ bypass, unlike
     * {@see ownedExportItem()}'s single-item check. A list of everyone's exports
     * would be the Queue admin screen with an access check removed.
     */
    public function testMyExportsListsOnlyTheSignedInUsersOwnTasks(): void
    {
        // Arrange — one task each for two different requesters
        $mine   = $this->insertExportItem(requestedBy: 7, status: 'completed', message: '3 rows exported');
        $theirs = $this->insertExportItem(requestedBy: 8, status: 'completed', message: '9 rows exported');
        $this->signIn(7);
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->myExports());

        // Assert
        $taskIds = array_column($body['exports'], 'taskid');
        $this->assertContains($mine, $taskIds);
        $this->assertNotContains($theirs, $taskIds);
    }

    /** A `completed` export in the list carries a download URL; anything else does not. */
    public function testMyExportsOnlyLinksCompletedTasks(): void
    {
        // Arrange
        $done    = $this->insertExportItem(requestedBy: 7, status: 'completed', message: '1 rows exported');
        $pending = $this->insertExportItem(requestedBy: 7, status: 'processing', message: '');
        $this->signIn(7);
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->myExports());

        // Assert
        $byId = [];
        foreach ($body['exports'] as $export) {
            $byId[(int) $export['taskid']] = $export;
        }
        $this->assertNotNull($byId[$done]['download']);
        $this->assertNull($byId[$pending]['download']);
    }

    /** Nobody signed in gets nothing, same as every other action here. */
    public function testMyExportsRefusesWhenSignedOut(): void
    {
        // Arrange
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->myExports());

        // Assert
        $this->assertSame(401, http_response_code());
        $this->assertArrayHasKey('error', $body);
    }

    // ── exportdownload() ─────────────────────────────────────────────────────

    /** Nobody signed in may download anything, regardless of status. */
    public function testExportdownloadRefusesWhenSignedOut(): void
    {
        // Arrange
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'completed', message: '');
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        ob_start();
        $controller->exportdownload($taskId);
        ob_end_clean();

        // Assert
        $this->assertSame(401, http_response_code());
    }

    /** No route id at all (taskid <= 0) answers the same 404 as an unknown id. */
    public function testExportstatusRefusesWhenNoIdIsGiven(): void
    {
        // Arrange: no `_option` set at all -> staticGetOption() -> 0.
        $this->signIn(7);
        $controller = $this->makeController();

        // Act
        $body = $this->captureJson(fn () => $controller->exportstatus());

        // Assert
        $this->assertSame(404, http_response_code());
        $this->assertArrayHasKey('error', $body);
    }

    /** An export that has not finished yet refuses the download with 409. */
    public function testExportdownloadRefusesAnUnfinishedExport(): void
    {
        // Arrange
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'processing', message: '');
        $this->signIn(7);
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        ob_start();
        $controller->exportdownload($taskId);
        ob_end_clean();

        // Assert
        $this->assertSame(409, http_response_code());
    }

    /** Marked completed, but the file behind it is gone: 404, not a PHP warning. */
    public function testExportdownloadRefusesWhenTheFileIsGone(): void
    {
        // Arrange: "completed" with a success_message naming a file that was never written.
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'completed', message: 'var/exports/does-not-exist.csv');
        $this->signIn(7);
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        ob_start();
        $controller->exportdownload($taskId);
        ob_end_clean();

        // Assert
        $this->assertSame(404, http_response_code());
    }

    /**
     * A finished export the owner may see streams the file's own content.
     *
     * `success_message` deliberately holds the human-readable "N rows exported" —
     * not a path — the same shape `DatatableExportTask::execute()` actually
     * returns. exportdownload() must compute the file's path from the task id
     * itself (`DatatableExportTask::csvPath()`); reading it out of
     * success_message, as it used to, downloaded nothing but a 404 once that
     * message stopped being a path.
     */
    public function testExportdownloadStreamsTheFinishedFile(): void
    {
        // Arrange
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'completed', message: '12 rows exported');
        $path   = DatatableExportTask::csvPath($taskId);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, "Id,Name\n1,Alpha\n");
        $this->signIn(7);
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        ob_start();
        $controller->exportdownload($taskId);
        $output = ob_get_clean();

        // Assert
        $this->assertSame("Id,Name\n1,Alpha\n", $output);
        unlink($path);
    }

    /**
     * `format: 'excel'` in the task's own payload — not `success_message`,
     * which never carried a format — picks the `.xlsx` path and the
     * spreadsheet `Content-Type`/filename extension instead of CSV's.
     */
    public function testExportdownloadStreamsTheFinishedXlsx(): void
    {
        // Arrange
        $taskId = $this->insertExportItem(requestedBy: 7, status: 'completed', message: '1 row exported', format: 'excel');
        $path   = DatatableExportTask::filePath($taskId, 'excel');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, 'fake-xlsx-bytes');
        $this->signIn(7);
        $_GET['_option'] = (string) $taskId;
        $controller = $this->makeController();

        // Act
        ob_start();
        $controller->exportdownload($taskId);
        $output = ob_get_clean();

        // Assert — the .xlsx content streamed, not the (absent) .csv
        $this->assertSame('fake-xlsx-bytes', $output);
        unlink($path);
    }

    /**
     * Inserts a queueitems row directly (not through QueueManager::addTask(),
     * which would need a worker to move it through statuses) so each test can
     * start from whatever status it needs to check.
     */
    private function insertExportItem(int $requestedBy, string $status, string $message, string $format = 'csv'): int
    {
        $payload = json_encode([
            'requested_by' => $requestedBy,
            'class'        => Fixtures\ExportActionsFakeModel::class,
            'format'       => $format,
        ]);
        $this->db->queryBuilder()->table('queueitems')->insert([
            'type'            => DatatableExportTask::TASK,
            'payload'         => $payload,
            'status'          => $status,
            'priority'        => 10,
            'attempts'        => 0,
            'maxattempts'     => 3,
            'success_message' => $message,
            'createdat'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->getInsertId();
    }
}

namespace Pramnos\Tests\Integration\Queue\Fixtures;

use Pramnos\Html\Datatable\Exportable;

/** A model implementing Exportable, just so it exists on the allow-list. */
class ExportActionsFakeModel implements Exportable
{
    public function __construct(private $controller)
    {
    }

    public function exportBatch(array $filters, int $limit, int $offset): array
    {
        return [];
    }
}
