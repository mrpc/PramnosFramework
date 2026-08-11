<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;

/**
 * A model that declares its table, the ordinary case.
 */
class PlainTableModel extends Model
{
    protected $_dbtable = 'plain_things';

    public function __construct()
    {
        // No parent constructor: table resolution needs no application context.
    }

    /** Run the resolution the listing helpers run. */
    public function resolve(): ?string
    {
        if ($this->_dbtable === null) {
            $this->initTable();
        }
        if ($this->_dbtable === null) {
            $this->tableFromLegacyLoad();
        }

        return $this->_dbtable;
    }
}

/**
 * A model that works its table name out at runtime.
 */
class ComputedTableModel extends PlainTableModel
{
    protected $_dbtable = null;

    /** @var string Stands in for a tenant, a locale, whatever decides the name. */
    public string $suffix = 'gr';

    protected function initTable()
    {
        $this->_dbtable = 'readings_' . $this->suffix;
    }
}

/**
 * A model in the old shape: no table property, `load()` sets it as a side effect.
 */
class LegacyLoadModel extends PlainTableModel
{
    protected $_dbtable = null;

    /** @var int|null The argument load() was called with. */
    public ?int $loadedWith = null;

    /**
     * @param  int $id
     * @return $this
     */
    public function load($id)
    {
        $this->loadedWith = $id;
        $this->_dbtable   = 'legacy_things';

        return $this;
    }
}

/**
 * A model whose `load()` takes the parameters *it* needs — two of them.
 *
 * This is the shape the base must not assume away, and the reason `load()` is
 * left undeclared: PHP would reject this class against any parent declaration.
 */
class TwoArgumentLoadModel extends PlainTableModel
{
    protected $_dbtable = null;

    /**
     * @param  string $username
     * @param  string $type
     * @return $this
     */
    public function load($username, $type)
    {
        $this->_dbtable = 'never_reached';

        return $this;
    }
}

/**
 * A model with neither a table, an initTable(), nor a load().
 */
class NoTableAtAllModel extends PlainTableModel
{
    protected $_dbtable = null;
}

/**
 * Covers how a listing helper finds the model's table.
 *
 * The base used to get it by calling `$this->load(0)` — not to load a record,
 * but hoping the subclass would set `$_dbtable` as a side effect before failing
 * to find the row with id 0. That coupled table discovery to record loading, ran
 * a pointless query, and assumed every model's `load()` takes exactly one
 * argument.
 *
 * The assumption could not be enforced without doing more damage: PHP only lets
 * a child *add optional* parameters, so declaring `load()` in the base — as
 * abstract, concrete, or variadic — would reject every model that needs its own
 * parameters. `Pramnos\Auth\Application` already has three. So the base owns a
 * hook of its own instead, and leaves `load()` alone.
 */
class ModelTableResolutionTest extends TestCase
{
    /**
     * A declared table is used as-is, and nothing else is consulted.
     */
    public function testADeclaredTableIsUsedDirectly(): void
    {
        // Arrange
        $model = new PlainTableModel();

        // Act + Assert
        $this->assertSame('plain_things', $model->resolve());
    }

    /**
     * A model that computes its name gets asked through initTable().
     */
    public function testAComputedTableComesFromTheHook(): void
    {
        // Arrange
        $model = new ComputedTableModel();
        $model->suffix = 'cy';

        // Act + Assert
        $this->assertSame('readings_cy', $model->resolve());
    }

    /**
     * The hook runs before the legacy fallback, so a model that overrides it
     * never has its load() called at all.
     */
    public function testTheHookIsPreferredOverCallingLoad(): void
    {
        // Arrange — this model has both
        $model = new class extends LegacyLoadModel {
            protected function initTable()
            {
                $this->_dbtable = 'from_hook';
            }
        };

        // Act
        $table = $model->resolve();

        // Assert
        $this->assertSame('from_hook', $table);
        $this->assertNull($model->loadedWith, 'load() must not be called when the hook answered');
    }

    /**
     * A model still written the old way keeps working.
     *
     * This is the compatibility guarantee: models that set `$_dbtable` inside
     * `load()` were the reason the base called it, and they must not break.
     */
    public function testAModelThatSetsItsTableInsideLoadStillWorks(): void
    {
        // Arrange
        $model = new LegacyLoadModel();

        // Act
        $table = $model->resolve();

        // Assert
        $this->assertSame('legacy_things', $table);
        $this->assertSame(0, $model->loadedWith, 'the legacy call passes 0, as before');
    }

    /**
     * A model whose load() needs more than one argument is told what to do.
     *
     * Before, the base called it with one argument regardless and PHP raised
     * `ArgumentCountError` from inside a framework method — an error about a
     * call the author never made. The message now names the class and the fix.
     */
    public function testAMultiArgumentLoadIsExplainedInsteadOfMiscalled(): void
    {
        // Arrange
        $model = new TwoArgumentLoadModel();

        // Act + Assert
        try {
            $model->resolve();
            $this->fail('Resolution must not silently succeed without a table');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('TwoArgumentLoadModel', $exception->getMessage());
            $this->assertStringContainsString('initTable()', $exception->getMessage());
            $this->assertStringContainsString('requires 2 arguments', $exception->getMessage());
        }
    }

    /**
     * ...and the miscall really is avoided, not merely reported afterwards.
     */
    public function testAMultiArgumentLoadIsNeverCalled(): void
    {
        // Arrange
        $model = new class extends TwoArgumentLoadModel {
            public bool $called = false;

            public function load($username, $type)
            {
                $this->called = true;

                return $this;
            }
        };

        // Act
        try {
            $model->resolve();
        } catch (\LogicException) {
            // expected
        }

        // Assert
        $this->assertFalse($model->called, 'the call must not be attempted at all');
    }

    /**
     * A model with no way at all to name its table says so.
     */
    public function testAModelWithNoTableAndNoLoadIsExplained(): void
    {
        // Arrange
        $model = new NoTableAtAllModel();

        // Act + Assert
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/NoTableAtAllModel.*initTable\(\)/s');
        $model->resolve();
    }

    /**
     * `load()` is declared nowhere in the Model hierarchy.
     *
     * This is the property that keeps subclasses free to choose their own
     * parameters, and it is invisible in the code — nothing shows an absence. It
     * would be undone by anyone "tidying up" the base with an abstract
     * declaration, and the damage would only appear on whichever model has an
     * incompatible signature.
     */
    public function testTheBaseDeclaresNoLoadMethod(): void
    {
        // Act + Assert
        $this->assertFalse(
            (new \ReflectionClass(Model::class))->hasMethod('load'),
            'Model must not declare load(): PHP only allows children to add '
            . 'optional parameters, so any declaration here would reject a model '
            . 'whose load() takes its own arguments'
        );
    }

    /**
     * A model with extra optional parameters is accepted — the shape the
     * framework's own Application model already uses.
     */
    public function testAModelMayAddItsOwnOptionalParameters(): void
    {
        // Arrange
        $reflection = new \ReflectionMethod(\Pramnos\Auth\Application::class, 'load');

        // Act + Assert
        $this->assertGreaterThan(
            1,
            $reflection->getNumberOfParameters(),
            'this model loads by id plus options, and must keep being able to'
        );
        $this->assertSame(1, $reflection->getNumberOfRequiredParameters());
    }
}
