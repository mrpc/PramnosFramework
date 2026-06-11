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

/**
 * Integration tests for the QueueItem ORM model against live MySQL 8.0.
 *
 * QueueItem is the thin Model subclass behind the queueitems table; these
 * tests prove its CRUD wrappers (load/save/delete/getList/getData) actually
 * round-trip rows through the real table created by the framework migration,
 * and that getJsonList() produces the admin DataTable envelope with the
 * show/edit/delete action links.
 *
 * Isolation: setUp recreates the queueitems table via the framework
 * migration and tearDown drops it, so every test starts clean.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class QueueItemMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;
    protected Controller $controller;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // Load settings so the Database singleton points at the Docker MySQL —
        // Model::_save()/_load() resolve the connection via Factory internally.
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app     = $app;

        // Model's constructor requires a Controller; a constructor-less mock
        // carrying the application reference is all QueueItem needs.
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $ctrl */
        $ctrl = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ctrl->application = $this->app;
        $this->controller  = $ctrl;

        // Fresh queueitems table from the framework migration
        $this->dropQueueTable();
        $dir = dirname(__DIR__, 3) . '/database/migrations/framework/queue';
        foreach (MigrationLoader::loadFromDirectory($dir, $this->app) as $m) {
            $m->up();
        }
    }

    protected function tearDown(): void
    {
        $this->dropQueueTable();
    }

    // -------------------------------------------------------------------------
    // save() / load()
    // -------------------------------------------------------------------------

    /**
     * save() on a new item must INSERT a row and populate the auto-increment
     * primary key; load() on a fresh instance must read the same row back.
     * This is the core ORM round-trip the queue admin screens depend on.
     */
    public function testSaveInsertsRowAndLoadRoundTrips(): void
    {
        // Arrange
        $item = $this->makePendingItem('send_email', ['to' => 'a@b.com']);

        // Act — insert
        $item->save();

        // Assert — primary key assigned by the INSERT
        $this->assertGreaterThan(0, (int) $item->taskid, 'save() must populate taskid');

        // Act — read the row back with a brand-new instance
        $loaded = new QueueItem($this->controller);
        $loaded->load($item->taskid);

        // Assert — persisted fields round-trip intact
        $this->assertSame('send_email', $loaded->type);
        $this->assertSame('pending', $loaded->status);
        $this->assertSame(['to' => 'a@b.com'], json_decode((string) $loaded->payload, true));
        $this->assertSame(5, (int) $loaded->priority);
    }

    /**
     * save() on an already-loaded item must UPDATE the existing row in place
     * (same taskid, new column values) — not insert a duplicate.
     */
    public function testSaveUpdatesExistingRow(): void
    {
        // Arrange — persisted pending item
        $item = $this->makePendingItem('resize_image', []);
        $item->save();
        $id = (int) $item->taskid;

        // Act — transition the status and persist again
        $item->status   = 'completed';
        $item->attempts = 1;
        $item->save();

        // Assert — still exactly one row, with the updated status
        $count = $this->db->query('SELECT COUNT(*) AS c FROM queueitems');
        $this->assertSame(1, (int) $count->fields['c'], 'update must not insert a duplicate');

        $reloaded = (new QueueItem($this->controller))->load($id);
        $this->assertSame('completed', $reloaded->status);
        $this->assertSame(1, (int) $reloaded->attempts);
    }

    // -------------------------------------------------------------------------
    // getList() / getData()
    // -------------------------------------------------------------------------

    /**
     * getList() with a SQL filter must return only matching rows, hydrated
     * as QueueItem instances (not raw arrays) — the contract worker loops
     * rely on when scanning for pending tasks.
     */
    public function testGetListFiltersAndHydratesQueueItems(): void
    {
        // Arrange — one pending and one completed task
        $this->makePendingItem('a_task', [])->save();
        $done         = $this->makePendingItem('b_task', []);
        $done->status = 'completed';
        $done->save();

        // Act
        $pending = (new QueueItem($this->controller))->getList("`status` = 'pending'");

        // Assert — only the pending row, as a hydrated model
        $this->assertCount(1, $pending);
        $first = reset($pending);
        $this->assertInstanceOf(QueueItem::class, $first);
        $this->assertSame('a_task', $first->type);
    }

    /**
     * getData() must expose the model's column values as an associative
     * array — the serialization used by API responses and logging.
     */
    public function testGetDataReturnsColumnValueMap(): void
    {
        // Arrange
        $item = $this->makePendingItem('export_csv', ['x' => 1]);
        $item->save();

        // Act
        $data = $item->getData();

        // Assert — key columns present with the values we set
        $this->assertSame('export_csv', $data['type']);
        $this->assertSame('pending', $data['status']);
        $this->assertArrayHasKey('taskid', $data);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    /**
     * delete() must remove the row from the table — verified with a direct
     * COUNT query, not through the model, to prove the DELETE really ran.
     */
    public function testDeleteRemovesRow(): void
    {
        // Arrange
        $item = $this->makePendingItem('tmp_task', []);
        $item->save();
        $id = (int) $item->taskid;

        // Act
        $item->delete($id);

        // Assert
        $count = $this->db->query("SELECT COUNT(*) AS c FROM queueitems WHERE taskid = {$id}");
        $this->assertSame(0, (int) $count->fields['c']);
    }

    // -------------------------------------------------------------------------
    // getJsonList() — admin DataTable envelope
    // -------------------------------------------------------------------------

    /**
     * getJsonList() must return a DataTable-compatible JSON envelope where
     * every data cell is wrapped in a link to the item's show URL and a
     * trailing actions cell carries the Edit / Delete links. This covers the
     * three URL hooks (getItemShowUrl/getItemEditUrl/getItemDeleteUrl) with
     * their default sURL-based routes.
     */
    public function testGetJsonListWrapsRowsWithActionLinks(): void
    {
        // Arrange
        $item = $this->makePendingItem('notify_user', []);
        $item->save();
        $id = (int) $item->taskid;

        // Act
        $json    = (new QueueItem($this->controller))->getJsonList();
        $decoded = json_decode($json, true);

        // Assert — envelope decodes and contains our single row
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('aaData', $decoded);
        $this->assertCount(1, $decoded['aaData']);

        $row = $decoded['aaData'][0];
        // First cell stays the raw taskid (used as the row key)
        $this->assertEquals($id, $row[0]);
        // Data cells are wrapped in links to the default show route
        $this->assertStringContainsString("Queueitems/show/{$id}", $row[1]);
        // Trailing actions cell carries both Edit and Delete links
        $actions = end($row);
        $this->assertStringContainsString("Queueitems/edit/{$id}", $actions);
        $this->assertStringContainsString("Queueitems/delete/{$id}", $actions);
        $this->assertStringContainsString('data-confirm', $actions);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an unsaved pending QueueItem with all NOT NULL columns populated.
     */
    protected function makePendingItem(string $type, array $payload): QueueItem
    {
        $item              = new QueueItem($this->controller);
        $item->type        = $type;
        $item->payload     = (string) json_encode($payload);
        $item->status      = 'pending';
        $item->priority    = 5;
        $item->attempts    = 0;
        $item->maxattempts = 3;
        $item->createdat   = date('Y-m-d H:i:s');
        return $item;
    }

    protected function dropQueueTable(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `queueitems`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
