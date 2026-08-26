<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\View;

/**
 * A missing template with `?format=json` must not take the page down.
 *
 * `View::getTpl()` falls into an `else` branch when it cannot find a template, and that
 * branch exists to **recover**: a few lines further on it logs *"Cannot find view
 * template"*. On the way there it offered the view's model a chance to answer with JSON:
 *
 * ```php
 * if (isset($this->model)) {
 *     if (method_exists($this->model, 'getJsonList')) {
 * ```
 *
 * `$model` is declared `public $model = false`, and `isset()` answers *not null* rather
 * than *not empty* — so the guard passed for every view that has no model, and
 * `method_exists(false, …)` is a `TypeError` on PHP 8. The recovery path was the crash.
 *
 * Reported as FW-021 from a consuming application's **home page**, with the stack trace
 * out of its `php_error.log` — not a theoretical reading.
 *
 * The guard is `is_object()` now. The default stays `false` rather than becoming `null`:
 * `isset()` would then work, but anything comparing `=== false` would change meaning, and
 * the question being asked is "have I got an object" either way.
 */
#[CoversClass(View::class)]
class ViewMissingTemplateJsonTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $get = [];

    protected function setUp(): void
    {
        $this->get = $_GET;
        $_GET = ['format' => 'json'];
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_GET = $this->get;
        \Pramnos\Http\Request::resetInstance();
    }

    /**
     * A view whose model is the default `false`, asked for a template that is not there.
     *
     * The regression test proper. Before the fix this raised
     * `method_exists(): Argument #1 ($object_or_class) must be of type object|string,
     * false given` — a fatal, from the branch handling a missing template.
     *
     * Asserted as "does not throw", because that is exactly what was wrong: there is no
     * wrong *value* here to check, only a process that stayed alive or did not.
     */
    public function testAViewWithNoModelDoesNotFatal(): void
    {
        // Arrange — the shipped default, and a template name nothing can resolve.
        $view = $this->view(false);

        // Act
        $result = $view->getTpl('a-template-that-does-not-exist');

        // Assert — it came back at all, which is the whole assertion.
        $this->assertFalse($result, 'a missing template reports failure, it does not fatal');
    }

    /**
     * A model that can answer with JSON still gets the chance to.
     *
     * The fix must not become "skip the branch": a view *with* a model that exposes
     * `getJsonList()` is the case the branch was written for, and it is the only reason
     * the guard is there rather than nothing at all.
     */
    public function testAModelThatCanAnswerWithJsonStillDoes(): void
    {
        // Arrange
        $model = new class {
            public function getJsonList(): string
            {
                return '{"answered":true}';
            }
        };
        $view = $this->view($model);

        // Act
        $result = $view->getTpl('a-template-that-does-not-exist');

        // Assert
        $this->assertTrue($result);
        $this->assertSame('{"answered":true}', $view->output);
    }

    /**
     * A model without `getJsonList()` falls through without complaint.
     *
     * `method_exists()` is the second half of the guard and it has to keep working: an
     * object is a valid argument, so this reaches the check and answers false — which is
     * the ordinary case for any view whose model is a plain model.
     */
    public function testAModelWithoutTheMethodFallsThrough(): void
    {
        // Arrange
        $view = $this->view(new \stdClass());

        // Act
        $result = $view->getTpl('a-template-that-does-not-exist');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * A view stubbed past its framework dependencies.
     *
     * `View::__construct()` wants a Controller and a booted application; none of that is
     * involved in the branch under test, which needs only a name, a type and a model.
     *
     * @param mixed $model What `$this->model` holds — `false` is the shipped default
     */
    private function view(mixed $model): View
    {
        $view = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();

        $view->name           = 'testview';
        $view->type           = 'html';
        $view->model          = $model;
        $view->controllerName = 'Test';
        $view->output         = '';

        return $view;
    }
}
