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
 * @package PramnosFramework
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

    public function execute(array $input): mixed
    {
        $router = $this->app->router ?? null;
        if ($router === null) {
            return ['error' => 'No router available'];
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
