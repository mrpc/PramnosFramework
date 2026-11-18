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

    private function signIn(int $userid, int $usertype = 10): void
    {
        RequestIdentity::seal((object) ['userid' => $userid, 'usertype' => $usertype], 'session');
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
     * Inserts a queueitems row directly (not through QueueManager::addTask(),
     * which would need a worker to move it through statuses) so each test can
     * start from whatever status it needs to check.
     */
    private function insertExportItem(int $requestedBy, string $status, string $message): int
    {
        $payload = json_encode(['requested_by' => $requestedBy, 'class' => Fixtures\ExportActionsFakeModel::class]);
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
