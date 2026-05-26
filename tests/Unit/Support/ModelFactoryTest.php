<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\OrmModel;
use Pramnos\Support\ModelFactory;

/**
 * Unit tests for ModelFactory and OrmModel::factory() / OrmModel::save().
 *
 * ModelFactory is the DX bridge between Faker and the ORM: subclasses
 * declare definition(), and the framework handles creating/persisting
 * model instances in bulk.
 *
 * These tests use in-memory stubs — no database is required. The
 * `make()` path is fully testable without a DB connection; `create()`
 * is exercised via a spy model that records save() calls.
 */
#[CoversClass(ModelFactory::class)]
#[CoversClass(OrmModel::class)]
class ModelFactoryTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // definition() + make()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * make() returns a single model instance (not an array) when count=1.
     * The model must have the attributes from definition() applied.
     */
    public function testMakeReturnsSingleModelWhenCountIsOne(): void
    {
        // Arrange
        $factory = new WidgetFactory();

        // Act
        $model = $factory->make();

        // Assert — single model, not array
        $this->assertInstanceOf(WidgetModel::class, $model, 'make() must return a model instance');
        $this->assertSame('default-name', $model->name, 'definition() attribute must be applied');
    }

    /**
     * make() with count > 1 returns an array of models, all with definition()
     * attributes applied.
     */
    public function testMakeReturnsArrayWhenCountGreaterThanOne(): void
    {
        // Arrange
        $factory = (new WidgetFactory())->count(3);

        // Act
        $models = $factory->make();

        // Assert
        $this->assertIsArray($models, 'make(count>1) must return an array');
        $this->assertCount(3, $models, 'array length must match count()');
        foreach ($models as $m) {
            $this->assertInstanceOf(WidgetModel::class, $m);
        }
    }

    /**
     * Attributes passed directly to make() override definition() values.
     *
     * This lets callers customise specific fields without subclassing the factory.
     */
    public function testMakeAppliesAttributeOverrides(): void
    {
        // Arrange
        $factory = new WidgetFactory();

        // Act
        $model = $factory->make(['name' => 'custom-name', 'value' => 99]);

        // Assert
        $this->assertSame('custom-name', $model->name, 'direct override must win over definition()');
        $this->assertSame(99,            $model->value, 'override must set value field');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // state()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * state() returns a clone so the original factory is not mutated.
     *
     * If state() mutated in place, the same factory instance would accumulate
     * overrides across multiple test calls, causing hard-to-diagnose coupling.
     */
    public function testStateReturnsCloneAndDoesNotMutateOriginal(): void
    {
        // Arrange
        $factory = new WidgetFactory();
        $modified = $factory->state(['value' => 42]);

        // Act
        $original = $factory->make();
        $stateful = $modified->make();

        // Assert — original factory is unchanged
        $this->assertNotSame(0, $original->value === 42,
            'state() must not mutate the original factory');
        $this->assertSame(42, $stateful->value,
            'state() override must appear in modified factory output');
    }

    /**
     * State overrides are merged into definition() in the correct priority
     * order: definition() < state() < direct attributes.
     */
    public function testStateMergesWithDefinitionInCorrectPriorityOrder(): void
    {
        // Arrange — state sets value=50; direct override sets value=99
        $factory = (new WidgetFactory())->state(['value' => 50]);

        // Act — direct override wins
        $model = $factory->make(['value' => 99]);

        // Assert
        $this->assertSame(99, $model->value, 'direct override must beat state() override');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // count()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * count() returns a clone so multiple count chains can derive from the
     * same base factory without interference.
     */
    public function testCountReturnsCloneAndDoesNotMutateOriginal(): void
    {
        // Arrange
        $base    = new WidgetFactory();
        $factory = $base->count(5);

        // Act
        $single   = $base->make();
        $multiple = $factory->make();

        // Assert
        $this->assertInstanceOf(WidgetModel::class, $single,
            'base factory must still produce a single model after count() clone');
        $this->assertIsArray($multiple);
        $this->assertCount(5, $multiple);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // create() — calls save()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * create() must call save() on each model it produces.
     *
     * We verify this via a spy model: save() is overridden to increment a
     * counter instead of writing to a DB. A factory that doesn't call save()
     * would leave the spy counter at 0.
     */
    public function testCreateCallsSaveOnEachModel(): void
    {
        // Arrange — spy tracks how many times save() was called
        SpyWidgetModel::$saveCount = 0;
        $factory = new SpyWidgetFactory();

        // Act — create 3 models
        $factory->count(3)->create();

        // Assert — save must have been called once per model
        $this->assertSame(3, SpyWidgetModel::$saveCount,
            'create() must call save() once per model instance');
    }

    /**
     * create() returns a single model when count=1, not an array.
     * This matches the fluent API: $user = User::factory()->create().
     */
    public function testCreateReturnsSingleModelWhenCountIsOne(): void
    {
        // Arrange
        SpyWidgetModel::$saveCount = 0;
        $factory = new SpyWidgetFactory();

        // Act
        $result = $factory->create();

        // Assert
        $this->assertInstanceOf(SpyWidgetModel::class, $result,
            'create() must return a model instance when count=1');
        $this->assertSame(1, SpyWidgetModel::$saveCount);
    }

    /**
     * create() with count > 1 returns an array of saved models.
     */
    public function testCreateReturnsArrayWhenCountGreaterThanOne(): void
    {
        // Arrange
        SpyWidgetModel::$saveCount = 0;
        $factory = new SpyWidgetFactory();

        // Act
        $results = $factory->count(2)->create();

        // Assert
        $this->assertIsArray($results, 'create(count>1) must return an array');
        $this->assertCount(2, $results);
        $this->assertSame(2, SpyWidgetModel::$saveCount);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // resolveModelClass() — error cases
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A factory without $model set must throw a descriptive RuntimeException,
     * not a silent type error or an undefined-property notice.
     */
    public function testMakeThrowsWhenModelClassNotDeclared(): void
    {
        // Arrange
        $factory = new NoModelFactory();

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must declare.*\$model/');

        // Act
        $factory->make();
    }

    /**
     * A factory whose $model points to a class that does NOT extend OrmModel
     * must throw, rather than producing a broken object that silently fails
     * at save() time.
     */
    public function testMakeThrowsWhenModelClassIsNotOrmModel(): void
    {
        // Arrange — factory points to a plain stdClass subclass
        $factory = new InvalidModelFactory();

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be a subclass of.*OrmModel/');

        // Act
        $factory->make();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OrmModel::factory()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * OrmModel::factory() must locate the factory class by the convention
     * {ModelClass}Factory and return an instance of it.
     */
    public function testOrmModelFactoryConventionReturnsCorrectFactory(): void
    {
        // Arrange — WidgetModel::factory() should find WidgetModelFactory
        // by convention since WidgetModelFactory class exists in this file.
        // We use SpyWidgetModel because SpyWidgetFactory is registered.

        // Act
        $factory = SpyWidgetModel::factory();

        // Assert
        $this->assertInstanceOf(SpyWidgetFactory::class, $factory,
            'OrmModel::factory() must find the factory by convention {ModelClass}Factory');
    }

    /**
     * OrmModel::factory() must throw when no factory class can be found,
     * not silently return null or a generic factory.
     */
    public function testOrmModelFactoryThrowsWhenNoFactoryExists(): void
    {
        // Arrange — WidgetModel has no WidgetModelFactory class

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Factory class not found/');

        // Act
        WidgetModel::factory();
    }
}

// =============================================================================
// Minimal OrmModel stub — no DB, no real _save()
// =============================================================================

/**
 * Minimal concrete OrmModel that doesn't touch a DB.
 * _save() and save() are left as stubs — tests use SpyWidgetModel when
 * save() behaviour needs to be asserted.
 */
class WidgetModel extends \Pramnos\Application\OrmModel
{
    protected $_dbtable   = '#PREFIX#widgets';
    protected $_primaryKey = 'id';

    public string $name  = '';
    public int    $value = 0;

    /** Noop _save so tests work without a DB connection. */
    protected function _save($table = null, $key = null, $autoGetValues = false, $debug = false, $force = false)
    {
        return $this;
    }
}

class WidgetFactory extends ModelFactory
{
    protected string $model = WidgetModel::class;

    public function definition(): array
    {
        return ['name' => 'default-name', 'value' => 0];
    }
}

// =============================================================================
// Spy model — tracks save() calls without a DB
// =============================================================================

class SpyWidgetModel extends \Pramnos\Application\OrmModel
{
    protected $_dbtable   = '#PREFIX#widgets';
    protected $_primaryKey = 'id';

    public string $name  = '';
    public int    $value = 0;

    public static int $saveCount = 0;

    /** Explicit factory binding so OrmModel::factory() finds SpyWidgetFactory by name. */
    protected static string $factory = SpyWidgetFactory::class;

    protected function _save($table = null, $key = null, $autoGetValues = false, $debug = false, $force = false)
    {
        return $this;
    }

    public function save(): static
    {
        self::$saveCount++;
        return $this;
    }
}

class SpyWidgetFactory extends ModelFactory
{
    protected string $model = SpyWidgetModel::class;

    public function definition(): array
    {
        return ['name' => 'spy-widget', 'value' => 1];
    }
}

// =============================================================================
// Error-case factories
// =============================================================================

/** Factory without a $model declaration — should throw. */
class NoModelFactory extends ModelFactory
{
    public function definition(): array
    {
        return ['name' => 'no-model'];
    }
}

/** Factory pointing to a non-OrmModel class — should throw. */
class NotAnOrmModel
{
    public string $name = '';
}

class InvalidModelFactory extends ModelFactory
{
    protected string $model = NotAnOrmModel::class;

    public function definition(): array
    {
        return ['name' => 'invalid'];
    }
}
