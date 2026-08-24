<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\OrmModel;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;
use Pramnos\Framework\Factory;

/**
 * A soft delete must announce a delete, not an update.
 *
 * `OrmModel::_delete()` performs its soft delete by writing `deleted_at` through
 * `parent::_save()`. Physically that is an UPDATE, and the base class would describe it
 * as one — so a subscriber would go on showing a row the application considers gone, and
 * a changelog would record the disappearance of a record as a routine field change.
 *
 * The symptom of getting this wrong is a stale row on somebody's screen, which reads as
 * a caching bug and sends the investigation to the wrong subsystem entirely. Hence a
 * test that names the operation rather than only counting emissions.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class OrmSoftDeleteChangeFeedTest extends TestCase
{
    private Database $db;
    private Controller $controller;

    /** @var list<ModelChange> */
    private array $received = [];

    private const TABLE = 'pramnos_softdelete_feed_probe';

    /** Whatever was the global Database singleton before this test replaced it. */
    private ?Database $previousSingleton = null;

    /** The connection and schema shared by every test in the class. */
    private static ?Database $sharedDb = null;

    /**
     * Connect once and build the schema once, for the whole class.
     *
     * Four tests that each dropped and recreated a table, and each opened its own
     * connection, for a schema none of them asserts anything about. The measurement that
     * settled this pattern in this repo is recorded on `OrmRelationsMySQLTest`: per-test
     * DDL was most of that class's time per test.
     */
    public static function setUpBeforeClass(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );
        Application::getInstance();

        $settings = Settings::getSetting('database');
        if (!$settings) {
            return;
        }

        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 3306;

        try {
            $db->connect(true);
        } catch (\Throwable) {
            return;
        }
        if (!$db->connected) {
            return;
        }

        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'label VARCHAR(100) NOT NULL, '
            . 'deleted_at DATETIME NULL'
            . ') ENGINE=InnoDB'
        );

        self::$sharedDb     = $db;
        Model::$columnCache = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedDb !== null) {
            self::$sharedDb->query('DROP TABLE IF EXISTS ' . self::TABLE);
            self::$sharedDb = null;
        }
        Model::$columnCache      = [];
        static::$sharedController = null;
    }

    protected function setUp(): void
    {
        if (self::$sharedDb === null) {
            $this->markTestSkipped('MySQL container not reachable');
        }

        $this->db = self::$sharedDb;

        // _save()/_delete() use the singleton, not the controller's connection. The
        // previous one is put back in tearDown, so nothing later in the process inherits
        // this connection — or, worse, an unconnected replacement for it.
        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        $this->controller = $this->sharedController();

        // Rows are what a test owns. The table belongs to the class.
        $this->db->query('DELETE FROM ' . self::TABLE);

        Event::forget();
        ChangeFeed::reset();
        $this->received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $change): void {
            $this->received[] = $change;
        });
    }

    protected function tearDown(): void
    {
        Event::forget();
        ChangeFeed::reset();

        $singleton = &Factory::getDatabase();
        $singleton = $this->previousSingleton;
    }

    /**
     * One controller for the whole class.
     *
     * PHPUnit builds a mock by reflecting the target and generating a class for it, and
     * `Application` is a large one. Nothing here mutates the controller, so building it
     * per test bought nothing and paid for it every time.
     *
     * @var Controller|null
     */
    private static ?Controller $sharedController = null;

    private function sharedController(): Controller
    {
        if (static::$sharedController === null) {
            static::$sharedController = $this->makeController();
        }

        return static::$sharedController;
    }

    private function makeController(): Controller
    {
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $ctrl */
        $ctrl = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database     = $this->db;
        $ctrl->application = $app;

        return $ctrl;
    }

    private function item(): SoftDeleteFeedItem
    {
        return new SoftDeleteFeedItem($this->controller);
    }

    /**
     * A saved row, loaded back, ready to be deleted.
     *
     * The load is not incidental. `OrmModel::_delete()` performs a soft delete through
     * `parent::_save()`, and an unloaded model is still `_isnew`, so that save **inserts
     * a second row** instead of marking the named one deleted. That is pre-existing
     * behaviour of the soft-delete path and not something this work changes — but a test
     * calling `_delete()` on a fresh instance exercises the insert rather than the
     * delete, and would sit there looking like it proved something.
     *
     * @return array{0: SoftDeleteFeedItem, 1: int}
     */
    private function savedAndLoaded(string $label = 'a label'): array
    {
        $item        = $this->item();
        $item->label = $label;
        $item->publicSave();
        $id = (int) $item->id;

        $loaded = $this->item();
        $loaded->publicLoad($id);

        $this->received = [];

        return [$loaded, $id];
    }

    /**
     * A soft delete emits exactly one event, and it says `deleted`.
     *
     * One event, not two: the silenced UPDATE must not also announce itself, or a
     * subscriber receives an update immediately followed by a delete for the same row
     * and has to guess which one is the truth.
     */
    public function testASoftDeleteAnnouncesADelete(): void
    {
        // Arrange
        [$item, $id] = $this->savedAndLoaded();

        // Act
        $item->publicDelete($id);

        // Assert
        $this->assertCount(1, $this->received);
        $this->assertSame(ModelChange::DELETED, $this->received[0]->op);
        $this->assertSame($id, (int) $this->received[0]->key);
    }

    /**
     * The row is still there — this really was a soft delete.
     *
     * Guards against the test passing for the wrong reason. If the suppression were ever
     * to swallow the write itself rather than only its announcement, the event would
     * still say `deleted` and the assertion above would still hold, while the model had
     * silently stopped persisting anything.
     */
    public function testTheRowSurvivesTheSoftDelete(): void
    {
        // Arrange
        [$item, $id] = $this->savedAndLoaded();

        // Act
        $item->publicDelete($id);

        // Assert — the row is still there, and marked
        $result = $this->db->query(
            'SELECT deleted_at FROM ' . self::TABLE . ' WHERE id = ' . $id
        );
        $this->assertNotEmpty(
            $result->fields['deleted_at'],
            'the soft delete must still have written deleted_at'
        );

        // And there is exactly one row: suppression must silence the announcement, never
        // redirect the write.
        $count = $this->db->query('SELECT COUNT(*) AS cnt FROM ' . self::TABLE);
        $this->assertSame(1, (int) $count->fields['cnt']);
    }

    /**
     * A hard delete on the same model still announces a delete.
     *
     * The suppression is scoped to the soft-delete branch; a model that turns soft
     * deletes off must behave exactly like any other. `withoutChangeEmission()` restores
     * the previous flag rather than clearing it, and this is what would catch a version
     * that left it set.
     */
    public function testAHardDeleteStillAnnouncesADelete(): void
    {
        // Arrange
        [$hard, $id] = $this->savedAndLoaded();
        $hard->hardDeletes();

        // Act
        $hard->publicDelete($id);

        // Assert
        $this->assertCount(1, $this->received);
        $this->assertSame(ModelChange::DELETED, $this->received[0]->op);
        $result = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM ' . self::TABLE . ' WHERE id = ' . $id
        );
        $this->assertSame(0, (int) $result->fields['cnt']);
    }

    /**
     * An ordinary save on a soft-delete model still announces an update.
     *
     * The other half of the scoping: silencing the soft delete's write must not silence
     * every write the model makes.
     */
    public function testAnOrdinarySaveStillAnnouncesAnUpdate(): void
    {
        // Arrange
        [$loaded, ] = $this->savedAndLoaded();

        // Act
        $loaded->label = 'a different label';
        $loaded->publicSave();

        // Assert
        $this->assertCount(1, $this->received);
        $this->assertSame(ModelChange::UPDATED, $this->received[0]->op);
    }
}

/**
 * A soft-deleting model that emits on the change feed.
 */
class SoftDeleteFeedItem extends OrmModel
{
    protected $_dbtable    = 'pramnos_softdelete_feed_probe';
    protected $_primaryKey = 'id';
    protected bool $softDelete = true;

    protected $emitChanges  = true;
    protected $changeEntity = 'softitem';

    /** @var int|null */
    public $id = null;
    /** @var string */
    public $label = '';
    /** @var string|null */
    public $deleted_at = null;

    public function hardDeletes(): void
    {
        $this->softDelete = false;
    }

    public function publicSave(): void
    {
        $this->_save();
    }

    public function publicLoad(mixed $pk): void
    {
        $this->_load($pk);
    }

    public function publicDelete(mixed $pk): void
    {
        $this->_delete($pk);
    }
}
