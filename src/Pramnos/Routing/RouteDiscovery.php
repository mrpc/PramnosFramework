<?php

declare(strict_types=1);

namespace Pramnos\Routing;

use Pramnos\Routing\Attributes\Route as RouteAttribute;
use Pramnos\Routing\Attributes\RouteGroup as RouteGroupAttribute;

/**
 * Scans a directory tree for controller classes decorated with #[Route]
 * attributes and registers every discovered route with the given Router.
 *
 * ## Usage
 *
 * ```php
 * // Direct instantiation:
 * (new RouteDiscovery($router))->discover(__DIR__ . '/Controllers', 'App\\Controllers');
 *
 * // Via Router convenience method:
 * $router->loadFromDirectory(__DIR__ . '/Controllers', 'App\\Controllers');
 * ```
 *
 * ## Controller example
 *
 * ```php
 * namespace App\Controllers;
 *
 * use Pramnos\Routing\Attributes\Route;
 *
 * class UserController {
 *     #[Route('/api/users',      methods: 'GET',  name: 'users.index')]
 *     #[Route('/api/users',      methods: 'POST', name: 'users.store')]
 *     public function index() { … }
 *
 *     #[Route('/api/users/{id}', methods: 'GET',  name: 'users.show', permissions: ['read:users'])]
 *     public function show(int $id) { … }
 * }
 * ```
 *
 */
class RouteDiscovery
{
    public function __construct(private Router $router) {}

    /**
     * Recursively scan `$directory` for PHP files, require each one, derive
     * the FQCN from its path relative to `$directory` + `$namespace`, and
     * inspect every public method for #[Route] attributes.
     *
     * @param  string $directory  Absolute path to the root controller directory.
     * @param  string $namespace  PHP namespace that maps to `$directory`.
     */
    public function discover(string $directory, string $namespace): void
    {
        $directory = rtrim($directory, '/\\');
        $files     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            require_once $file->getPathname();

            $class = $this->pathToClass($file->getPathname(), $directory, $namespace);

            if (!class_exists($class, false)) {
                continue;
            }

            $this->registerRoutesFromClass($class);
        }
    }

    /**
     * Convert an absolute file path inside `$directory` to a fully-qualified
     * class name under `$namespace`.
     *
     * Example:
     *   directory  = /app/Controllers
     *   namespace  = App\Controllers
     *   path       = /app/Controllers/Api/UserController.php
     *   → App\Controllers\Api\UserController
     */
    private function pathToClass(string $filePath, string $directory, string $namespace): string
    {
        // Strip the base directory prefix
        $relative = substr($filePath, strlen($directory));
        // Normalize slashes and strip the leading separator + .php suffix
        $relative = ltrim(str_replace(['/', '\\'], '\\', $relative), '\\');
        $relative = substr($relative, 0, -4); // remove ".php"

        return rtrim($namespace, '\\') . '\\' . $relative;
    }

    /**
     * Inspect every public method of `$class` for `#[Route]` attributes and
     * register the corresponding routes.
     *
     * If the class carries a `#[RouteGroup]` attribute, all discovered routes
     * are registered inside a Router::group() call so they inherit the group's
     * prefix, middleware, permissions, and name prefix.
     *
     * @param  class-string $class
     */
    private function registerRoutesFromClass(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        // Check for a class-level #[RouteGroup] attribute.
        $groupAttrs = $reflection->getAttributes(RouteGroupAttribute::class);
        $groupAttr  = !empty($groupAttrs) ? $groupAttrs[0]->newInstance() : null;

        $register = function() use ($reflection, $class): void {
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteAttribute::class) as $attrRef) {
                    /** @var RouteAttribute $routeDef */
                    $routeDef = $attrRef->newInstance();
                    $this->registerRoute($class, $method->getName(), $routeDef);
                }
            }
        };

        if ($groupAttr !== null) {
            $this->router->group([
                'prefix'      => $groupAttr->prefix,
                'middleware'  => $groupAttr->middleware,
                'permissions' => $groupAttr->permissions,
                'name'        => $groupAttr->name,
            ], function() use ($register): void {
                $register();
            });
        } else {
            $register();
        }
    }

    /**
     * Register a single `#[Route]` definition for `$class::$methodName`.
     *
     * When the attribute specifies multiple HTTP methods the route action is
     * registered for each. The logical name (if any) is associated with the
     * first registered Route object — URL generation is method-agnostic.
     *
     * @param  class-string  $class
     * @param  string        $methodName
     * @param  RouteAttribute $attr
     */
    private function registerRoute(string $class, string $methodName, RouteAttribute $attr): void
    {
        $action      = [$class, $methodName];
        $httpMethods = array_map('strtoupper', (array) $attr->methods);
        $firstRoute  = null;

        foreach ($httpMethods as $httpMethod) {
            $route = match ($httpMethod) {
                'GET'     => $this->router->get($attr->uri, $action),
                'HEAD'    => $this->router->head($attr->uri, $action),
                'POST'    => $this->router->post($attr->uri, $action),
                'PUT'     => $this->router->put($attr->uri, $action),
                'DELETE'  => $this->router->delete($attr->uri, $action),
                'PATCH'   => $this->router->patch($attr->uri, $action),
                'OPTIONS' => $this->router->options($attr->uri, $action),
                default   => null,
            };

            if ($route === null) {
                continue;
            }

            if (!empty($attr->permissions)) {
                $route->requirePermissions($attr->permissions, false);
            }

            if (!empty($attr->middleware)) {
                $route->middleware(...$attr->middleware);
            }

            $firstRoute ??= $route;
        }

        // Assign name to first registered route; URL generation is URI-only.
        if ($attr->name !== null && $firstRoute !== null) {
            $firstRoute->name($attr->name);
        }
    }
}
