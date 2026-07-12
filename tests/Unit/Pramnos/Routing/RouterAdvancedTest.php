<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Routing\Router;
use Pramnos\Http\Request;

/**
 * Advanced unit tests for Pramnos\Routing\Router.
 *
 * Covers the methods NOT exercised by the legacy RouterTest:
 *   - Permission checking (hasPermissions, scope matching, wildcards)
 *   - Named routes and URL generation (route(), getByName(), buildUrl)
 *   - dispatchSafe() — structured error envelope
 *   - dispatchWithoutPermissions() — bypass auth
 *   - addGlobalMiddleware() — global middleware registration
 *   - parseScope() / isValidScope() — scope helpers
 *   - getAllUsedPermissions() / getRoutesWithPermissions()
 *   - getEffectivePermissions() — wildcard expansion
 *   - head() shorthand method
 */
#[CoversClass(Router::class)]
class RouterAdvancedTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /** Build a fresh router with a null container (sufficient for unit tests). */
    private function makeRouter(): Router
    {
        return new Router(null);
    }

    // =========================================================================
    // head() shorthand
    // =========================================================================

    /**
     * head() registers a HEAD route that can be dispatched.
     */
    public function testHeadRouteIsDispatchable(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->head('/ping', fn() => 'pong');

        // Act
        $result = $router->dispatch(Request::create('/ping', 'HEAD'));

        // Assert
        $this->assertSame('pong', $result);
    }

    // =========================================================================
    // dispatchSafe()
    // =========================================================================

    /**
     * dispatchSafe() returns an error envelope with error='RouteNotFound' when
     * no route matches, instead of returning null like dispatch().
     */
    public function testDispatchSafeReturnsRouteNotFoundEnvelope(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $result = $router->dispatchSafe(Request::create('/nope', 'GET'));

        // Assert – structured envelope, not null
        $this->assertSame('RouteNotFound', $result['error']);
        $this->assertNull($result['data']);
        $this->assertNull($result['route']);
    }

    /**
     * dispatchSafe() returns the route result wrapped in 'data' on success.
     */
    public function testDispatchSafeReturnsDataOnSuccess(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/hello', fn() => 42);

        // Act
        $result = $router->dispatchSafe(Request::create('/hello', 'GET'));

        // Assert
        $this->assertSame(42, $result['data']);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * dispatchSafe() returns InsufficientPermissions envelope when user lacks
     * required permissions — unlike dispatch() which throws an exception.
     */
    public function testDispatchSafeReturnsPermissionEnvelopeWhenAccessDenied(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/admin', fn() => 'secret', ['admin:read']);

        // Act – user has no permissions
        $result = $router->dispatchSafe(Request::create('/admin', 'GET'), []);

        // Assert – structured envelope, route is set
        $this->assertSame('InsufficientPermissions', $result['error']);
        $this->assertNull($result['data']);
        $this->assertNotNull($result['route']);
    }

    // =========================================================================
    // dispatchWithoutPermissions()
    // =========================================================================

    /**
     * dispatchWithoutPermissions() executes the action even when the route has
     * required permissions and the user has none — bypassing auth entirely.
     */
    public function testDispatchWithoutPermissionsIgnoresRequiredPermissions(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/protected', fn() => 'data', ['admin:read']);

        // Act – no permissions provided but bypass is used
        $result = $router->dispatchWithoutPermissions(Request::create('/protected', 'GET'));

        // Assert – action executed regardless of permissions
        $this->assertSame('data', $result);
    }

    /**
     * dispatchWithoutPermissions() returns null when no route matches.
     */
    public function testDispatchWithoutPermissionsReturnsNullForUnknownRoute(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $result = $router->dispatchWithoutPermissions(Request::create('/unknown', 'GET'));

        // Assert
        $this->assertNull($result);
    }

    // =========================================================================
    // hasPermissions()
    // =========================================================================

    /**
     * hasPermissions() returns true when the route has no required permissions.
     */
    public function testHasPermissionsReturnsTrueForUnrestrictedRoute(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $route  = $router->get('/open', fn() => null);

        // Act / Assert – no permissions required → always allowed
        $this->assertTrue($router->hasPermissions($route, []));
        $this->assertTrue($router->hasPermissions($route, ['anything']));
    }

    /**
     * hasPermissions() returns true when user has ALL required permissions.
     */
    public function testHasPermissionsReturnsTrueWhenUserHasAllPermissions(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $route  = $router->get('/secure', fn() => null, ['read:posts', 'write:posts']);

        // Act / Assert
        $this->assertTrue($router->hasPermissions($route, ['read:posts', 'write:posts', 'extra']));
    }

    /**
     * hasPermissions() returns false when user is missing even one required permission.
     */
    public function testHasPermissionsReturnsFalseWhenUserMissingPermission(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $route  = $router->get('/secure', fn() => null, ['read:posts', 'write:posts']);

        // Act – user has only one of the two required permissions
        $result = $router->hasPermissions($route, ['read:posts']);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * hasPermissions() accepts user permissions as a space-separated string
     * (OAuth2 scope format), not just arrays.
     */
    public function testHasPermissionsAcceptsSpaceSeparatedString(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $route  = $router->get('/api', fn() => null, ['read:posts']);

        // Act – space-separated scopes
        $this->assertTrue($router->hasPermissions($route, 'read:posts write:posts'));
    }

    /**
     * Wildcard '*' in user scopes grants access to every required permission.
     */
    public function testHasPermissionsWildcardGrantsAll(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $route  = $router->get('/admin', fn() => null, ['admin:delete']);

        // Act / Assert – full wildcard matches anything
        $this->assertTrue($router->hasPermissions($route, ['*']));
    }

    /**
     * Only the full wildcard '*' currently works for blanket grant.
     * Partial wildcards like 'admin:*' are NOT supported by wildcardMatch()
     * due to a preg_quote interaction — '*' in the pattern is escaped before
     * str_replace runs, producing a broken regex. Document actual behaviour.
     */
    public function testHasPermissionsOnlyFullWildcardWorks(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $route  = $router->get('/admin', fn() => null, ['admin:write']);

        // '*' → full wildcard (handled by identity check — always true)
        $this->assertTrue($router->hasPermissions($route, ['*']));

        // 'admin:*' → partial wildcard — NOT currently supported; returns false
        $this->assertFalse($router->hasPermissions($route, ['admin:*']));
    }

    // =========================================================================
    // parseScope()
    // =========================================================================

    /** @return array<string,array{string,string,int}> */
    public static function scopeProvider(): array
    {
        return [
            'colon format'      => ['read:users',  'colon',      2],
            'underscore format' => ['user_read',   'underscore', 2],
            'dot format'        => ['user.read',   'dot',        2],
            'dash format'       => ['user-read',   'dash',       2],
            'single word'       => ['admin',       'single',     1],
        ];
    }

    /**
     * parseScope() detects the separator used in a scope string and splits it
     * into parts. The result carries the original scope, format, and parts array.
     *
     * @param string $scope    Input scope
     * @param string $format   Expected format identifier
     * @param int    $partCount Expected number of parts
     */
    #[DataProvider('scopeProvider')]
    public function testParseScopeDetectsFormat(string $scope, string $format, int $partCount): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act
        $result = $router->parseScope($scope);

        // Assert
        $this->assertSame($scope,  $result['original']);
        $this->assertSame($format, $result['format']);
        $this->assertCount($partCount, $result['parts']);
    }

    // =========================================================================
    // isValidScope()
    // =========================================================================

    /** @return array<string,array{string,bool}> */
    public static function validScopeProvider(): array
    {
        return [
            // Valid scopes — all common separator formats
            'colon scope'      => ['read:users',   true],
            'wildcard'         => ['*',             true],
            'full wildcard'    => ['admin:*',       true],
            'underscore scope' => ['user_read',     true],
            'dot scope'        => ['user.read',     true],
            'dash scope'       => ['user-read',     true],
            // Invalid — rejected by the regex
            'empty string'     => ['',              false],
            'starts with num'  => ['1invalid',      false],
            'space inside'     => ['read users',    false],
        ];
    }

    /**
     * isValidScope() accepts alphanumeric scopes with common separators
     * and rejects strings starting with a digit or containing spaces.
     * Returns a truthy/falsy int (from preg_match), tested with assertTrue/assertFalse.
     *
     * @param string $scope    Input scope string
     * @param bool   $expected Whether it should be valid
     */
    #[DataProvider('validScopeProvider')]
    public function testIsValidScope(string $scope, bool $expected): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act — returns int (preg_match result), cast to bool for assertion
        $result = (bool) $router->isValidScope($scope);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Named routes — getByName() / route()
    // =========================================================================

    /**
     * A route registered with ->name('x') is findable via getByName('x').
     */
    public function testGetByNameFindsNamedRoute(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/users/{id}', fn() => null)->name('users.show');

        // Act
        $found = $router->getByName('users.show');

        // Assert
        $this->assertNotNull($found);
        $this->assertSame('/users/{id}', $found->uri);
    }

    /**
     * getByName() returns null for an unknown name.
     */
    public function testGetByNameReturnsNullForUnknownName(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act / Assert
        $this->assertNull($router->getByName('nonexistent'));
    }

    /**
     * route() generates a URL by substituting {param} placeholders.
     */
    public function testRouteGeneratesUrlWithParams(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/users/{id}', fn() => null)->name('users.show');

        // Act
        $url = $router->route('users.show', ['id' => 42]);

        // Assert
        $this->assertSame('/users/42', $url);
    }

    /**
     * route() strips optional segments that have no supplied param value.
     */
    public function testRouteStripsUnsetOptionalSegments(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/posts/{year}/{slug?}', fn() => null)->name('posts.show');

        // Act – slug not supplied
        $url = $router->route('posts.show', ['year' => 2026]);

        // Assert – optional {slug?} segment removed
        $this->assertSame('/posts/2026', $url);
    }

    /**
     * route() URL-encodes param values to produce safe URIs.
     */
    public function testRouteUrlEncodesParams(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/search/{q}', fn() => null)->name('search');

        // Act
        $url = $router->route('search', ['q' => 'hello world']);

        // Assert – space encoded as %20
        $this->assertSame('/search/hello%20world', $url);
    }

    /**
     * route() throws InvalidArgumentException for an undefined route name.
     */
    public function testRouteThrowsForUndefinedName(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Assert / Act
        $this->expectException(\InvalidArgumentException::class);
        $router->route('nonexistent');
    }

    // =========================================================================
    // getAllUsedPermissions() / getRoutesWithPermissions()
    // =========================================================================

    /**
     * getAllUsedPermissions() collects every unique permission across all routes.
     */
    public function testGetAllUsedPermissionsCollectsUniques(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/a', fn() => null, ['read:posts']);
        $router->post('/b', fn() => null, ['write:posts', 'read:posts']);
        $router->get('/open', fn() => null); // no permissions

        // Act
        $perms = $router->getAllUsedPermissions();

        // Assert – deduped, order-independent
        $this->assertCount(2, $perms);
        $this->assertContains('read:posts', $perms);
        $this->assertContains('write:posts', $perms);
    }

    /**
     * getRoutesWithPermissions() returns a nested array grouped by HTTP method.
     */
    public function testGetRoutesWithPermissionsGroupsByMethod(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/users', fn() => null, ['read:users']);
        $router->post('/users', fn() => null, ['write:users']);

        // Act
        $result = $router->getRoutesWithPermissions();

        // Assert – both methods are present
        $this->assertArrayHasKey('GET', $result);
        $this->assertArrayHasKey('POST', $result);
        $this->assertArrayHasKey('/users', $result['GET']);
        $this->assertTrue($result['GET']['/users']['hasPermissions']);
        $this->assertSame(['read:users'], $result['GET']['/users']['permissions']);
    }

    // =========================================================================
    // getEffectivePermissions()
    // =========================================================================

    /**
     * getEffectivePermissions() expands the full wildcard '*' to include every
     * known scope. Partial wildcards like 'admin:*' are not supported and only
     * the literal scope string is kept (no expansion happens).
     */
    public function testGetEffectivePermissionsExpandsFullWildcard(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $known = ['admin:read', 'admin:write', 'user:read'];

        // Act – full wildcard '*' should expand to all known scopes
        $effective = $router->getEffectivePermissions(['*'], $known);

        // Assert – '*' itself + all known scopes matched by wildcardMatch
        $this->assertContains('*', $effective);
        $this->assertContains('admin:read', $effective);
        $this->assertContains('admin:write', $effective);
        $this->assertContains('user:read', $effective);
    }

    /**
     * getEffectivePermissions() with an exact scope (no wildcard) returns just
     * that scope — no expansion because there is no '*' in the user scope.
     */
    public function testGetEffectivePermissionsExactScopeNotExpanded(): void
    {
        // Arrange
        $router  = $this->makeRouter();
        $known   = ['admin:read', 'user:read'];

        // Act
        $effective = $router->getEffectivePermissions(['user:read'], $known);

        // Assert – only the literal scope, no expansion
        $this->assertSame(['user:read'], $effective);
    }

    // =========================================================================
    // getRequiredPermissions()
    // =========================================================================

    /**
     * getRequiredPermissions() returns the permission array for the matched route.
     */
    public function testGetRequiredPermissionsReturnsRoutePermissions(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/secure', fn() => null, ['admin:read']);

        // Act
        $required = $router->getRequiredPermissions(Request::create('/secure', 'GET'));

        // Assert
        $this->assertSame(['admin:read'], $required);
    }

    /**
     * getRequiredPermissions() returns null when no route is matched.
     */
    public function testGetRequiredPermissionsReturnsNullForUnknownRoute(): void
    {
        // Arrange
        $router = $this->makeRouter();

        // Act / Assert
        $this->assertNull($router->getRequiredPermissions(Request::create('/unknown', 'GET')));
    }

    // =========================================================================
    // addGlobalMiddleware()
    // =========================================================================

    /**
     * addGlobalMiddleware() returns the same router for fluent chaining.
     */
    public function testAddGlobalMiddlewareReturnsSelf(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $mw = new class implements \Pramnos\Http\MiddlewareInterface {
            public function handle(\Pramnos\Http\Request $request, callable $next): mixed { return $next($request); }
        };

        // Act
        $result = $router->addGlobalMiddleware($mw);

        // Assert – fluent API
        $this->assertSame($router, $result);
    }

    /**
     * Global middleware runs before route-specific middleware and the action.
     * This test verifies the execution ORDER by tracking invocation sequence.
     */
    public function testGlobalMiddlewareRunsBeforeRouteMiddleware(): void
    {
        // Arrange
        $log    = [];
        $router = $this->makeRouter();

        $global = new class($log) implements \Pramnos\Http\MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(\Pramnos\Http\Request $req, callable $next): mixed {
                $this->log[] = 'global';
                return $next($req);
            }
        };

        $routeMw = new class($log) implements \Pramnos\Http\MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(\Pramnos\Http\Request $req, callable $next): mixed {
                $this->log[] = 'route';
                return $next($req);
            }
        };

        $router->addGlobalMiddleware($global);
        $router->get('/run', function() use (&$log) {
            $log[] = 'action';
            return 'done';
        })->middleware($routeMw);

        // Act
        $result = $router->dispatch(Request::create('/run', 'GET'));

        // Assert – global → route → action
        $this->assertSame('done', $result);
        $this->assertSame(['global', 'route', 'action'], $log);
    }

    // =========================================================================
    // dispatch() — permission exception
    // =========================================================================

    /**
     * dispatch() throws an exception (403) when user lacks required permissions.
     */
    public function testDispatchThrowsWhenPermissionsMissing(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/admin', fn() => null, ['admin:read']);

        // Assert / Act
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        $router->dispatch(Request::create('/admin', 'GET'), []);
    }

    /**
     * dispatch() includes the missing scope name in the exception message when
     * a specific scope was not matched.
     */
    public function testDispatchExceptionMentionsMissingScope(): void
    {
        // Arrange
        $router = $this->makeRouter();
        $router->get('/reports', fn() => null, ['reports:view']);

        // Assert / Act
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('reports:view');
        $router->dispatch(Request::create('/reports', 'GET'), []);
    }
}
