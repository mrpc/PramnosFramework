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
     * @var type
     */
    public $failback;

    /**
     * Class constructor
     * @param \Pramnos\Framework\Container $container IoC Container
     */
    public function __construct($container)
    {
        $this->container = $container;
        parent::__construct();
    }

    public function addRoute($uri, $methods, $action)
    {
        if (is_array($methods)) {
            foreach ($methods as $method) {
                $this->addSingleRoute($uri, $method, $action);
            }
        } else {
            $this->addSingleRoute($uri, $methods, $action);
        }
        return $this;
    }

    /**
     * Finds and executes a route
     * @param \Pramnos\Http\Request $request
     */
    public function dispatch(\Pramnos\Http\Request $request)
    {
        $method = $request->requestMethod;
        $uri = $request->requestUri;
        // If there is no route with the selected method, return null
        // no need to check one-by-one
        if (!isset($this->routes[$method])) {
            return null;
        }
        // First, we check for static routes (no regex)
        if (isset($this->routes[$method][$uri])) {
            return $this->routes[$method][$uri]->execute($this->container);
        }
        // Advanced matching
        foreach ($this->routes[$method] as $route) {
            if ($route->matches($request)) {
                return $route->execute($this->container);
            }
        }

    }

    /**
     * Validates the method name and makes sure it has the right format
     * @param string $method
     * @throws Exception
     * @return string
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
     * This does the actual work
     * @param string $uri
     * @param string $method
     * @param \Closure|array|string $action
     */
    protected function addSingleRoute($uri, $method, $action)
    {
        if (!isset($this->routes[$this->fixMethodName($method)])) {
            $this->routes[$this->fixMethodName($method)] = array();
        }
        $this->routes[$this->fixMethodName($method)][$uri] = new Route(
            $uri, $method, $action
        );
    }

    /**
     * Register a new GET route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return \Pramnos\Routing\Router
     */
    public function get($uri, $action)
    {
        $this->addRoute($uri, 'GET', $action);
        return $this;
    }

    /**
     * Register a new POST route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return \Pramnos\Routing\Router
     */
    public function post($uri, $action)
    {
        $this->addRoute($uri, 'POST', $action);
        return $this;
    }

    /**
     * Register a new PUT route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return \Pramnos\Routing\Router
     */
    public function put($uri, $action)
    {
        $this->addRoute($uri, 'PUT', $action);
        return $this;
    }

    /**
     * Register a new DELETE route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return \Pramnos\Routing\Router
     */
    public function delete($uri, $action)
    {
        $this->addRoute($uri, 'DELETE', $action);
        return $this;
    }

    /**
     * Register a new PATCH route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return \Pramnos\Routing\Router
     */
    public function patch($uri, $action)
    {
        $this->addRoute($uri, 'PATCH', $action);
        return $this;
    }

    /**
     * Register a new OPTIONS route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return \Pramnos\Routing\Router
     */
    public function options($uri, $action)
    {
        $this->addRoute($uri, 'OPTIONS', $action);
        return $this;
    }

    /**
     * Register a new route with the given verbs.
     *
     * @param  array|string  $methods
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return \Pramnos\Routing\Router
     */
    public function match($methods, $uri, $action)
    {
        if (is_string($methods)) {
            $methods = explode(',', $methods);
        }
        return $this->addRoute($uri, $methods, $action);
    }


}
