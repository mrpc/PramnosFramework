<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Routing\Attributes\Route;

/**
 * Unit tests for Pramnos\Routing\Attributes\Route.
 *
 * Route is a PHP 8 attribute that marks a controller method as a route handler.
 * It stores the URI pattern, HTTP method(s), logical name, required permissions,
 * and middleware list as readonly properties so RouteDiscovery::discover() can
 * read them via reflection.
 *
 * These tests verify the stored values after construction — both for required
 * parameters and for all optional defaults.
 */
#[CoversClass(Route::class)]
class RouteAttributeTest extends TestCase
{
    // =========================================================================
    // Required parameter: uri
    // =========================================================================

    /**
     * The uri is the only required argument.  After construction it is
     * accessible as a readonly property.
     */
    public function testUriIsStoredAsReadonlyProperty(): void
    {
        // Arrange / Act
        $route = new Route('/api/users');

        // Assert
        $this->assertSame('/api/users', $route->uri);
    }

    // =========================================================================
    // Default values
    // =========================================================================

    /**
     * When only the URI is given, methods defaults to 'GET' — the most common
     * route verb so controllers need not repeat it on every read-only endpoint.
     */
    public function testMethodsDefaultsToGet(): void
    {
        // Arrange / Act
        $route = new Route('/api/posts');

        // Assert
        $this->assertSame('GET', $route->methods);
    }

    /**
     * name defaults to null — unnamed routes are valid; the name is only
     * needed when Router::route($name) is used to generate URLs.
     */
    public function testNameDefaultsToNull(): void
    {
        // Arrange / Act
        $route = new Route('/api/posts');

        // Assert
        $this->assertNull($route->name);
    }

    /**
     * permissions defaults to an empty array — no scope restrictions.
     */
    public function testPermissionsDefaultsToEmptyArray(): void
    {
        // Arrange / Act
        $route = new Route('/api/posts');

        // Assert
        $this->assertSame([], $route->permissions);
    }

    /**
     * middleware defaults to an empty array — no middleware stack.
     */
    public function testMiddlewareDefaultsToEmptyArray(): void
    {
        // Arrange / Act
        $route = new Route('/api/posts');

        // Assert
        $this->assertSame([], $route->middleware);
    }

    // =========================================================================
    // Named arguments
    // =========================================================================

    /**
     * All optional parameters can be supplied in any order using named
     * constructor arguments.
     */
    public function testAllParametersCanBeSetViaNamedArguments(): void
    {
        // Arrange / Act
        $route = new Route(
            uri:         '/admin/reports',
            methods:     ['GET', 'HEAD'],
            name:        'admin.reports',
            permissions: ['admin:read', 'reports:view'],
            middleware:  ['App\\Http\\Middleware\\AuthMiddleware'],
        );

        // Assert
        $this->assertSame('/admin/reports', $route->uri);
        $this->assertSame(['GET', 'HEAD'], $route->methods);
        $this->assertSame('admin.reports', $route->name);
        $this->assertSame(['admin:read', 'reports:view'], $route->permissions);
        $this->assertSame(['App\\Http\\Middleware\\AuthMiddleware'], $route->middleware);
    }

    /**
     * methods accepts a single string for the common single-verb case.
     */
    public function testMethodsAcceptsString(): void
    {
        // Arrange / Act
        $route = new Route('/api/posts', methods: 'POST');

        // Assert
        $this->assertSame('POST', $route->methods);
    }

    /**
     * methods also accepts an array for endpoints that respond to multiple
     * HTTP verbs (e.g. PUT and PATCH for a partial/full update endpoint).
     */
    public function testMethodsAcceptsArray(): void
    {
        // Arrange / Act
        $route = new Route('/api/posts/{id}', methods: ['PUT', 'PATCH']);

        // Assert
        $this->assertSame(['PUT', 'PATCH'], $route->methods);
    }

    /**
     * name is stored and retrievable when explicitly set.
     */
    public function testNameIsStoredWhenProvided(): void
    {
        // Arrange / Act
        $route = new Route('/api/users/{id}', name: 'users.show');

        // Assert
        $this->assertSame('users.show', $route->name);
    }

    // =========================================================================
    // PHP attribute metadata
    // =========================================================================

    /**
     * The Route class is declared with #[Attribute], so it can be read via
     * ReflectionClass::getAttributes().  This test verifies it is recognised
     * as an attribute at runtime.
     */
    public function testRouteClassIsRegisteredAsPhpAttribute(): void
    {
        // Arrange
        $refClass = new \ReflectionClass(Route::class);

        // Act – check the Attribute annotation on the class itself
        $attrs = $refClass->getAttributes(\Attribute::class);

        // Assert – Route has the #[Attribute] declaration
        $this->assertNotEmpty($attrs, 'Route class must be annotated with #[Attribute]');
    }

    /**
     * The Attribute declaration includes IS_REPEATABLE so a single method can
     * carry multiple Route attributes (multi-URI mapping).
     */
    public function testRouteAttributeIsRepeatable(): void
    {
        // Arrange
        $refClass = new \ReflectionClass(Route::class);
        $attrs    = $refClass->getAttributes(\Attribute::class);

        // Act – instantiate the Attribute metadata to inspect its flags
        $attrInstance = $attrs[0]->newInstance();

        // Assert – the IS_REPEATABLE flag is set
        $this->assertSame(
            \Attribute::IS_REPEATABLE,
            $attrInstance->flags & \Attribute::IS_REPEATABLE
        );
    }
}
