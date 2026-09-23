<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Http\Request;

/**
 * A middleware must be able to decide what a request **is**, not only what happens around it.
 *
 * `init()` copies the controller and action off the URL; the middleware pipeline runs after
 * it, wrapping `exec()`. So a middleware calling
 * `$request->setController('bio')->setAction('geekdom')` was writing to an object nothing
 * read again, and `exec()` dispatched whatever the path had said. Deciding the route from
 * something other than the path — a `Host` header, a locale prefix, an A/B split — is close
 * to the whole reason to have a pipeline.
 *
 * **And the failure was silent in the worst way.** `Request::$_controller` is static, so the
 * setter succeeded and `getController()` handed the new value straight back: a test
 * asserting the middleware's decision passed. The first thing that ever disagreed was a
 * production URL serving the wrong page — which is why these tests assert what the
 * *application* dispatches, never what the request reports.
 *
 * `routeAtInit` is set by `init()`, which reaches a database and a session. It is written
 * here directly, which is exactly what `init()` would have left behind for the URL in each
 * case.
 */
#[CoversClass(Application::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ApplicationLateRouteTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', realpath(__DIR__ . '/../../../../'));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', ROOT . DS . 'app');
        }
        if (!defined('sURL')) {
            define('sURL', 'http://route.test/');
        }

        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        Request::resetInstance();
    }

    /**
     * An application with everything that reaches outside a request stubbed out.
     *
     * @param string $controller What the URL said, as `init()` read it
     * @param string $action     Likewise
     */
    private function application(string $controller, string $action): Application
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'sendCspHeader',
                'checkversion',
                'runAutoMigrations',
                'getController',
                'addbreadcrumb',
            ])
            ->getMock();

        $app->method('checkversion')->willReturn(true);
        $app->method('sendCspHeader')->willReturn(null);
        $app->method('addbreadcrumb')->willReturn($app);

        $controllerMock = $this->getMockBuilder(\Pramnos\Application\Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controllerMock->method('exec')->willReturn('');
        $app->method('getController')->willReturn($controllerMock);

        $app->applicationInfo = [];
        $app->controller      = $controller;
        $app->action          = $action;

        // What `init()` records once it has read the URL.
        (new \ReflectionProperty(Application::class, 'routeAtInit'))
            ->setValue($app, [$controller, $action]);

        return $app;
    }

    /**
     * The whole finding: a middleware's decision is the one that gets dispatched.
     */
    public function testAMiddlewaresDecisionIsWhatRuns(): void
    {
        // Arrange — the URL said /home, as init() read it
        $app = $this->application('home', 'display');

        // Act — a middleware decides otherwise, then the pipeline calls exec()
        Request::getInstance()->setController('bio')->setAction('geekdom');
        $app->exec();

        // Assert — on the application, not on the request, which always agreed with itself
        $this->assertSame('bio', $app->controller, 'the middleware decided and nothing listened');
        $this->assertSame('geekdom', $app->action);
    }

    /**
     * A route nobody touched is dispatched exactly as before.
     *
     * The assignment is its own no-op on the overwhelming majority of requests — the value
     * read at dispatch is the one `init()` already read — and this is what says so.
     */
    public function testAnUntouchedRouteIsUnchanged(): void
    {
        // Arrange
        $app = $this->application('articles', 'view');
        Request::getInstance()->setController('articles')->setAction('view');

        // Act
        $app->exec();

        // Assert
        $this->assertSame('articles', $app->controller);
        $this->assertSame('view', $app->action);
    }

    /**
     * An application that assigned the route by hand keeps it.
     *
     * `$app->controller = 'x'` between `init()` and `exec()` says something the URL did not,
     * and it must outrank a request that still reports the path. Without this guard the
     * change would silently undo a pattern applications are entitled to use.
     */
    public function testADirectAssignmentOutranksTheUrl(): void
    {
        // Arrange — init() read /home; the application then said otherwise
        $app = $this->application('home', 'display');
        $app->controller = 'dashboard';
        $app->action     = 'summary';

        // Act — the request still reports what the URL said
        Request::getInstance()->setController('home')->setAction('display');
        $app->exec();

        // Assert
        $this->assertSame('dashboard', $app->controller, 'a hand-assigned route was overwritten');
        $this->assertSame('summary', $app->action);
    }

    /**
     * An explicit `exec('name')` argument is untouched.
     *
     * The most explicit statement available, and it is how `exec()` is called from a test,
     * from `Api`, and from an application dispatching a controller on purpose.
     */
    public function testAnExplicitArgumentWins(): void
    {
        // Arrange
        $app = $this->application('home', 'display');
        Request::getInstance()->setController('bio')->setAction('geekdom');

        // Act
        $app->exec('reports');

        // Assert
        $this->assertSame('reports', $app->controller);
    }

    /**
     * An application whose `init()` never ran is left alone.
     *
     * `routeAtInit` is null until `init()` records it, and null means "nothing here has read
     * a URL" — a console command constructing an Application, for instance. Reading the
     * request then would invent a route out of whatever the process last parsed.
     */
    public function testWithoutInitNothingIsAdopted(): void
    {
        // Arrange — as above but with no record of a URL ever having been read
        $app = $this->application('home', 'display');
        (new \ReflectionProperty(Application::class, 'routeAtInit'))->setValue($app, null);

        // Act
        Request::getInstance()->setController('bio')->setAction('geekdom');
        $app->exec();

        // Assert
        $this->assertSame('home', $app->controller);
    }
}
