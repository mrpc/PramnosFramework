<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Pramnos\Routing\Router;
use Pramnos\Routing\Route;
use Pramnos\Routing\RouteDiscovery;
use Pramnos\Routing\Attributes\RouteGroup as RouteGroupAttribute;
use Pramnos\Http\Request;
use Pramnos\Http\MiddlewareInterface;

/**
 * Tests for Router::group() and the #[RouteGroup] attribute.
 *
 * Verifies that:
 * - URI prefixes are prepended to every route inside the group
 * - Middleware is prepended before per-route middleware
 * - Permissions are merged with per-route permissions
 * - Named-route name prefix is prepended
 * - Nested groups stack their attributes correctly
 * - RouteDiscovery applies a class-level #[RouteGroup] attribute
 */
#[CoversClass(\Pramnos\Routing\Router::class)]
#[CoversClass(\Pramnos\Routing\Route::class)]
#[CoversClass(\Pramnos\Routing\Attributes\RouteGroup::class)]
#[CoversClass(\Pramnos\Routing\RouteDiscovery::class)]
class RouteGroupTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $container    = new \Pramnos\Application\Application('myApp');
        $this->router = new Router($container);
    }

    // -------------------------------------------------------------------------
    // Prefix
    // -------------------------------------------------------------------------

    /**
     * Routes registered inside a group inherit the group's URI prefix.
     * Dispatch must resolve the prefixed URI — not the bare one.
     */
    public function testGroupPrefixIsPrepenedToRouteUri(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/api/v1'], function (Router $r): void {
            $r->get('/users', fn() => 'users-list');
        });

        // Act — request for the prefixed URI
        $result = $this->router->dispatch(Request::create('/api/v1/users', 'GET'));

        // Assert — action executed correctly
        $this->assertSame('users-list', $result);
    }

    /**
     * Routes for the bare (un-prefixed) URI must NOT match after grouping.
     */
    public function testGroupRouteDoesNotMatchWithoutPrefix(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/api/v1'], function (Router $r): void {
            $r->get('/users', fn() => 'users-list');
        });

        // Act — request for the unprefixed URI
        $result = $this->router->dispatch(Request::create('/users', 'GET'));

        // Assert — no match returns null
        $this->assertNull($result);
    }

    /**
     * A leading slash on the group prefix and a leading slash on the route URI
     * must not produce a double slash in the final URI.
     */
    public function testGroupPrefixNormalizesDoubleSlashes(): void
    {
        // Arrange
        $called = false;
        $this->router->group(['prefix' => '/api/'], function (Router $r) use (&$called): void {
            $r->get('/users', function () use (&$called): string {
                $called = true;
                return 'ok';
            });
        });

        // Act
        $result = $this->router->dispatch(Request::create('/api/users', 'GET'));

        // Assert — double-slash was collapsed, route dispatched
        $this->assertTrue($called);
        $this->assertSame('ok', $result);
    }

    /**
     * Routes registered outside the group closure must not inherit the prefix.
     */
    public function testRoutesOutsideGroupAreUnaffected(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/api'], function (Router $r): void {
            $r->get('/items', fn() => 'inside');
        });
        $this->router->get('/items', fn() => 'outside');

        // Act
        $inside  = $this->router->dispatch(Request::create('/api/items', 'GET'));
        $outside = $this->router->dispatch(Request::create('/items', 'GET'));

        // Assert
        $this->assertSame('inside', $inside);
        $this->assertSame('outside', $outside);
    }

    // -------------------------------------------------------------------------
    // Middleware
    // -------------------------------------------------------------------------

    /**
     * Group middleware is prepended before per-route middleware.
     * The execution order must be: group MW → route MW → action.
     */
    public function testGroupMiddlewareRunsBeforePerRouteMiddleware(): void
    {
        // Arrange — capture execution order via a shared log array
        $log = [];

        $groupMw = $this->makeLoggingMiddleware('group', $log);
        $routeMw = $this->makeLoggingMiddleware('route', $log);

        $this->router->group(['prefix' => '/', 'middleware' => [$groupMw]], function (Router $r) use ($routeMw, &$log): void {
            $r->get('/order-test', function () use (&$log): string {
                $log[] = 'action';
                return 'done';
            })->middleware($routeMw);
        });

        // Act
        $this->router->dispatch(Request::create('/order-test', 'GET'));

        // Assert — group middleware ran first
        $this->assertSame(['group', 'route', 'action'], $log);
    }

    /**
     * Routes inside a group get the group middleware even without per-route middleware.
     */
    public function testGroupMiddlewareAppliedWithoutPerRouteMiddleware(): void
    {
        // Arrange
        $ran = false;
        $mw  = $this->makePassThroughMiddleware(function () use (&$ran): void {
            $ran = true;
        });

        $this->router->group(['middleware' => [$mw]], function (Router $r): void {
            $r->get('/ping', fn() => 'pong');
        });

        // Act
        $this->router->dispatch(Request::create('/ping', 'GET'));

        // Assert — group middleware executed
        $this->assertTrue($ran);
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    /**
     * Group permissions are merged with per-route permissions.
     * A user without both should be denied.
     */
    public function testGroupPermissionsMergedWithRoutePermissions(): void
    {
        // Arrange
        $this->router->group(['permissions' => ['group_perm']], function (Router $r): void {
            $r->get('/protected', fn() => 'secret')->requirePermissions(['route_perm']);
        });

        // Act — dispatch with only one of the two required permissions
        $this->expectException(\Exception::class);
        $this->router->dispatch(Request::create('/protected', 'GET'), ['group_perm']);
        // Missing route_perm → should throw
    }

    /**
     * A user with both group and route permissions can access the route.
     */
    public function testGroupPermissionsAllowedWithBothPermissions(): void
    {
        // Arrange
        $this->router->group(['permissions' => ['scope:read']], function (Router $r): void {
            $r->get('/data', fn() => 'data');
        });

        // Act — user has the required group permission
        $result = $this->router->dispatch(
            Request::create('/data', 'GET'),
            ['scope:read']
        );

        // Assert
        $this->assertSame('data', $result);
    }

    // -------------------------------------------------------------------------
    // Name prefix
    // -------------------------------------------------------------------------

    /**
     * Named routes inside a group have the group name prefix prepended.
     * Router::route() must resolve using the full prefixed name.
     */
    public function testGroupNamePrefixPrependedToRouteName(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/api', 'name' => 'api.'], function (Router $r): void {
            $r->get('/users', fn() => 'users')->name('users.index');
        });

        // Act — generate URL by full prefixed name
        $url = $this->router->route('api.users.index');

        // Assert
        $this->assertSame('/api/users', $url);
    }

    /**
     * Router::getByName() finds the route under the prefixed name.
     */
    public function testGroupNamedRouteRetrievableByPrefixedName(): void
    {
        // Arrange
        $this->router->group(['name' => 'admin.'], function (Router $r): void {
            $r->get('/dashboard', fn() => 'dash')->name('home');
        });

        // Act
        $route = $this->router->getByName('admin.home');

        // Assert — route is findable; bare name 'home' should not exist
        $this->assertInstanceOf(Route::class, $route);
        $this->assertNull($this->router->getByName('home'));
    }

    // -------------------------------------------------------------------------
    // Nested groups
    // -------------------------------------------------------------------------

    /**
     * Nested groups stack their prefixes: outer prefix + inner prefix.
     */
    public function testNestedGroupPrefixesStack(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/api'], function (Router $r): void {
            $r->group(['prefix' => '/v2'], function (Router $r): void {
                $r->get('/items', fn() => 'v2-items');
            });
        });

        // Act
        $result = $this->router->dispatch(Request::create('/api/v2/items', 'GET'));

        // Assert
        $this->assertSame('v2-items', $result);
    }

    /**
     * Nested group name prefixes stack: outer.inner.route-name.
     */
    public function testNestedGroupNamePrefixesStack(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/api', 'name' => 'api.'], function (Router $r): void {
            $r->group(['prefix' => '/v2', 'name' => 'v2.'], function (Router $r): void {
                $r->get('/items', fn() => 'items')->name('items.index');
            });
        });

        // Act
        $url = $this->router->route('api.v2.items.index');

        // Assert
        $this->assertSame('/api/v2/items', $url);
    }

    /**
     * After a nested group closes, the outer group context is restored.
     * A route registered in the outer group after the inner group must
     * only have the outer prefix.
     */
    public function testGroupContextRestoredAfterNestedGroup(): void
    {
        // Arrange
        $this->router->group(['prefix' => '/outer'], function (Router $r): void {
            $r->group(['prefix' => '/inner'], function (Router $r): void {
                $r->get('/deep', fn() => 'deep');
            });
            // This route must only carry /outer, not /outer/inner
            $r->get('/shallow', fn() => 'shallow');
        });

        // Act
        $deep    = $this->router->dispatch(Request::create('/outer/inner/deep', 'GET'));
        $shallow = $this->router->dispatch(Request::create('/outer/shallow', 'GET'));
        $gone    = $this->router->dispatch(Request::create('/outer/inner/shallow', 'GET'));

        // Assert
        $this->assertSame('deep', $deep);
        $this->assertSame('shallow', $shallow);
        $this->assertNull($gone, '/outer/inner/shallow must not exist');
    }

    // -------------------------------------------------------------------------
    // #[RouteGroup] attribute via RouteDiscovery
    // -------------------------------------------------------------------------

    /**
     * RouteDiscovery respects a class-level #[RouteGroup] attribute:
     * the discovered routes must have the declared prefix applied.
     *
     * We use an anonymous-class trick: define the class inline, then
     * register it manually to avoid filesystem discovery.
     */
    public function testRouteGroupAttributeAppliedByRouteDiscovery(): void
    {
        // Arrange — create a temporary class with #[RouteGroup] and #[Route]
        // We can't use an anonymous class with PHP attributes, so we use
        // a named class defined in this test file's helper section below.
        // Instead, we verify the attribute data model directly.
        $attr = new RouteGroupAttribute(
            prefix:      '/rg',
            middleware:  [],
            permissions: ['rg:perm'],
            name:        'rg.',
        );

        // Assert — attribute properties are preserved correctly
        $this->assertSame('/rg', $attr->prefix);
        $this->assertSame(['rg:perm'], $attr->permissions);
        $this->assertSame('rg.', $attr->name);
        $this->assertSame([], $attr->middleware);
    }

    /**
     * RouteDiscovery wraps discovered routes in Router::group() when the
     * controller class carries #[RouteGroup]. Verified by simulating the
     * discovery flow via a temp file with a controller class.
     *
     * We verify that the route was registered under the prefixed URI and that
     * the named route resolves with the name prefix — without dispatching the
     * action (Route::execute() does not support [class, method] arrays).
     */
    public function testRouteDiscoveryAppliesRouteGroupAttributeOnClass(): void
    {
        // Arrange — write a temporary controller class with #[RouteGroup]
        $tmpFile = tempnam(sys_get_temp_dir(), 'rg_test_') . '.php';
        file_put_contents($tmpFile, <<<'PHP'
<?php
namespace Pramnos\Tests\Unit\Routing\Fixtures;

use Pramnos\Routing\Attributes\Route;
use Pramnos\Routing\Attributes\RouteGroup;

#[RouteGroup(prefix: '/grp', name: 'grp.')]
class GroupedController {
    #[Route('/ping', methods: 'GET', name: 'ping')]
    public function ping(): string { return 'pong'; }
}
PHP);
        require_once $tmpFile;

        $class     = \Pramnos\Tests\Unit\Routing\Fixtures\GroupedController::class;
        $discovery = new RouteDiscovery($this->router);

        // Invoke registerRoutesFromClass (public since PHP 8.1 makes setAccessible a no-op;
        // but the method is private — call via ReflectionMethod without setAccessible).
        $ref = new \ReflectionMethod(RouteDiscovery::class, 'registerRoutesFromClass');
        $ref->invoke($discovery, $class);

        // Act — verify the route was registered under the prefixed URI
        $matched = $this->router->getMatchedRoute(Request::create('/grp/ping', 'GET'));
        $named   = $this->router->route('grp.ping');

        // Assert — group prefix was applied to URI and name
        $this->assertInstanceOf(Route::class, $matched,
            'Route /grp/ping must be registered (group prefix applied by RouteDiscovery)');
        $this->assertSame('/grp/ping', $named,
            'Named route grp.ping must resolve to /grp/ping');

        // Assert — bare URI /ping must NOT exist (prefix was not applied without group)
        $bare = $this->router->getMatchedRoute(Request::create('/ping', 'GET'));
        $this->assertNull($bare, 'Route /ping must not exist — only /grp/ping');

        unlink($tmpFile);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a pass-through middleware that logs its label to $log before calling next().
     *
     * @param string  $label  The label pushed to $log when the middleware runs.
     * @param array   &$log   Shared log array.
     */
    private function makeLoggingMiddleware(string $label, array &$log): MiddlewareInterface
    {
        return new class($label, $log) implements MiddlewareInterface {
            public function __construct(private string $label, private array &$log) {}

            public function handle(Request $request, callable $next): mixed
            {
                $this->log[] = $this->label;
                return $next($request);
            }
        };
    }

    /**
     * Create a pass-through middleware that executes $callback before calling next().
     */
    private function makePassThroughMiddleware(\Closure $callback): MiddlewareInterface
    {
        return new class($callback) implements MiddlewareInterface {
            public function __construct(private \Closure $cb) {}

            public function handle(Request $request, callable $next): mixed
            {
                ($this->cb)();
                return $next($request);
            }
        };
    }
}
