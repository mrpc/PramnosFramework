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
        foreach (glob($exportDir . \DS . '*.csv*') ?: [] as $leftover) {
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
        $singleton = &Factory::getDatabase();
        $singleton = null;
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
