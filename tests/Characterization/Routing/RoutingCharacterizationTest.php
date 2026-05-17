<?php

declare(strict_types=1);

namespace PramnosTest\Characterization\Routing;

use PHPUnit\Framework\TestCase;
use Pramnos\Routing\Attributes\Route as RouteAttr;
use Pramnos\Routing\Route;
use Pramnos\Routing\RouteDiscovery;
use Pramnos\Routing\Router;

/**
 * Characterization tests for Phase 7: Modern Routing Engine.
 *
 * Covers three sub-features:
 *   1. #[Route] PHP 8 Attribute — instantiation, repeatability, parameter defaults.
 *   2. Named Routes & URL Generation — Route::name(), Router::route(), getByName().
 *   3. Route Discovery — RouteDiscovery::discover() and Router::loadFromDirectory().
 *
 * These tests use fixture controller classes in tests/Characterization/Routing/Fixtures/
 * rather than live controllers so the suite has no application bootstrapping dependency.
 */
class RoutingCharacterizationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal Router with a stub container (null — actions in these
     * tests are closures or array callables that don't need DI).
     */
    private function makeRouter(): Router
    {
        return new Router(null);
    }

    // =========================================================================
    // 1. #[Route] Attribute
    // =========================================================================

    /**
     * The attribute can be instantiated with only the URI (all other params optional).
     * This verifies the default values: methods='GET', name=null, permissions=[], middleware=[].
     */
    public function testAttributeDefaultParameters(): void
    {
        // Arrange / Act
        $attr = new RouteAttr('/hello');

        // Assert — defaults are correct
        $this->assertSame('/hello', $attr->uri);
        $this->assertSame('GET', $attr->methods);
        $this->assertNull($attr->name);
        $this->assertSame([], $attr->permissions);
        $this->assertSame([], $attr->middleware);
    }

    /**
     * All named constructor parameters are stored as read-only properties.
     * Verifies that multi-method arrays and name/permissions/middleware all round-trip correctly.
     */
    public function testAttributeAllParameters(): void
    {
        // Arrange / Act
        $attr = new RouteAttr(
            uri:         '/api/resources/{id}',
            methods:     ['PUT', 'PATCH'],
            name:        'resources.update',
            permissions: ['write:resources'],
            middleware:  ['App\\Middleware\\AuthMiddleware'],
        );

        // Assert
        $this->assertSame('/api/resources/{id}', $attr->uri);
        $this->assertSame(['PUT', 'PATCH'], $attr->methods);
        $this->assertSame('resources.update', $attr->name);
        $this->assertSame(['write:resources'], $attr->permissions);
        $this->assertSame(['App\\Middleware\\AuthMiddleware'], $attr->middleware);
    }

    /**
     * The attribute carries IS_REPEATABLE so it can appear multiple times on a method.
     * This is critical: a single controller method can serve several URIs/methods.
     * We verify this at the PHP attribute flags level.
     */
    public function testAttributeIsRepeatable(): void
    {
        // Arrange
        $attrMeta = new \ReflectionClass(RouteAttr::class);
        $attrAnnotations = $attrMeta->getAttributes(\Attribute::class);

        // Assert — at least one #[Attribute] declares IS_REPEATABLE
        $this->assertNotEmpty($attrAnnotations, 'RouteAttr must itself carry #[Attribute]');

        $flags = $attrAnnotations[0]->newInstance()->flags;
        $this->assertTrue(
            (bool) ($flags & \Attribute::IS_REPEATABLE),
            'RouteAttr must declare Attribute::IS_REPEATABLE'
        );
    }

    /**
     * The attribute targets only methods (TARGET_METHOD).
     * Applying it to a class or property should not be its intended usage.
     */
    public function testAttributeTargetMethod(): void
    {
        // Arrange
        $attrMeta = new \ReflectionClass(RouteAttr::class);
        $flags = $attrMeta->getAttributes(\Attribute::class)[0]->newInstance()->flags;

        // Assert
        $this->assertTrue(
            (bool) ($flags & \Attribute::TARGET_METHOD),
            'RouteAttr must target methods'
        );
    }

    // =========================================================================
    // 2. Named Routes & URL Generation
    // =========================================================================

    /**
     * Route::name() returns the same Route instance (fluent API).
     * Callers chain ->name() after ->middleware() and other setters.
     */
    public function testRouteNameReturnsSelf(): void
    {
        // Arrange
        $route = new Route('/test', 'GET', fn() => 'ok');

        // Act
        $result = $route->name('test.route');

        // Assert — must be the same object (not a clone)
        $this->assertSame($route, $result);
    }

    /**
     * After calling name(), getName() returns the assigned string.
     */
    public function testRouteGetName(): void
    {
        // Arrange
        $route = new Route('/test', 'GET', fn() => 'ok');

        // Act
        $route->name('users.index');

        // Assert
        $this->assertSame('users.index', $route->getName());
    }

    /**
     * A newly created Route has no name by default (null).
     */
    public function testRouteNameIsNullByDefault(): void
    {
        // Arrange / Act
        $route = new Route('/test', 'GET', fn() => 'ok');

        // Assert
        $this->assertNull($route->getName());
    }

    /**
     * After chaining ->name() on a registered route, Router::getByName() returns
     * the exact same Route object.
     *
     * This verifies the callback mechanism that Route uses to self-register
     * in the router's named-route index without requiring a Router reference
     * to be passed to the Route constructor.
     */
    public function testNamedRouteRegisteredViaFluent(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act — chain name() on the returned Route
        $route = $router->get('/users', fn() => 'list')->name('users.index');

        // Assert
        $this->assertSame($route, $router->getByName('users.index'));
    }

    /**
     * getByName() returns null when no route has been registered under that name.
     */
    public function testGetByNameReturnsNullForUnknown(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Assert
        $this->assertNull($router->getByName('does.not.exist'));
    }

    /**
     * Router::route() for a static URI (no parameters) returns the URI unchanged.
     */
    public function testRouteUrlGenerationStaticUri(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/dashboard', fn() => 'dash')->name('dashboard');

        // Act
        $url = $router->route('dashboard');

        // Assert
        $this->assertSame('/dashboard', $url);
    }

    /**
     * Router::route() substitutes a required {param} placeholder.
     */
    public function testRouteUrlGenerationWithRequiredParam(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/users/{id}', fn() => 'show')->name('users.show');

        // Act
        $url = $router->route('users.show', ['id' => 42]);

        // Assert
        $this->assertSame('/users/42', $url);
    }

    /**
     * Router::route() substitutes multiple params in the correct positions.
     */
    public function testRouteUrlGenerationWithMultipleParams(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/posts/{year}/{month}/{slug}', fn() => 'post')->name('posts.show');

        // Act
        $url = $router->route('posts.show', ['year' => 2026, 'month' => '05', 'slug' => 'hello-world']);

        // Assert
        $this->assertSame('/posts/2026/05/hello-world', $url);
    }

    /**
     * Router::route() with an optional {param?} that IS supplied replaces it.
     */
    public function testRouteUrlGenerationOptionalParamSupplied(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/archive/{year}/{slug?}', fn() => 'arch')->name('archive.show');

        // Act
        $url = $router->route('archive.show', ['year' => 2026, 'slug' => 'my-post']);

        // Assert
        $this->assertSame('/archive/2026/my-post', $url);
    }

    /**
     * Router::route() strips optional {param?} segments that are NOT supplied.
     * The resulting URL must not contain a dangling placeholder or trailing slash.
     */
    public function testRouteUrlGenerationOptionalParamOmitted(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/archive/{year}/{slug?}', fn() => 'arch')->name('archive.show');

        // Act
        $url = $router->route('archive.show', ['year' => 2026]);

        // Assert — optional segment removed, no trailing slash artifact
        $this->assertSame('/archive/2026', $url);
    }

    /**
     * Parameter values are URL-encoded so that special characters are safe in URIs.
     */
    public function testRouteUrlGenerationEncodesParams(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/search/{query}', fn() => 'search')->name('search');

        // Act
        $url = $router->route('search', ['query' => 'hello world']);

        // Assert — space becomes %20
        $this->assertSame('/search/hello%20world', $url);
    }

    /**
     * Router::route() throws InvalidArgumentException for an unregistered name.
     * This is a hard failure so the developer gets early feedback instead of a
     * silent empty URL.
     */
    public function testRouteUrlGenerationThrowsForUnknownName(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\[ghost\.route\]/');

        // Act
        $router->route('ghost.route');
    }

    /**
     * A route name can be reassigned; the latest call to name() wins in the index.
     * Verifies that the named-route index always reflects the most recently set name.
     */
    public function testRouteNameCanBeReassigned(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $route  = $router->get('/items', fn() => 'items');
        $route->name('items.v1');
        $this->assertSame($route, $router->getByName('items.v1'));

        // Act — reassign the name
        $route->name('items.v2');

        // Assert — new name works
        $this->assertSame($route, $router->getByName('items.v2'));
        // Old name still points to same route (index entries are additive)
        // — implementation stores both; this is acceptable behavior
    }

    // =========================================================================
    // 3. Route Discovery
    // =========================================================================

    /**
     * RouteDiscovery can be instantiated with a Router and exposes discover().
     */
    public function testRouteDiscoveryInstantiation(): void
    {
        // Arrange / Act
        $discovery = new RouteDiscovery($this->makeRouter());

        // Assert
        $this->assertInstanceOf(RouteDiscovery::class, $discovery);
        $this->assertTrue(method_exists($discovery, 'discover'));
    }

    /**
     * discover() registers routes from #[Route]-annotated controller methods.
     * After scanning the fixture directory, the router must know the routes
     * defined in UserController.
     */
    public function testDiscoverRegistersRoutesFromFixtures(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $discovery = new RouteDiscovery($router);

        // Act
        $discovery->discover(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — UserController::index is registered as GET /users
        $route = $router->getByName('users.index');
        $this->assertNotNull($route, 'users.index must be registered by discovery');
        $this->assertSame('/users', $route->uri);
        $this->assertSame('GET', strtoupper($route->method));
    }

    /**
     * Routes in subdirectories are discovered by recursive scanning.
     * PostController lives in Fixtures/Sub/ and must be found.
     */
    public function testDiscoverScansSubdirectoriesRecursively(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — PostController is in Fixtures/Sub/
        $route = $router->getByName('posts.show');
        $this->assertNotNull($route, 'posts.show must be discovered in sub-directory');
        $this->assertSame('/posts/{year}/{slug?}', $route->uri);
    }

    /**
     * When the #[Route] attribute specifies permissions, they are applied to
     * the registered Route object.
     */
    public function testDiscoverAppliesPermissions(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — UserController::store has permissions: ['write:users']
        $route = $router->getByName('users.store');
        $this->assertNotNull($route);
        $this->assertTrue($route->hasPermissions());
        $this->assertContains('write:users', $route->getPermissions());
    }

    /**
     * A method with multiple #[Route] attributes (IS_REPEATABLE) results in
     * multiple routes being registered — one per attribute.
     *
     * UserController::update has two attributes:
     *   #[Route('/users/{id}', methods: 'PUT',   name: 'users.update')]
     *   #[Route('/users/{id}', methods: 'PATCH')]
     */
    public function testDiscoverRepeatableAttributeRegistersMultipleRoutes(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — both PUT and PATCH are registered for /users/{id}
        $putRoute   = $router->getByName('users.update');
        $this->assertNotNull($putRoute);
        $this->assertSame('PUT', strtoupper($putRoute->method));

        // PATCH route has no name, verify via direct route table access
        // by checking the router can match the PATCH URI
        $request = \Pramnos\Http\Request::create('users/5', 'PATCH');
        $matched = $router->getMatchedRoute($request);
        $this->assertNotNull($matched, 'PATCH /users/{id} must be registered');
    }

    /**
     * An attribute with multiple methods in a single #[Route] (e.g. ['GET','HEAD'])
     * registers separate route objects for each HTTP method.
     * PostController::index has methods: ['GET', 'HEAD'].
     */
    public function testDiscoverMultipleMethodsInOneAttribute(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — both GET and HEAD are registered
        $getRequest = \Pramnos\Http\Request::create('posts', 'GET');
        $this->assertNotNull($router->getMatchedRoute($getRequest), 'GET /posts must be registered');

        $headRequest = \Pramnos\Http\Request::create('posts', 'HEAD');
        $this->assertNotNull($router->getMatchedRoute($headRequest), 'HEAD /posts must be registered');
    }

    /**
     * Router::loadFromDirectory() is a thin convenience wrapper around
     * RouteDiscovery::discover() — its results must be identical.
     */
    public function testLoadFromDirectoryIsConvenienceWrapper(): void
    {
        // Arrange — two routers, identical setup via different entry points
        $routerA = $this->makeRouter();
        $routerA->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        $routerB = $this->makeRouter();
        (new RouteDiscovery($routerB))->discover(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — same named routes registered in both routers
        $this->assertNotNull($routerA->getByName('users.index'));
        $this->assertNotNull($routerB->getByName('users.index'));
        $this->assertSame(
            $routerA->getByName('users.index')->uri,
            $routerB->getByName('users.index')->uri
        );
    }

    /**
     * A discovered named route can be used with Router::route() for URL generation.
     * The optional {slug?} in PostController::show must be stripped when omitted.
     */
    public function testDiscoveredNamedRouteUrlGeneration(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Act — generate URL without optional slug
        $url = $router->route('posts.show', ['year' => 2026]);

        // Assert — optional segment removed
        $this->assertSame('/posts/2026', $url);
    }

    /**
     * Discovering routes from a directory does not affect already-registered
     * manual routes — the two registration mechanisms coexist.
     */
    public function testManualAndDiscoveredRoutesCoexist(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/manual', fn() => 'manual')->name('manual.route');

        // Act — discovery adds more routes without wiping the manual one
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — both exist
        $this->assertNotNull($router->getByName('manual.route'));
        $this->assertNotNull($router->getByName('users.index'));
    }

    // =========================================================================
    // Route Permission API (addPermissions / removePermissions / isValidScope)
    // =========================================================================

    /**
     * addPermissions() appends to the existing permission list rather than
     * replacing it.  It returns $this for fluent chaining.
     *
     * This covers Route::addPermissions() and implicitly Route::isValidScope()
     * (called when validateScopes=true, the default).
     */
    public function testAddPermissionsAppendsToExistingList(): void
    {
        // Arrange — route already has one permission from requirePermissions()
        $route = new Route('/resource', 'GET', fn() => 'ok');
        $route->requirePermissions(['read:resource']);

        // Act — add a second permission
        $returned = $route->addPermissions(['write:resource']);

        // Assert — both permissions are present, return value is $this
        $this->assertSame($route, $returned);
        $permissions = $route->getPermissions();
        $this->assertContains('read:resource', $permissions);
        $this->assertContains('write:resource', $permissions);
    }

    /**
     * addPermissions() accepts a string shorthand (auto-wraps in array).
     */
    public function testAddPermissionsAcceptsStringShorthand(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');

        // Act
        $route->addPermissions('delete:resource');

        // Assert
        $this->assertContains('delete:resource', $route->getPermissions());
    }

    /**
     * addPermissions() deduplicates: adding an already-present permission must
     * not create duplicate entries in the permissions list.
     */
    public function testAddPermissionsDeduplicates(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');
        $route->requirePermissions(['read:resource']);

        // Act
        $route->addPermissions(['read:resource']);

        // Assert — still just one entry
        $this->assertCount(1, $route->getPermissions());
    }

    /**
     * removePermissions() removes the given permissions from the list and
     * returns $this for fluent chaining.
     *
     * This covers Route::removePermissions().
     */
    public function testRemovePermissionsRemovesSpecifiedEntries(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');
        $route->requirePermissions(['read:resource', 'write:resource', 'delete:resource']);

        // Act
        $returned = $route->removePermissions(['write:resource']);

        // Assert — write:resource gone, read and delete remain; fluent return
        $this->assertSame($route, $returned);
        $permissions = $route->getPermissions();
        $this->assertNotContains('write:resource', $permissions);
        $this->assertContains('read:resource', $permissions);
        $this->assertContains('delete:resource', $permissions);
    }

    /**
     * removePermissions() accepts a string shorthand (auto-wraps in array).
     */
    public function testRemovePermissionsAcceptsStringShorthand(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');
        $route->requirePermissions('read:resource');

        // Act
        $route->removePermissions('read:resource');

        // Assert — permissions list is now empty
        $this->assertFalse($route->hasPermissions());
    }

    /**
     * requirePermissions() with an invalid scope format must throw
     * InvalidArgumentException.
     *
     * This covers the negative branch of Route::isValidScope() — specifically
     * the wildcard-in-middle-of-segment guard (wildcard embedded in a segment
     * token rather than being the entire segment).
     */
    public function testRequirePermissionsThrowsForInvalidScopeFormat(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid scope format/');

        // Act — "wr*ite" has wildcard in the middle of a segment (invalid)
        $route->requirePermissions(['wr*ite:resource']);
    }

    /**
     * addPermissions() must also throw InvalidArgumentException for an invalid
     * scope, exercising the validation in addPermissions() specifically.
     *
     * This covers the `throw new InvalidArgumentException` on line 130 of Route.php
     * (the addPermissions validation path, distinct from requirePermissions).
     */
    public function testAddPermissionsThrowsForInvalidScope(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act — scope starting with a digit fails the regex check (line 170)
        $route->addPermissions(['123invalid:resource']);
    }

    /**
     * A simple permission string with no colon (e.g., 'admin') is accepted when
     * it matches the alphanumeric pattern.
     *
     * This covers the `strpos($scope, ':') === false` early return in isValidScope()
     * (the no-colon path that delegates to a simpler preg_match).
     */
    public function testRequirePermissionsAcceptsSimplePermissionWithoutColon(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');

        // Act — 'admin' is a valid simple permission (no colon, alphanumeric)
        $route->requirePermissions(['admin']);

        // Assert — set correctly, no exception
        $this->assertContains('admin', $route->getPermissions());
    }

    /**
     * A standalone wildcard '*' as a scope segment is valid (e.g., 'read:*').
     *
     * This covers the `if ($part === '*') { continue; }` branch in isValidScope()
     * — the path that accepts a single asterisk as a complete scope part.
     */
    public function testRequirePermissionsAcceptsStandaloneWildcardSegment(): void
    {
        // Arrange
        $route = new Route('/resource', 'GET', fn() => 'ok');

        // Act — 'read:*' means "read anything" — standalone wildcard in resource segment
        $route->requirePermissions(['read:*']);

        // Assert — no exception; permission stored correctly
        $this->assertContains('read:*', $route->getPermissions());
    }

    // =========================================================================
    // Route::matches() — query-parameter URI path
    // =========================================================================

    /**
     * Route::matches() must correctly match a static-URI request that includes
     * a query string when the route's URI has none.
     *
     * The internal logic strips the query part via parse_url(PHP_URL_PATH) before
     * comparing, handling the simple "exact match after stripping" code path.
     */
    public function testMatchesStripsQueryStringFromRequestUri(): void
    {
        // Arrange — route registered without query params
        $route = new Route('/users', 'GET', fn() => 'list');

        // Act — request comes in with a query string
        $request = \Pramnos\Http\Request::create('/users?page=2&limit=10', 'GET');
        $matched = $route->matches($request);

        // Assert — must match despite the trailing query params
        $this->assertTrue($matched,
            'matches() must strip query string before comparing URIs');
    }

    /**
     * Route::matches() must match a parameterised route even when the incoming
     * request URI includes a query string.
     *
     * The compiled Symfony route regex uses `[^/]++` which captures the query string
     * as part of the last path parameter, so the first regex attempt (with raw URI)
     * already succeeds.  This confirms that parameterised routes handle query strings
     * via the regex path, not the parse_url fallback.
     */
    public function testMatchesParameterisedRouteWithQueryString(): void
    {
        // Arrange — parameterised route (no leading slash, following RouteTest convention)
        $route = new Route('users/{id}', 'GET', fn($id) => $id);

        // Act — request URI has both a param segment and a query string
        $request = \Pramnos\Http\Request::create('users/42?page=1', 'GET');
        $matched = $route->matches($request);

        // Assert — param route matches; query string is absorbed by the last segment
        $this->assertTrue($matched,
            'matches() must match a parameterised route even when the URI has a query string');
    }

    // =========================================================================
    // Route Middleware API
    // =========================================================================

    /**
     * middleware() attaches one or more middleware to the route and returns
     * $this for fluent chaining.
     *
     * This covers Route::middleware(), getMiddleware(), and hasMiddleware().
     */
    public function testMiddlewareAttachesAndIsRetrievable(): void
    {
        // Arrange
        $route = new Route('/secured', 'GET', fn() => 'ok');
        $this->assertFalse($route->hasMiddleware(), 'no middleware by default');

        // Act — attach middleware as FQCN strings (lazy-instantiation path)
        $returned = $route->middleware('App\\Middleware\\Auth', 'App\\Middleware\\Throttle');

        // Assert — fluent return, both middleware stored, hasMiddleware() true
        $this->assertSame($route, $returned);
        $this->assertTrue($route->hasMiddleware());
        $this->assertCount(2, $route->getMiddleware());
        $this->assertContains('App\\Middleware\\Auth', $route->getMiddleware());
        $this->assertContains('App\\Middleware\\Throttle', $route->getMiddleware());
    }

    /**
     * Multiple middleware() calls accumulate: each call appends rather than replaces.
     */
    public function testMiddlewareCallsAccumulate(): void
    {
        // Arrange
        $route = new Route('/secured', 'GET', fn() => 'ok');

        // Act
        $route->middleware('App\\Middleware\\Auth');
        $route->middleware('App\\Middleware\\Log');

        // Assert — both attached
        $this->assertCount(2, $route->getMiddleware());
    }
}
