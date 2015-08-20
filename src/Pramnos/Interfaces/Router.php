<?php

namespace Pramnos\Interfaces;

/**
 * This is based mainly on the laravel framework - with minor changes
 * @link        http://laravel.com/ Laravel.com
 * @license     http://opensource.org/licenses/MIT MIT
 * @copyright   Copyright (c) <Taylor Otwell>
 */
interface Router
{
    /**
     * Register a new GET route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return void
     */
    public function get($uri, $action);

    /**
     * Register a new POST route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return void
     */
    public function post($uri, $action);

    /**
     * Register a new PUT route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return void
     */
    public function put($uri, $action);

    /**
     * Register a new DELETE route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return void
     */
    public function delete($uri, $action);

    /**
     * Register a new PATCH route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return void
     */
    public function patch($uri, $action);

    /**
     * Register a new OPTIONS route with the router.
     *
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return void
     */
    public function options($uri, $action);

    /**
     * Register a new route with the given verbs.
     *
     * @param  array|string  $methods
     * @param  string  $uri
     * @param  \Closure|array|string  $action
     * @return void
     */
    public function match($methods, $uri, $action);
}
