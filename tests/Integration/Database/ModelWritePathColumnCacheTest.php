<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Factory;

/**
 * **`Model::_save()`'s column introspection is cached now, and a migration still invalidates
 * it instantly — FW-310.**
 *
 * The write path used to be deliberately uncached: a stale schema there means `_save()`'s own
 * loop never sees a newly added column, so it writes every row without it — silent, unrecoverable
 * data loss, unlike a read path where a stale schema only drops a column from a list. FW-310
 * measured the cost of staying uncached at ~165k calls / 9.7ms mean in one production install,
 * almost all of it for schema that had not changed — because `MigrationRunner::forgetAllColumns()`
 * already flushes this exact cache the moment a migration runs, same as the read paths use.
 *
 * So the write path is now cached too (`Model::WRITE_PATH_COLUMN_CACHE_TTL`, 60s), on the same
 * key the read paths use and the same invalidation `SchemaBuilder`'s DDL methods and
 * `MigrationRunner` already call. This test is the correctness half of that change: a column
 * added through the schema builder must still be visible to `_save()` on the very next save, not
 * after 60s — the whole point was never to trade correctness for the saving, only to stop paying
 * for it when nothing changed.
 */
#[CoversClass(Model::class)]
#[\PHPUnit\Framework\Attributes\Group('mysql')]
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ModelWritePathColumnCacheTest extends TestCase
{
    private Database $db;
    private SchemaBuilder $schema;
    private string $table;
    private Controller $controller;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }
        Settings::clearSettings();
        Settings::loadSettings(
            ROOT . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'settings.php'
        );

        $singleton = &Factory::getDatabase();
        $singleton = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->schema = new SchemaBuilder($this->db);
        // Unique per test, so a run cannot inherit another test's cached entry for the
        // same name — which is exactly the staleness this test exists to rule out.
        $this->table = 'modelwritecache_' . bin2hex(random_bytes(4));

        Model::$columnCache = [];

        $this->controller = new class extends Controller {
        };
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
        $this->db->forgetColumns($this->table);
    }

    /** A model bound to this test's table, built with the table already decided. */
    private function model(): Model
    {
        $table = $this->table;

        return new class ($this->controller, $table) extends Model {
            protected $_dbtable  = null;
            protected $_primaryKey = 'id';
            public $id    = null;
            public $name  = null;
            public $extra = null;

            public function __construct(Controller $controller, string $table)
            {
                $this->_dbtable = $table;
                parent::__construct($controller);
            }

            public function save(): mixed
            {
                return $this->_save();
            }
        };
    }

    /**
     * A column added by a migration (through `SchemaBuilder`) is written to on the very
     * next `_save()`, not after the 60s TTL — the invalidation that already makes the read
     * paths correct after a migration reaches this cached write path too.
     */
    public function testAColumnAddedThroughSchemaBuilderIsWrittenOnTheNextSave(): void
    {
        // Arrange — create the table, then save once so the write path's column cache,
        // which did not exist before this table did, gets populated with the old shape.
        $this->schema->createTable($this->table, function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        $first = $this->model();
        $first->name = 'before';
        $first->save();

        // Act — add a column the way a migration would, which flushes this exact cache
        // (`Database::forgetColumns()`), then save a value into it on a fresh model.
        $this->schema->alterTable($this->table, function ($table) {
            $table->string('extra')->nullable();
        });
        $second = $this->model();
        $second->name  = 'after';
        $second->extra = 'should be written';
        $second->save();

        // Assert — read the row back with a raw query, bypassing the model entirely, so
        // this proves the column was actually written to the database, not merely held on
        // the PHP object: a stale write-path schema would have silently dropped it instead.
        $result = $this->db->query(
            'SELECT `extra` FROM `' . $this->table . '` WHERE `id` = ' . (int) $second->id
        );
        $result->fetch();
        $this->assertSame(
            'should be written',
            $result->fields['extra'],
            'a column added through the schema builder must be visible to _save() immediately, not after the cache TTL'
        );
    }

    /**
     * A save populates the write path's own cache entry — the thing a second save to the
     * same, unchanged table now gets to reuse instead of asking `information_schema` again.
     */
    public function testASaveLeavesTheIntrospectionCached(): void
    {
        // Arrange
        $this->schema->createTable($this->table, function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        $model = $this->model();
        $model->name = 'one';

        // Act
        $model->save();

        // Assert — read the exact cache entry _save() wrote through, the way the next
        // save's `query($sql, true, 60, $cacheKey)` would read it: by the same SQL and the
        // same category key `columnCacheKey()` builds, with no table lock involved at all.
        $ref    = new \ReflectionObject($model);
        $method = $ref->getMethod('columnIntrospectionSql');
        $sql    = $method->invoke($model, $model->getFullTableName());
        $database = \Pramnos\Database\Database::getInstance();
        $cacheKey = $database->columnCacheKey($model->getFullTableName());

        $this->assertNotFalse(
            $database->cacheRead($sql, $cacheKey),
            '_save() must leave its own introspection cached for the next save to reuse'
        );
    }
}
