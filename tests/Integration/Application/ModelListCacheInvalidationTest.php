<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/** A model that caches its lists, which is what `$useCacheInLists` is for. */
class ListCacheProbeModel extends Model
{
    protected $modelname = 'listcacheprobe';

    protected $_primaryKey = 'probeid';

    protected $useCacheInLists = true;

    protected $cacheInListsTime = 600;

    public $probeid;

    public $title;

    public function __construct(?string $table = null)
    {
        if ($table !== null) {
            $this->_dbtable = $table;
        }
    }

    /**
     * The cached list, through the same call an application makes.
     *
     * `_getList($filter, $order, …)` — the filter is first, which the first version of this
     * got wrong: passing the `ORDER BY` as the filter produced `WHERE ORDER BY …`, so the
     * list came back empty and every test failed for a reason that had nothing to do with
     * caching.
     *
     * @return array<int, static>
     */
    public function titles(): array
    {
        return (array) $this->_getList(null, 'ORDER BY a.probeid ASC');
    }

    /** `_load`, `_save` and `_delete` are protected; an application exposes what it needs. */
    public function fetch($primaryKey): static
    {
        $this->_load($primaryKey);

        return $this;
    }

    public function store(): static
    {
        return $this->_save();
    }

    public function remove($primaryKey): mixed
    {
        return $this->_delete($primaryKey);
    }
}

/**
 * A row that changes must not leave a cached list describing the row it was.
 *
 * `$useCacheInLists` is the per-model switch — `protected`, **off** by default — and it caches
 * the list and count queries under the model's own category. `Model::save()` is what has to
 * invalidate that, and on the path an application actually takes it did not:
 *
 * | | |
 * |---|---|
 * | insert | clears the category — correct, a new row changes every list |
 * | update **with** a primary key | cleared only `<id>-<table>`, a category nothing writes to |
 * | update with no primary key | clears the category |
 *
 * So the ordinary case — load a row, change a field, save — invalidated **nothing**, and a
 * cached list kept serving the old value until its TTL. The comment above it said *"clear only
 * the specific record's cache, not the entire category"*, which is the right instinct and the
 * wrong conclusion: a list is not the record, and it contains it.
 *
 * That went unnoticed because it is cheap to not notice: the default TTL is 60 seconds, so the
 * page is correct again by the time anybody reloads it twice. It is the same shape as the
 * settings cache reported separately — a write that looks saved and a screen that redraws from
 * before it.
 */
#[CoversClass(Model::class)]
class ModelListCacheInvalidationTest extends BaseTestCase
{
    private $db;

    private string $table = '';

    /** @var array<string, mixed>|null */
    private $savedCacheSetting = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        // A store this test knows works, so a miss is never the ambient cache being cold.
        $this->savedCacheSetting = Settings::getSetting('cache');
        Settings::setSetting('cache', ['method' => 'file'], false);

        $this->table = 'listcacheprobe_' . bin2hex(random_bytes(4));
        $this->createTable();
        $this->db->query(
            'INSERT INTO ' . $this->quoted($this->table) . ' (' . $this->quoted('title')
            . ") VALUES ('before')"
        );
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->quoted($this->table));
        }

        Settings::setSetting('cache', $this->savedCacheSetting, false);

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function quoted(string $name): string
    {
        return $this->db->type === 'postgresql' ? '"' . $name . '"' : '`' . $name . '`';
    }

    private function createTable(): void
    {
        $this->db->query(
            'CREATE TABLE ' . $this->quoted($this->table) . ' ('
            . $this->quoted('probeid')
            . ($this->db->type === 'postgresql'
                ? ' SERIAL PRIMARY KEY, '
                : ' INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ')
            . $this->quoted('title') . ' VARCHAR(255) NOT NULL)'
            . ($this->db->type === 'postgresql' ? '' : ' ENGINE=InnoDB')
        );
    }

    private function model(): ListCacheProbeModel
    {
        return new ListCacheProbeModel($this->table);
    }

    /**
     * The reported shape: read a list, change a row, read the list again.
     *
     * The assertion is the second read. Nothing here is about *how* invalidation works — only
     * that a value which changed in the table is not still being served from a list somebody
     * cached before it changed.
     */
    public function testAnUpdatedRowDoesNotSurviveInACachedList(): void
    {
        // Arrange — the list is read once, so it is cached
        $first = $this->model()->titles();
        $this->assertCount(1, $first, 'the fixture row was not listed');
        $this->assertSame('before', (string) reset($first)->title);

        // Act — load it, change it, save it
        $row = $this->model()->fetch(1);
        $row->title = 'after';
        $row->store();

        // Assert — the list must not describe the row it was
        $again = $this->model()->titles();

        $this->assertSame(
            'after',
            (string) reset($again)->title,
            'a cached list served the value the row had before it was saved'
        );
    }

    /**
     * A new row appears in the list, which is the case that already worked.
     *
     * The control. The insert path clears the category, so this passed before the fix — and a
     * fix that only made the update path work by weakening the insert path would show up here.
     */
    public function testAnInsertedRowAppearsInACachedList(): void
    {
        // Arrange
        $this->assertCount(1, $this->model()->titles());

        // Act
        $row = $this->model();
        $row->title = 'second';
        $row->store();

        // Assert
        $this->assertCount(2, $this->model()->titles(), 'a new row was hidden by a cached list');
    }

    /**
     * A deleted row leaves the list too.
     */
    public function testADeletedRowLeavesACachedList(): void
    {
        // Arrange
        $this->assertCount(1, $this->model()->titles());

        // Act
        $this->model()->remove(1);

        // Assert
        $this->assertSame(array(), $this->model()->titles(), 'a cached list kept a deleted row');
    }

    /**
     * A save that changes nothing does not invalidate anything.
     *
     * The half worth keeping, and it already worked: `save()` compares against `_initialData`
     * and returns early when `getChanges()` is empty — no `UPDATE`, no flush. Asserted so that
     * making the update path invalidate more does not turn every no-op `save()` into a
     * cache-wide flush, which on a write-heavy table is the whole cost back again.
     */
    public function testASaveThatChangesNothingInvalidatesNothing(): void
    {
        // Arrange — cache the list, then note what the category holds
        $this->model()->titles();

        $row = $this->model()->fetch(1);

        // Act — saving unchanged data
        $row->store();

        // Assert — `reset()` takes a reference, so the list is a variable first
        $listed = $this->model()->titles();

        $this->assertSame(
            'before',
            (string) reset($listed)->title,
            'an unchanged save altered the row'
        );
    }
}
