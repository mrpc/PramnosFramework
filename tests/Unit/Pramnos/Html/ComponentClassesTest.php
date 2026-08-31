<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Html\Breadcrumb;
use Pramnos\Html\ComponentClasses;
use Pramnos\Html\Pagination;

/**
 * Class names declared once, for the case a stylesheet cannot answer.
 *
 * The components emit neutral `pf-*` hooks and each theme's stylesheet dresses them; that
 * arrangement needs no PHP and is the right answer to "make this look different". This is for the
 * other case — markup that must carry a **specific** name because something other than CSS is
 * looking for it. A jQuery plugin doing `$('.breadcrumb')` reads the name.
 *
 * The reason it is configuration rather than the per-object property that already existed: a
 * `Breadcrumb` is constructed in eight places in a scaffolded project, and two of them are inside
 * this framework where an application cannot reach them. A property covers six.
 */
#[CoversClass(ComponentClasses::class)]
#[CoversClass(Breadcrumb::class)]
#[CoversClass(Pagination::class)]
class ComponentClassesTest extends TestCase
{
    private mixed $previous = null;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->previous = $app->applicationInfo['component_classes'] ?? null;
        unset($app->applicationInfo['component_classes']);
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        if ($this->previous === null) {
            unset($app->applicationInfo['component_classes']);
        } else {
            $app->applicationInfo['component_classes'] = $this->previous;
        }
    }

    private function declare(array $classes): void
    {
        Application::getInstance()->applicationInfo['component_classes'] = $classes;
    }

    /**
     * With nothing declared, the framework's own hooks.
     */
    public function testTheDefaultsAreTheHooks(): void
    {
        // Assert
        $this->assertSame('pf-breadcrumb', ComponentClasses::get('breadcrumb'));
        $this->assertSame('pf-pagination', ComponentClasses::get('pagination'));
        $this->assertSame('current', ComponentClasses::get('pagination.current'));
    }

    /**
     * A declared name reaches a component constructed anywhere — including by the framework.
     *
     * The whole point. The two sites an application cannot reach are `Document`'s breadcrumb and
     * `Application`'s, and they render on every page; a per-object property never touched them.
     */
    public function testADeclaredNameReachesTheComponent(): void
    {
        // Arrange
        $this->declare(['breadcrumb' => 'breadcrumb', 'pagination' => 'pagination']);

        // Act
        $bc = new Breadcrumb();
        $bc->addItem('Home', '/');

        // Assert
        $this->assertStringContainsString('class="breadcrumb"', $bc->render());
        $this->assertStringContainsString(
            'class="pagination"',
            (new Pagination(3, 1, '/x/:page'))->render()
        );
    }

    /**
     * A caller who sets the property afterwards still wins.
     *
     * Configuration decides the default, not the outcome: it is a statement about the project, and
     * the property is a statement about one object.
     */
    public function testThePropertyStillOverridesTheConfiguration(): void
    {
        // Arrange
        $this->declare(['breadcrumb' => 'breadcrumb']);

        $bc = new Breadcrumb();
        $bc->listClass = 'something-else';
        $bc->addItem('Home', '/');

        // Assert
        $this->assertStringContainsString('class="something-else"', $bc->render());
    }

    /**
     * An empty string is a decision, not an absence.
     *
     * A project that wants no class at all says so, and gets `class=""` rather than the hook — the
     * opposite of the absent-is-not-empty rule, because here the caller *did* speak.
     */
    public function testAnEmptyStringMeansNoClass(): void
    {
        // Arrange
        $this->declare(['pagination' => '']);

        // Assert
        $this->assertSame('', ComponentClasses::get('pagination'));
    }

    /**
     * An unread key is reported rather than ignored.
     *
     * A misspelled key is silent otherwise, and silence is indistinguishable from a feature that
     * does not work — which is what somebody will conclude.
     */
    public function testAMisspelledKeyIsReportable(): void
    {
        // Arrange
        $this->declare(['breadcrum' => 'breadcrumb', 'pagination' => 'pagination']);

        // Assert
        $this->assertSame(['breadcrum'], ComponentClasses::unknownKeys());
    }

    /**
     * Every key it advertises has a default.
     *
     * A key in `KEYS` with no default would resolve to `''`, so a project that did not configure
     * it would silently lose the class it never asked to lose.
     */
    public function testEveryAdvertisedKeyHasADefault(): void
    {
        foreach (ComponentClasses::KEYS as $key => $default) {
            // Assert
            $this->assertNotSame('', $default, $key . ' advertises no default');
            $this->assertSame($default, ComponentClasses::get($key));
        }
    }

    /**
     * A scaffolded project is shown the key exists.
     *
     * Commented out, because the answer is almost always CSS — but present, so somebody finds it
     * by reading their own config rather than this framework's source.
     */
    public function testAScaffoldedProjectIsShownTheKey(): void
    {
        // Arrange
        $init = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Console/Commands/Init.php'
        );

        // Assert
        $this->assertStringContainsString("'component_classes' => [", $init);
    }
}
