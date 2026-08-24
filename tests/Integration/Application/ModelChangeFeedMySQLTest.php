<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;
use Pramnos\Framework\Factory;

/**
 * Integration tests for the change feed driven by real Model saves and deletes.
 *
 * The unit suite covers the decisions made around the write. This covers the write
 * itself: that an emission happens where the row actually changed, and — the case the
 * whole design turns on — that it does **not** happen where the row did not.
 *
 * Every assertion here needs a real database. `_save()` introspects the table's columns
 * to build its field list, decides insert-versus-update from state the previous statement
 * left behind, and returns early on paths that only exist because a statement failed.
 * None of that is reproducible against a double, and each of those paths is one where a
 * feed could announce a change that never landed.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class ModelChangeFeedMySQLTest extends TestCase
{
    protected Database $db;
    protected Controller $controller;

    /** @var list<ModelChange> Everything the feed delivered during the current test. */
    protected array $received = [];

    protected static string $table = 'pramnos_changefeed_probe';

    /**
     * Whatever was the global Database singleton before this test replaced it.
     *
     * Restored verbatim in tearDown. An earlier version set it to null instead, on the
     * theory that the factory would rebuild a correct one — it rebuilds an *unconnected*
     * one, and the next test in the process to reach for the singleton got a connection
     * attempt to a local socket that does not exist. The full suite passed anyway,
     * because its ordering happened to put nothing after these; a narrower filter did
     * not. Leaving the world as found is the version that does not depend on luck.
     */
    protected ?Database $previousSingleton = null;

    /** The connection and schema shared by every test in the class. */
    protected static ?Database $sharedDb = null;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Connect once and build the schema once, for the whole class.
     *
     * Not micro-optimisation. `OrmRelationsMySQLTest` records the measurement that
     * settled this pattern in this repo: per-test DROP and CREATE were "most of this
     * class's 352 ms per test", and not one test here asserts anything about the schema.
     * A connection handshake per test is the same waste again. Rows are what a test owns,
     * so rows are what setUp() clears.
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

        static::$sharedDb = static::openConnection();
        if (static::$sharedDb !== null) {
            static::createTableOn(static::$sharedDb);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$sharedDb !== null) {
            static::$sharedDb->query('DROP TABLE IF EXISTS ' . static::$table);
            static::$sharedDb = null;
        }
        Model::$columnCache      = [];
        static::$sharedController = null;
    }

    protected function setUp(): void
    {
        if (static::$sharedDb === null) {
            $this->markTestSkipped('Database container not reachable');
        }

        $this->db = static::$sharedDb;

        // _save()/_delete() reach for Database::getInstance() rather than the connection
        // on their controller, so the singleton has to be this one. The previous value is
        // put back in tearDown: an earlier version nulled it instead, and the factory
        // rebuilds an *unconnected* replacement, which the next test in the process then
        // tries to query.
        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        $this->controller = $this->sharedController();

        // Rows are what a test owns. The table belongs to the class.
        $this->db->query('DELETE FROM ' . static::$table);

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

        if ($this->db->inTransaction()) {
            $this->db->rollbackTransaction();
        }

        $singleton = &Factory::getDatabase();
        $singleton = $this->previousSingleton;
    }

    /**
     * Open the connection under test. PostgreSQL overrides this and the DDL, nothing else.
     *
     * Built explicitly from settings rather than taken from `Factory::getDatabase()`,
     * because other integration classes restore the singleton by nulling it — and a
     * rebuilt-but-unconfigured singleton connects to a local socket that is not there.
     * Returns null when the container is unreachable, so setUp() can skip rather than
     * every test erroring identically.
     */
    protected static function openConnection(): ?Database
    {
        $settings = Settings::getSetting('database');
        if (!$settings) {
            return null;
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
            return null;
        }

        return $db->connected ? $db : null;
    }

    protected static function createTableOn(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS ' . static::$table);
        $db->query(
            'CREATE TABLE ' . static::$table . ' ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'status VARCHAR(50) NOT NULL, '
            . 'label VARCHAR(100) NOT NULL, '
            . 'viewcache TEXT NOT NULL'
            . ') ENGINE=InnoDB'
        );

        // Keyed by table name and shared across the process, so a stale entry would
        // describe a table this method just replaced.
        Model::$columnCache = [];
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
    protected static ?Controller $sharedController = null;

    protected function sharedController(): Controller
    {
        if (static::$sharedController === null) {
            static::$sharedController = $this->makeController();
        }

        return static::$sharedController;
    }

    protected function makeController(): Controller
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

    /**
     * A model bound to the scratch table, emitting or silent as the test needs.
     *
     * @param array<string, mixed> $config
     */
    protected function model(array $config = []): Model
    {
        $model = new class ($this->controller, 'probe') extends Model {
            /** @var string */
            protected $_primaryKey = 'id';
            /** @var int|null */
            public $id = null;
            /** @var string */
            public $status = 'new';
            /** @var string */
            public $label = 'a label';
            /** @var string */
            public $viewcache = '';

            /** @param array<string, mixed> $config */
            public function configure(array $config): void
            {
                foreach ($config as $key => $value) {
                    $this->$key = $value;
                }
            }

            public function save(): static
            {
                $this->_save();

                return $this;
            }

            public function remove(mixed $id): static
            {
                $this->_delete($id);

                return $this;
            }

            public function load(mixed $id): static
            {
                $this->_load($id);

                return $this;
            }
        };

        $model->configure(
            $config + ['_dbtable' => static::$table, 'emitChanges' => true]
        );

        return $model;
    }

    protected function rowCount(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS cnt FROM ' . static::$table);

        return (int) ($result->fields['cnt'] ?? -1);
    }

    // -------------------------------------------------------------------------
    // The write happened, and was announced
    // -------------------------------------------------------------------------

    /**
     * Inserting a row emits `created`, carrying the key the database assigned.
     *
     * The key is the part worth an integration test: it does not exist until the INSERT
     * returns, so an emission placed anywhere earlier in `_save()` would announce a
     * change to a row nobody can address.
     */
    public function testInsertEmitsCreatedWithTheGeneratedKey(): void
    {
        // Arrange
        $model = $this->model();

        // Act
        $model->save();

        // Assert
        $this->assertCount(1, $this->received);
        $this->assertSame(ModelChange::CREATED, $this->received[0]->op);
        $this->assertNotNull($this->received[0]->key);
        $this->assertSame((int) $model->id, (int) $this->received[0]->key);
        $this->assertSame(1, $this->rowCount());
    }

    /**
     * Updating a row emits `updated` with the real before-and-after values.
     *
     * The diff comes from `_initialData`, which is only populated by an actual load or a
     * previous save — so this is the assertion that the feed reports what the database
     * had, rather than what the object was constructed with.
     */
    public function testUpdateEmitsTheRealDiff(): void
    {
        // Arrange
        $model = $this->model();
        $model->save();
        $id = $model->id;
        $this->received = [];

        $loaded = $this->model();
        $loaded->load($id);

        // Act
        $loaded->status = 'active';
        $loaded->save();

        // Assert
        $this->assertCount(1, $this->received);
        $change = $this->received[0];
        $this->assertSame(ModelChange::UPDATED, $change->op);
        $this->assertSame(
            ['old' => 'new', 'new' => 'active'],
            $change->changes['status']
        );
    }

    /**
     * Deleting a row emits `deleted` with the key that was passed in.
     */
    public function testDeleteEmitsDeleted(): void
    {
        // Arrange
        $model = $this->model();
        $model->save();
        $id = $model->id;
        $this->received = [];

        // Act
        $this->model()->remove($id);

        // Assert
        $this->assertCount(1, $this->received);
        $this->assertSame(ModelChange::DELETED, $this->received[0]->op);
        $this->assertSame($id, (int) $this->received[0]->key);
        $this->assertSame(0, $this->rowCount());
    }

    // -------------------------------------------------------------------------
    // The write did not happen, and was not announced
    // -------------------------------------------------------------------------

    /**
     * A save with nothing to change writes nothing and announces nothing.
     *
     * `_save()` returns early when the diff is empty. Emitting there would put a stream
     * of identical "updated" events on the wire for every request that re-saves an
     * unmodified model — which application code does constantly, and which no subscriber
     * could distinguish from a real change.
     */
    public function testASaveWithNoChangesAnnouncesNothing(): void
    {
        // Arrange
        $model = $this->model();
        $model->save();
        $id = $model->id;

        $loaded = $this->model();
        $loaded->load($id);
        $this->received = [];

        // Act — saved again, untouched
        $loaded->save();

        // Assert
        $this->assertSame([], $this->received);
    }

    /**
     * **A rolled-back transaction announces nothing.**
     *
     * The invariant the feed's buffer exists for, end to end through a real transaction:
     * the row is gone afterwards, and so is the announcement. A listener writing a
     * changelog would otherwise hold a permanent record of something that never happened,
     * indistinguishable from the records that are true.
     */
    public function testARolledBackSaveAnnouncesNothing(): void
    {
        // Arrange
        $model = $this->model();

        // Act
        $this->db->startTransaction();
        $model->save();
        $this->assertSame([], $this->received, 'nothing may be delivered mid-transaction');
        $this->db->rollbackTransaction();

        // Assert
        $this->assertSame([], $this->received);
        $this->assertSame(0, $this->rowCount());
    }

    /**
     * A committed transaction announces after the commit, not during it.
     *
     * The mirror of the rollback case. The mid-transaction assertion is the one that
     * matters: a listener released early would act on rows that are not durable yet.
     */
    public function testACommittedSaveAnnouncesAfterTheCommit(): void
    {
        // Arrange
        $model = $this->model();

        // Act
        $this->db->startTransaction();
        $model->save();
        $this->assertSame([], $this->received, 'nothing may be delivered mid-transaction');
        $this->db->commitTransaction();

        // Assert
        $this->assertCount(1, $this->received);
        $this->assertSame(ModelChange::CREATED, $this->received[0]->op);
        $this->assertSame(1, $this->rowCount());
    }

    /**
     * Several changes in one transaction are all delivered, in order, on commit.
     *
     * A batch import is the realistic shape here: nothing is announced while it runs, and
     * the whole set arrives once the data is durable.
     */
    public function testEveryChangeInATransactionIsDeliveredInOrder(): void
    {
        // Arrange
        $labels = ['first', 'second', 'third'];

        // Act
        $this->db->startTransaction();
        foreach ($labels as $label) {
            $this->model(['label' => $label])->save();
        }
        $this->db->commitTransaction();

        // Assert
        $this->assertCount(3, $this->received);
        $this->assertSame(
            $labels,
            array_map(static fn(ModelChange $c): string => $c->data['label'], $this->received)
        );
    }

    // -------------------------------------------------------------------------
    // Configuration, against a real column set
    // -------------------------------------------------------------------------

    /**
     * A model that has not opted in announces nothing, through a real save and delete.
     *
     * The backwards-compatibility guarantee, exercised where it actually applies rather
     * than through the protected hook. Every model in every application that upgrades is
     * this model.
     */
    public function testAModelThatHasNotOptedInAnnouncesNothing(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => false]);

        // Act
        $model->save();
        $id = $model->id;
        $silent = $this->model(['emitChanges' => false]);
        $silent->load($id);
        $silent->status = 'active';
        $silent->save();
        $this->model(['emitChanges' => false])->remove($id);

        // Assert — the writes all happened; none of them said so
        $this->assertSame([], $this->received);
        $this->assertSame(0, $this->rowCount());
    }

    /**
     * An ignored column is absent from the diff and from the record.
     *
     * Run against real columns because the ignore list is applied to `getData()`, whose
     * contents depend on what the table actually has.
     */
    public function testAnIgnoredColumnIsAbsentFromTheEmission(): void
    {
        // Arrange
        $model = $this->model(['changeIgnoreFields' => ['viewcache']]);
        $model->save();
        $id = $model->id;
        $this->received = [];

        $loaded = $this->model(['changeIgnoreFields' => ['viewcache']]);
        $loaded->load($id);

        // Act — both a noisy column and a real one change
        $loaded->viewcache = 'a big blob';
        $loaded->status    = 'active';
        $loaded->save();

        // Assert
        $this->assertCount(1, $this->received);
        $change = $this->received[0];
        $this->assertArrayNotHasKey('viewcache', $change->changes);
        $this->assertArrayNotHasKey('viewcache', $change->data);
        $this->assertArrayHasKey('status', $change->changes);
    }

    /**
     * An update touching only insignificant columns is not announced, but is written.
     *
     * The distinction matters: the gate suppresses the *announcement*, never the save.
     * A model whose significant list accidentally excluded everything would otherwise
     * stop persisting, which is a far worse failure than a missed notification.
     */
    public function testAnInsignificantUpdateIsWrittenButNotAnnounced(): void
    {
        // Arrange
        $model = $this->model(['changeSignificantFields' => ['status']]);
        $model->save();
        $id = $model->id;
        $this->received = [];

        $loaded = $this->model(['changeSignificantFields' => ['status']]);
        $loaded->load($id);

        // Act
        $loaded->label = 'a different label';
        $loaded->save();

        // Assert
        $this->assertSame([], $this->received);
        $result = $this->db->query(
            'SELECT label FROM ' . static::$table . ' WHERE id = ' . (int) $id
        );
        $this->assertSame('a different label', $result->fields['label']);
    }
}
