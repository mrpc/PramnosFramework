<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Application\Application;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: list all registered routes in the application.
 *
 * Returns HTTP method, URI, controller/action, and required permissions so
 * the AI assistant can navigate the application's URL structure.
 *
 */
class RouteListTool implements McpToolInterface
{
    public function __construct(private readonly Application $app) {}

    public function name(): string
    {
        return 'route-list';
    }

    public function description(): string
    {
        return 'List all registered application routes with their HTTP methods, URIs, actions, and required permissions.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'filter' => [
                    'type'        => 'string',
                    'description' => 'Optional substring to filter routes by URI or action.',
                ],
            ],
        ];
    }

    /**
     * A router with this application's routes in it, or an explanation.
     *
     * The MCP server is launched by `mcp:serve`, so the application behind this tool is the
     * **console** kernel — and the console never builds a router, because routing is an HTTP
     * concern. This tool therefore used to answer `{"error": "No router available"}` on the only
     * path that can reach it: not a defensive fallback, but the whole method.
     *
     * So it builds one. Attribute routes are discoverable without an HTTP request — that is the
     * point of them being attributes — and `Router::loadFromDirectory()` is the same call an
     * application's HTTP bootstrap makes.
     *
     * @return \Pramnos\Routing\Router|array<string, mixed> A populated router, or an error
     */
    private function resolveRouter(): \Pramnos\Routing\Router|array
    {
        $router = $this->app->router ?? null;
        if ($router !== null) {
            return $router;   // an HTTP request built one already
        }

        $router    = new \Pramnos\Routing\Router($this->app);
        $searched  = [];
        $found     = false;

        foreach ($this->controllerDirectories() as $namespace => $directory) {
            $searched[] = $directory;
            if (!is_dir($directory)) {
                continue;
            }

            $router->loadFromDirectory($directory, $namespace);
            $found = true;
        }

        if (!$found || $router->getRoutesWithPermissions() === []) {
            return [
                'error' => 'No routes found. This tool runs under the console, which builds no '
                    . 'router, so it discovers #[Route] attributes instead.',
                'searched' => $searched,
                'note' => 'An application that registers its routes inside a routes.php which '
                    . 'dispatches at the end cannot be listed this way: including that file would '
                    . 'serve a request rather than describe one. Attribute routes can.',
            ];
        }

        return $router;
    }

    /**
     * The project root, where `composer.json` lives.
     *
     * `APP_PATH` points at the application directory inside the project, so the root is its
     * parent. Extracted as a method because a test cannot redefine a constant.
     *
     * @return string Absolute path to the project root
     */
    protected function projectRoot(): string
    {
        return defined('APP_PATH') ? dirname(APP_PATH) : (string) getcwd();
    }

    /**
     * Directories that may hold attribute-routed controllers, keyed by namespace.
     *
     * Taken from the application's own `composer.json` PSR-4 map, because that is what the
     * autoloader uses — guessing `src/Controllers` would be right for a scaffolded project and
     * wrong for anything that moved.
     *
     * @return array<string, string> Namespace => absolute directory
     */
    private function controllerDirectories(): array
    {
        $root = $this->projectRoot();
        $file = $root . '/composer.json';

        if (!is_file($file)) {
            return [];
        }

        $composer = json_decode((string) file_get_contents($file), true);
        $psr4     = $composer['autoload']['psr-4'] ?? [];

        if (!is_array($psr4)) {
            return [];
        }

        $directories = [];
        foreach ($psr4 as $prefix => $path) {
            $path = is_array($path) ? ($path[0] ?? '') : $path;
            if (!is_string($path) || $path === '') {
                continue;
            }

            $base   = rtrim($root . '/' . trim($path, '/'), '/');
            $prefix = rtrim((string) $prefix, '\\');

            // The conventional homes for controllers in a Pramnos application, plus the base
            // itself for a project that puts them at the root of its namespace.
            foreach (['Controllers', 'Api/Controllers', ''] as $sub) {
                $directory = $sub === '' ? $base : $base . '/' . $sub;
                $namespace = $sub === ''
                    ? $prefix
                    : $prefix . '\\' . str_replace('/', '\\', $sub);

                $directories[$namespace] = $directory;
            }
        }

        return $directories;
    }

    public function execute(array $input): mixed
    {
        $router = $this->resolveRouter();
        if (is_array($router)) {
            return $router;   // an explanation of why there are no routes to list
        }

        $filter     = strtolower(trim($input['filter'] ?? ''));
        $routeMap   = $router->getRoutesWithPermissions();
        $routes     = [];

        foreach ($routeMap as $method => $methodRoutes) {
            foreach ($methodRoutes as $uri => $info) {
                /** @var \Pramnos\Routing\Route $route */
                $route  = $info['route'];
                $action = $route->action;
                $actionStr = $action instanceof \Closure
                    ? '(Closure)'
                    : (is_array($action)
                        ? implode('@', array_filter($action, 'is_string'))
                        : (string) $action);

                if ($filter !== ''
                    && stripos($uri, $filter) === false
                    && stripos($actionStr, $filter) === false) {
                    continue;
                }

                $routes[] = [
                    'method'      => strtoupper($method),
                    'uri'         => $uri,
                    'action'      => $actionStr,
                    'permissions' => $info['permissions'] ?? [],
                    'name'        => $route->routeName ?? '',
                ];
            }
        }

        usort($routes, fn($a, $b) => strcmp($a['uri'], $b['uri']) ?: strcmp($a['method'], $b['method']));

        return $routes;
    }
}
