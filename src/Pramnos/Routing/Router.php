<?php

namespace Pramnos\Routing;

use Pramnos\Framework\Base;
use Pramnos\Interfaces\Router as RouterInterface;

/**
 * The router object class
 * @package     PramnosFramework
 * @subpackage  Routing
 * @copyright   2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @todo        Groups, Domains, Tokens, Regular Expressions
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
            $result = $route->execute($this->container);
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
     * Adds a single route to the router's route collection
     *
     * @param string $uri  The URI pattern for the route
     * @param string $method  The HTTP method for this route
     * @param \Closure|array|string $action  The action to be executed when the route is matched
     * @param array|string|null $permissions  The required permissions for this route
     * @return void
     */
    protected function addSingleRoute($uri, $method, $action, $permissions = null)
    {
        if (!isset($this->routes[$this->fixMethodName($method)])) {
            $this->routes[$this->fixMethodName($method)] = array();
        }
        $route = new Route($uri, $method, $action);
        
        // Set permissions if provided
        if ($permissions !== null) {
            $route->requirePermissions($permissions);
        }
        
        $this->routes[$this->fixMethodName($method)][$uri] = $route;
    }

    /**
     * Register a new GET route with the router.
     *
     * @param  string  $uri  The URI pattern for the route
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router
     */
    public function get($uri, $action, $permissions = null)
    {
        $this->addRoute($uri, 'GET', $action, $permissions);
        return $this;
    }

    /**
     * Register a new POST route with the router.
     *
     * @param  string  $uri  The URI pattern for the route
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router
     */
    public function post($uri, $action, $permissions = null)
    {
        $this->addRoute($uri, 'POST', $action, $permissions);
        return $this;
    }

    /**
     * Register a new PUT route with the router.
     *
     * @param  string  $uri  The URI pattern for the route
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router
     */
    public function put($uri, $action, $permissions = null)
    {
        $this->addRoute($uri, 'PUT', $action, $permissions);
        return $this;
    }

    /**
     * Register a new DELETE route with the router.
     *
     * @param  string  $uri  The URI pattern for the route
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router
     */
    public function delete($uri, $action, $permissions = null)
    {
        $this->addRoute($uri, 'DELETE', $action, $permissions);
        return $this;
    }

    /**
     * Register a new PATCH route with the router.
     *
     * @param  string  $uri  The URI pattern for the route
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router
     */
    public function patch($uri, $action, $permissions = null)
    {
        $this->addRoute($uri, 'PATCH', $action, $permissions);
        return $this;
    }

    /**
     * Register a new OPTIONS route with the router.
     *
     * @param  string  $uri  The URI pattern for the route
     * @param  \Closure|array|string  $action  The action to be executed when the route is matched
     * @param  array|string|null  $permissions  The required permissions for this route
     * @return \Pramnos\Routing\Router
     */
    public function options($uri, $action, $permissions = null)
    {
        $this->addRoute($uri, 'OPTIONS', $action, $permissions);
        return $this;
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
