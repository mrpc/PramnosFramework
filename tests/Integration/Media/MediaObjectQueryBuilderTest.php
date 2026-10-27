<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Media;

use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\DatabaseTestCase;
use Pramnos\Media\MediaObject;

/**
 * `MediaObject`'s usage-tracking/CRUD methods, after converting their raw
 * `prepareQuery()`/`query()`/`insertDataToTable()`/`updateTableData()` calls to the query
 * builder (rule 12): `getMediaUsages()`, `staticGetUsages()`, `getList()`, `addUsage()`,
 * `removeUsage()`, `clearUsage()`, `loadByUsage()`, `save()`, `saveUsage()`.
 *
 * None of these statements is dialect-specific — ordinary SELECT/INSERT/UPDATE/DELETE with
 * no raw SQL feature either engine lacks — so what this class (and its PostgreSQL subclass)
 * actually proves is that the converted query-builder calls compile to something both
 * engines accept and that return the same rows. That is exactly the class of thing the
 * conversion could get wrong silently: an `order by \`order\`` with the reserved word
 * unquoted, a nested `where()`/`orWhere()` group that doesn't parenthesize the way the old
 * string concatenation did, or `getInsertId()` after an insert reading the wrong sequence.
 *
 * MySQL here, PostgreSQL in {@see MediaObjectQueryBuilderPostgreSQLTest}.
 */
class MediaObjectQueryBuilderTest extends DatabaseTestCase
{
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => TEST_DATABASE,
            'port'     => 3306,
        ];
    }

    protected static function ownedTables(): array
    {
        return ['mediause', 'media'];
    }

    /**
     * Nothing: the tables come from the shipped migration, like
     * {@see \Pramnos\Tests\Integration\Media\MediaRenditionPolicyTest}. Hand-written DDL
     * beside a migration is how a test asserts a shape the framework does not ship.
     */
    protected static function schemaStatements(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $db = static::openConnection();

        $application = (new \ReflectionClass(\Pramnos\Application\Application::class))
            ->newInstanceWithoutConstructor();
        $application->database = $db;

        (new \Pramnos\Framework\Migrations\Core\CreateMediaTables($application))->up();

        $db->close();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // MediaObject reaches for the Factory singleton, not for $this->db, so the lane's
        // connection has to *be* the singleton or the rows land in whichever engine the
        // settings file names — which would make the PostgreSQL subclass a copy of this one.
        $singleton = &Factory::getDatabase();
        $singleton = $this->db;
    }

    protected function tearDown(): void
    {
        $singleton = &Factory::getDatabase();
        $singleton = null;

        parent::tearDown();
    }

    private function newMedia(string $name = 'Test'): MediaObject
    {
        $media           = new MediaObject();
        $media->mediatype = 1;
        $media->name     = $name;
        $media->filename = '/var/www/html/www/uploads/' . $name . '.jpg';
        $media->url      = 'uploads/' . $name . '.jpg';
        $media->x        = 100;
        $media->y        = 100;

        return $media;
    }

    /**
     * save() inserts a new row (getInsertId() populates mediaid) and then updates the same
     * row in place — the two branches `_isnew` selects between.
     */
    public function testSaveInsertsThenUpdatesExistingRow(): void
    {
        // Arrange
        $media = $this->newMedia('insert-update');

        // Act — insert
        $media->save();
        $insertedId = $media->mediaid;

        // Assert — a real id came back through getInsertId()
        $this->assertGreaterThan(0, $insertedId);

        // Act — update the same row
        $media->name = 'renamed';
        $media->save();

        // Assert — same id, new value persisted
        $reloaded = new MediaObject();
        $reloaded->load((string) $insertedId);
        $this->assertSame($insertedId, $reloaded->mediaid);
        $this->assertSame('renamed', $reloaded->name);
    }

    /**
     * addUsage() inserts a `mediause` row through the query builder and reads its id back
     * with getInsertId() — the exact path FW-295 found broken on PostgreSQL before the
     * Result::getInsertId() fix. loadByUsage() must then read the usage fields back intact.
     */
    public function testAddUsageInsertsAndLoadByUsageReadsItBack(): void
    {
        // Arrange
        $media = $this->newMedia('usage-owner');
        $media->save();

        // Act
        $usageId = $media->addUsage('a_module', 'a_spec', 'A title');

        // Assert — a real, dialect-correct insert id
        $this->assertGreaterThan(0, $usageId);

        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId);
        $this->assertSame($media->mediaid, $loaded->mediaid);
        $this->assertSame('A title', $loaded->usageTitle);
        $this->assertSame('a_module', $loaded->usageModule);
    }

    /**
     * getMediaUsages() returns one MediaObject per usage row, ordered by `order` — the
     * reserved-word column the query builder has to quote correctly on both engines.
     */
    public function testGetMediaUsagesReturnsEachUsageOrdered(): void
    {
        // Arrange
        $media = $this->newMedia('multi-usage');
        $media->save();
        $media->addUsage('mod_a', 'first', '', '', '', 2);
        $media->addUsage('mod_a', 'second', '', '', '', 1);

        // Act
        $usages = $media->getMediaUsages();

        // Assert — both usages came back, ordered by `order` ascending
        $this->assertCount(2, $usages);
        $this->assertSame('second', $usages[0]->usageSpecific);
        $this->assertSame('first', $usages[1]->usageSpecific);
    }

    /**
     * staticGetUsages() with a module-only filter, a module+specific filter and no filter at
     * all — the three branches collapsed into conditional where()s.
     */
    public function testStaticGetUsagesFiltersByModuleAndSpecific(): void
    {
        // Arrange
        $media = $this->newMedia('static-usages');
        $media->save();
        $media->addUsage('mod_x', 'one');
        $media->addUsage('mod_x', 'two');
        $media->addUsage('mod_y', 'one');

        // Act / Assert
        $this->assertCount(2, MediaObject::staticGetUsages('mod_x'));
        $this->assertCount(1, MediaObject::staticGetUsages('mod_x', 'one'));
        $this->assertCount(3, MediaObject::staticGetUsages());
    }

    /**
     * getList() with a type filter and a module filter together — both conditions must land
     * inside the same AND, and the permission OR-group must nest inside that correctly.
     */
    public function testGetListFiltersByTypeAndModule(): void
    {
        // Arrange
        $_SESSION['uid'] = 1;
        $matching = $this->newMedia('matching');
        $matching->module = 'gallery';
        $matching->userid = 1;
        $matching->save();

        $otherModule = $this->newMedia('other-module');
        $otherModule->module = 'elsewhere';
        $otherModule->userid = 1;
        $otherModule->save();

        // Act
        $probe = new MediaObject();
        $result = $probe->getList(1, 'gallery', 1);

        // Assert — only the matching module came back
        $this->assertCount(1, $result);
        $this->assertSame('matching', $result[0]->name);

        unset($_SESSION['uid']);
    }

    /**
     * removeUsage() with $safe=false: deletes the mediause row, decrements `usages` on the
     * parent, and — since it was the last usage — deletes the (now-unused) media row too.
     * Exercises decrement(), value() and count() together.
     */
    public function testRemoveUsageUnsafeDeletesTheMediaWhenNoUsagesRemain(): void
    {
        // Arrange
        $media = $this->newMedia('remove-usage');
        $media->save();
        $usageId = $media->addUsage('mod', 'spec');

        // Act
        $media->removeUsage($usageId, false);

        // Assert — the usage and its now-orphaned media row are both gone
        $this->assertCount(0, MediaObject::staticGetUsages('mod', 'spec'));
        $reloaded = new MediaObject();
        $reloaded->load((string) $media->mediaid);
        $this->assertEquals(0, $reloaded->mediaid);
    }

    /**
     * clearUsage() removes every usage for a module+specific pair via pluck()+removeUsage().
     */
    public function testClearUsageRemovesEveryMatchingUsage(): void
    {
        // Arrange
        $media = $this->newMedia('clear-usage');
        $media->save();
        $media->addUsage('clear_mod', 'clear_spec');
        $media->addUsage('clear_mod', 'clear_spec');

        // Act
        $media->clearUsage('clear_mod', 'clear_spec');

        // Assert
        $this->assertCount(0, MediaObject::staticGetUsages('clear_mod', 'clear_spec'));
    }

    /**
     * loadByUsage() with $updateViews=true persists the incremented view count through the
     * query builder's update().
     */
    public function testLoadByUsageWithUpdateViewsPersistsTheCount(): void
    {
        // Arrange
        $media = $this->newMedia('views');
        $media->save();
        $usageId = $media->addUsage('mod', 'spec');

        // Act
        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId, true);

        // Assert — read the column directly: load() caches by mediaid for 600s, so a second
        // MediaObject::load() in the same test would see the pre-update cached row, not what
        // the update just persisted.
        $persistedViews = $this->db->queryBuilder()->table('media')
            ->where('mediaid', $media->mediaid)->value('views');
        $this->assertSame(1, (int) $persistedViews);
    }

    /**
     * saveUsage() updates the usage row's own fields through the query builder.
     */
    public function testSaveUsagePersistsChangedFields(): void
    {
        // Arrange
        $media = $this->newMedia('save-usage');
        $media->save();
        $usageId = $media->addUsage('mod', 'spec');

        // Act
        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId);
        $loaded->usageTitle = 'changed title';
        $loaded->saveUsage();

        // Assert
        $reloaded = new MediaObject();
        $reloaded->loadByUsage($usageId);
        $this->assertSame('changed title', $reloaded->usageTitle);
    }
}
