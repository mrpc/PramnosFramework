<?php

namespace Pramnos\Tests\Unit\Database;

use Pramnos\Support\Faker;
use Pramnos\Support\FakerGrProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Factory;

/**
 * Unit tests for Factory — the base data-factory class for tests and seeders.
 *
 * All tests use concrete anonymous factories that override insertRow() so no
 * live database connection is required. Integration tests (which test create()
 * against a real DB) live in tests/Integration/.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Factory::class)]
class FactoryTest extends TestCase
{
    // =========================================================================
    // Helpers — concrete factories used across tests
    // =========================================================================

    /**
     * Build a simple factory whose definition returns deterministic fields.
     * The returned object has a public `inserted` array that accumulates every
     * row passed to insertRow(), so tests can assert DB writes without a live
     * connection.
     *
     * @param array<string, mixed> $definition  Fixed attribute set (no Faker randomness).
     * @param string               $table       Value for the $table property.
     */
    private function makeFactory(array $definition, string $table = 'test_table'): Factory
    {
        return new class($definition, $table) extends Factory {
            /** @var list<array<string,mixed>> Rows captured by insertRow(). */
            public array $inserted = [];
            private array $def;

            public function __construct(array $def, string $table)
            {
                parent::__construct();
                $this->def   = $def;
                $this->table = $table;
            }

            public function definition(): array
            {
                return $this->def;
            }

            protected function insertRow(array $data): void
            {
                $this->inserted[] = $data;
            }
        };
    }

    // =========================================================================
    // new() static constructor
    // =========================================================================

    /**
     * Factory::new() is a static named constructor that returns an instance of
     * the concrete subclass — allowing fluent call chains without assigning a
     * variable first: UserFactory::new()->count(3)->make().
     *
     * We verify it returns an instance of the calling class (not just Factory).
     */
    public function testNewReturnsInstanceOfCallingClass(): void
    {
        // Arrange — anonymous factory with static new()
        $factoryClass = new class extends Factory {
            public string $table = 'x';
            public function definition(): array { return ['k' => 1]; }
        };

        // Act — call new() via the class name
        $instance = $factoryClass::new();

        // Assert — same concrete class returned
        $this->assertInstanceOf($factoryClass::class, $instance);
        $this->assertSame(['k' => 1], $instance->make());
    }

    // =========================================================================
    // make() — in-memory generation
    // =========================================================================

    /**
     * make() with the default count (1) returns a flat array of the definition
     * keys/values, not a nested list. The single-item case must NOT wrap the
     * result in an extra array layer.
     */
    public function testMakeReturnsSingleArrayWhenCountIsOne(): void
    {
        // Arrange
        $factory = $this->makeFactory(['name' => 'Alice', 'role' => 'user']);

        // Act
        $result = $factory->make();

        // Assert — flat array, not [[...]]
        $this->assertSame(['name' => 'Alice', 'role' => 'user'], $result);
    }

    /**
     * make() with count(n) returns a list of n arrays — one per record.
     * Each element is a flat attribute map, not further nested.
     */
    public function testMakeReturnsListOfArraysWhenCountIsGreaterThanOne(): void
    {
        // Arrange
        $factory = $this->makeFactory(['x' => 1])->count(3);

        // Act
        $result = $factory->make();

        // Assert
        $this->assertCount(3, $result);
        $this->assertSame(['x' => 1], $result[0]);
        $this->assertSame(['x' => 1], $result[1]);
        $this->assertSame(['x' => 1], $result[2]);
    }

    /**
     * make($overrides) merges the given array over the definition values.
     * Overrides are applied last, so they always win over both definition and
     * state values.
     */
    public function testMakeAppliesOverrides(): void
    {
        // Arrange
        $factory = $this->makeFactory(['name' => 'Alice', 'role' => 'user']);

        // Act
        $result = $factory->make(['name' => 'Bob']);

        // Assert — only name was overridden; role is untouched
        $this->assertSame('Bob',  $result['name']);
        $this->assertSame('user', $result['role']);
    }

    /**
     * make($overrides) with count > 1 applies the same overrides to every row.
     */
    public function testMakeAppliesOverridesToEachRowWhenCountIsGreaterThanOne(): void
    {
        // Arrange
        $factory = $this->makeFactory(['name' => 'Alice'])->count(2);

        // Act
        $rows = $factory->make(['name' => 'Carol']);

        // Assert — both rows carry the override
        foreach ($rows as $row) {
            $this->assertSame('Carol', $row['name']);
        }
    }

    // =========================================================================
    // state() — attribute overrides
    // =========================================================================

    /**
     * state() with an array merges the given attributes over the definition.
     * This is the simplest form of state — used for named states like admin().
     */
    public function testStateWithArrayMergesAttributesOverDefinition(): void
    {
        // Arrange
        $factory = $this->makeFactory(['name' => 'Alice', 'role' => 'user'])
                        ->state(['role' => 'admin']);

        // Act
        $result = $factory->make();

        // Assert — role was promoted to admin; name is still from the definition
        $this->assertSame('admin', $result['role']);
        $this->assertSame('Alice', $result['name']);
    }

    /**
     * state() with a callable receives the current attributes and the Faker
     * instance. The callable's return value is merged over the definition.
     *
     * This allows states that derive their value from other attributes
     * (e.g. building a URL from a slug that was already generated).
     */
    public function testStateWithCallableMergesReturnValueOverDefinition(): void
    {
        // Arrange
        $factory = $this->makeFactory(['name' => 'Alice', 'role' => 'user'])
                        ->state(fn(array $attrs) => ['slug' => strtolower($attrs['name'])]);

        // Act
        $result = $factory->make();

        // Assert — derived attribute was added
        $this->assertSame('alice', $result['slug']);
    }

    /**
     * The callable form of state() receives both current attributes and a Faker
     * instance — verifying the second parameter is indeed a Faker\Generator.
     */
    public function testStateCallableReceivesFakerInstance(): void
    {
        // Arrange
        $capturedFaker = null;
        $factory = $this->makeFactory(['name' => 'Alice'])
                        ->state(function (array $attrs, Faker $faker) use (&$capturedFaker): array {
                            $capturedFaker = $faker;
                            return [];
                        });

        // Act
        $factory->make();

        // Assert — second argument is a Faker generator
        $this->assertInstanceOf(Faker::class, $capturedFaker);
    }

    /**
     * Multiple state() calls are applied left to right. A later state can
     * override what an earlier state set.
     *
     * This ordering invariant is critical: callers who chain ->state(A)->state(B)
     * expect B to win over A.
     */
    public function testChainedStatesApplyInOrder(): void
    {
        // Arrange
        $factory = $this->makeFactory(['role' => 'user'])
                        ->state(['role' => 'moderator'])   // first
                        ->state(['role' => 'admin']);       // second wins

        // Act
        $result = $factory->make();

        // Assert — last state wins
        $this->assertSame('admin', $result['role']);
    }

    /**
     * state() is non-destructive: the original factory instance is unchanged,
     * and the returned clone carries only the new state. This allows a single
     * base factory to be branched into multiple variants.
     */
    public function testStateReturnsNewInstanceWithoutMutatingOriginal(): void
    {
        // Arrange
        $base  = $this->makeFactory(['role' => 'user']);
        $admin = $base->state(['role' => 'admin']);

        // Act
        $baseResult  = $base->make();
        $adminResult = $admin->make();

        // Assert — original is untouched; clone has the state
        $this->assertSame('user',  $baseResult['role']);
        $this->assertSame('admin', $adminResult['role']);
    }

    // =========================================================================
    // count() — fluent count builder
    // =========================================================================

    /**
     * count() is non-destructive: calling count(n) on a factory returns a new
     * instance rather than mutating the original. The original stays at count=1.
     */
    public function testCountReturnsNewInstanceWithoutMutatingOriginal(): void
    {
        // Arrange
        $base  = $this->makeFactory(['x' => 1]);
        $multi = $base->count(3);

        // Act
        $baseResult  = $base->make();
        $multiResult = $multi->make();

        // Assert — original still returns single array; clone returns list
        $this->assertArrayHasKey('x', $baseResult);  // flat array
        $this->assertCount(3, $multiResult);           // list of 3
    }

    /**
     * count(1) is equivalent to no count() call — returns a single flat array,
     * not a list containing one array.
     */
    public function testCountOneReturnsFlatArray(): void
    {
        // Arrange
        $factory = $this->makeFactory(['y' => 2])->count(1);

        // Act
        $result = $factory->make();

        // Assert — flat, not [[...]]
        $this->assertArrayHasKey('y', $result);
        $this->assertSame(2, $result['y']);
    }

    /**
     * count() with a value less than 1 is clamped to 1 (no empty results).
     * Negative counts are meaningless and must not produce empty arrays.
     */
    public function testCountIsClampedToMinimumOfOne(): void
    {
        // Arrange
        $factory = $this->makeFactory(['z' => 0])->count(0);

        // Act
        $result = $factory->make();

        // Assert — clamped to 1, returns single flat array
        $this->assertArrayHasKey('z', $result);
    }

    // =========================================================================
    // sequence() — cycling attribute sets
    // =========================================================================

    /**
     * sequence() cycles through the given attribute sets as records are built.
     * For count(4) with two sets the pattern is: set0, set1, set0, set1.
     *
     * This is the canonical way to produce alternating values in fixtures
     * (e.g. half admin / half user, or alternating boolean flags).
     */
    public function testSequenceCyclesThroughSetsInOrder(): void
    {
        // Arrange
        $factory = $this->makeFactory(['role' => 'user'])
                        ->count(4)
                        ->sequence(['role' => 'admin'], ['role' => 'user']);

        // Act
        $rows = $factory->make();

        // Assert — admin, user, admin, user
        $this->assertSame('admin', $rows[0]['role']);
        $this->assertSame('user',  $rows[1]['role']);
        $this->assertSame('admin', $rows[2]['role']);
        $this->assertSame('user',  $rows[3]['role']);
    }

    /**
     * sequence() with more sets than records stops at the record count — no
     * index-out-of-bounds error.
     */
    public function testSequenceWithMoreSetsThanRecordsDoesNotError(): void
    {
        // Arrange
        $factory = $this->makeFactory(['color' => 'red'])
                        ->count(2)
                        ->sequence(['color' => 'blue'], ['color' => 'green'], ['color' => 'yellow']);

        // Act
        $rows = $factory->make();

        // Assert — only first two sets used
        $this->assertSame('blue',  $rows[0]['color']);
        $this->assertSame('green', $rows[1]['color']);
    }

    /**
     * sequence() with no arguments is a no-op — the factory is returned
     * unchanged and no state is added.
     */
    public function testSequenceWithNoArgumentsIsNoOp(): void
    {
        // Arrange
        $factory = $this->makeFactory(['val' => 1])->count(2)->sequence();

        // Act
        $rows = $factory->make();

        // Assert — definition values unchanged
        $this->assertSame(1, $rows[0]['val']);
        $this->assertSame(1, $rows[1]['val']);
    }

    // =========================================================================
    // create() — database insertion
    // =========================================================================

    /**
     * create() with count=1 inserts exactly one row and returns a flat array
     * (same shape as make() for count=1).
     *
     * The insert collector on the test factory captures what would be written
     * to the DB, allowing us to assert correctness without a live connection.
     */
    public function testCreateInsertsSingleRowAndReturnsFlatArray(): void
    {
        // Arrange
        $factory = $this->makeFactory(['name' => 'Alice'], 'users');

        // Act
        $result = $factory->create();

        // Assert — one row inserted, return value matches
        $this->assertCount(1, $factory->inserted);
        $this->assertSame(['name' => 'Alice'], $result);
        $this->assertSame(['name' => 'Alice'], $factory->inserted[0]);
    }

    /**
     * create() with count > 1 inserts one row per record and returns a list of
     * arrays — the same list shape as make() for count > 1.
     */
    public function testCreateWithCountInsertsMultipleRowsAndReturnsList(): void
    {
        // Arrange — count() returns a clone, so insertions land on $factory
        $factory = $this->makeFactory(['name' => 'Alice'], 'users')->count(3);

        // Act
        $results = $factory->create();

        // Assert — three rows inserted, list returned
        $this->assertCount(3, $factory->inserted);
        $this->assertCount(3, $results);
        foreach ($results as $row) {
            $this->assertSame(['name' => 'Alice'], $row);
        }
    }

    /**
     * create($overrides) applies the overrides to each inserted row, exactly
     * as make($overrides) does — the two methods share the same resolution path.
     */
    public function testCreateAppliesOverridesToInsertedRows(): void
    {
        // Arrange
        $factory = $this->makeFactory(['name' => 'Alice', 'role' => 'user'], 'users');

        // Act
        $result = $factory->create(['name' => 'Charlie']);

        // Assert — override propagated to both return value and the inserted row
        $this->assertSame('Charlie', $result['name']);
        $this->assertSame('Charlie', $factory->inserted[0]['name']);
        $this->assertSame('user',    $result['role']);
    }

    /**
     * create() without a table set must throw LogicException — silently inserting
     * to an unknown table would corrupt the database or fail with an unclear error.
     *
     * The exception message must include the class name so it's easy to find
     * which factory is misconfigured.
     */
    public function testCreateWithoutTableThrowsLogicException(): void
    {
        // Arrange — factory with no table
        $factory = new class extends Factory {
            public function definition(): array
            {
                return ['x' => 1];
            }
        };

        // Act + Assert
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/\$table/');
        $factory->create();
    }

    // =========================================================================
    // Faker integration
    // =========================================================================

    /**
     * The Faker instance is created on construction and accessible inside
     * definition(). Two separate factory instances have independent Faker
     * generators (unique() sequences don't bleed across instances).
     */
    public function testFakerIsAvailableInsideDefinition(): void
    {
        // Arrange — factory that uses faker directly
        $factory = new class extends Factory {
            public function definition(): array
            {
                return ['email' => $this->faker->unique()->safeEmail()];
            }
        };

        // Act
        $a = $factory->make();
        $b = $factory->make();

        // Assert — produced valid-looking email addresses
        $this->assertStringContainsString('@', $a['email']);
        $this->assertStringContainsString('@', $b['email']);
    }

    /**
     * new('el_GR') registers GrProvider in addition to BaseProvider, so Greek
     * names and addresses are returned when name(), city(), etc. are called.
     *
     * We verify this by checking that FakerGrProvider is present among the
     * generator's registered providers.
     */
    public function testNewWithElGrLocaleRegistersGrProvider(): void
    {
        // Arrange
        $factory = new class('el_GR') extends Factory {
            public function definition(): array { return []; }
            /** @return list<object> */
            public function getFakerProviders(): array { return $this->faker->getProviders(); }
        };

        // Act
        $providers = $factory->getFakerProviders();

        // Assert — exactly one provider registered, and it is GrProvider
        $this->assertCount(1, $providers);
        $this->assertInstanceOf(FakerGrProvider::class, $providers[0]);
    }
}
