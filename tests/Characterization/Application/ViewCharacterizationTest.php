<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\View;

/**
 * Characterization tests for View model management and type handling.
 *
 * Tests lock the addModel/getModel registry, default-model tracking,
 * getType contract, and the construction parameter binding.
 * No filesystem or database access is required.
 */
#[CoversClass(View::class)]
class ViewCharacterizationTest extends TestCase
{
    private Controller $ctrl;

    protected function setUp(): void
    {
        // Arrange – minimal dependency graph
        Application::getInstance();
        $this->ctrl = new Controller(null);
    }

    // -----------------------------------------------------------------------
    // Construction
    // -----------------------------------------------------------------------

    /**
     * Constructor stores the controller reference, path, name, and type as
     * accessible properties / via getType().
     */
    public function testConstructorBindsParameters(): void
    {
        // Act
        $view = new View($this->ctrl, '/some/path', 'articles', 'json');

        // Assert
        $this->assertSame($this->ctrl, $view->controller);
        $this->assertSame('json', $view->getType());
    }

    /**
     * Default view type is 'html' when the $type argument is omitted.
     */
    public function testDefaultTypeIsHtml(): void
    {
        // Act
        $view = new View($this->ctrl);

        // Assert
        $this->assertSame('html', $view->getType());
    }

    // -----------------------------------------------------------------------
    // Model registry — addModel / getModel
    // -----------------------------------------------------------------------

    /**
     * getModel returns false when no model has been added.
     */
    public function testGetModelReturnsFalseWhenEmpty(): void
    {
        // Arrange
        $view = new View($this->ctrl);

        // Act
        $model = $view->getModel('anything');

        // Assert
        $this->assertFalse($model);
    }

    /**
     * addModel stores the model and getModel retrieves it by name.
     */
    public function testAddModelAndGetModelByName(): void
    {
        // Arrange
        $view  = new View($this->ctrl);
        $model = $this->makeModel('articles');

        // Act
        $view->addModel($model);

        // Assert
        $retrieved = $view->getModel('articles');
        $this->assertSame($model, $retrieved);
    }

    /**
     * The first added model becomes the default model (accessible via
     * getModel() with no argument).
     */
    public function testFirstAddedModelBecomesDefault(): void
    {
        // Arrange
        $view   = new View($this->ctrl);
        $modelA = $this->makeModel('posts');

        // Act
        $view->addModel($modelA);

        // Assert – no argument resolves to the default
        $default = $view->getModel();
        $this->assertSame($modelA, $default);
    }

    /**
     * Adding a second model with $default=true replaces the default model
     * reference.
     */
    public function testSecondDefaultModelOverridesDefault(): void
    {
        // Arrange
        $view   = new View($this->ctrl);
        $modelA = $this->makeModel('posts');
        $modelB = $this->makeModel('comments');
        $view->addModel($modelA);

        // Act
        $view->addModel($modelB, true); // explicit default

        // Assert
        $default = $view->getModel();
        $this->assertSame($modelB, $default);
    }

    /**
     * Adding a model with $default=false does NOT change the current default.
     */
    public function testAddModelWithDefaultFalseDoesNotChangeDefault(): void
    {
        // Arrange
        $view   = new View($this->ctrl);
        $modelA = $this->makeModel('posts');
        $modelB = $this->makeModel('tags');
        $view->addModel($modelA);

        // Act
        $view->addModel($modelB, false);

        // Assert – default is still modelA
        $this->assertSame($modelA, $view->getModel());
        // But modelB is still accessible by name
        $this->assertSame($modelB, $view->getModel('tags'));
    }

    /**
     * Multiple models can be registered and each is independently retrievable.
     */
    public function testMultipleModelsAreIndependentlyRetrievable(): void
    {
        // Arrange
        $view   = new View($this->ctrl);
        $modelA = $this->makeModel('alpha');
        $modelB = $this->makeModel('beta');
        $modelC = $this->makeModel('gamma');

        // Act
        $view->addModel($modelA);
        $view->addModel($modelB, false);
        $view->addModel($modelC, false);

        // Assert
        $this->assertSame($modelA, $view->getModel('alpha'));
        $this->assertSame($modelB, $view->getModel('beta'));
        $this->assertSame($modelC, $view->getModel('gamma'));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create a minimal anonymous Model stub with the given name.
     * Bypasses the real Model constructor to avoid Database::getInstance()
     * triggering DB warnings, and sets $model->name via the Base magic setter
     * so that View::addModel() can index the model correctly.
     */
    private function makeModel(string $name): Model
    {
        $ctrl = $this->ctrl;
        $model = new class($ctrl, $name) extends Model {
            /**
             * Skip parent constructor (avoids Database::getInstance()).
             * Sets the minimum state needed for View to register the model.
             */
            public function __construct($controller, string $modelName)
            {
                $this->controller  = $controller;
                $this->modelname   = $modelName;
                // Set $model->name via Base::__set → _data['name']
                // so View::addModel() can index by $model->name
                $this->name = $modelName;
                // Do NOT call parent::__construct()
            }
        };
        return $model;
    }
}
