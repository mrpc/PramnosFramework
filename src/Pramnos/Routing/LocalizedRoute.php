<?php

namespace Pramnos\Routing;

/**
 * A fluent handle over the sibling routes created by registering one route
 * per locale (e.g. `$router->get(['el' => '/x', 'en' => '/y'], $action)`).
 *
 * Not a Route subclass: a locale group is several routes sharing a name and
 * a group id ({@see Route::$localeGroupId}), not one route with several
 * URIs. Chaining ->name()/->middleware()/->requirePermissions() on this
 * handle applies the call to every sibling, so a localized route group
 * reads exactly like registering a single route.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
final class LocalizedRoute
{
    /**
     * @param array<string, Route> $routes  Keyed by locale.
     * @param \Closure $registerName  Writes the whole locale map into the
     *                                owning Router's named-route index.
     */
    public function __construct(
        private readonly array $routes,
        private readonly \Closure $registerName
    ) {
    }

    /**
     * Assign a logical name shared by every locale in this group.
     *
     * Router::route($name, $params, $locale) resolves it back to the right
     * sibling; Router::getByName() does not, because a name pointing at
     * several routes has no single answer.
     *
     * @param  string $name
     * @return static
     */
    public function name(string $name): static
    {
        foreach ($this->routes as $route) {
            $route->routeName = $name;
        }
        ($this->registerName)($name);
        return $this;
    }

    /**
     * Attach middleware to every route in this locale group.
     *
     * @param  \Pramnos\Http\MiddlewareInterface|class-string ...$middlewares
     * @return static
     */
    public function middleware(\Pramnos\Http\MiddlewareInterface|string ...$middlewares): static
    {
        foreach ($this->routes as $route) {
            $route->middleware(...$middlewares);
        }
        return $this;
    }

    /**
     * Require permissions on every route in this locale group.
     *
     * @param  array|string $permissions
     * @param  bool $validateScopes
     * @return static
     */
    public function requirePermissions($permissions, $validateScopes = true): static
    {
        foreach ($this->routes as $route) {
            $route->requirePermissions($permissions, $validateScopes);
        }
        return $this;
    }

    /**
     * The underlying routes, keyed by locale.
     *
     * @return array<string, Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }
}
