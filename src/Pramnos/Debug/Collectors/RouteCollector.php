<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects information about the matched route for the current request.
 *
 * The route data is set externally via setRoute() after the router resolves
 * the request — typically inside the Application dispatch pipeline.
 *
 * @package PramnosFramework
 */
class RouteCollector implements CollectorInterface
{
    private array $routeData = [];

    public function name(): string
    {
        return 'route';
    }

    /**
     * Record the matched route details.
     *
     * @param array<string, mixed> $data  Keys: uri, method, action, name, middleware
     */
    public function setRoute(array $data): void
    {
        $this->routeData = $data;
    }

    public function collect(): array
    {
        return $this->routeData ?: ['uri' => '(not matched)', 'method' => '', 'action' => '', 'middleware' => []];
    }
}
