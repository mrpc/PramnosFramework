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
