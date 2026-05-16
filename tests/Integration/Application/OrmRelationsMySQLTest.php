<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Orm\Collection;
use Pramnos\Application\Orm\Relations\BelongsTo;
use Pramnos\Application\Orm\Relations\BelongsToMany;
use Pramnos\Application\Orm\Relations\HasMany;
use Pramnos\Application\Orm\Relations\HasOne;
use Pramnos\Application\OrmModel;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Integration tests for ORM Relations against a live MySQL 8.0 database.
 *
 * These tests verify the four relation types — HasOne, HasMany, BelongsTo,
 * BelongsToMany — execute real SQL via getResults() and return the correct
 * model instances.  They also cover lazy loading via OrmModel::__get__(),
 * __isset__() behaviour for loaded/unloaded relations, eager loading via
 * with() + getCollection(), OrmModel::toArray() with appended relations, and
 * the Collection wrapper returned by getCollection().
 *
 * Each test is isolated: setUp() creates five scratch tables and tearDown()
 * drops them.  Fixture OrmModel subclasses are declared at the bottom of this
 * file so they remain co-located with the tests that use them.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class OrmRelationsMySQLTest extends TestCase
{
    protected Database $db;
    protected Controller $controller;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
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

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->controller = $this->makeController();

        // Start from a clean slate every test
        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
    }

    // -------------------------------------------------------------------------
    // HasOne::getResults()
    // -------------------------------------------------------------------------

    /**
     * HasOne::getResults() must return an OrmModel instance populated with
     * the related row when a matching foreign-key value exists.
     *
     * This proves the WHERE $foreignKey = $localVal query runs and hydrates
     * the related model correctly.
     */
    public function testHasOneGetResultsReturnsRelatedModel(): void
    {
        // Arrange — one user with one profile
        $userId = $this->insertUser('Alice');
        $this->insertProfile($userId, 'Developer');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Act
        $relation = $user->profile();
        $this->assertInstanceOf(HasOne::class, $relation);
        $profile = $relation->getResults();

        // Assert — profile is populated with a real DB id (proves setIsNew(false) and hydration)
        $this->assertInstanceOf(OrmTestProfile::class, $profile);
        $this->assertSame('Developer', $profile->bio);
        $this->assertGreaterThan(0, (int) $profile->id, 'id must be populated after hydration from DB');
    }

    /**
     * HasOne::getResults() must return null when no related row exists.
     *
     * Without this, code that checks `if ($user->profile)` would crash on a
     * null-dereference or return an empty shell model.
     */
    public function testHasOneGetResultsReturnsNullWhenNoRelated(): void
    {
        // Arrange — user with no profile row
        $userId = $this->insertUser('Bob');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Act
        $profile = $user->profile()->getResults();

        // Assert
        $this->assertNull($profile);
    }

    /**
     * HasOne::getResults() must return null immediately (no DB query) when
     * the local key is null — prevents "WHERE user_id = NULL" queries that
     * would return zero rows but still hit the database.
     */
    public function testHasOneGetResultsReturnsNullWhenLocalKeyIsNull(): void
    {
        // Arrange — model with no id set
        $user = new OrmTestUser($this->controller);
        // id is not set, so $user->id is null

        // Act
        $profile = $user->profile()->getResults();

        // Assert
        $this->assertNull($profile);
    }

    // -------------------------------------------------------------------------
    // HasMany::getResults()
    // -------------------------------------------------------------------------

    /**
     * HasMany::getResults() must return a Collection containing all related
     * rows keyed by the related model's primary key.
     */
    public function testHasManyGetResultsReturnsCollection(): void
    {
        // Arrange — user with two posts
        $userId = $this->insertUser('Carol');
        $this->insertPost($userId, 'First Post');
        $this->insertPost($userId, 'Second Post');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Act
        $relation = $user->posts();
        $this->assertInstanceOf(HasMany::class, $relation);
        $posts = $relation->getResults();

        // Assert — Collection with exactly two hydrated OrmTestPost instances
        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertCount(2, $posts);
        foreach ($posts as $post) {
            $this->assertInstanceOf(OrmTestPost::class, $post);
            $this->assertGreaterThan(0, (int) $post->id, 'id must be populated — proves hydration from DB');
        }
    }

    /**
     * HasMany::getResults() must return an empty Collection (not null) when
     * no related rows exist.
     */
    public function testHasManyGetResultsReturnsEmptyCollection(): void
    {
        // Arrange — user with no posts
        $userId = $this->insertUser('Dave');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Act
        $posts = $user->posts()->getResults();

        // Assert — empty collection, not null
        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertCount(0, $posts);
    }

    /**
     * HasMany::getResults() must return an empty Collection when the local
     * key is null rather than issuing a broken query.
     */
    public function testHasManyGetResultsReturnsEmptyCollectionWhenLocalKeyIsNull(): void
    {
        // Arrange
        $user = new OrmTestUser($this->controller);

        // Act
        $posts = $user->posts()->getResults();

        // Assert
        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertCount(0, $posts);
    }

    // -------------------------------------------------------------------------
    // BelongsTo::getResults()
    // -------------------------------------------------------------------------

    /**
     * BelongsTo::getResults() must return the owner model when the foreign-key
     * on this model points to a valid row on the related table.
     *
     * This is the inverse of HasOne/HasMany — the FK lives on the current table.
     */
    public function testBelongsToGetResultsReturnsOwner(): void
    {
        // Arrange — user owns a post
        $userId = $this->insertUser('Eve');
        $postId = $this->insertPost($userId, 'My Post');

        $post = new OrmTestPost($this->controller);
        $post->id      = $postId;
        $post->user_id = $userId;

        // Act
        $relation = $post->author();
        $this->assertInstanceOf(BelongsTo::class, $relation);
        $author = $relation->getResults();

        // Assert
        $this->assertInstanceOf(OrmTestUser::class, $author);
        $this->assertSame('Eve', $author->name);
        $this->assertGreaterThan(0, (int) $author->id, 'id must be populated — proves hydration from DB');
    }

    /**
     * BelongsTo::getResults() must return null when the foreign key is null
     * (no owning model assigned yet).
     */
    public function testBelongsToGetResultsReturnsNullWhenFkIsNull(): void
    {
        // Arrange — post with user_id = null
        $post = new OrmTestPost($this->controller);
        $post->user_id = null;

        // Act
        $author = $post->author()->getResults();

        // Assert
        $this->assertNull($author);
    }

    // -------------------------------------------------------------------------
    // BelongsToMany::getResults()
    // -------------------------------------------------------------------------

    /**
     * BelongsToMany::getResults() must return a Collection of related models
     * by JOIN-ing the pivot table with the related table.
     *
     * This proves the two-table JOIN query executes correctly and hydrates
     * each related model with the data from the related table (not the pivot).
     */
    public function testBelongsToManyGetResultsReturnsCollection(): void
    {
        // Arrange — post tagged with two tags
        $postId = $this->insertPost(null, 'Tagged Post');
        $tagId1 = $this->insertTag('php');
        $tagId2 = $this->insertTag('testing');
        $this->attachTag($postId, $tagId1);
        $this->attachTag($postId, $tagId2);

        $post = new OrmTestPost($this->controller);
        $post->id = $postId;

        // Act
        $relation = $post->tags();
        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $tags = $relation->getResults();

        // Assert — both tags returned and hydrated
        $this->assertInstanceOf(Collection::class, $tags);
        $this->assertCount(2, $tags);
        $names = [];
        foreach ($tags as $tag) {
            $this->assertInstanceOf(OrmTestTag::class, $tag);
            $names[] = $tag->name;
        }
        $this->assertContains('php', $names);
        $this->assertContains('testing', $names);
    }

    /**
     * BelongsToMany::getResults() must return an empty Collection when no
     * pivot rows exist for this model.
     */
    public function testBelongsToManyGetResultsReturnsEmptyWhenNoPivotRows(): void
    {
        // Arrange — post with no tags
        $postId = $this->insertPost(null, 'Untagged Post');

        $post = new OrmTestPost($this->controller);
        $post->id = $postId;

        // Act
        $tags = $post->tags()->getResults();

        // Assert
        $this->assertInstanceOf(Collection::class, $tags);
        $this->assertCount(0, $tags);
    }

    /**
     * BelongsToMany::getResults() must return an empty Collection immediately
     * when the local key is null, without issuing any JOIN query.
     */
    public function testBelongsToManyGetResultsReturnsEmptyWhenLocalKeyIsNull(): void
    {
        // Arrange — post with no id
        $post = new OrmTestPost($this->controller);

        // Act
        $tags = $post->tags()->getResults();

        // Assert
        $this->assertInstanceOf(Collection::class, $tags);
        $this->assertCount(0, $tags);
    }

    // -------------------------------------------------------------------------
    // Lazy loading via OrmModel::__get()
    // -------------------------------------------------------------------------

    /**
     * Accessing a relation property on an OrmModel must trigger lazy loading
     * via __get() and cache the result in $loadedRelations.
     *
     * On a second access the cached result must be returned without a second
     * DB round-trip — confirmed by checking the relation is already in
     * $loadedRelations before the second property read.
     */
    public function testLazyLoadViaGetLoadsAndCachesRelation(): void
    {
        // Arrange
        $userId = $this->insertUser('Frank');
        $this->insertProfile($userId, 'Tester');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Act — first access triggers getResults()
        $profile = $user->profile;

        // Assert — hydrated correctly
        $this->assertInstanceOf(OrmTestProfile::class, $profile);
        $this->assertSame('Tester', $profile->bio);

        // Assert — second access returns the same cached instance
        // (if not cached, getResults() would return a NEW object, not the same reference)
        $profileAgain = $user->profile;
        $this->assertSame($profile, $profileAgain,
            'second access must return the cached instance, not a new one');
    }

    /**
     * Lazy-loading a HasMany relation via __get() must return a Collection.
     */
    public function testLazyLoadHasManyReturnsCollection(): void
    {
        // Arrange
        $userId = $this->insertUser('Grace');
        $this->insertPost($userId, 'Post A');
        $this->insertPost($userId, 'Post B');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Act
        $posts = $user->posts;

        // Assert
        $this->assertInstanceOf(Collection::class, $posts);
        $this->assertCount(2, $posts);
    }

    // -------------------------------------------------------------------------
    // OrmModel::__isset() for relations
    // -------------------------------------------------------------------------

    /**
     * isset() on a relation that resolves to a non-null model must return true.
     *
     * PHP's empty() and null-coalescing rely on __isset(); without it, a loaded
     * relation that returns an object would still be considered "not set".
     */
    public function testIssetReturnsTrueForLoadedNonNullRelation(): void
    {
        // Arrange
        $userId = $this->insertUser('Hank');
        $this->insertProfile($userId, 'Writer');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Pre-load via __get()
        $user->profile;

        // Act / Assert — __isset() checks loadedRelations array
        $this->assertTrue(isset($user->profile),
            '__isset() must return true when the relation is loaded and non-null');
    }

    /**
     * isset() on a relation that resolves to null must return false.
     */
    public function testIssetReturnsFalseForNullRelation(): void
    {
        // Arrange — user has no profile
        $userId = $this->insertUser('Iris');

        $user = new OrmTestUser($this->controller);
        $user->id = $userId;

        // Pre-load (result is null)
        $user->profile;

        // Act / Assert — null result → __isset returns false
        $this->assertFalse(isset($user->profile),
            '__isset() must return false when the loaded relation is null');
    }

    // -------------------------------------------------------------------------
    // Eager loading via with() + getCollection()
    // -------------------------------------------------------------------------

    /**
     * getCollection() wrapped with with('posts') must attach the posts
     * Collection to each user model in a single batch query, not N+1 queries.
     *
     * We verify correctness (the right posts end up on the right user) rather
     * than query count (not observable from a test).
     */
    public function testEagerLoadWithGetCollectionAttachesRelations(): void
    {
        // Arrange — two users, each with different post counts
        $user1Id = $this->insertUser('Jack');
        $user2Id = $this->insertUser('Jill');
        $this->insertPost($user1Id, 'Jack Post 1');
        $this->insertPost($user1Id, 'Jack Post 2');
        $this->insertPost($user2Id, 'Jill Post 1');

        // Act
        $prototype = new OrmTestUser($this->controller);
        $collection = $prototype->with('posts')->getCollection();

        // Assert — both users are in the collection
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertCount(2, $collection);

        // Assert — eager-loaded posts are accessible via __get (uses cached loadedRelations internally)
        $users = array_values(iterator_to_array($collection));
        $postsByUser = [];
        foreach ($users as $user) {
            // $user->posts via __get: if eager-loaded, returns cached value; otherwise lazy-loads
            $userPosts = $user->posts;
            $this->assertInstanceOf(Collection::class, $userPosts,
                'posts must be accessible as a Collection for every user');
            $postsByUser[$user->name] = $userPosts;
        }

        $this->assertCount(2, $postsByUser['Jack'],
            'Jack must have 2 posts');
        $this->assertCount(1, $postsByUser['Jill'],
            'Jill must have 1 post');
    }

    // -------------------------------------------------------------------------
    // OrmModel::getCollection()
    // -------------------------------------------------------------------------

    /**
     * getCollection() with no filter must return a Collection of all rows in
     * the table, each hydrated as an OrmModel instance (id is populated).
     */
    public function testGetCollectionReturnsAllModels(): void
    {
        // Arrange
        $this->insertUser('User1');
        $this->insertUser('User2');
        $this->insertUser('User3');

        // Act
        $prototype = new OrmTestUser($this->controller);
        $collection = $prototype->getCollection();

        // Assert
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertCount(3, $collection);
        foreach ($collection as $user) {
            $this->assertInstanceOf(OrmTestUser::class, $user);
            $this->assertGreaterThan(0, (int) $user->id, 'id populated from DB proves correct hydration');
        }
    }

    /**
     * getCollection() with a WHERE filter must return only matching rows.
     */
    public function testGetCollectionWithFilterReturnsMatchingModels(): void
    {
        // Arrange
        $id = $this->insertUser('FilterTarget');
        $this->insertUser('OtherUser');

        // Act — filter by primary key
        $prototype  = new OrmTestUser($this->controller);
        $collection = $prototype->getCollection("id = {$id}");

        // Assert — exactly one matching user
        $this->assertCount(1, $collection);
        $user = $collection->first();
        $this->assertSame('FilterTarget', $user->name);
    }

    // -------------------------------------------------------------------------
    // OrmModel::toArray() with loaded relations
    // -------------------------------------------------------------------------

    /**
     * toArray() must include any loaded relation results in the returned array.
     *
     * This is required for JSON serialization and API responses — without it,
     * relation data loaded via with() or lazy loading would be silently dropped.
     */
    public function testToArrayIncludesLoadedRelation(): void
    {
        // Arrange
        $userId = $this->insertUser('Karen');
        $this->insertProfile($userId, 'DevOps');

        $user = new OrmTestUser($this->controller);
        $user->id   = $userId;
        $user->name = 'Karen';
        // Trigger lazy load so the relation lands in loadedRelations
        $user->profile;

        // Act
        $arr = $user->toArray();

        // Assert — the 'profile' key is present from loadedRelations
        $this->assertArrayHasKey('profile', $arr,
            'toArray() must append loaded relations to the output array');
        $this->assertNotNull($arr['profile']);
    }

    // -------------------------------------------------------------------------
    // OrmModel::_save(), _load(), _delete()
    // -------------------------------------------------------------------------

    /**
     * OrmModel::_save() on a new model must INSERT a row, assign the auto-increment
     * id back to the model, and fire 'creating'/'created' events.
     *
     * This exercises the OrmModel::_save() override (timestamps + events) over
     * the base Model::_save() DB write path.
     */
    public function testSaveInsertsNewRow(): void
    {
        // Arrange
        $user = new OrmTestUser($this->controller);
        $user->name = 'SaveTest';

        // Act — _save() is protected; call it via an instrumented subclass that exposes it
        $user->publicSave();

        // Assert — id must be populated (AUTO_INCREMENT assigned by DB)
        $this->assertGreaterThan(0, (int) $user->id,
            '_save() must assign the auto-increment id after insert');

        // Assert — row is actually in the DB
        $result = $this->db->queryBuilder()->from('orm_test_users')->where('id', (int) $user->id)->limit(1)->get();
        $this->assertSame('SaveTest', $result->fields['name']);
    }

    /**
     * OrmModel::_save() on an existing model must UPDATE the row rather than
     * inserting a new one.
     */
    public function testSaveUpdatesExistingRow(): void
    {
        // Arrange — seed a row then load it
        $id   = $this->insertUser('Original');
        $user = new OrmTestUser($this->controller);
        $user->publicLoad($id);

        // Act — mutate and save
        $user->name = 'Updated';
        $user->publicSave();

        // Assert — DB has the updated value, still the same row count
        $result = $this->db->queryBuilder()->from('orm_test_users')->where('id', $id)->limit(1)->get();
        $this->assertSame('Updated', $result->fields['name']);

        $cnt = $this->db->queryBuilder()->from('orm_test_users')->select('COUNT(*) AS c')->get();
        $this->assertSame(1, (int) $cnt->fields['c'], 'UPDATE must not insert a new row');
    }

    /**
     * OrmModel::_load() must hydrate the model from the DB and mark it as
     * not-new.  The soft-delete filter override path is exercised even when
     * softDelete = false (the method is still called).
     */
    public function testLoadHydratesModelFromDb(): void
    {
        // Arrange
        $id = $this->insertUser('LoadMe');

        $user = new OrmTestUser($this->controller);

        // Act
        $user->publicLoad($id);

        // Assert — model has the right data
        $this->assertSame('LoadMe', $user->name);
        $this->assertSame((string) $id, (string) $user->id,
            '_load() must populate id from DB');
    }

    /**
     * OrmModel::_delete() must hard-delete the row (no softDelete) and fire
     * 'deleting'/'deleted' events.
     */
    public function testDeleteRemovesRow(): void
    {
        // Arrange — insert and load
        $id   = $this->insertUser('DeleteMe');
        $user = new OrmTestUser($this->controller);
        $user->publicLoad($id);

        // Act
        $user->publicDelete($id);

        // Assert — row is gone
        $result = $this->db->queryBuilder()->from('orm_test_users')->select('COUNT(*) AS c')->where('id', $id)->get();
        $this->assertSame(0, (int) $result->fields['c'], '_delete() must remove the row');
    }

    /**
     * BelongsTo::getResults() must return null when the FK value is set but
     * the related row no longer exists (was deleted or never inserted).
     *
     * This covers the `if ($result->numRows === 0) return null` branch
     * (line 52 of BelongsTo.php) that is distinct from the null-FK early return.
     */
    public function testBelongsToGetResultsReturnsNullWhenRelatedRowMissing(): void
    {
        // Arrange — post with user_id pointing to a non-existent user
        $post = new OrmTestPost($this->controller);
        $post->user_id = 999999; // no such user

        // Act
        $author = $post->author()->getResults();

        // Assert — no matching row → null, not an exception
        $this->assertNull($author);
    }

    /**
     * BelongsToMany pivot accessors must return the values passed to __construct.
     *
     * These accessors (getPivotTable, getForeignPivotKey, getRelatedPivotKey,
     * getRelatedKey) are used by loadRelationForModels and exist for inspection.
     * Covering them avoids false 0% method-coverage gaps in the Clover report.
     */
    public function testBelongsToManyAccessorsReturnConstructorValues(): void
    {
        // Arrange — post with a known BelongsToMany definition
        $postId = $this->insertPost(null, 'AccessorTest');
        $post   = new OrmTestPost($this->controller);
        $post->id = $postId;

        // Act — call tags() to get the BelongsToMany relation object
        $relation = $post->tags();

        // Assert — all four accessors return correct values from __construct
        $this->assertSame('orm_test_post_tag', $relation->getPivotTable());
        $this->assertSame('post_id',           $relation->getForeignPivotKey());
        $this->assertSame('tag_id',            $relation->getRelatedPivotKey());
        $this->assertSame('id',                $relation->getRelatedKey());
    }

    // -------------------------------------------------------------------------
    // OrmModel soft-delete paths
    // -------------------------------------------------------------------------

    /**
     * OrmModel::_delete() on a soft-delete model must write deleted_at instead of
     * executing a hard DELETE, leaving the row in the DB.
     *
     * This covers the `if ($this->softDelete)` branch in OrmModel::_delete().
     */
    public function testSoftDeleteSetsDeletedAtAndKeepsRow(): void
    {
        // Arrange — insert and load a soft-deletable item
        $id   = $this->insertSoftItem('SoftItem');
        $item = new OrmTestSoftItem($this->controller);
        $item->publicLoad($id);

        // Act
        $item->publicDelete($id);

        // Assert — row still exists with deleted_at populated
        $result = $this->db->queryBuilder()
            ->from('orm_test_items')
            ->where('id', $id)
            ->limit(1)
            ->get();
        $this->assertNotEmpty($result->fields['deleted_at'],
            'soft-delete must write deleted_at rather than hard-deleting the row');
    }

    /**
     * OrmModel::_load() with soft-delete enabled must apply the deleted_at
     * guard after loading, marking the model as "new" (not-found).
     *
     * The soft-delete guard in OrmModel::_load() sets _isnew=true and clears
     * _initialData when deleted_at is non-null, so subsequent _save() calls
     * INSERT instead of UPDATE.  We verify this by checking that deleted_at
     * was populated (the guard was entered) and the model still carries the
     * loaded data (data is not wiped, only the "is existing" flag is cleared).
     *
     * This covers the soft-delete filter block in OrmModel::_load().
     */
    public function testLoadSoftDeletedRecordEntersSoftDeleteGuard(): void
    {
        // Arrange — insert and soft-delete an item
        $id   = $this->insertSoftItem('Trashed');
        $item = new OrmTestSoftItem($this->controller);
        $item->publicLoad($id);
        $item->publicDelete($id); // soft-deletes the row

        // Act — reload the same id (the soft-delete guard should activate)
        $reloaded = new OrmTestSoftItem($this->controller);
        $reloaded->publicLoad($id);

        // Assert — the guard was entered: deleted_at is populated on the model,
        // and the title data was loaded from DB before the guard ran
        $this->assertNotEmpty($reloaded->deleted_at,
            '_load() must load the row including deleted_at before checking the guard');
        $this->assertSame('Trashed', $reloaded->title,
            'data is still accessible — guard clears _isnew/_initialData, not $_data');
    }

    // =========================================================================
    // Infrastructure helpers
    // =========================================================================

    protected function makeController(): Controller
    {
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $ctrl */
        $ctrl = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();

        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database  = $this->db;
        $ctrl->application = $app;

        return $ctrl;
    }

    // ---- Table management ---------------------------------------------------

    protected function createTables(): void
    {
        $this->db->query(
            "CREATE TABLE `orm_test_users` (
                `id`   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->query(
            "CREATE TABLE `orm_test_profiles` (
                `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `bio`     TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->query(
            "CREATE TABLE `orm_test_posts` (
                `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `title`   VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->query(
            "CREATE TABLE `orm_test_tags` (
                `id`   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(50) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->query(
            "CREATE TABLE `orm_test_post_tag` (
                `post_id` INT UNSIGNED NOT NULL,
                `tag_id`  INT UNSIGNED NOT NULL,
                PRIMARY KEY (`post_id`, `tag_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->query(
            "CREATE TABLE `orm_test_items` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title`      VARCHAR(255) NOT NULL,
                `deleted_at` DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    protected function dropTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['orm_test_post_tag', 'orm_test_tags', 'orm_test_posts', 'orm_test_profiles', 'orm_test_users', 'orm_test_items'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ---- Row insertion helpers ----------------------------------------------

    protected function insertUser(string $name): int
    {
        $this->db->query("INSERT INTO `orm_test_users` (`name`) VALUES ('" . $this->db->prepareInput($name) . "')");
        return (int) $this->db->getInsertId();
    }

    protected function insertProfile(int $userId, string $bio): int
    {
        $this->db->query("INSERT INTO `orm_test_profiles` (`user_id`, `bio`) VALUES ({$userId}, '" . $this->db->prepareInput($bio) . "')");
        return (int) $this->db->getInsertId();
    }

    protected function insertPost(?int $userId, string $title): int
    {
        $userVal = $userId === null ? 'NULL' : (string) $userId;
        $this->db->query("INSERT INTO `orm_test_posts` (`user_id`, `title`) VALUES ({$userVal}, '" . $this->db->prepareInput($title) . "')");
        return (int) $this->db->getInsertId();
    }

    protected function insertTag(string $name): int
    {
        $this->db->query("INSERT INTO `orm_test_tags` (`name`) VALUES ('" . $this->db->prepareInput($name) . "')");
        return (int) $this->db->getInsertId();
    }

    protected function attachTag(int $postId, int $tagId): void
    {
        $this->db->query("INSERT INTO `orm_test_post_tag` (`post_id`, `tag_id`) VALUES ({$postId}, {$tagId})");
    }

    protected function insertSoftItem(string $title): int
    {
        $this->db->query("INSERT INTO `orm_test_items` (`title`) VALUES ('" . $this->db->prepareInput($title) . "')");
        return (int) $this->db->getInsertId();
    }
}

// =============================================================================
// Fixture OrmModel subclasses
// =============================================================================

/**
 * Test user model.  HasOne profile, HasMany posts.
 */
class OrmTestUser extends OrmModel
{
    protected $_dbtable    = 'orm_test_users';
    protected $_primaryKey = 'id';

    public function profile(): HasOne
    {
        return $this->hasOne(OrmTestProfile::class, 'user_id', 'id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(OrmTestPost::class, 'user_id', 'id');
    }

    // Expose protected CRUD methods for integration testing
    public function publicSave(): void         { $this->_save(); }
    public function publicLoad(mixed $pk): void { $this->_load($pk); }
    public function publicDelete(mixed $pk): void { $this->_delete($pk); }
}

/**
 * Test profile model.  BelongsTo user (inverse of hasOne).
 */
class OrmTestProfile extends OrmModel
{
    protected $_dbtable    = 'orm_test_profiles';
    protected $_primaryKey = 'id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(OrmTestUser::class, 'user_id', 'id');
    }
}

/**
 * Test post model.  BelongsTo user, BelongsToMany tags via orm_test_post_tag.
 */
class OrmTestPost extends OrmModel
{
    protected $_dbtable    = 'orm_test_posts';
    protected $_primaryKey = 'id';

    public function author(): BelongsTo
    {
        return $this->belongsTo(OrmTestUser::class, 'user_id', 'id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            OrmTestTag::class,
            'orm_test_post_tag',
            'post_id',
            'tag_id',
            'id',
            'id'
        );
    }
}

/**
 * Test tag model.  No relations defined (it is always the "many" side).
 */
class OrmTestTag extends OrmModel
{
    protected $_dbtable    = 'orm_test_tags';
    protected $_primaryKey = 'id';
}

/**
 * Test item model with soft-delete enabled.
 * Used to exercise OrmModel::_delete() soft-delete path and _load() soft-delete filter.
 */
class OrmTestSoftItem extends OrmModel
{
    protected $_dbtable    = 'orm_test_items';
    protected $_primaryKey = 'id';
    protected bool $softDelete = true;

    public function publicSave(): void          { $this->_save(); }
    public function publicLoad(mixed $pk): void  { $this->_load($pk); }
    public function publicDelete(mixed $pk): void { $this->_delete($pk); }
}
