<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Http\AdminArea;

/**
 * Inside an area, `src/Admin/Views/` answers first — and the site's views still answer.
 *
 * First, not instead. An area holds the screens that belong to it; everything it shares
 * with the site — a partial, a form, an error page — is still found where it lives. An
 * area that overrides one view must not have to copy the other thirty, or the next
 * fix to a shared view reaches one of the two copies.
 */
class AreaViewResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        AdminArea::reset();
    }

    protected function tearDown(): void
    {
        AdminArea::reset();
    }

    /**
     * A view the area has its own copy of comes from the area.
     */
    public function testTheAreasOwnViewWins(): void
    {
        // Arrange
        $controller = $this->controller('Admin');

        // Act
        $view = $controller->getView('widgets', 'html');

        // Assert
        $this->assertStringContainsString(
            'Admin' . DIRECTORY_SEPARATOR . 'Views',
            $this->pathOf($view),
            'the area has its own copy of this view'
        );
    }

    /**
     * A view the area does not have comes from the site's own directory.
     */
    public function testASharedViewStillResolves(): void
    {
        // Arrange
        $controller = $this->controller('Admin');

        // Act
        $view = $controller->getView('shared', 'html');

        // Assert
        $path = $this->pathOf($view);
        $this->assertStringContainsString('shared', $path);
        $this->assertStringNotContainsString('Admin' . DIRECTORY_SEPARATOR . 'Views', $path);
    }

    /**
     * Outside an area, the area's views are not consulted.
     */
    public function testTheSiteViewIsUsedWhenNoAreaIsActive(): void
    {
        // Arrange
        $controller = $this->controller('');

        // Act
        $view = $controller->getView('widgets', 'html');

        // Assert
        $this->assertStringNotContainsString(
            'Admin' . DIRECTORY_SEPARATOR . 'Views',
            $this->pathOf($view)
        );
    }

    /**
     * A controller bound to the fixture application, in or out of an area.
     *
     * `applicationsBasePath()` is overridden rather than `APPS_PATH` defined: a
     * constant is process-wide, and a suite is one process.
     */
    private function controller(string $area): Controller
    {
        $app = new class () extends Application {
            public function __construct()
            {
                $this->applicationInfo = [
                    'namespace' => 'Pramnos\\Tests\\Fixtures\\AreaApp',
                ];
            }
        };
        $app->area = $area;

        return new class ($app) extends Controller {
            protected static function applicationsBasePath()
            {
                return dirname(__DIR__, 2) . '/Fixtures/AreaApp';
            }
        };
    }

    /** The directory a resolved view is bound to. */
    private function pathOf(object $view): string
    {
        $reflection = new \ReflectionObject($view);
        foreach (['path', '_path', 'viewPath'] as $name) {
            if ($reflection->hasProperty($name)) {
                return (string) $reflection->getProperty($name)->getValue($view);
            }
        }

        $this->fail('the View does not expose the directory it resolved to');
    }
}
