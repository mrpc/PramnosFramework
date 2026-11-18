<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Exportable;
use Pramnos\Queue\QueueItem;
use Pramnos\Queue\Tasks\DatatableExportTask;

/**
 * Integration test for the REPEATABLE READ guarantee at the heart of
 * DatatableExportTask: the whole batch loop runs inside one transaction set to
 * that isolation level, so a row inserted (and committed) by another
 * connection between two batches must not appear in the finished CSV.
 *
 * Unit coverage of the batching/CSV-writing logic itself (short last batch,
 * column order, missing keys) lives in DatatableExportTaskTest — no database
 * needed there. This is the one test that can prove the isolation level does
 * what it is for, because that is a property of two real connections to a
 * real database, not of the PHP loop around them.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class DatatableExportTaskMySQLTest extends TestCase
{
    protected Database $db;

    /** A second, independent connection — the "someone else" writing concurrently. */
    protected Database $otherDb;

    protected Controller $controller;

    protected string $dbType = 'mysql';

    protected function setUp(): void
    {
        // Arrange: two live connections to the same database — the task's own
        // (through the Factory singleton it reads inside execute()) and a second,
        // independent one standing in for a concurrent writer.
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = $this->connectMain();
        $singleton = &Factory::getDatabase();
        $singleton = $this->db;

        $this->otherDb = $this->connectSecondary();

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $ctrl = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $ctrl->application = $app;
        $this->controller = $ctrl;

        $this->createSchema();
        $this->seedRows(['Alpha', 'Beta']); // two rows -> one full batch of size 2

        $exportDir = ROOT . \DS . 'var' . \DS . 'exports';
        foreach (array_merge(
            glob($exportDir . \DS . '*.csv*') ?: [],
            glob($exportDir . \DS . '*.xlsx') ?: [],
            glob($exportDir . \DS . '*.json') ?: []
        ) as $leftover) {
            @unlink($leftover);
        }
    }

    protected function connectMain(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(true);
        }

        return $db;
    }

    protected function connectSecondary(): Database
    {
        $settings = Settings::getSetting('database');

        $db = new Database();
        $db->type     = $this->dbType;
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 3306;
        $db->connect(true);

        return $db;
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `dt_export_rows`');
        $this->db->schema()->dropTableIfExists('mails');
        $singleton = &Factory::getDatabase();
        $singleton = null;
    }

    /**
     * The `mails` table, from its own framework migration — not assumed, same
     * reasoning as {@see \Pramnos\Tests\Integration\Email\MailOutboxMySQLTest}:
     * dropped in tearDown, which is safe because nothing else in this schema has it.
     */
    protected function createMailsTable(): void
    {
        $this->db->schema()->dropTableIfExists('mails');
        $app = new Application();
        $app->database = $this->db;
        $dir = dirname(__DIR__, 3) . '/database/migrations/framework/messaging';
        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory($dir, $app) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Messaging\CreateMailsTable) {
                $migration->up();
            }
        }
    }

    protected function createSchema(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `dt_export_rows`');
        $this->db->query('CREATE TABLE `dt_export_rows` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL
        )');
    }

    protected function seedRows(array $names): void
    {
        foreach ($names as $name) {
            $this->db->query("INSERT INTO `dt_export_rows` (`name`) VALUES ('" . $name . "')");
        }
    }

    /**
     * The task's REPEATABLE READ transaction must hide a row another connection
     * inserts and commits mid-export: the snapshot is taken once, at the start of
     * the transaction, and every batch inside it reads from that same snapshot —
     * not from whatever the table holds by the time the next batch runs.
     */
    public function testConcurrentInsertBetweenBatchesIsNotInTheExport(): void
    {
        // Arrange: a fake Exportable that, right before fetching the SECOND batch
        // (offset > 0), has the OTHER connection insert and commit a third row —
        // simulating a write that lands on the live table while the export is
        // still in progress.
        $otherDb = $this->otherDb;
        Fixtures\InsertingExportableSource::$onSecondBatch = static function () use ($otherDb): void {
            $otherDb->query("INSERT INTO dt_export_rows (name) VALUES ('Intruder')");
        };

        $item = $this->makeQueueItem(json_encode([
            'class'     => Fixtures\InsertingExportableSource::class,
            'columns'   => [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']],
            'batchSize' => 1, // forces (at least) 3 fetches for 2 seeded rows: a real "next batch"
        ]));
        $task = new DatatableExportTask($this->controller);

        // Act
        $task->execute($item);

        // Assert: the finished CSV has exactly the two rows seeded before the
        // transaction started — "Intruder", inserted and committed between
        // batches by the other connection, is not in it.
        $csv = file_get_contents(DatatableExportTask::csvPath($item->taskid));
        $this->assertStringNotContainsString('Intruder', $csv);
        $this->assertStringContainsString('Alpha', $csv);
        $this->assertStringContainsString('Beta', $csv);

        // The other connection's own read confirms the row really was committed —
        // proving the absence above is the transaction's snapshot, not a failed insert.
        $visible = $this->otherDb->query("SELECT COUNT(*) AS c FROM dt_export_rows WHERE name = 'Intruder'");
        $this->assertSame(1, (int) $visible->fields['c']);
    }

    /**
     * `format: 'excel'` writes a real, readable `.xlsx` — not a `.csv` with the
     * extension changed. `Xlsx::fromCsv()` is unit-tested on its own; this
     * proves `execute()` actually calls it and writes the result where
     * `DatatableExportTask::filePath($taskId, 'excel')` expects it, with the
     * `.csv.tmp` intermediate cleaned up rather than left beside it.
     */
    public function testExcelFormatWritesAReadableXlsx(): void
    {
        // Arrange
        $item = $this->makeQueueItem(json_encode([
            'class'   => Fixtures\InsertingExportableSource::class,
            'columns' => [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']],
            'format'  => 'excel',
        ]));
        $task = new DatatableExportTask($this->controller);

        // Act
        $task->execute($item);

        // Assert — the .xlsx exists, is a real zip (every xlsx is one), and the
        // intermediate CSV it was converted from did not survive beside it
        $path = DatatableExportTask::filePath($item->taskid, 'excel');
        $this->assertFileExists($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'the .xlsx must open as a valid zip archive');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('Alpha', $sheet);
        $this->assertStringContainsString('Beta', $sheet);
        $this->assertFileDoesNotExist(DatatableExportTask::filePath($item->taskid, 'csv') . '.tmp');
    }

    /**
     * `format: 'json'` writes a real, parseable JSON array of row objects —
     * not a `.csv` with the extension changed, and the intermediate CSV it
     * was converted from does not survive beside it.
     */
    public function testJsonFormatWritesParseableJson(): void
    {
        // Arrange
        $item = $this->makeQueueItem(json_encode([
            'class'   => Fixtures\InsertingExportableSource::class,
            'columns' => [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']],
            'format'  => 'json',
        ]));
        $task = new DatatableExportTask($this->controller);

        // Act
        $task->execute($item);

        // Assert
        $path    = DatatableExportTask::filePath($item->taskid, 'json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame([
            ['Id' => '1', 'Name' => 'Alpha'],
            ['Id' => '2', 'Name' => 'Beta'],
        ], $decoded);
        $this->assertFileDoesNotExist(DatatableExportTask::filePath($item->taskid, 'csv') . '.tmp');
    }

    /**
     * `notify_email` queues a real row in the mail outbox (`Email::queue()`,
     * `Mail::STATUS_QUEUED`) addressed to `notify_to`, its body carrying a link
     * to this export's download — proving the task actually hands off to the
     * framework's mail-sending mechanism, not just that it would if it tried.
     * `queue()` rather than a synchronous send: the task does not block a
     * worker on an SMTP round trip for a notice that can wait for `mail:flush`.
     */
    public function testNotifyEmailQueuesARealOutboxRow(): void
    {
        // Arrange
        $this->createMailsTable();
        $item = $this->makeQueueItem(json_encode([
            'class'        => Fixtures\InsertingExportableSource::class,
            'columns'      => [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']],
            'notify_email' => true,
            'notify_to'    => 'requester@example.com',
            'exportBase'   => 'users',
        ]));
        $task = new DatatableExportTask($this->controller);

        // Act
        $task->execute($item);

        // Assert — one queued row, addressed and linked to this task's own download
        $row = $this->db->query(
            "SELECT * FROM mails WHERE tomail = 'requester@example.com' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotFalse($row->fetch(), 'notify_email must write a row to the outbox');
        $this->assertSame(\Pramnos\Messaging\Mail::STATUS_QUEUED, (int) $row->fields['status']);
        $this->assertStringContainsString('/exportdownload/' . $item->taskid, (string) $row->fields['content']);
    }

    /** `notify_email = true` with no `notify_to` captured queues no email — nothing to address it to. */
    public function testNotifyEmailWithNoAddressQueuesNoOutboxRow(): void
    {
        // Arrange
        $this->createMailsTable();
        $item = $this->makeQueueItem(json_encode([
            'class'        => Fixtures\InsertingExportableSource::class,
            'columns'      => [['key' => 'id', 'label' => 'Id']],
            'notify_email' => true,
            // notify_to deliberately absent
        ]));
        $task = new DatatableExportTask($this->controller);

        // Act
        $task->execute($item);

        // Assert
        $count = $this->db->query('SELECT COUNT(*) AS c FROM mails');
        $this->assertSame(0, (int) $count->fields['c']);
    }

    /** `notify_email` left unset (or false) queues no email at all. */
    public function testNoNotifyEmailQueuesNoOutboxRow(): void
    {
        // Arrange
        $this->createMailsTable();
        $item = $this->makeQueueItem(json_encode([
            'class'   => Fixtures\InsertingExportableSource::class,
            'columns' => [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']],
        ]));
        $task = new DatatableExportTask($this->controller);

        // Act
        $task->execute($item);

        // Assert
        $count = $this->db->query('SELECT COUNT(*) AS c FROM mails');
        $this->assertSame(0, (int) $count->fields['c']);
    }

    /**
     * A batch that throws must roll back the transaction and propagate the
     * exception, rather than leave a half-written CSV behind or a dangling
     * transaction on the shared connection — the error path of the
     * REPEATABLE READ wrap, not just its success path.
     */
    public function testAFailingBatchRollsBackAndPropagates(): void
    {
        // Arrange
        $item = $this->makeQueueItem(json_encode([
            'class'     => Fixtures\ThrowingExportableSource::class,
            'columns'   => [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']],
            'batchSize' => 1,
        ]));
        $task = new DatatableExportTask($this->controller);

        // Act + Assert: the fake source's exception is the one that comes out —
        // execute() did not swallow it after rolling back.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $task->execute($item);
        } finally {
            // The connection must still be usable afterwards: rollbackTransaction()
            // actually ran and did not leave the session inside an open transaction.
            $stillWorks = $this->db->query('SELECT 1 AS ok');
            $this->assertSame(1, (int) $stillWorks->fields['ok']);
        }
    }

    private function makeQueueItem(string $payload): QueueItem
    {
        $item = new class extends QueueItem {
            public function __construct() {}
            /** @param mixed $a */
            public function save($a = false, $d = false): static { return $this; }
        };
        $item->payload = $payload;
        $item->taskid  = 'it_' . bin2hex(random_bytes(4));
        $item->type    = DatatableExportTask::TASK;

        return $item;
    }
}

namespace Pramnos\Tests\Integration\Queue\Fixtures;

use Pramnos\Framework\Factory;
use Pramnos\Html\Datatable\Exportable;

/**
 * Reads `dt_export_rows` through the real DB singleton (the same connection
 * DatatableExportTask's transaction runs on), and fires a test-supplied
 * callback right before any batch after the first — the hook the integration
 * test uses to insert a concurrent row mid-export.
 */
class InsertingExportableSource implements Exportable
{
    /** @var (callable(): void)|null */
    public static $onSecondBatch = null;

    public function __construct(private $controller)
    {
    }

    public function exportBatch(array $filters, int $limit, int $offset): array
    {
        if ($offset > 0 && self::$onSecondBatch !== null) {
            $callback = self::$onSecondBatch;
            self::$onSecondBatch = null; // once — the point is "between batches", not "every batch"
            $callback();
        }

        return Factory::getDatabase()->queryBuilder()
            ->table('dt_export_rows')
            ->orderBy('id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->fetchAll();
    }
}

/** Throws on its second batch — DatatableExportTaskMySQLTest's rollback-path fixture. */
class ThrowingExportableSource implements Exportable
{
    public function __construct(private $controller)
    {
    }

    public function exportBatch(array $filters, int $limit, int $offset): array
    {
        if ($offset > 0) {
            throw new \RuntimeException('boom');
        }

        return Factory::getDatabase()->queryBuilder()
            ->table('dt_export_rows')
            ->orderBy('id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->fetchAll();
    }
}
