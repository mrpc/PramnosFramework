<?php

namespace Pramnos\Routing;
use Symfony\Component\Routing\Route as SymfonyRoute;

/**
 * A Route
 * @package     PramnosFramework
 * @subpackage  Routing
 * @copyright   2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Route
{
    /**
     * Route uri
     * @var string
     */
    public $uri = '';
    /**
     * Route method
     * @var string
     */
    public $method;
    /**
     * Action to execute on dispatch
     * @var \Closure|string
     */
    public $action;

    /**
     * Default values for the optional variables
     * @var array
     */
    public $defaults = array();
    /**
     * Array of parameters we get from the request
     * @var array
     */
    public $parameters = array();
    /**
     * Compiled symfony pattern
     * @var Symfony\Component\Routing\CompiledRoute
     */
    protected $compiled = null;

    /**
     * Required permissions for this route
     * @var array
     */
    public $permissions = array();

    /**
     * Middleware stack for this route
     * @var array<\Pramnos\Http\MiddlewareInterface|class-string>
     */
    private array $middlewares = [];

    /**
     * Logical name for this route (used by Router::route() for URL generation).
     * @var string|null
     */
    public ?string $routeName = null;

    /**
     * Optional callback invoked by name() to register the name with the Router.
     * Set by Router::addSingleRoute() via setNameRegistrationCallback().
     * @var \Closure|null
     */
    private ?\Closure $nameRegistrationCallback = null;

    /**
     * class constructor
     * @param string $uri
     * @param string $method
     * @param string $action
     */
    public function __construct($uri, $method, $action)
    {
        $this->action = $action;
        $this->uri = $uri;
        $this->method = $method;
    }

    /**
     * Set required permissions for this route
     *
     * @param array|string $permissions The required permissions
     * @param bool $validateScopes Whether to validate scope format (default: true)
     * @return \Pramnos\Routing\Route Returns the route instance for method chaining
     * @throws \InvalidArgumentException If scope validation fails
     */
    public function requirePermissions($permissions, $validateScopes = true)
    {
        if (is_string($permissions)) {
            $permissions = array($permissions);
        }
        
        // Validate scopes if requested
        if ($validateScopes) {
            foreach ($permissions as $permission) {
                if (!$this->isValidScope($permission)) {
                    throw new \InvalidArgumentException("Invalid scope format: {$permission}");
                }
            }
        }
        
        $this->permissions = $permissions;
        return $this;
    }

    /**
     * Add additional permissions to this route
     *
     * @param array|string $permissions The additional permissions to add
     * @param bool $validateScopes Whether to validate scope format (default: true)
     * @return \Pramnos\Routing\Route Returns the route instance for method chaining
     * @throws \InvalidArgumentException If scope validation fails
     */
    public function addPermissions($permissions, $validateScopes = true)
    {
        if (is_string($permissions)) {
            $permissions = array($permissions);
        }
        
        // Validate scopes if requested
        if ($validateScopes) {
            foreach ($permissions as $permission) {
                if (!$this->isValidScope($permission)) {
                    throw new \InvalidArgumentException("Invalid scope format: {$permission}");
                }
            }
        }
        
        $this->permissions = array_unique(array_merge($this->permissions, $permissions));
        return $this;
    }

    /**
     * Remove permissions from this route
     *
     * @param array|string $permissions The permissions to remove
     * @return \Pramnos\Routing\Route Returns the route instance for method chaining
     */
    public function removePermissions($permissions)
    {
        if (is_string($permissions)) {
            $permissions = array($permissions);
        }
        
        $this->permissions = array_diff($this->permissions, $permissions);
        return $this;
    }

    /**
     * Validate if a scope follows the expected format
     *
     * @param string $scope  The scope to validate
     * @return bool  True if scope is valid, false otherwise
     */
    protected function isValidScope($scope)
    {
        // Allow simple permissions without colons
        if (strpos($scope, ':') === false) {
            return preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $scope);
        }
        
        // Validate scope format: action:resource[:subresource]
        if (!preg_match('/^[a-zA-Z*][a-zA-Z0-9_*]*(?::[a-zA-Z*][a-zA-Z0-9_*]*)*$/', $scope)) {
            return false;
        }
        
        // Check for valid wildcard usage
        $parts = explode(':', $scope);
        foreach ($parts as $part) {
            if ($part === '*') {
                continue; // Single * is valid
            }
            if (strpos($part, '*') !== false) {
                return false; // * must be standalone in each part
            }
        }
        
        return true;
    }

    /**
     * Get the required permissions for this route
     *
     * @return array The required permissions
     */
    public function getPermissions()
    {
        return $this->permissions;
    }

    /**
     * Check if the route has any required permissions
     *
     * @return bool True if permissions are required, false otherwise
     */
    public function hasPermissions()
    {
        return !empty($this->permissions);
    }

    /**
     * Attach one or more middleware to this route.
     *
     * Accepts instances or FQCN strings (lazy-instantiated by the pipeline).
     * Returns $this for fluent chaining after route registration:
     *
     *   $router->get('/api/users', fn() => ...)
     *          ->middleware(new AuthMiddleware(), new ThrottleMiddleware(60, 60));
     *
     * @param  \Pramnos\Http\MiddlewareInterface|class-string ...$middlewares
     * @return static
     */
    public function middleware(\Pramnos\Http\MiddlewareInterface|string ...$middlewares): static
    {
        foreach ($middlewares as $mw) {
            $this->middlewares[] = $mw;
        }
        return $this;
    }

    /**
     * Return all middleware attached to this route.
     *
     * @return array<\Pramnos\Http\MiddlewareInterface|class-string>
     */
    public function getMiddleware(): array
    {
        return $this->middlewares;
    }

    /**
     * Whether this route has any middleware registered.
     */
    public function hasMiddleware(): bool
    {
        return !empty($this->middlewares);
    }

    /**
     * Assign a logical name to this route.
     *
     * Names are used by Router::route() to generate URLs without hard-coding
     * URI patterns in application code:
     *
     *   $router->get('/users/{id}', fn($id) => ...)->name('users.show');
     *   $url = $router->route('users.show', ['id' => 42]); // '/users/42'
     *
     * If a name-registration callback was injected by the Router
     * (via setNameRegistrationCallback), it is called immediately so the
     * Router's named-route index is updated at definition time.
     *
     * @param  string $name  Dot-notation name, e.g. 'users.index', 'api.v1.posts.store'.
     * @return static
     */
    public function name(string $name): static
    {
        $this->routeName = $name;
        if ($this->nameRegistrationCallback !== null) {
            ($this->nameRegistrationCallback)($name, $this);
        }
        return $this;
    }

    /**
     * Return the logical name of this route, or null if unnamed.
     */
    public function getName(): ?string
    {
        return $this->routeName;
    }

    /**
     * Inject the callback that registers a name with the owning Router.
     * Called by Router::addSingleRoute() immediately after creating the route.
     */
    public function setNameRegistrationCallback(\Closure $callback): void
    {
        $this->nameRegistrationCallback = $callback;
    }

    /**
     * Determine if the route matches given request.
     *
     * @param  \Pramnos\Http\Request  $request
     * @return bool
     */
    public function matches(\Pramnos\Http\Request $request)
    {
        $method = $request->getRequestMethod();
        $uri = $request->getRequestUri();
        if ($this->method != $method) {
            return false;
        }
        if ($this->uri == $uri) {
            return true;
        }
        // If it doesn't match anything until now, it's time for regex matches
        $this->extractOptionalParameters();
        // Remove all optional marks to parse it through symfony framework
        if (preg_match(
            $this->getCompiledRoute()->getRegex(),
            '/' . $uri,
            $this->parameters
        )) {
            return true;
        }

        // Check if $this->uri contains query parameters
        if (strpos($this->uri, '?') === false) {
            // Remove query parameters from the URI
            $uri = parse_url($uri, PHP_URL_PATH);
            if ($this->uri == $uri) {
            return true;
            }
            if (preg_match(
            $this->getCompiledRoute()->getRegex(),
            '/' . $uri,
            $this->parameters
            )) {
            return true;
            }
        }

        return false;
    }

    /**
     * Generate or return the symfony compiled route
     *
     * @return Symfony\Component\Routing\CompiledRoute
     */
    protected function getCompiledRoute()
    {
        if ($this->compiled == null) {
            $removedOptionalsUri = preg_replace(
                '/\{(\w+?)\?\}/', '{$1}', $this->uri
            );
            $SymfonyRoute = new SymfonyRoute(
                $removedOptionalsUri, $this->defaults, array(), array()
            );
            $this->compiled = $SymfonyRoute->compile();
        }
        return $this->compiled;
    }

    /**
     * Execute the action of this route
     * @param \Pramnos\Framework\Container $container IoC Container
     */
    public function execute($container)
    {
        if (is_callable($this->action)) {
            $reflectFunction = new \ReflectionFunction($this->action);
            $parameters = $reflectFunction->getParameters();
            $runParams = array_merge($this->defaults, $this->parameters);
            $finalArray = array();
            foreach ($parameters as $param) {
                if (isset($runParams[$param->name])
                    && $runParams[$param->name] != null ) {
                    $finalArray[$param->name] = $runParams[$param->name];
                }
            }
            return call_user_func_array($this->action, $finalArray);
        }
    }

    /**
     * Get the optional parameters for the route.
     * This is based mainly on the laravel framework - with minor changes
     *
     * @link        http://laravel.com/ Laravel.com
     * @license     http://opensource.org/licenses/MIT MIT
     * @copyright   Copyright (c) <Taylor Otwell>
     * @return      array
     */
    protected function extractOptionalParameters()
    {
        $matches = array();
        preg_match_all('/\{(\w+?)\?\}/', $this->uri, $matches);
        if (isset($matches[1])) {
            $this->defaults = array_fill_keys($matches[1], null);
        }
    }
}
