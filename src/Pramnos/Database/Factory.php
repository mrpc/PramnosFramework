<?php

namespace Pramnos\Database;

use Pramnos\Support\Faker;

/**
 * Base class for model/data factories used in tests and database seeders.
 *
 * Subclasses declare a `definition()` method returning a column→value map
 * powered by a Faker instance. Factories support state overrides, count-based
 * bulk generation, and optional database insertion via `create()`.
 *
 * Basic usage:
 *
 *   class UserFactory extends Factory
 *   {
 *       protected string $table = 'users';
 *
 *       public function definition(): array
 *       {
 *           return [
 *               'name'  => $this->faker->name(),
 *               'email' => $this->faker->unique()->safeEmail(),
 *               'role'  => 'user',
 *           ];
 *       }
 *
 *       public function admin(): static
 *       {
 *           return $this->state(['role' => 'admin']);
 *       }
 *   }
 *
 *   // Generate in-memory only (no DB insert):
 *   $data  = UserFactory::new()->make();
 *   $rows  = UserFactory::new()->count(5)->make();
 *
 *   // Insert into the database:
 *   $data  = UserFactory::new()->create();
 *   $rows  = UserFactory::new()->count(3)->admin()->create();
 *
 *   // Named state:
 *   $data  = UserFactory::new()->state(['role' => 'moderator'])->make();
 *
 *   // Sequence — cycles through the given attribute sets:
 *   $rows  = UserFactory::new()->count(4)
 *                ->sequence(['role' => 'admin'], ['role' => 'user'])
 *                ->make();
 *
 * @package    PramnosFramework
 * @subpackage Database
 */
abstract class Factory
{
    /** Faker generator for the current instance. */
    protected Faker $faker;

    /**
     * Database table name (required to use create()).
     * Subclasses set this property.
     */
    protected string $table = '';

    /** Number of rows to generate. 1 → single array; >1 → array of arrays. */
    private int $count = 1;

    /**
     * Ordered stack of state overrides.
     * Each entry is either an array<string,mixed> or a callable(array, Faker): array.
     *
     * @var list<array<string,mixed>|callable(array<string,mixed>, Faker): array<string,mixed>>
     */
    private array $states = [];

    public function __construct(?string $locale = null)
    {
        $this->faker = Faker::create($locale ?? 'el_GR');
    }

    /** Create a new factory instance. */
    public static function new(?string $locale = null): static
    {
        return new static($locale);
    }

    /**
     * Return the default attribute set for one record.
     * Override in each concrete factory.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Set the number of records to generate.
     *
     * When count is 1 (the default), make() and create() return a single array.
     * When count > 1 they return a list of arrays.
     */
    public function count(int $n): static
    {
        $clone        = clone $this;
        $clone->count = max(1, $n);
        return $clone;
    }

    /**
     * Apply an attribute override after the definition has been resolved.
     *
     * $state may be:
     *   - an array:    merged directly over the definition
     *   - a callable:  fn(array $attributes, Faker $faker): array — receives the
     *                  current (post-definition) attributes and must return the
     *                  array of overrides to apply
     *
     * Multiple state() calls are applied left to right.
     *
     * @param array<string,mixed>|callable(array<string,mixed>, Faker): array<string,mixed> $state
     */
    public function state(array|callable $state): static
    {
        $clone           = clone $this;
        $clone->states[] = $state;
        return $clone;
    }

    /**
     * Apply a cycling sequence of attribute sets across generated records.
     *
     * For count(4)->sequence(['role'=>'admin'], ['role'=>'user']):
     *   row 0 → role=admin, row 1 → role=user, row 2 → role=admin, row 3 → role=user
     *
     * @param array<string,mixed> ...$sets
     */
    public function sequence(array ...$sets): static
    {
        if (empty($sets)) {
            return $this;
        }
        $index = 0;
        return $this->state(function (array $attributes) use ($sets, &$index): array {
            $override = $sets[$index % count($sets)];
            $index++;
            return $override;
        });
    }

    /**
     * Build attribute arrays without persisting to the database.
     *
     * Returns a single array<string,mixed> when count is 1, or a
     * list<array<string,mixed>> when count > 1.
     *
     * @param array<string,mixed> $overrides  Applied last, after all states.
     * @return array<string,mixed>|list<array<string,mixed>>
     */
    public function make(array $overrides = []): array
    {
        if ($this->count === 1) {
            return $this->makeOne($overrides);
        }

        $results = [];
        for ($i = 0; $i < $this->count; $i++) {
            $results[] = $this->makeOne($overrides);
        }
        return $results;
    }

    /**
     * Build attribute arrays and insert each row into the database.
     *
     * Returns a single array when count is 1, or a list of arrays when
     * count > 1 — mirroring the return shape of make().
     *
     * @param array<string,mixed> $overrides  Applied last, after all states.
     * @return array<string,mixed>|list<array<string,mixed>>
     * @throws \LogicException When $table is empty.
     */
    public function create(array $overrides = []): array
    {
        if ($this->count === 1) {
            $data = $this->makeOne($overrides);
            $this->insertRow($data);
            return $data;
        }

        $results = [];
        for ($i = 0; $i < $this->count; $i++) {
            $data      = $this->makeOne($overrides);
            $this->insertRow($data);
            $results[] = $data;
        }
        return $results;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Resolve one complete attribute set: definition + all states + overrides.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makeOne(array $overrides): array
    {
        $data = $this->definition();

        foreach ($this->states as $state) {
            $resolved = is_callable($state) ? $state($data, $this->faker) : $state;
            $data     = array_merge($data, $resolved);
        }

        return array_merge($data, $overrides);
    }

    /**
     * Persist a single row to the database.
     * Protected so test subclasses can override without a live DB connection.
     *
     * @param array<string,mixed> $data
     * @throws \LogicException When $table is empty.
     */
    protected function insertRow(array $data): void
    {
        if ($this->table === '') {
            throw new \LogicException(
                static::class . '::$table must be set before calling create()'
            );
        }
        Database::getInstance()->insertDataToTable($this->table, $data); // @codeCoverageIgnore
    }
}
