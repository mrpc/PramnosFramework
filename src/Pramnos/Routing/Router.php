<?php

namespace Pramnos\Routing;

use Pramnos\Framework\Base;
use Pramnos\Interfaces\Router as RouterInterface;

/**
 * The router object class
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @todo        Groups, Domains, Tokens, Regular Expressions
 * @license    MIT
 */
class Router extends Base implements RouterInterface
{

    /**
     * Array to store defined routes
     * @var array
     */
    protected $routes = array();

    /**
     * Supported methods
     * @var array
     */
    private $methods = array(
        'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'
    );

    /**
     * IoC Container that will be used to resolve controllers etc
     * @var \Pramnos\Framework\Container
     */
    private $container;


    /**
     * Failback action - if a route is not found
     * @var \Closure|array|string|null
     */
    public $failback;

    /**
     * Global middleware applied to every dispatched route.
     * @var array<\Pramnos\Http\MiddlewareInterface|class-string>
     */
    private array $globalMiddlewares = [];

    /**
     * Named-route index — keyed by route name.
     * @var array<string, \Pramnos\Routing\Route>
     */
    private array $namedRoutes = [];

    /**
     * Stack of active group attribute sets — nested groups push/pop here.
     * Each entry: ['prefix'=>string, 'middleware'=>array, 'permissions'=>array, 'name'=>string]
     * @var array<int, array>
     */
    private array $groupStack = [];

    private $_invalidScope = null;

    /**
     * Class constructor
     * @param \Pramnos\Framework\Container $container IoC Container
     */
    public function __construct($container)
    {
        $this->container = $container;
        parent::__construct();
    }

    /**
     * Register a new route with the router
     *
     * @param  string  $uri  The URI pattern for the route
     * @param  array|string  $methods  The HTTP methods (GET, POST, etc.) that this route should respond to
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router  Returns the router instance for method chaining
     */
    public function addRoute($uri, $methods, $action, $permissions = null)
    {
        if (is_array($methods)) {
            foreach ($methods as $method) {
                $this->addSingleRoute($uri, $method, $action, $permissions);
            }
        } else {
            $this->addSingleRoute($uri, $methods, $action, $permissions);
        }
        return $this;
    }

    /**
     * Register a middleware that runs on every dispatched route.
     *
     * Global middleware runs before any route-specific middleware.
     * Order: global (registration order) → route-specific (registration order) → action.
     *
     * Usage in ServiceProvider::boot():
     *   $router->addGlobalMiddleware(new CorsMiddleware(['https://app.example.com']));
     *   $router->addGlobalMiddleware(new MaintenanceModeMiddleware());
     *
     * @param  \Pramnos\Http\MiddlewareInterface|class-string $middleware
     * @return static
     */
    public function addGlobalMiddleware(\Pramnos\Http\MiddlewareInterface|string $middleware): static
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    /**
     * Finds and executes a route
     *
     * @param \Pramnos\Http\Request $request  The HTTP request object
     * @param array|string $userPermissions  The permissions that the current user has (array or space-separated string)
     * @return mixed|null  Returns the result of the executed route action, or null if no route is found
     * @throws \Exception  Throws exception if user doesn't have required permissions
     */
    public function dispatch(\Pramnos\Http\Request $request, $userPermissions = array())
    {
        $route = $this->getMatchedRoute($request);
        if ($route) {
            // Check permissions before executing
            if (!$this->hasPermissions($route, $userPermissions)) {
                if ($this->_invalidScope !== null) {
                    throw new \Exception('Insufficient permissions to access this route. Missing scope: ' . $this->_invalidScope, 403);
                }
                throw new \Exception('Insufficient permissions to access this route', 403);
            }

            $allMiddlewares = array_merge($this->globalMiddlewares, $route->getMiddleware());
            if (!empty($allMiddlewares)) {
                $container = $this->container;
                $pipeline  = new \Pramnos\Http\MiddlewarePipeline();
                foreach ($allMiddlewares as $mw) {
                    $pipeline->pipe($mw);
                }
                return $pipeline->run($request, fn(\Pramnos\Http\Request $r) => $route->execute($container));
            }

            return $route->execute($this->container);
        }
        return null;
    }

    /**
     * Finds and executes a route with safe permission checking (doesn't throw exceptions)
     *
     * @param \Pramnos\Http\Request $request  The HTTP request object
     * @param array|string $userPermissions  The permissions that the current user has (array or space-separated string)
     * @return array  Returns array with 'success', 'result', 'error', and 'route' keys
     */
    public function dispatchSafe(\Pramnos\Http\Request $request, $userPermissions = array())
    {
        $route = $this->getMatchedRoute($request);
        
        if (!$route) {
            return array(
                'data' => null,
                'error' => 'RouteNotFound',
                'message' => 'Route not found',
                'route' => null
            );
        }
        
        // Check permissions before executing
        if (!$this->hasPermissions($route, $userPermissions)) {
            return array(
                'data' => null,
                'error' => 'InsufficientPermissions',
                'message' => 'You do not have permission to access this route',
                'route' => $route
            );
        }
        
        try {
            $allMiddlewares = array_merge($this->globalMiddlewares, $route->getMiddleware());
            if (!empty($allMiddlewares)) {
                $container = $this->container;
                $pipeline  = new \Pramnos\Http\MiddlewarePipeline();
                foreach ($allMiddlewares as $mw) {
                    $pipeline->pipe($mw);
                }
                $result = $pipeline->run($request, fn(\Pramnos\Http\Request $r) => $route->execute($container));
            } else {
                $result = $route->execute($this->container);
            }
            return array(
                'data' => $result,
                'route' => $route
            );
        } catch (\Exception $e) {
            return array(
                'data' => null,
                'error' => 'Error',
                'message' => $e->getMessage(),
                'route' => $route
            );
        }
    }

    /**
     * Finds and executes a route without permission checking
     *
     * @param \Pramnos\Http\Request $request  The HTTP request object
     * @return mixed|null  Returns the result of the executed route action, or null if no route is found
     */
    public function dispatchWithoutPermissions(\Pramnos\Http\Request $request)
    {
        $route = $this->getMatchedRoute($request);
        if ($route) {
            return $route->execute($this->container);
        }
        return null;
    }

    /**
     * Finds and returns a matched route without executing it
     *
     * @param \Pramnos\Http\Request $request  The HTTP request object
     * @return \Pramnos\Routing\Route|null  Returns the matched route object, or null if no route is found
     */
    public function getMatchedRoute(\Pramnos\Http\Request $request)
    {
        $method = $request->getRequestMethod();
        $uri = $request->getRequestUri();
        
        // If there is no route with the selected method, return null
        // no need to check one-by-one
        if (!isset($this->routes[$method])) {
            return null;
        }
        
        // First, we check for static routes (no regex)
        if (isset($this->routes[$method][$uri])) {
            return $this->routes[$method][$uri];
        }
        
        // Advanced matching
        foreach ($this->routes[$method] as $route) {
            if ($route->matches($request)) {
                return $route;
            }
        }
        
        return null;
    }

    /**
     * Validates the method name and makes sure it has the right format
     *
     * @param string $method  The HTTP method to validate
     * @throws \Exception  Throws exception if the method is not supported
     * @return string  Returns the validated and normalized method name
     */
    protected function fixMethodName($method)
    {
        $fixedMethod = trim(strtoupper($method));
        if (array_search($fixedMethod, $this->methods) === false) {
            throw new \Exception('Invalid Method: ' . $fixedMethod);
        }
        return $fixedMethod;
    }

    /**
     * Adds a single route to the router's route collection and returns it.
     *
     * When called inside a Router::group() callback (or discovered inside a
     * class with #[RouteGroup]), the active group stack is merged: URI prefixes
     * are concatenated, middleware and permissions are prepended, and any name
     * prefix is injected into the name-registration callback.
     *
     * @param string $uri  The URI pattern for the route
     * @param string $method  The HTTP method for this route
     * @param \Closure|array|string $action  The action to be executed when the route is matched
     * @param array|string|null $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Route  The created route — callers may chain ->middleware() on it.
     */
    protected function addSingleRoute($uri, $method, $action, $permissions = null): Route
    {
        // Merge active group stack — outer groups first.
        $groupPrefix      = '';
        $groupMiddlewares = [];
        $groupPermissions = [];
        $namePrefix       = '';

        foreach ($this->groupStack as $group) {
            $groupPrefix      .= rtrim($group['prefix'] ?? '', '/');
            $groupMiddlewares  = array_merge($groupMiddlewares, $group['middleware'] ?? []);
            $groupPermissions  = array_merge($groupPermissions, $group['permissions'] ?? []);
            $namePrefix       .= $group['name'] ?? '';
        }

        // Build the full URI: normalize so we always have a leading slash.
        if ($groupPrefix !== '') {
            $uri = '/' . ltrim($groupPrefix . '/' . ltrim($uri, '/'), '/');
            // Collapse double slashes (e.g. prefix '/api' + uri '/' → '/api/')
            $uri = preg_replace('#/{2,}#', '/', $uri);
        }

        if (!isset($this->routes[$this->fixMethodName($method)])) {
            $this->routes[$this->fixMethodName($method)] = array();
        }
        $route = new Route($uri, $method, $action);

        // Inject callback so that Route::name() auto-registers in $namedRoutes.
        // If there is a group name prefix, prepend it transparently.
        $route->setNameRegistrationCallback(function(string $name, Route $r) use ($namePrefix): void {
            $this->namedRoutes[$namePrefix . $name] = $r;
        });

        // Group middleware runs before per-route middleware (prepended at pipeline build time).
        if (!empty($groupMiddlewares)) {
            $route->prependMiddleware(...$groupMiddlewares);
        }

        // Merge group permissions with per-route permissions — no scope validation
        // because the group may supply internal permission strings.
        if (!empty($groupPermissions)) {
            $route->addPermissions($groupPermissions, false);
        }

        // Set per-route permissions if provided
        if ($permissions !== null) {
            $route->requirePermissions($permissions);
        }

        $this->routes[$this->fixMethodName($method)][$uri] = $route;
        return $route;
    }

    /**
     * Define a route group — apply shared attributes to all routes registered
     * inside the callback.
     *
     * Supported keys in `$attributes`:
     * - `prefix`      (string) — URI prefix prepended to every route URI.
     * - `middleware`  (array)  — Middleware applied before each route's own middleware.
     * - `permissions` (array)  — Permission scopes merged with each route's permissions.
     * - `name`        (string) — Name prefix prepended to every named route's logical name.
     *
     * Groups can be nested; inner group attributes stack on top of outer ones.
     *
     * ```php
     * $router->group(['prefix' => '/api/v1', 'middleware' => [ApiAuthMiddleware::class]], function($r) {
     *     $r->get('/users', fn() => ...)->name('users.index');   // GET /api/v1/users
     *
     *     $r->group(['prefix' => '/admin', 'permissions' => ['admin']], function($r) {
     *         $r->delete('/users/{id}', fn($id) => ...);         // DELETE /api/v1/admin/users/{id}
     *     });
     * });
     * ```
     *
     * @param  array<string,mixed>  $attributes  Group options (see above).
     * @param  \Closure             $callback    Routes defined inside this closure inherit the group.
     * @return void
     */
    public function group(array $attributes, \Closure $callback): void
    {
        $this->groupStack[] = [
            'prefix'      => $attributes['prefix']      ?? '',
            'middleware'  => (array) ($attributes['middleware']  ?? []),
            'permissions' => (array) ($attributes['permissions'] ?? []),
            'name'        => $attributes['name'] ?? '',
        ];

        $callback($this);

        array_pop($this->groupStack);
    }

    /**
     * Register a new GET route and return it for optional middleware chaining.
     *
     *   $router->get('/api/users', fn() => ...)
     *          ->middleware(new AuthMiddleware());
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @param  array|string|null  $permissions
     * @return \Pramnos\Routing\Route
     */
    public function get($uri, $action, $permissions = null): Route
    {
        return $this->addSingleRoute($uri, 'GET', $action, $permissions);
    }

    /**
     * Register a new POST route and return it for optional middleware chaining.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @param  array|string|null  $permissions
     * @return \Pramnos\Routing\Route
     */
    public function post($uri, $action, $permissions = null): Route
    {
        return $this->addSingleRoute($uri, 'POST', $action, $permissions);
    }

    /**
     * Register a new PUT route and return it for optional middleware chaining.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @param  array|string|null  $permissions
     * @return \Pramnos\Routing\Route
     */
    public function put($uri, $action, $permissions = null): Route
    {
        return $this->addSingleRoute($uri, 'PUT', $action, $permissions);
    }

    /**
     * Register a new DELETE route and return it for optional middleware chaining.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @param  array|string|null  $permissions
     * @return \Pramnos\Routing\Route
     */
    public function delete($uri, $action, $permissions = null): Route
    {
        return $this->addSingleRoute($uri, 'DELETE', $action, $permissions);
    }

    /**
     * Register a new PATCH route and return it for optional middleware chaining.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @param  array|string|null  $permissions
     * @return \Pramnos\Routing\Route
     */
    public function patch($uri, $action, $permissions = null): Route
    {
        return $this->addSingleRoute($uri, 'PATCH', $action, $permissions);
    }

    /**
     * Register a new OPTIONS route and return it for optional middleware chaining.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @param  array|string|null  $permissions
     * @return \Pramnos\Routing\Route
     */
    public function options($uri, $action, $permissions = null): Route
    {
        return $this->addSingleRoute($uri, 'OPTIONS', $action, $permissions);
    }

    /**
     * Register a new HEAD route and return it for optional middleware chaining.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @param  array|string|null  $permissions
     * @return \Pramnos\Routing\Route
     */
    public function head($uri, $action, $permissions = null): Route
    {
        return $this->addSingleRoute($uri, 'HEAD', $action, $permissions);
    }

    /**
     * Register a new route with the given verbs.
     *
     * @param  array|string  $methods  The HTTP methods that this route should respond to
     * @param  string  $uri  The URI pattern for the route
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router
     */
    public function match($methods, $uri, $action, $permissions = null)
    {
        if (is_string($methods)) {
            $methods = explode(',', $methods);
        }
        return $this->addRoute($uri, $methods, $action, $permissions);
    }

    /**
     * Check if user has required permissions for a route (with scope support)
     *
     * @param \Pramnos\Routing\Route $route  The route to check permissions for
     * @param array|string $userPermissions  The permissions that the user has (array or space-separated string)
     * @return bool  True if user has all required permissions, false otherwise
     */
    public function hasPermissions(\Pramnos\Routing\Route $route, $userPermissions = array())
    {
        if (!$route->hasPermissions()) {
            return true; // No permissions required
        }

        // Normalize user permissions to array
        $userPermissions = $this->normalizePermissions($userPermissions);

        $requiredPermissions = $route->getPermissions();
        
        // Check if user has all required permissions using scope matching
        foreach ($requiredPermissions as $requiredPermission) {
            if (!$this->hasScope($requiredPermission, $userPermissions)) {
                $this->_invalidScope = $requiredPermission;
                return false;
            }
        }
        
        return true;
    }

    /**
     * Normalize permissions to array format
     *
     * @param array|string $permissions  Permissions as array or space-separated string
     * @return array  Normalized permissions array
     */
    protected function normalizePermissions($permissions)
    {
        if (is_array($permissions)) {
            return $permissions;
        }
        
        if (is_string($permissions)) {
            // Handle space-separated scopes (OAuth2 style)
            if (strpos($permissions, ' ') !== false) {
                return array_filter(explode(' ', trim($permissions)));
            }
            // Single permission
            return array($permissions);
        }
        
        return array();
    }

    /**
     * Check if user has a specific scope/permission
     *
     * @param string $requiredScope  The scope/permission to check for
     * @param array $userScopes  The scopes/permissions that the user has
     * @return bool  True if user has the required scope, false otherwise
     */
    protected function hasScope($requiredScope, $userScopes)
    {
        // Direct match
        if (in_array($requiredScope, $userScopes)) {
            return true;
        }

        // Check for wildcard matches only (no hierarchical assumptions)
        foreach ($userScopes as $userScope) {
            if ($this->wildcardMatch($requiredScope, $userScope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for wildcard scope matches (flexible pattern matching)
     *
     * @param string $requiredScope  The required scope
     * @param string $userScope  The user's scope (may contain wildcards)
     * @return bool  True if wildcard matches
     */
    protected function wildcardMatch($requiredScope, $userScope)
    {
        // Handle full wildcard
        if ($userScope === '*') {
            return true;
        }

        // No wildcards in user scope
        if (strpos($userScope, '*') === false) {
            return false;
        }

        // Convert wildcard pattern to regex pattern
        $pattern = str_replace('*', '.*', preg_quote($userScope, '/'));
        
        // Match the pattern
        return preg_match('/^' . $pattern . '$/', $requiredScope);
    }

    /**
     * Get all routes with their permissions
     *
     * @return array  Array of routes grouped by method with their permissions
     */
    public function getRoutesWithPermissions()
    {
        $result = array();
        
        foreach ($this->routes as $method => $routes) {
            $result[$method] = array();
            foreach ($routes as $uri => $route) {
                $result[$method][$uri] = array(
                    'route' => $route,
                    'permissions' => $route->getPermissions(),
                    'hasPermissions' => $route->hasPermissions()
                );
            }
        }
        
        return $result;
    }

    /**
     * Parse a scope string into its components (flexible parsing)
     *
     * @param string $scope  The scope to parse (e.g., "read:users", "user_read", "users.read")
     * @return array  Array with parsed components and detected format
     */
    public function parseScope($scope)
    {
        $result = array(
            'original' => $scope,
            'format' => 'unknown',
            'parts' => array()
        );
        
        // Detect format and parse accordingly
        if (strpos($scope, ':') !== false) {
            // Colon format: action:resource or resource:action
            $parts = explode(':', $scope);
            $result['format'] = 'colon';
            $result['parts'] = $parts;
        } elseif (strpos($scope, '_') !== false) {
            // Underscore format: action_resource or resource_action
            $parts = explode('_', $scope);
            $result['format'] = 'underscore';
            $result['parts'] = $parts;
        } elseif (strpos($scope, '.') !== false) {
            // Dot format: action.resource or resource.action
            $parts = explode('.', $scope);
            $result['format'] = 'dot';
            $result['parts'] = $parts;
        } elseif (strpos($scope, '-') !== false) {
            // Dash format: action-resource or resource-action
            $parts = explode('-', $scope);
            $result['format'] = 'dash';
            $result['parts'] = $parts;
        } else {
            // Single word format
            $result['format'] = 'single';
            $result['parts'] = array($scope);
        }
        
        return $result;
    }

    /**
     * Check what permissions a user would need for a specific route
     *
     * @param \Pramnos\Http\Request $request  The HTTP request object
     * @return array|null  Array of required permissions, or null if route not found
     */
    public function getRequiredPermissions(\Pramnos\Http\Request $request)
    {
        $route = $this->getMatchedRoute($request);
        
        if (!$route) {
            return null;
        }
        
        return $route->getPermissions();
    }

    /**
     * Get all unique scopes/permissions used across all routes
     *
     * @return array  Array of unique permissions used in the router
     */
    public function getAllUsedPermissions()
    {
        $permissions = array();
        
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $uri => $route) {
                if ($route->hasPermissions()) {
                    $permissions = array_merge($permissions, $route->getPermissions());
                }
            }
        }
        
        return array_unique($permissions);
    }

    /**
     * Validate if a scope follows the expected format
     *
     * @param string $scope  The scope to validate
     * @return bool  True if scope is valid, false otherwise
     */
    public function isValidScope($scope)
    {
        // Allow any alphanumeric characters with common separators
        // Supports: user_read, users:read, user.read, user-read, admin_all, etc.
        return preg_match('/^[a-zA-Z*][a-zA-Z0-9_:.*-]*$/', $scope);
    }

    // -------------------------------------------------------------------------
    // Named routes & URL generation
    // -------------------------------------------------------------------------

    /**
     * Look up a route by its logical name.
     *
     * @param  string  $name  The name assigned via Route::name() or #[Route(name: '…')].
     * @return \Pramnos\Routing\Route|null
     */
    public function getByName(string $name): ?Route
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Generate a URL for a named route, substituting URI parameters.
     *
     * Replaces `{param}` and `{param?}` placeholders with the values from
     * `$params`. Any remaining optional segments are stripped. Required
     * parameters that are not supplied remain as-is in the returned string.
     *
     * ```php
     * $router->get('/users/{id}', fn() => ...)->name('users.show');
     * echo $router->route('users.show', ['id' => 42]); // '/users/42'
     *
     * $router->get('/posts/{year}/{slug?}', fn() => ...)->name('posts.show');
     * echo $router->route('posts.show', ['year' => 2026]); // '/posts/2026'
     * ```
     *
     * @param  string               $name    The route name.
     * @param  array<string, mixed> $params  URI parameter values keyed by name.
     * @return string  The generated URI.
     * @throws \InvalidArgumentException  When the name does not match any registered route.
     */
    public function route(string $name, array $params = []): string
    {
        $route = $this->getByName($name);
        if ($route === null) {
            throw new \InvalidArgumentException("Route [{$name}] not defined.");
        }
        return $this->buildUrl($route->uri, $params);
    }

    /**
     * Substitute URI placeholders with concrete values and strip leftover optionals.
     *
     * @param  string               $uri
     * @param  array<string, mixed> $params
     * @return string
     */
    private function buildUrl(string $uri, array $params): string
    {
        foreach ($params as $key => $value) {
            $encoded = rawurlencode((string) $value);
            $uri = str_replace(
                ['{' . $key . '}', '{' . $key . '?}'],
                $encoded,
                $uri
            );
        }
        // Remove remaining optional segments (with their leading slash if present)
        $uri = preg_replace('#/?\{[^}]+\?\}#', '', $uri);
        return $uri;
    }

    // -------------------------------------------------------------------------
    // Attribute-based Route Discovery
    // -------------------------------------------------------------------------

    /**
     * Scan a directory for controller classes decorated with #[Route] attributes
     * and register all discovered routes with this router.
     *
     * Each PHP file under `$path` is required and its corresponding class
     * (derived by replacing directory separators with namespace separators) is
     * inspected via Reflection. Public methods carrying one or more
     * `#[\Pramnos\Routing\Attributes\Route]` attributes are registered
     * automatically.
     *
     * ```php
     * $router->loadFromDirectory(
     *     __DIR__ . '/Controllers',
     *     'App\\Controllers'
     * );
     * ```
     *
     * @param  string $path       Absolute path to the controller directory.
     * @param  string $namespace  Root namespace that maps to `$path`.
     */
    public function loadFromDirectory(string $path, string $namespace): void
    {
        (new RouteDiscovery($this))->discover($path, $namespace);
    }

    /**
     * Get effective permissions for a user (expand wildcards only)
     *
     * @param array|string $userScopes  The user's scopes (array or space-separated string)
     * @param array $allKnownScopes  All known scopes in the system (optional)
     * @return array  Array of effective permissions
     */
    public function getEffectivePermissions($userScopes, $allKnownScopes = null)
    {
        // Normalize user scopes to array
        $userScopes = $this->normalizePermissions($userScopes);
        
        if ($allKnownScopes === null) {
            $allKnownScopes = $this->getAllUsedPermissions();
        }
        
        $effectivePermissions = array();
        
        foreach ($userScopes as $userScope) {
            $effectivePermissions[] = $userScope;
            
            // Expand wildcards only
            if (strpos($userScope, '*') !== false) {
                foreach ($allKnownScopes as $knownScope) {
                    if ($this->wildcardMatch($knownScope, $userScope)) {
                        $effectivePermissions[] = $knownScope;
                    }
                }
            }
        }
        
        return array_unique($effectivePermissions);
    }
}
