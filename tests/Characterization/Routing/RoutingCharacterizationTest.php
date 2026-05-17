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

    // =========================================================================
    // Route::matches() — method mismatch and exact URI match
    // =========================================================================

    /**
     * matches() must return false immediately when the request HTTP method does
     * not match the route's method.
     *
     * This covers the `return false` on line 298 of Route.php — the very first
     * guard in matches() that short-circuits on method mismatch.
     */
    public function testMatchesReturnsFalseForMethodMismatch(): void
    {
        // Arrange — GET route, but request uses POST
        $route   = new Route('/items', 'GET', fn() => 'ok');
        $request = \Pramnos\Http\Request::create('/items', 'POST');

        // Act
        $result = $route->matches($request);

        // Assert — method mismatch → false immediately (no regex evaluation)
        $this->assertFalse($result,
            'matches() must return false when request method differs from route method');
    }

    /**
     * matches() must return true when the request URI equals the route URI
     * exactly (static route, no regex needed).
     *
     * This covers the `return true` on line 301 of Route.php — the second
     * guard that short-circuits on literal URI equality.
     */
    public function testMatchesReturnsTrueForExactUriMatch(): void
    {
        // Arrange — static route, request URI matches exactly
        $route   = new Route('/dashboard', 'GET', fn() => 'ok');
        $request = \Pramnos\Http\Request::create('/dashboard', 'GET');

        // Act
        $result = $route->matches($request);

        // Assert — exact match path returns true without regex
        $this->assertTrue($result,
            'matches() must return true for exact static URI match');
    }

    // =========================================================================
    // Route::execute() — closure invocation
    // =========================================================================

    /**
     * execute() must invoke the closure action, resolve named parameters from
     * the matched parameter bag, and return the action's return value.
     *
     * This covers lines 358-369 in Route.php — the is_callable branch,
     * ReflectionFunction parameter introspection, and call_user_func_array.
     */
    public function testExecuteCallsClosureAction(): void
    {
        // Arrange — route with a parameterised URI that captures {id}
        $route   = new Route('items/{id}', 'GET', fn(int $id) => 'item:' . $id);
        $request = \Pramnos\Http\Request::create('items/7', 'GET');

        // Act — matches() populates $route->parameters, execute() calls the closure
        $this->assertTrue($route->matches($request));
        $result = $route->execute(null);

        // Assert — closure returned correct value with parameter resolved
        $this->assertSame('item:7', $result,
            'execute() must invoke the closure with extracted route parameters');
    }

    // =========================================================================
    // RouteDiscovery — OPTIONS method, unknown method skip, middleware attribute
    // =========================================================================

    /**
     * discover() must register a route for the OPTIONS HTTP method when a
     * controller method is annotated with #[Route(..., methods: 'OPTIONS')].
     *
     * This covers line 146 of RouteDiscovery.php:
     * `'OPTIONS' => $this->router->options($attr->uri, $action)`
     */
    public function testDiscoverRegistersOptionsRoute(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — DiscoveryEdgeCasesController::preflight is registered
        $route = $router->getByName('edge.options');
        $this->assertNotNull($route, 'OPTIONS route must be discovered');
        $this->assertSame('OPTIONS', strtoupper($route->method));
        $this->assertSame('/api/preflight', $route->uri);
    }

    /**
     * When a #[Route] attribute lists an unrecognised HTTP method, that specific
     * method must be silently skipped (default => null → continue) without
     * preventing sibling attributes on the same method from being registered.
     *
     * This covers lines 147 and 151 of RouteDiscovery.php — the `default => null`
     * arm of the match statement and the subsequent `if ($route === null) { continue; }`.
     */
    public function testDiscoverSkipsUnknownHttpMethod(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — PURGE route not registered (unknown method → null → skipped)
        $purgeRequest = \Pramnos\Http\Request::create('/api/edge/purge', 'PURGE');
        $this->assertNull($router->getMatchedRoute($purgeRequest),
            'Unknown HTTP method PURGE must not be registered');

        // Assert — GET fallback for same URI was registered (sibling attribute)
        $getRoute = $router->getByName('edge.purge.get');
        $this->assertNotNull($getRoute,
            'GET route registered alongside unknown PURGE must still be discovered');
    }

    /**
     * When a #[Route] attribute includes middleware, RouteDiscovery must attach
     * those middleware to the registered Route object.
     *
     * This covers line 159 of RouteDiscovery.php:
     * `$route->middleware(...$attr->middleware)`
     */
    public function testDiscoverAppliesMiddlewareFromAttribute(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $router->loadFromDirectory(
            __DIR__ . '/Fixtures',
            'PramnosTest\\Routing\\Fixtures'
        );

        // Assert — DiscoveryEdgeCasesController::secured has middleware attached
        $route = $router->getByName('edge.secured');
        $this->assertNotNull($route);
        $this->assertTrue($route->hasMiddleware(),
            'Route discovered with middleware attribute must report hasMiddleware()');
        $this->assertContains('App\\Middleware\\AuthMiddleware', $route->getMiddleware());
        $this->assertContains('App\\Middleware\\Throttle', $route->getMiddleware());
    }

    // =========================================================================
    // RouteDiscovery — non-PHP file skip and class-not-found skip
    // =========================================================================

    /**
     * discover() must silently skip non-PHP files (e.g. .md, .txt) found
     * during directory traversal without throwing an error.
     *
     * This covers the `if ($file->getExtension() !== 'php') { continue; }`
     * guard on line 65-67 of RouteDiscovery.php.
     */
    public function testDiscoverSkipsNonPhpFiles(): void
    {
        // Arrange — temp directory with one valid PHP controller and one .md file
        $tmpDir = sys_get_temp_dir() . '/pf_rd_test_' . uniqid('', true);
        mkdir($tmpDir, 0777, true);

        $phpFile = $tmpDir . '/SkipNonPhpController.php';
        $mdFile  = $tmpDir . '/README.md';

        // Unique namespace avoids conflicts with other test runs
        $ns = 'PramnosRdSkip' . substr(md5(uniqid()), 0, 8);
        file_put_contents($phpFile, <<<PHP
        <?php
        namespace {$ns};
        use Pramnos\\Routing\\Attributes\\Route;
        class SkipNonPhpController {
            #[Route('/skiptest', methods: 'GET', name: 'skip.phpfile')]
            public function index(): void {}
        }
        PHP);
        file_put_contents($mdFile, '# Not PHP — must be ignored by discovery');

        $router = $this->makeRouter();

        try {
            // Act — must not throw on the .md file; PHP route must be found
            (new \Pramnos\Routing\RouteDiscovery($router))->discover($tmpDir, $ns);

            // Assert — PHP controller registered its route
            $this->assertNotNull($router->getByName('skip.phpfile'),
                'Route from the PHP file must be registered');
        } finally {
            @unlink($phpFile);
            @unlink($mdFile);
            @rmdir($tmpDir);
        }
    }

    /**
     * discover() must skip PHP files whose path-derived class name does not
     * correspond to any actually-defined class (e.g. the file defines a class
     * with a different name or namespace).
     *
     * This covers the `if (!class_exists($class, false)) { continue; }` guard
     * on lines 73-75 of RouteDiscovery.php.
     */
    public function testDiscoverSkipsFileWithUnmatchedClassName(): void
    {
        // Arrange — temp dir with a PHP file that defines a class whose name does
        // NOT match what pathToClass() would derive from the file path.
        $tmpDir = sys_get_temp_dir() . '/pf_rd_mismatch_' . uniqid('', true);
        mkdir($tmpDir, 0777, true);

        // The file is named MismatchedController.php; pathToClass() will derive
        // `{$ns}\MismatchedController`, but the file defines `WronglyNamedClass`.
        $ns = 'PramnosRdMismatch' . substr(md5(uniqid()), 0, 8);
        $phpFile = $tmpDir . '/MismatchedController.php';
        file_put_contents($phpFile, <<<PHP
        <?php
        namespace {$ns};
        // Intentionally different class name — pathToClass() derives MismatchedController
        class WronglyNamedClassXYZ {}
        PHP);

        $router = $this->makeRouter();

        try {
            // Act — discover() must not throw; just skip the file
            (new \Pramnos\Routing\RouteDiscovery($router))->discover($tmpDir, $ns);

            // Assert — no routes registered (mismatched class was skipped)
            // (We verify indirectly: the router has no routes for this ns)
            $this->assertNull($router->getByName($ns . '.any'),
                'No routes must be registered when the class name does not match the file');
        } finally {
            @unlink($phpFile);
            @rmdir($tmpDir);
        }
    }

    // =========================================================================
    // Router::dispatch() — execution, permissions, middleware pipeline
    // =========================================================================

    /**
     * dispatch() must return null when no route matches the request.
     *
     * This covers the `return null` at the end of dispatch() (after `if ($route)`)
     * and also the null return from getMatchedRoute() when no route is registered
     * for the request method.
     */
    public function testDispatchReturnsNullForUnmatchedRequest(): void
    {
        // Arrange
        $router  = $this->makeRouter();
        $request = \Pramnos\Http\Request::create('/not/a/route', 'GET');

        // Act
        $result = $router->dispatch($request);

        // Assert
        $this->assertNull($result, 'dispatch() must return null when no route matches');
    }

    /**
     * dispatch() must execute the matched route's action and return its result
     * when no permissions are required and no middleware is attached.
     *
     * This covers lines 120-141 in Router.php — the happy path through dispatch().
     */
    public function testDispatchExecutesMatchedRoute(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/hello', fn() => 'hello_world');
        $request = \Pramnos\Http\Request::create('/hello', 'GET');

        // Act
        $result = $router->dispatch($request);

        // Assert
        $this->assertSame('hello_world', $result,
            'dispatch() must return the action result for a matched route');
    }

    /**
     * dispatch() must throw an Exception (code 403) when the route requires a
     * permission that the user does not have.
     *
     * This covers lines 123-128 in Router.php — the hasPermissions() false branch
     * including the _invalidScope tracking path (line 124-125).
     */
    public function testDispatchThrowsForInsufficientPermissions(): void
    {
        // Arrange — route requires 'write:items', user has none
        $router = $this->makeRouter();
        $router->get('/items', fn() => 'items')->requirePermissions(['write:items']);
        $request = \Pramnos\Http\Request::create('/items', 'GET');

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);

        // Act
        $router->dispatch($request, []);
    }

    /**
     * dispatch() must execute the action when the user has the required permission.
     *
     * This also covers Router::hasPermissions(), normalizePermissions(), and
     * hasScope() for direct-match (no wildcard) permission checking.
     */
    public function testDispatchSucceedsWhenUserHasRequiredPermission(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/items', fn() => 'items_list')->requirePermissions(['read:items']);
        $request = \Pramnos\Http\Request::create('/items', 'GET');

        // Act
        $result = $router->dispatch($request, ['read:items']);

        // Assert
        $this->assertSame('items_list', $result);
    }

    /**
     * dispatch() must run the global middleware pipeline before executing the
     * route action, and the final handler result must be returned.
     *
     * This covers lines 130-138 in Router.php — the middleware pipeline branch
     * inside dispatch() — and addGlobalMiddleware() (lines 106-107).
     */
    public function testDispatchRunsGlobalMiddlewarePipeline(): void
    {
        // Arrange — attach a global middleware that appends a marker to the result
        $router = $this->makeRouter();

        // Inline middleware: prepend 'mw:' to the action result
        $middleware = new class implements \Pramnos\Http\MiddlewareInterface {
            public function handle(\Pramnos\Http\Request $request, callable $next): mixed {
                $result = $next($request);
                return 'mw:' . $result;
            }
        };

        $router->addGlobalMiddleware($middleware);
        $router->get('/mw-test', fn() => 'action_result');
        $request = \Pramnos\Http\Request::create('/mw-test', 'GET');

        // Act
        $result = $router->dispatch($request);

        // Assert — middleware ran and wrapped the action result
        $this->assertSame('mw:action_result', $result,
            'Global middleware must wrap the route action result');
    }

    /**
     * dispatch() must pass permissions correctly when the user has more scopes
     * than the route requires; only the matching ones need to be present.
     *
     * This also verifies that hasPermissions() iterates correctly and short-circuits
     * on the first matching scope, without triggering an exception.
     */
    public function testDispatchSucceedsWhenUserHasExtraPermissions(): void
    {
        // Arrange — route requires only 'read:items', user has many permissions
        $router = $this->makeRouter();
        $router->get('/items', fn() => 'ok')->requirePermissions(['read:items']);
        $request = \Pramnos\Http\Request::create('/items', 'GET');

        // Act — user has the required scope plus extras
        $result = $router->dispatch($request, ['write:items', 'read:items', 'delete:items']);

        // Assert
        $this->assertSame('ok', $result);
    }

    // =========================================================================
    // Router::dispatchSafe() — safe dispatch returning structured array
    // =========================================================================

    /**
     * dispatchSafe() must return a RouteNotFound error array when no route matches.
     *
     * This covers lines 156-163 of Router.php — the !$route early return.
     */
    public function testDispatchSafeReturnsRouteNotFoundForUnmatchedRequest(): void
    {
        // Arrange
        $router  = $this->makeRouter();
        $request = \Pramnos\Http\Request::create('/no/such/path', 'GET');

        // Act
        $result = $router->dispatchSafe($request);

        // Assert — structured error array
        $this->assertSame('RouteNotFound', $result['error']);
        $this->assertNull($result['data']);
    }

    /**
     * dispatchSafe() must return an InsufficientPermissions error array when the
     * user lacks the required permission.
     *
     * This covers lines 166-173 of Router.php.
     */
    public function testDispatchSafeReturnsPermissionErrorForDeniedRequest(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/admin', fn() => 'admin')->requirePermissions(['admin:access']);
        $request = \Pramnos\Http\Request::create('/admin', 'GET');

        // Act
        $result = $router->dispatchSafe($request, []);

        // Assert
        $this->assertSame('InsufficientPermissions', $result['error']);
        $this->assertNull($result['data']);
    }

    /**
     * dispatchSafe() must return a data array with the action result on success.
     *
     * This covers the success path (lines 185-190 of Router.php) — no middleware.
     */
    public function testDispatchSafeReturnsDataOnSuccess(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/ok', fn() => 'success_value');
        $request = \Pramnos\Http\Request::create('/ok', 'GET');

        // Act
        $result = $router->dispatchSafe($request);

        // Assert — success array contains data key
        $this->assertSame('success_value', $result['data']);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * dispatchSafe() must catch any exception thrown by the route action and
     * return an error array instead of propagating it.
     *
     * This covers lines 191-197 of Router.php — the catch(\Exception) block.
     */
    public function testDispatchSafeReturnsErrorOnActionException(): void
    {
        // Arrange — route action throws
        $router = $this->makeRouter();
        $router->get('/boom', fn() => throw new \Exception('action failed'));
        $request = \Pramnos\Http\Request::create('/boom', 'GET');

        // Act
        $result = $router->dispatchSafe($request);

        // Assert — exception converted to error array
        $this->assertSame('Error', $result['error']);
        $this->assertSame('action failed', $result['message']);
        $this->assertNull($result['data']);
    }

    /**
     * dispatchSafe() must run route-specific middleware before the action and
     * return the wrapped result.
     *
     * This covers lines 177-183 of Router.php — the middleware pipeline inside
     * dispatchSafe() — including the route->getMiddleware() merging path.
     */
    public function testDispatchSafeRunsRouteMiddleware(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $middleware = new class implements \Pramnos\Http\MiddlewareInterface {
            public function handle(\Pramnos\Http\Request $request, callable $next): mixed {
                return 'safe_mw:' . $next($request);
            }
        };

        $router->get('/mw-safe', fn() => 'base')
               ->middleware($middleware);
        $request = \Pramnos\Http\Request::create('/mw-safe', 'GET');

        // Act
        $result = $router->dispatchSafe($request);

        // Assert
        $this->assertSame('safe_mw:base', $result['data'],
            'Route middleware must wrap the action result in dispatchSafe()');
    }

    // =========================================================================
    // Router::dispatchWithoutPermissions()
    // =========================================================================

    /**
     * dispatchWithoutPermissions() must execute the route without checking
     * permissions, even when the route requires a permission the user lacks.
     *
     * This covers lines 209-213 in Router.php.
     */
    public function testDispatchWithoutPermissionsSkipsPermissionCheck(): void
    {
        // Arrange — route requires permission, but we call the no-check dispatcher
        $router = $this->makeRouter();
        $router->get('/locked', fn() => 'unlocked')->requirePermissions(['super:admin']);
        $request = \Pramnos\Http\Request::create('/locked', 'GET');

        // Act — must NOT throw
        $result = $router->dispatchWithoutPermissions($request);

        // Assert
        $this->assertSame('unlocked', $result,
            'dispatchWithoutPermissions() must skip permission checks entirely');
    }

    /**
     * dispatchWithoutPermissions() must return null when no route matches.
     *
     * Covers the `return null` at line 213.
     */
    public function testDispatchWithoutPermissionsReturnsNullForUnmatchedRequest(): void
    {
        // Arrange
        $router  = $this->makeRouter();
        $request = \Pramnos\Http\Request::create('/nowhere', 'DELETE');

        // Act
        $result = $router->dispatchWithoutPermissions($request);

        // Assert
        $this->assertNull($result);
    }

    // =========================================================================
    // Router::addRoute() — array-methods shorthand
    // =========================================================================

    /**
     * addRoute() with an array of HTTP methods must register one route per method.
     *
     * This covers lines 81-84 of Router.php — the `is_array($methods)` branch
     * in addRoute().
     */
    public function testAddRouteRegistersOneRoutePerMethod(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->addRoute('/multi', ['GET', 'POST'], fn() => 'multi');

        // Act
        $getResult  = $router->dispatch(\Pramnos\Http\Request::create('/multi', 'GET'));
        $postResult = $router->dispatch(\Pramnos\Http\Request::create('/multi', 'POST'));

        // Assert — both methods registered and executable
        $this->assertSame('multi', $getResult);
        $this->assertSame('multi', $postResult);
    }

    // =========================================================================
    // Router permission helpers — normalizePermissions, hasScope, wildcardMatch
    // =========================================================================

    /**
     * dispatch() with a space-separated permission string must normalise it to
     * an array before checking scopes.
     *
     * This covers normalizePermissions() for the space-separated string path
     * (lines 447-449 of Router.php).
     */
    public function testDispatchNormalisesSpaceSeparatedPermissionString(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/api/items', fn() => 'list')->requirePermissions(['read:items']);
        $request = \Pramnos\Http\Request::create('/api/items', 'GET');

        // Act — permissions passed as space-separated string (OAuth2 scope format)
        $result = $router->dispatch($request, 'read:items write:items');

        // Assert
        $this->assertSame('list', $result);
    }

    /**
     * A user with the global wildcard '*' scope must be granted access to any
     * permission-protected route.
     *
     * This covers the `if ($userScope === '*') { return true; }` path in
     * Router::wildcardMatch() (line 492 of Router.php).
     */
    public function testDispatchAcceptsGlobalWildcardScope(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/anything', fn() => 'ok')->requirePermissions(['very:specific:permission']);
        $request = \Pramnos\Http\Request::create('/anything', 'GET');

        // Act
        $result = $router->dispatch($request, ['*']);

        // Assert
        $this->assertSame('ok', $result, 'Global wildcard * must satisfy any permission');
    }

    // =========================================================================
    // Router utility methods
    // =========================================================================

    /**
     * match() with a comma-separated string of methods must register a route
     * for each method (it splits on ',' before delegating to addRoute()).
     *
     * This covers lines 399-402 of Router.php — the string→array branch in match().
     */
    public function testMatchWithCommaSeparatedMethodsRegistersAllMethods(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act — 'GET,POST' as a string shorthand
        $router->match('GET,POST', '/api/resource', fn() => 'resource');

        // Assert — both methods registered
        $this->assertSame('resource',
            $router->dispatch(\Pramnos\Http\Request::create('/api/resource', 'GET')));
        $this->assertSame('resource',
            $router->dispatch(\Pramnos\Http\Request::create('/api/resource', 'POST')));
    }

    /**
     * getRoutesWithPermissions() must return a nested array of method → uri → data
     * entries for all registered routes, including their permission lists.
     *
     * This covers lines 513-529 of Router.php.
     */
    public function testGetRoutesWithPermissionsReturnsAllRouteData(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/public', fn() => 'pub');
        $router->post('/private', fn() => 'priv')->requirePermissions(['write:items']);

        // Act
        $data = $router->getRoutesWithPermissions();

        // Assert — structure is method → uri → {route, permissions, hasPermissions}
        $this->assertArrayHasKey('GET', $data);
        $this->assertArrayHasKey('/public', $data['GET']);
        $this->assertFalse($data['GET']['/public']['hasPermissions']);

        $this->assertArrayHasKey('POST', $data);
        $this->assertTrue($data['POST']['/private']['hasPermissions']);
        $this->assertContains('write:items', $data['POST']['/private']['permissions']);
    }

    /**
     * getRequiredPermissions() must return the permission list of the matching
     * route, or null when no route matches.
     *
     * This covers lines 581-590 of Router.php.
     */
    public function testGetRequiredPermissionsReturnsPermissionsOrNull(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/protected', fn() => 'ok')->requirePermissions(['read:data']);

        // Act — matching route
        $perms = $router->getRequiredPermissions(
            \Pramnos\Http\Request::create('/protected', 'GET')
        );

        // Assert — returns permissions from the matched route
        $this->assertContains('read:data', $perms);

        // Act — unmatched route returns null
        $null = $router->getRequiredPermissions(
            \Pramnos\Http\Request::create('/no-such-route', 'GET')
        );
        $this->assertNull($null, 'getRequiredPermissions() must return null for no match');
    }

    /**
     * getAllUsedPermissions() must collect all unique permissions from all routes
     * across all HTTP methods.
     *
     * This covers lines 597-610 of Router.php.
     */
    public function testGetAllUsedPermissionsReturnsUniquePermissions(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/a', fn() => 'a')->requirePermissions(['read:items']);
        $router->post('/b', fn() => 'b')->requirePermissions(['write:items', 'read:items']);
        $router->get('/c', fn() => 'c'); // no permissions

        // Act
        $all = $router->getAllUsedPermissions();

        // Assert — unique: read:items appears only once despite being in two routes
        $this->assertContains('read:items', $all);
        $this->assertContains('write:items', $all);
        $this->assertCount(2, array_values($all),
            'getAllUsedPermissions() must deduplicate across routes');
    }

    /**
     * isValidScope() must return truthy for valid scope formats and falsy for
     * invalid ones.
     *
     * This covers line 618-623 of Router.php.
     */
    public function testIsValidScopeAcceptsValidAndRejectsInvalid(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Assert — valid formats accepted
        $this->assertTrue((bool) $router->isValidScope('read:items'));
        $this->assertTrue((bool) $router->isValidScope('user_read'));
        $this->assertTrue((bool) $router->isValidScope('admin'));
        $this->assertTrue((bool) $router->isValidScope('*'));

        // Assert — invalid format rejected (starts with digit)
        $this->assertFalse((bool) $router->isValidScope('1invalid'));
    }

    /**
     * parseScope() must detect and parse each supported scope format:
     * colon (action:resource), underscore, dot, dash, and single-word.
     *
     * This covers lines 537-577 of Router.php — the parseScope() method body
     * including all five format-detection branches.
     */
    public function testParseScopeDetectsAllFormats(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Assert — colon format
        $colon = $router->parseScope('read:items');
        $this->assertSame('colon', $colon['format']);
        $this->assertContains('read', $colon['parts']);

        // Assert — underscore format
        $under = $router->parseScope('user_read');
        $this->assertSame('underscore', $under['format']);

        // Assert — dot format
        $dot = $router->parseScope('user.read');
        $this->assertSame('dot', $dot['format']);

        // Assert — dash format
        $dash = $router->parseScope('user-read');
        $this->assertSame('dash', $dash['format']);

        // Assert — single-word format
        $single = $router->parseScope('admin');
        $this->assertSame('single', $single['format']);
        $this->assertSame('admin', $single['original']);
    }

    /**
     * getEffectivePermissions() with a global '*' scope must expand to include
     * all known permissions from the router's registered routes.
     *
     * This covers lines 727-751 of Router.php — the wildcard expansion path
     * in getEffectivePermissions().
     */
    public function testGetEffectivePermissionsExpandsWildcard(): void
    {
        // Arrange — register routes with permissions so getAllUsedPermissions() has data
        $router = $this->makeRouter();
        $router->get('/x', fn() => 'x')->requirePermissions(['read:items']);
        $router->get('/y', fn() => 'y')->requirePermissions(['write:items']);

        // Act — user has global '*' scope; effective permissions should include known scopes
        $effective = $router->getEffectivePermissions(['*']);

        // Assert — '*' scope + expanded known permissions
        $this->assertContains('*', $effective,
            'The original * scope must be in effective permissions');
        $this->assertContains('read:items', $effective,
            'Known scope read:items must be expanded from wildcard');
        $this->assertContains('write:items', $effective,
            'Known scope write:items must be expanded from wildcard');
    }
}
