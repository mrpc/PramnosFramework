<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Orm\Collection;
use Pramnos\Application\Orm\Concerns\HasAttributes;
use Pramnos\Application\Orm\Concerns\HasEvents;
use Pramnos\Application\Orm\Concerns\HasRelationships;
use Pramnos\Application\Orm\Concerns\HasScopes;
use Pramnos\Application\Orm\Concerns\HasSoftDeletes;
use Pramnos\Application\Orm\Concerns\HasTimestamps;
use Pramnos\Application\OrmModel;
use Pramnos\Application\Settings;

/**
 * Characterization tests for OrmModel — the extended ORM base model.
 *
 * These tests lock the contracts of all v1.2 ORM features before any
 * refactoring:
 *
 * - Mass Assignment ($fillable / $guarded / fill())
 * - Casting ($casts — int, float, bool, string, array/json, datetime)
 * - Accessors and Mutators (getXxxAttribute / setXxxAttribute)
 * - Timestamps (auto-set created_at / updated_at)
 * - Soft Deletes (deleted_at pattern + restore)
 * - Model Events (creating/created/updating/updated/deleting/deleted)
 * - Scopes (local via scopeXxx + applyScope; global via addGlobalScope)
 * - Collections (filter, map, pluck, groupBy, sortBy, first, last, etc.)
 * - Relationships (hasOne, hasMany, belongsTo, belongsToMany factory creation)
 * - Eager Loading (with() sets eagerLoad list)
 *
 * Note: Tests that touch the database are in a separate integration test class.
 * The tests below are pure unit tests that do not require a live DB.
 */
#[CoversClass(OrmModel::class)]
#[CoversClass(HasAttributes::class)]
#[CoversClass(HasTimestamps::class)]
#[CoversClass(HasSoftDeletes::class)]
#[CoversClass(HasEvents::class)]
#[CoversClass(HasScopes::class)]
#[CoversClass(HasRelationships::class)]
#[CoversClass(Collection::class)]
class OrmModelCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        // Arrange — bootstrap Application to satisfy Model's constructor chain
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();
    }

    protected function tearDown(): void
    {
        // Remove any event listeners registered during tests
        OrmFixtureModel::flushEventListeners();
        OrmFixtureModelSoftDelete::flushEventListeners();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeOrm(string $class = OrmFixtureModel::class): OrmFixtureModel
    {
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $ctrl */
        $ctrl = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        return new $class($ctrl);
    }

    // =========================================================================
    // Mass Assignment
    // =========================================================================

    /**
     * fill() must assign only keys listed in $fillable and ignore the rest.
     * This prevents mass-assignment vulnerabilities.
     */
    public function testFillOnlySetsAllowedAttributes(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $model->fill(['name' => 'Alice', 'email' => 'alice@example.com', 'admin' => true]);

        // Assert — name and email are in $fillable, admin is not
        $this->assertSame('Alice', $model->name);
        $this->assertSame('alice@example.com', $model->email);
        $this->assertNull($model->admin); // not fillable
    }

    /**
     * fill() must return $this for fluent chaining.
     */
    public function testFillReturnsSelf(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act / Assert
        $this->assertSame($model, $model->fill(['name' => 'Bob']));
    }

    /**
     * isFillable() must return false for keys not listed in $fillable
     * when $fillable is non-empty.
     */
    public function testIsFillableReturnsFalseForUnlistedKey(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act / Assert
        $this->assertFalse($model->isFillable('admin'));
    }

    /**
     * isGuarded() is the inverse of isFillable().
     */
    public function testIsGuardedIsInverseOfIsFillable(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act / Assert
        $this->assertTrue($model->isGuarded('admin'));
        $this->assertFalse($model->isGuarded('name'));
    }

    // =========================================================================
    // Casting
    // =========================================================================

    /**
     * An 'int' cast must return a PHP int regardless of the stored string value.
     */
    public function testIntCastConvertsStoredStringToInt(): void
    {
        // Arrange
        $model = $this->makeOrm();
        $model->age = '42'; // stored as string in $_data

        // Act — __get applies the cast
        $value = $model->age;

        // Assert
        $this->assertSame(42, $value);
        $this->assertIsInt($value);
    }

    /**
     * A 'bool' cast must return true for truthy stored values.
     */
    public function testBoolCastConvertsIntToBoolean(): void
    {
        // Arrange
        $model = $this->makeOrm();
        $model->active = 1;

        // Act
        $value = $model->active;

        // Assert
        $this->assertSame(true, $value);
        $this->assertIsBool($value);
    }

    /**
     * A 'json' cast must decode a JSON string into a PHP array when reading.
     */
    public function testJsonCastDecodesJsonStringToArray(): void
    {
        // Arrange
        $model = $this->makeOrm();
        $model->prefs = '{"theme":"dark","lang":"el"}';

        // Act
        $value = $model->prefs;

        // Assert
        $this->assertIsArray($value);
        $this->assertSame('dark', $value['theme']);
        $this->assertSame('el', $value['lang']);
    }

    /**
     * A 'json' cast must encode a PHP array to JSON string when writing (__set).
     */
    public function testJsonCastEncodesArrayToJsonStringOnSet(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act — __set applies decast
        $model->prefs = ['theme' => 'light'];

        // Assert — getRaw reads internal $_data directly, bypassing ORM __get
        $stored = $model->getRaw('prefs');
        $this->assertIsString($stored);
        $this->assertStringContainsString('light', $stored);
    }

    /**
     * A 'datetime' cast must return a DateTimeImmutable from a stored string.
     */
    public function testDatetimeCastReturnsDateTimeImmutable(): void
    {
        // Arrange
        $model = $this->makeOrm();
        $model->created_at = '2026-01-15 12:00:00';

        // Act
        $value = $model->created_at;

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-01-15', $value->format('Y-m-d'));
    }

    // =========================================================================
    // Accessors / Mutators
    // =========================================================================

    /**
     * Accessing a property with a defined getXxxAttribute() method must
     * return the transformed value, not the raw value.
     */
    public function testAccessorTransformsReadValue(): void
    {
        // Arrange — setRaw writes directly to $_data, bypassing ORM __set / mutators
        $model = $this->makeOrm();
        $model->setRaw('nickname', 'alice');

        // Act — __get detects getNicknameAttribute() and returns strtoupper($rawValue)
        $value = $model->nickname;

        // Assert
        $this->assertSame('ALICE', $value);
    }

    /**
     * Assigning to a property with a defined setXxxAttribute() method must
     * store the mutated value.
     */
    public function testMutatorTransformsWriteValue(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act — __set detects setNicknameAttribute(), trims + lowercases, stores
        $model->nickname = '  BOB  ';

        // Assert — getRaw reads $_data directly to verify what was stored
        $this->assertSame('bob', $model->getRaw('nickname'));
    }

    // =========================================================================
    // Timestamps
    // =========================================================================

    /**
     * touchTimestamps(true) must set both created_at and updated_at to
     * a non-empty datetime string when inserting a new record.
     */
    public function testTouchTimestampsSetsCreatedAtAndUpdatedAtOnInsert(): void
    {
        // Arrange — ensure both columns start null in raw storage
        $model = $this->makeOrm();
        $model->timestamps = true;
        $model->setRaw('created_at', null);
        $model->setRaw('updated_at', null);

        // Act
        $model->touchTimestampsPublic(true);

        // Assert — getRaw reads internal $_data directly
        $this->assertNotEmpty($model->getRaw('created_at'));
        $this->assertNotEmpty($model->getRaw('updated_at'));
    }

    /**
     * touchTimestamps(false) must update updated_at but NOT overwrite an
     * existing created_at (update scenario).
     */
    public function testTouchTimestampsOnlyUpdatesUpdatedAtOnUpdate(): void
    {
        // Arrange — set raw values directly, bypassing ORM __set
        $model = $this->makeOrm();
        $model->timestamps = true;
        $model->setRaw('created_at', '2026-01-01 00:00:00');
        $model->setRaw('updated_at', null);

        // Act
        $model->touchTimestampsPublic(false);

        // Assert — created_at unchanged, updated_at now set
        $this->assertSame('2026-01-01 00:00:00', $model->getRaw('created_at'));
        $this->assertNotEmpty($model->getRaw('updated_at'));
    }

    /**
     * withoutTimestamps() must disable automatic timestamp management.
     */
    public function testWithoutTimestampsDisablesAutoTimestamps(): void
    {
        // Arrange
        $model = $this->makeOrm();
        $model->setRaw('updated_at', null);

        // Act
        $model->withoutTimestamps();
        $model->touchTimestampsPublic(true);

        // Assert — timestamps not set because withoutTimestamps() disabled them
        $this->assertNull($model->getRaw('updated_at'));
    }

    // =========================================================================
    // Soft Deletes
    // =========================================================================

    /**
     * trashed() must return false for a model that has not been soft-deleted.
     */
    public function testTrashedReturnsFalseForLiveRecord(): void
    {
        // Arrange
        $model = $this->makeOrm(OrmFixtureModelSoftDelete::class);
        $model->setRaw('deleted_at', null);

        // Act / Assert
        $this->assertFalse($model->trashed());
    }

    /**
     * trashed() must return true once deleted_at is populated.
     */
    public function testTrashedReturnsTrueAfterDeletion(): void
    {
        // Arrange
        $model = $this->makeOrm(OrmFixtureModelSoftDelete::class);
        $model->setRaw('deleted_at', '2026-05-01 00:00:00');

        // Act / Assert
        $this->assertTrue($model->trashed());
    }

    /**
     * buildSoftDeleteFilter() must return "deleted_at IS NULL" for a model
     * with $softDelete = true that is neither withTrashed nor onlyTrashed.
     */
    public function testBuildSoftDeleteFilterExcludesTrashedByDefault(): void
    {
        // Arrange
        $model = $this->makeOrm(OrmFixtureModelSoftDelete::class);

        // Act
        $filter = $model->buildSoftDeleteFilterPublic();

        // Assert
        $this->assertSame('deleted_at IS NULL', $filter);
    }

    /**
     * withTrashed() must make buildSoftDeleteFilter() return an empty string
     * (= include all records).
     */
    public function testWithTrashedReturnsEmptyFilter(): void
    {
        // Arrange
        $model = $this->makeOrm(OrmFixtureModelSoftDelete::class);
        $model->withTrashed();

        // Act
        $filter = $model->buildSoftDeleteFilterPublic();

        // Assert
        $this->assertSame('', $filter);
    }

    /**
     * onlyTrashed() must produce "deleted_at IS NOT NULL" filter.
     */
    public function testOnlyTrashedProducesIsNotNullFilter(): void
    {
        // Arrange
        $model = $this->makeOrm(OrmFixtureModelSoftDelete::class);
        $model->onlyTrashed();

        // Act
        $filter = $model->buildSoftDeleteFilterPublic();

        // Assert
        $this->assertSame('deleted_at IS NOT NULL', $filter);
    }

    // =========================================================================
    // Model Events
    // =========================================================================

    /**
     * A 'creating' listener registered via on() must be called when
     * fireEvent('creating') fires.
     */
    public function testOnRegistersListenerCalledOnEvent(): void
    {
        // Arrange
        $called = false;
        OrmFixtureModel::on('creating', function () use (&$called) {
            $called = true;
        });
        $model = $this->makeOrm();

        // Act
        $model->fireEventPublic('creating');

        // Assert
        $this->assertTrue($called);
    }

    /**
     * A listener returning false must make fireEvent() return false,
     * signalling cancellation to _save().
     */
    public function testListenerReturningFalseCancelsEvent(): void
    {
        // Arrange
        OrmFixtureModel::on('creating', fn() => false);
        $model = $this->makeOrm();

        // Act
        $result = $model->fireEventPublic('creating');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * observe() must register all event methods found on the observer object.
     */
    public function testObserveRegistersAllEventMethods(): void
    {
        // Arrange
        $log      = [];
        $observer = new class ($log) {
            public function __construct(private array &$log) {}
            public function creating(OrmFixtureModel $m): void  { $this->log[] = 'creating'; }
            public function created(OrmFixtureModel $m): void   { $this->log[] = 'created'; }
        };
        OrmFixtureModel::observe($observer);
        $model = $this->makeOrm();

        // Act
        $model->fireEventPublic('creating');
        $model->fireEventPublic('created');

        // Assert
        $this->assertSame(['creating', 'created'], $log);
    }

    /**
     * flushEventListeners() must remove all registered callbacks for this class.
     */
    public function testFlushEventListenersClearsCallbacks(): void
    {
        // Arrange
        $called = false;
        OrmFixtureModel::on('deleting', function () use (&$called) { $called = true; });

        // Act
        OrmFixtureModel::flushEventListeners();
        $model = $this->makeOrm();
        $model->fireEventPublic('deleting');

        // Assert
        $this->assertFalse($called);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * appendCondition() must combine two clauses with AND when both are non-empty.
     */
    public function testAppendConditionCombinesWithAnd(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $result = $model->appendConditionPublic('status = "active"', 'age > 18');

        // Assert
        $this->assertSame('(status = "active") AND (age > 18)', $result);
    }

    /**
     * appendCondition() with an empty $filter returns just the condition.
     */
    public function testAppendConditionWithEmptyFilterReturnsConditionAlone(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $result = $model->appendConditionPublic('', 'type = "post"');

        // Assert
        $this->assertSame('type = "post"', $result);
    }

    /**
     * applyScope() with a defined scopeActive() method must accumulate the
     * scope for later application.
     */
    public function testApplyScopeAccumulatesScope(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $returned = $model->applyScope('active');

        // Assert — method is fluent
        $this->assertSame($model, $returned);
        $this->assertCount(1, $model->getPendingScopesPublic());
    }

    /**
     * applyScope() with an undefined scope name must throw BadMethodCallException.
     */
    public function testApplyScopeThrowsForUndefinedScope(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Assert
        $this->expectException(\BadMethodCallException::class);

        // Act
        $model->applyScope('nonExistent');
    }

    /**
     * addGlobalScope() must register a scope that applyGlobalScopes() invokes.
     */
    public function testAddGlobalScopeIsApplied(): void
    {
        // Arrange
        OrmFixtureModel::addGlobalScope('tenant', fn(string $f) => $f === '' ? 'tenant_id = 1' : "({$f}) AND (tenant_id = 1)");
        $model = $this->makeOrm();

        // Act
        $result = $model->applyGlobalScopesPublic('');

        // Assert
        $this->assertStringContainsString('tenant_id = 1', $result);

        // Cleanup
        OrmFixtureModel::removeGlobalScope('tenant');
    }

    /**
     * withoutGlobalScope() must exclude the named scope from the next query.
     */
    public function testWithoutGlobalScopeExcludesScope(): void
    {
        // Arrange
        OrmFixtureModel::addGlobalScope('active', fn($f) => 'active = 1');
        $model = $this->makeOrm();
        $model->withoutGlobalScope('active');

        // Act
        $result = $model->applyGlobalScopesPublic('');

        // Assert — scope was skipped
        $this->assertSame('', $result);

        // Cleanup
        OrmFixtureModel::removeGlobalScope('active');
    }

    // =========================================================================
    // Collection
    // =========================================================================

    /**
     * Collection::filter() must return a new Collection with only matching items.
     */
    public function testCollectionFilterReturnsMatchingItems(): void
    {
        // Arrange
        $c = new Collection([1, 2, 3, 4, 5]);

        // Act
        $even = $c->filter(fn($v) => $v % 2 === 0);

        // Assert
        $this->assertCount(2, $even);
        $this->assertSame([2, 4], $even->all());
    }

    /**
     * Collection::map() must transform every item.
     */
    public function testCollectionMapTransformsItems(): void
    {
        // Arrange
        $c = new Collection([1, 2, 3]);

        // Act
        $doubled = $c->map(fn($v) => $v * 2);

        // Assert
        $this->assertSame([2, 4, 6], $doubled->all());
    }

    /**
     * Collection::pluck() must extract a single named property from each item.
     */
    public function testCollectionPluckExtractsProperty(): void
    {
        // Arrange
        $items = [(object)['id' => 1, 'name' => 'Alice'], (object)['id' => 2, 'name' => 'Bob']];
        $c     = new Collection($items);

        // Act
        $names = $c->pluck('name');

        // Assert
        $this->assertSame(['Alice', 'Bob'], $names->all());
    }

    /**
     * Collection::groupBy() must partition items by a shared key value.
     */
    public function testCollectionGroupByPartitionsByKey(): void
    {
        // Arrange
        $items = [
            (object)['type' => 'a', 'val' => 1],
            (object)['type' => 'b', 'val' => 2],
            (object)['type' => 'a', 'val' => 3],
        ];
        $c = new Collection($items);

        // Act
        $groups = $c->groupBy('type');

        // Assert
        $this->assertArrayHasKey('a', $groups);
        $this->assertArrayHasKey('b', $groups);
        $this->assertCount(2, $groups['a']);
        $this->assertCount(1, $groups['b']);
    }

    /**
     * Collection::first() and last() return the correct boundary elements.
     */
    public function testCollectionFirstAndLastReturnBoundaryItems(): void
    {
        // Arrange
        $c = new Collection([10, 20, 30]);

        // Act / Assert
        $this->assertSame(10, $c->first());
        $this->assertSame(30, $c->last());
    }

    /**
     * Collection::isEmpty() must return true for an empty collection.
     */
    public function testCollectionIsEmpty(): void
    {
        // Assert
        $this->assertTrue((new Collection())->isEmpty());
        $this->assertFalse((new Collection([1]))->isEmpty());
    }

    /**
     * Collection::sortBy() must order items by a property in ascending order.
     */
    public function testCollectionSortByAscending(): void
    {
        // Arrange
        $items = [(object)['n' => 3], (object)['n' => 1], (object)['n' => 2]];
        $c     = new Collection($items);

        // Act
        $sorted = $c->sortBy('n');

        // Assert
        $this->assertSame([1, 2, 3], $sorted->pluck('n')->all());
    }

    /**
     * Collection is immutable: filter/map/pluck do not modify the original.
     */
    public function testCollectionOperationsAreImmutable(): void
    {
        // Arrange
        $c = new Collection([1, 2, 3]);

        // Act
        $filtered = $c->filter(fn($v) => $v > 1);

        // Assert — original unchanged
        $this->assertCount(3, $c);
        $this->assertCount(2, $filtered);
    }

    // =========================================================================
    // Relationships (factory creation — no DB)
    // =========================================================================

    /**
     * hasOne() must return a HasOne relation object bound to the correct
     * related class and foreign key.
     */
    public function testHasOneReturnsHasOneRelation(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $relation = $model->hasOne(OrmFixtureRelated::class, 'orm_model_id', 'id');

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Orm\Relations\HasOne::class, $relation);
        $this->assertSame(OrmFixtureRelated::class, $relation->getRelatedClass());
        $this->assertSame('orm_model_id', $relation->getForeignKey());
    }

    /**
     * hasMany() must return a HasMany relation with the correct related class.
     */
    public function testHasManyReturnsHasManyRelation(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $relation = $model->hasMany(OrmFixtureRelated::class, 'parent_id', 'id');

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Orm\Relations\HasMany::class, $relation);
        $this->assertSame(OrmFixtureRelated::class, $relation->getRelatedClass());
    }

    /**
     * belongsTo() must return a BelongsTo relation that reads the FK from
     * this model's attributes.
     */
    public function testBelongsToReturnsBelongsToRelation(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $relation = $model->belongsTo(OrmFixtureRelated::class, 'related_id', 'id');

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Orm\Relations\BelongsTo::class, $relation);
        $this->assertSame('related_id', $relation->getForeignKey());
    }

    /**
     * belongsToMany() must return a BelongsToMany with the correct pivot table.
     */
    public function testBelongsToManyReturnsBelongsToManyRelation(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $relation = $model->belongsToMany(
            OrmFixtureRelated::class,
            'fixture_related',
            'fixture_id',
            'related_id'
        );

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Orm\Relations\BelongsToMany::class, $relation);
        $this->assertSame('fixture_related', $relation->getPivotTable());
        $this->assertSame('fixture_id', $relation->getForeignPivotKey());
        $this->assertSame('related_id', $relation->getRelatedPivotKey());
    }

    // =========================================================================
    // Eager Loading
    // =========================================================================

    /**
     * with() must add the relation name(s) to the eagerLoad list and return
     * $this for fluent chaining.
     */
    public function testWithAddsRelationsToEagerLoadList(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $returned = $model->with('posts');

        // Assert
        $this->assertSame($model, $returned);
        $this->assertContains('posts', $model->getEagerLoadPublic());
    }

    /**
     * with() accepts an array of relation names.
     */
    public function testWithAcceptsArrayOfRelations(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $model->with(['posts', 'comments']);

        // Assert
        $this->assertContains('posts', $model->getEagerLoadPublic());
        $this->assertContains('comments', $model->getEagerLoadPublic());
    }

    // =========================================================================
    // Relationship key helpers (pure string logic — no DB)
    // =========================================================================

    /**
     * guessForeignKey() must derive a FK name from the model's own table name
     * by stripping the trailing "s" and appending "_id".
     *
     * This is the Laravel convention: table "posts" → FK "post_id".
     * OrmFixtureModel uses table "fixture_orm_models", so the expected key is
     * "fixture_orm_model_id".  Verifying the exact derivation locks the
     * convention so related models don't silently get the wrong FK column.
     */
    public function testGuessForeignKeyDerivedFromTableName(): void
    {
        // Arrange — OrmFixtureModel::$_dbtable = 'fixture_orm_models'
        $model = $this->makeOrm();

        // Act
        $fk = $model->guessForeignKeyPublic();

        // Assert — trailing "s" stripped, "_id" appended
        $this->assertSame('fixture_orm_model_id', $fk);
    }

    /**
     * guessForeignKeyFor() must extract the short class name from a FQCN,
     * lowercase it, and append "_id".
     *
     * Given a related class "Pramnos\Tests\Characterization\Application\OrmFixtureRelated"
     * the returned FK must be "ormfixturerelated_id".  This ensures the
     * BelongsTo/BelongsToMany default FK guessing won't choose the wrong column
     * when a related class lives in a deep namespace.
     */
    public function testGuessForeignKeyForUsesShortClassName(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act — pass a FQCN; only the last segment should matter
        $fk = $model->guessForeignKeyForPublic(OrmFixtureRelated::class);

        // Assert — lowercased short name + "_id"
        $this->assertSame('ormfixturerelated_id', $fk);
    }

    /**
     * guessPivotTable() must sort the two lowercased short class names
     * alphabetically and join them with "_".
     *
     * This matches the Laravel pivot-table naming convention and ensures two
     * different orderings of the same pair produce the same pivot table name.
     * OrmFixtureModel ("ormfixturemodel") + OrmFixtureRelated ("ormfixturerelated"):
     * sorted → [ormfixturemodel, ormfixturerelated] → "ormfixturemodel_ormfixturerelated".
     */
    public function testGuessPivotTableSortsNamesAlphabetically(): void
    {
        // Arrange
        $model = $this->makeOrm();

        // Act
        $pivot = $model->guessPivotTablePublic(OrmFixtureRelated::class);

        // Assert — alphabetical order: "ormfixturemodel" < "ormfixturerelated"
        $this->assertSame('ormfixturemodel_ormfixturerelated', $pivot);
    }
}

// =============================================================================
// Inline fixture classes
// =============================================================================

/**
 * Concrete OrmModel subclass used as a test fixture.
 * Exposes protected helpers as public methods for easy assertion.
 */
class OrmFixtureModel extends OrmModel
{
    protected $_dbtable = 'fixture_orm_models';

    // Fillable / guarded
    protected array $fillable = ['name', 'email'];
    protected array $guarded  = [];

    // Casts
    protected array $casts = [
        'age'        => 'int',
        'active'     => 'bool',
        'prefs'      => 'json',
        'created_at' => 'datetime',
    ];

    // Accessor: uppercase nickname on read
    public function getNicknameAttribute(mixed $value): string
    {
        return strtoupper((string) $value);
    }

    // Mutator: lowercase + trim on write
    public function setNicknameAttribute(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    // Local scope
    public function scopeActive(string $filter): string
    {
        return $this->appendCondition($filter, 'active = 1');
    }

    // Expose protected methods for testing
    public function touchTimestampsPublic(bool $isNew): void   { $this->touchTimestamps($isNew); }
    public function fireEventPublic(string $event): bool       { return $this->fireEvent($event); }
    public function appendConditionPublic(string $f, string $c): string { return $this->appendCondition($f, $c); }
    public function buildSoftDeleteFilterPublic(): string      { return $this->buildSoftDeleteFilter(); }
    public function applyGlobalScopesPublic(string $filter): string { return $this->applyGlobalScopes($filter); }
    public function getPendingScopesPublic(): array            { return $this->_pendingScopes; }
    public function getEagerLoadPublic(): array              { return $this->eagerLoad; }
    public function guessForeignKeyPublic(): string          { return $this->guessForeignKey(); }
    public function guessForeignKeyForPublic(string $c): string { return $this->guessForeignKeyFor($c); }
    public function guessPivotTablePublic(string $c): string { return $this->guessPivotTable($c); }

    // Direct $_data access helpers (bypasses ORM __get/__set, for test inspection)
    public function setRaw(string $key, mixed $value): void { $this->_data[$key] = $value; }
    public function getRaw(string $key): mixed              { return $this->_data[$key] ?? null; }

}

/**
 * OrmModel subclass with soft-delete enabled.
 */
class OrmFixtureModelSoftDelete extends OrmFixtureModel
{
    protected bool $softDelete = true;
}

/**
 * A related OrmModel used in relationship tests.
 */
class OrmFixtureRelated extends OrmModel
{
    protected $_dbtable = 'fixture_related';
    protected array $fillable = ['name'];
}
