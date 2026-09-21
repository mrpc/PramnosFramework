<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;

/**
 * `Model::forget()` — emptying a model that has loaded a row it must not serve.
 *
 * WHAT: the columns a load wrote go back to what a fresh instance has, the model is marked
 *       new, and the same instance comes back so `return $this->forget();` reads.
 *
 * WHY:  a model that scopes itself to a tenant refuses a row by returning a *different*
 *       instance — but `_load()` has already written every column onto `$this` by then. A
 *       caller that discards the return value inspects the object and serves the row that
 *       was refused. That was the shape `create:crud` generated, so one project had it in
 *       nine call sites, two of them MCP tools an outside assistant can reach; the model's
 *       own isolation test passed throughout, because it uses the return value.
 *
 *       The generator takes the return value now. This is the other half: with both, the
 *       refusal holds whichever shape the caller wrote.
 *
 * No database. `forget()` undoes what a load did, and what a load did is "these properties
 * hold these values and `_initialData` names them" — which a test can set up directly and
 * a real query only makes slower.
 */
#[CoversClass(Model::class)]
class ModelForgetTest extends TestCase
{
    /**
     * A model in the three shapes a generated or hand-written one comes in.
     *
     * Untyped is what `create:crud` emits (`public $colName;`), a default is what somebody
     * writes by hand, and typed-without-default is the one that cannot be assigned null —
     * each takes a different branch and each would be a different bug.
     */
    private function model(): object
    {
        return new class extends Model {
            /** As `create:crud` emits it: untyped, so the declared default is null. */
            public $thing_id;

            /** Hand-written with a default, which is what it must go back to. */
            public $status = 'draft';

            /** Typed and undefaulted: assigning null here is a TypeError. */
            public int $organization_id;

            /** Not a column. Nothing loaded it, so nothing may clear it. */
            public string $notAColumn = 'kept';

            // The parent constructor wants a controller and a database; neither is
            // involved in forgetting a row.
            public function __construct()
            {
            }

            /** @param array<string, mixed> $row */
            public function pretendLoaded(array $row): void
            {
                foreach ($row as $field => $value) {
                    $this->$field = $value;
                }
                $this->_initialData = $row;
                $this->_isnew       = false;
            }

            public function callForget(): static
            {
                return $this->forget();
            }

            public function isNew(): bool
            {
                return $this->_isnew;
            }

            /** @return array<string, mixed> */
            public function initialData(): array
            {
                return $this->_initialData;
            }
        };
    }

    /**
     * Every column the load wrote goes back to what a fresh instance has.
     *
     * The three declarations take three different branches, and the third is the one that
     * would fatal: `public int $organization_id` cannot be assigned null, so it has to be
     * unset rather than nulled.
     */
    public function testItRestoresEveryLoadedColumn(): void
    {
        // Arrange — a row belonging to another tenant
        $model = $this->model();
        $model->pretendLoaded([
            'thing_id'        => 42,
            'status'          => 'published',
            'organization_id' => 7,
        ]);

        // Act
        $model->callForget();

        // Assert — untyped goes to null, defaulted goes to its default…
        $this->assertNull($model->thing_id);
        $this->assertSame('draft', $model->status);

        // …and typed-without-default goes back to uninitialized, which is what a fresh
        // instance has. Reading it would throw, so the question asked is whether it is set.
        $this->assertFalse(
            (new \ReflectionProperty($model, 'organization_id'))->isInitialized($model),
            'a typed property with no default must be unset, not nulled'
        );
    }

    /**
     * The primary key is cleared, which is what every caller checks.
     *
     * The generated controller's 404 is `if ($model->thing_id == 0)`. If `forget()` left
     * the key behind, the refusal would still read as a hit and the row would still be
     * served — the whole defect, with a method in front of it.
     */
    public function testThePrimaryKeyNoLongerReadsAsAHit(): void
    {
        // Arrange
        $model = $this->model();
        $model->pretendLoaded(['thing_id' => 42, 'status' => 'published']);

        // Act
        $model->callForget();

        // Assert — the generated guard's exact test
        $this->assertTrue($model->thing_id == 0, 'the refused row still answers as found');
    }

    /**
     * It leaves alone what the load did not write.
     *
     * `_initialData` names the columns this load read, and that is the scope. Clearing
     * every public property instead would take out state a model legitimately carries —
     * and a hand-written column list would be correct until somebody adds a column, then
     * leak exactly that one, which is why neither is used.
     */
    public function testItLeavesPropertiesTheLoadDidNotWrite(): void
    {
        // Arrange
        $model = $this->model();
        $model->pretendLoaded(['thing_id' => 42]);
        $model->notAColumn = 'still here';

        // Act
        $model->callForget();

        // Assert
        $this->assertSame('still here', $model->notAColumn);
        $this->assertSame('draft', $model->status, 'a column no load wrote was cleared');
    }

    /**
     * The model is new afterwards, so a later `save()` inserts.
     *
     * Without this the emptied model still looks loaded, and `save()` would issue an UPDATE
     * against the primary key of the row that was refused — writing nulls over somebody
     * else's data, which is worse than reading it.
     */
    public function testTheModelIsNewAfterwards(): void
    {
        // Arrange
        $model = $this->model();
        $model->pretendLoaded(['thing_id' => 42, 'status' => 'published']);
        $this->assertFalse($model->isNew(), 'the fixture did not look loaded');

        // Act
        $model->callForget();

        // Assert
        $this->assertTrue($model->isNew());
        $this->assertSame([], $model->initialData());
    }

    /**
     * It returns the same instance, so `return $this->forget();` is the whole refusal.
     *
     * Returning a new object would be a second thing to get right at every call site, and
     * the call sites are exactly where this went wrong the first time.
     */
    public function testItReturnsTheSameInstance(): void
    {
        // Arrange
        $model = $this->model();
        $model->pretendLoaded(['thing_id' => 42]);

        // Act + Assert
        $this->assertSame($model, $model->callForget());
    }

    /**
     * Forgetting a model that never loaded anything does nothing and does not raise.
     *
     * A scoped `load()` calls `forget()` on the refusal path, and the refusal path is
     * reached for a primary key that matched no row at all — `_load()` leaves the model
     * untouched then, so `_initialData` is empty and there is nothing to undo.
     */
    public function testForgettingAnUnloadedModelIsHarmless(): void
    {
        // Arrange
        $model = $this->model();

        // Act
        $model->callForget();

        // Assert
        $this->assertSame('draft', $model->status);
        $this->assertTrue($model->isNew());
    }

    /**
     * A column in `_initialData` that the class does not declare is skipped.
     *
     * `_load()` writes every column the query returned, including one added to the table
     * but not yet to the model — those arrive as dynamic properties. Unsetting a property
     * that does not exist is harmless, but `getDefaultProperties()` does not list it, so
     * the branch is worth pinning: it must not raise and must not invent the property.
     */
    public function testAnUndeclaredColumnIsSkippedRatherThanRaising(): void
    {
        // Arrange — a column the table has and the class does not
        $model = $this->model();
        $model->pretendLoaded(['thing_id' => 42, 'added_later' => 'x']);

        // Act
        $model->callForget();

        // Assert
        $this->assertNull($model->thing_id);
        $this->assertSame([], $model->initialData());
    }
}
