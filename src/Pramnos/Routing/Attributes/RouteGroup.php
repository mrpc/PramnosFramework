<?php

declare(strict_types=1);

namespace Pramnos\Routing\Attributes;

/**
 * Marks a controller class as a route group, applying shared attributes
 * (prefix, middleware, permissions, name prefix) to every #[Route] method inside it.
 *
 * ```php
 * #[RouteGroup(prefix: '/api/v1', middleware: [ApiAuthMiddleware::class], name: 'api.v1.')]
 * class UserController {
 *     #[Route('/users',      methods: 'GET',  name: 'users.index')]
 *     #[Route('/users/{id}', methods: 'GET',  name: 'users.show')]
 *     public function index() { … }
 * }
 * // Registers: GET /api/v1/users  named  api.v1.users.index
 * //            GET /api/v1/users/{id}  named  api.v1.users.show
 * ```
 *
 * Can also be used programmatically via Router::group():
 *
 * ```php
 * $router->group(['prefix' => '/api/v1', 'middleware' => [ApiAuthMiddleware::class]], function($r) {
 *     $r->get('/users', fn() => ...)->name('users.index');
 * });
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Routing\Attributes
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class RouteGroup
{
    /**
     * @param string    $prefix      URI prefix prepended to every route in the group.
     * @param array     $middleware  FQCN strings of middleware classes applied to every route.
     * @param array     $permissions Required permission scopes merged with each route's own permissions.
     * @param string    $name        Name prefix prepended to every named route's logical name.
     */
    public function __construct(
        public readonly string $prefix      = '',
        public readonly array  $middleware  = [],
        public readonly array  $permissions = [],
        public readonly string $name        = '',
    ) {}
}
