<?php

declare(strict_types=1);

namespace Pramnos\Routing\Attributes;

/**
 * Marks a controller method as a route handler.
 *
 * The attribute is repeatable so a single method can serve multiple URIs:
 *
 * ```php
 * #[Route('/api/users',        methods: 'GET',         name: 'users.index')]
 * #[Route('/api/users/{id}',   methods: 'GET',         name: 'users.show')]
 * #[Route('/api/users',        methods: 'POST',        name: 'users.store')]
 * #[Route('/api/users/{id}',   methods: ['PUT','PATCH'], name: 'users.update')]
 * class UserController {
 *     public function index() { … }
 * }
 * ```
 *
 * RouteDiscovery::discover() reads these attributes and registers the routes
 * with the Router automatically.
 *
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Route
{
    /**
     * @param string            $uri          URI pattern (may include {param} or {param?} segments).
     * @param string|string[]   $methods      HTTP method(s) — e.g. 'GET', ['GET','HEAD'], 'POST'.
     * @param string|null       $name         Logical route name used by Router::route().
     * @param string[]          $permissions  Required permission scopes (same format as requirePermissions()).
     * @param string[]          $middleware   FQCN strings of middleware classes to attach to the route.
     */
    public function __construct(
        public readonly string            $uri,
        public readonly string|array      $methods     = 'GET',
        public readonly ?string           $name        = null,
        public readonly array             $permissions = [],
        public readonly array             $middleware  = [],
    ) {}
}
