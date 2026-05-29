<?php

declare(strict_types=1);

namespace Pramnos\Support;

/**
 * Base class for ORM model factories.
 *
 * Subclasses declare a `definition()` method that returns an array of
 * attribute → value pairs (typically using Pramnos\Support\Faker). The
 * factory then creates and optionally persists model instances.
 *
 * Usage:
 *
 *   // 1. Declare a factory
 *   class UserFactory extends \Pramnos\Support\ModelFactory
 *   {
 *       protected string $model = User::class;
 *
 *       public function definition(): array
 *       {
 *           $faker = \Pramnos\Support\Faker::create();
 *           return [
 *               'username'  => $faker->username,
 *               'email'     => $faker->email,
 *               'active'    => 1,
 *               'usertype'  => 0,
 *               'regdate'   => time(),
 *               'lastlogin' => 0,
 *           ];
 *       }
 *   }
 *
 *   // 2. Create instances via the model
 *   User::factory()->create();               // 1 persisted user
 *   User::factory()->count(50)->create();    // 50 persisted users
 *   User::factory()->make();                 // 1 in-memory user (not saved)
 *
 *   // 3. Override specific attributes
 *   User::factory()->create(['usertype' => 90]);  // admin user
 *
 *   // 4. Apply named states
 *   User::factory()->state('admin')->count(3)->create();
 *
 */
abstract class ModelFactory
{
    /**
     * The OrmModel class this factory produces.
     * Must be a fully-qualified class name extending OrmModel.
     *
     * @var class-string<\Pramnos\Application\OrmModel>
     */
    protected string $model = '';

    /**
     * Number of models to create in one call.
     */
    protected int $count = 1;

    /**
     * Accumulated attribute overrides applied in addition to definition().
     * @var array<string, mixed>
     */
    protected array $stateOverrides = [];

    // =========================================================================
    // Abstract contract
    // =========================================================================

    /**
     * Return the base attribute set for this factory.
     *
     * Each call may use Faker to generate fresh random data. The array
     * must include all required (non-nullable) columns.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    // =========================================================================
    // Fluent interface
    // =========================================================================

    /**
     * Set the number of model instances to produce.
     *
     * Returns a clone so the original factory stays reusable.
     */
    public function count(int $n): static
    {
        $clone        = clone $this;
        $clone->count = max(1, $n);
        return $clone;
    }

    /**
     * Merge extra attributes into every model produced by this factory.
     *
     * Returns a clone so the original factory stays reusable.
     *
     * @param array<string, mixed> $attributes
     */
    public function state(array $attributes): static
    {
        $clone                 = clone $this;
        $clone->stateOverrides = array_merge($this->stateOverrides, $attributes);
        return $clone;
    }

    // =========================================================================
    // Factory instantiation convenience
    // =========================================================================

    /**
     * Create and return a new factory instance (alternative to `new static()`).
     */
    public static function new(): static
    {
        return new static();
    }

    // =========================================================================
    // Create / make
    // =========================================================================

    /**
     * Build model instance(s) WITHOUT persisting them to the database.
     *
     * Returns a single model instance when count=1, or an array of instances
     * when count>1.
     *
     * @param array<string, mixed> $attributes  Attribute overrides applied on top of definition().
     * @return \Pramnos\Application\OrmModel|array<int, \Pramnos\Application\OrmModel>
     */
    public function make(array $attributes = []): mixed
    {
        if ($this->count === 1) {
            return $this->makeOne($attributes);
        }

        $items = [];
        for ($i = 0; $i < $this->count; $i++) {
            $items[] = $this->makeOne($attributes);
        }
        return $items;
    }

    /**
     * Build model instance(s) AND persist each one via `save()`.
     *
     * Returns a single model when count=1, an array when count>1.
     *
     * @param array<string, mixed> $attributes  Attribute overrides applied on top of definition().
     * @return \Pramnos\Application\OrmModel|array<int, \Pramnos\Application\OrmModel>
     */
    public function create(array $attributes = []): mixed
    {
        if ($this->count === 1) {
            $model = $this->makeOne($attributes);
            $model->save();
            return $model;
        }

        $items = [];
        for ($i = 0; $i < $this->count; $i++) {
            $model   = $this->makeOne($attributes);
            $model->save();
            $items[] = $model;
        }
        return $items;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Build and return a single unpersisted model instance.
     *
     * Resolves attributes in priority order (lowest → highest):
     *   1. definition()
     *   2. stateOverrides (from state() calls)
     *   3. $attributes argument (direct overrides)
     *
     * @param array<string, mixed> $attributes
     * @return \Pramnos\Application\OrmModel
     */
    protected function makeOne(array $attributes = []): \Pramnos\Application\OrmModel
    {
        $resolved = array_merge(
            $this->definition(),
            $this->stateOverrides,
            $attributes
        );

        $class = $this->resolveModelClass();
        $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        foreach ($resolved as $key => $value) {
            $model->$key = $value;
        }

        return $model;
    }

    /**
     * Return the model class to instantiate, validated against OrmModel.
     *
     * @return class-string<\Pramnos\Application\OrmModel>
     * @throws \RuntimeException When $model is not set or is not an OrmModel subclass.
     */
    protected function resolveModelClass(): string
    {
        if ($this->model === '') {
            throw new \RuntimeException(
                static::class . ' must declare: protected string $model = YourModel::class;'
            );
        }
        if (!is_subclass_of($this->model, \Pramnos\Application\OrmModel::class)) {
            throw new \RuntimeException(
                static::class . '::$model must be a subclass of \\Pramnos\\Application\\OrmModel, got: '
                . $this->model
            );
        }
        return $this->model;
    }
}
