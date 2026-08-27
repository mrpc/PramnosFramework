<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Http\AdminArea;

/**
 * The administration area has its own controllers and its own views.
 *
 * `src/Admin/Controllers/` and `src/Admin/Views/`, the counterparts of `src/Api/`,
 * resolved before the site's own — and only for a request inside the area.
 *
 * The point is not tidiness. While every admin screen lived in `src/Controllers/`, each
 * one answered on two addresses: `/admin/Users` inside the area, and `/Users` outside
 * it — the same page, in the public theme, with no sidebar and outside the area's
 * usertype floor. Reported as a link: `/Logs/viewer` opened the public site. Once the
 * controller lives under `src/Admin/`, the bare path finds nothing.
 *
 * Both directions are asserted, because "the area finds its own controller" alone would
 * pass on a framework that always looks there — which would make every project's
 * `src/Controllers` unreachable.
 */
class AdminAreaNamespaceTest extends TestCase
{
    protected function setUp(): void
    {
        AdminArea::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        AdminArea::reset();
        $_GET = [];
    }

    /**
     * Inside the area, the area's own controller answers.
     */
    public function testTheAreasOwnControllerIsPreferred(): void
    {
        // Arrange
        $app = $this->application('admin/Widgets');

        // Act
        $controller = $app->getController('Widgets');

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Tests\Fixtures\AreaApp\Admin\Controllers\Widgets::class,
            $controller,
            'a request inside /admin must reach Admin\\Controllers\\Widgets'
        );
    }

    /**
     * A screen the area does not own still comes from the site.
     *
     * An area holds the screens that belong to it, not a copy of the application. A
     * project whose `Home` is shared must still reach it from inside `/admin` —
     * otherwise mounting an area means duplicating every controller.
     */
    public function testAControllerTheAreaDoesNotOwnFallsBackToTheSite(): void
    {
        // Arrange
        $app = $this->application('admin/Shared');

        // Act
        $controller = $app->getController('Shared');

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Tests\Fixtures\AreaApp\Controllers\Shared::class,
            $controller
        );
    }

    /**
     * Outside the area, the area's controllers are not in scope at all.
     *
     * This is the half that closes the second front door: `/Widgets` must not find
     * `Admin\Controllers\Widgets`.
     */
    public function testTheAreasControllersAreUnreachableFromOutside(): void
    {
        // Arrange — a request that is not under the prefix
        $app = $this->application('Widgets');
        $this->assertFalse(AdminArea::isActive());
        $this->assertSame('', $app->area);

        // Act & Assert
        $this->expectException(\Exception::class);
        $app->getController('Widgets');
    }

    /**
     * The area is per-request, like the theme it selects.
     *
     * A process that handles more than one request — a test client, a worker, a
     * long-running server — must not leave the area's controllers in scope for the
     * public page after an admin one. That exact bug already happened once with the
     * theme, which is why `beginRequest()` exists.
     */
    public function testTheAreaIsForgottenBetweenRequests(): void
    {
        // Arrange
        $app = $this->application('admin/Widgets');
        $this->assertSame('Admin', $app->area);

        // Act — the next request is a public one
        $_GET['r'] = 'Shared';
        $app->beginRequest();

        // Assert
        $this->assertSame('', $app->area);
    }

    /**
     * An application whose config names no area gets the conventional one.
     *
     * `Admin` is what `pramnos init` writes and what the guides describe, so a project
     * that mounted an area before this existed picks the directory up by creating it.
     */
    public function testTheDirectoryNameDefaultsToAdmin(): void
    {
        // Arrange & Act
        $app = $this->application('admin/Widgets');

        // Assert
        $this->assertSame('Admin', $app->area);
    }

    /**
     * An application configured for the area's fixture namespace.
     *
     * Built by hand rather than through `getInstance()`: the factory reads `app.php`,
     * defines constants and constructs a database, and none of that is part of the
     * question here.
     */
    private function application(string $route): Application
    {
        $_GET['r'] = $route;

        $app = new class () extends Application {
            public function __construct()
            {
                // Deliberately not parent::__construct(): it boots an application.
                $this->applicationInfo = [
                    'namespace' => 'Pramnos\\Tests\\Fixtures\\AreaApp',
                    'admin'     => ['prefix' => 'admin', 'min_usertype' => 80],
                ];
                $this->beginRequest();
            }
        };

        return $app;
    }
}
