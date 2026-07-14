<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Lists every route registered with the application's Router.
 *
 * ## Usage
 *
 * ```
 * php pramnos route:list
 * php pramnos route:list --json
 * ```
 *
 * ## Route availability in CLI
 *
 * Application routes are declared in `ROOT/src/Api/routes.php`. That file
 * creates a *local* Router, registers the routes on it and immediately calls
 * `$router->dispatch()` — it is designed to be `include`d during HTTP request
 * handling (with `$this` bound to the `Api` instance) and it produces live
 * side effects (dispatch, database access). It therefore cannot be safely
 * loaded from a CLI process, and the Router it builds is never exposed
 * globally.
 *
 * To make routes visible to this command (and to the MCP `route-list` tool,
 * which uses the same convention) an application may publish its populated
 * Router as the dynamic `router` property on its `Pramnos\Application\Application`
 * instance, e.g. inside a service provider or bootstrap:
 *
 *   $app->router = $router;
 *
 * When no such Router can be resolved, the command degrades gracefully: it
 * prints an explanatory message and an empty result set rather than failing.
 * A Router can also be injected directly via the public {@see $router}
 * property (used by tests and programmatic callers).
 *
 * Exit codes:
 *   0 — command ran (routes listed, or none available)
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class RouteList extends Command
{
    protected static $defaultName = 'route:list';

    /**
     * Optional Router injected by tests or programmatic callers.
     *
     * When set, it takes precedence over any Router discovered on the
     * application instance. Left null in normal CLI usage.
     *
     * @var \Pramnos\Routing\Router|null
     */
    public ?\Pramnos\Routing\Router $router = null;

    protected function configure(): void
    {
        $this
            ->setName('route:list')
            ->setDescription('List all registered application routes')
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output results as JSON instead of a table'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $router = $this->resolveRouter();
        $routes = $router !== null ? $this->collectRoutes($router) : [];

        if ($input->getOption('json')) {
            $output->writeln(json_encode(
                $routes,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            return Command::SUCCESS;
        }

        if ($router === null) {
            $output->writeln('');
            $output->writeln('<comment>No router is available in this CLI context.</comment>');
            $output->writeln(
                'Routes are registered during HTTP bootstrap (src/Api/routes.php). '
                . 'Publish the populated Router as $app->router to list them here.'
            );
            $output->writeln('');
            return Command::SUCCESS;
        }

        $this->renderTable($output, $routes);
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Locate a populated Router from the available sources.
     *
     * Resolution order:
     *   1. A Router explicitly injected via the public $router property.
     *   2. The dynamic `router` property on the internal Pramnos application
     *      exposed by the console Application (the same convention the MCP
     *      route-list tool relies on).
     *   3. The dynamic `router` property on the current global Application
     *      instance, if one exists.
     *
     * Every discovery path is guarded so a missing/partial framework bootstrap
     * results in a null return rather than a fatal error.
     *
     * @return \Pramnos\Routing\Router|null
     */
    private function resolveRouter(): ?\Pramnos\Routing\Router
    {
        // 1. Explicitly injected Router (tests / programmatic use)
        if ($this->router instanceof \Pramnos\Routing\Router) {
            return $this->router;
        }

        // 2. Router published on the internal Pramnos application
        try {
            $console = $this->getApplication();
            if ($console instanceof \Pramnos\Console\Application) {
                $internal = $console->internalApplication;
                if (isset($internal->router)
                    && $internal->router instanceof \Pramnos\Routing\Router) {
                    return $internal->router;
                }
            }
        } catch (\Throwable) {
            // Fall through to the next strategy.
        }

        // 3. Router published on the global Application instance
        try {
            $app = \Pramnos\Application\Application::getInstance();
            if (isset($app->router)
                && $app->router instanceof \Pramnos\Routing\Router) {
                return $app->router;
            }
        } catch (\Throwable) {
            // No usable application instance — degrade gracefully.
        }

        return null;
    }

    /**
     * Flatten the Router's method/URI map into a sorted list of rows.
     *
     * @param  \Pramnos\Routing\Router $router
     * @return array<int, array{method: string, uri: string, handler: string, name: string, permissions: array<int, string>}>
     */
    private function collectRoutes(\Pramnos\Routing\Router $router): array
    {
        $routeMap = $router->getRoutesWithPermissions();
        $routes   = [];

        foreach ($routeMap as $method => $methodRoutes) {
            foreach ($methodRoutes as $uri => $info) {
                /** @var \Pramnos\Routing\Route $route */
                $route = $info['route'];

                $routes[] = [
                    'method'      => strtoupper((string) $method),
                    'uri'         => (string) $uri,
                    'handler'     => $this->describeHandler($route->action),
                    'name'        => $route->routeName ?? '',
                    'permissions' => array_values($info['permissions'] ?? []),
                ];
            }
        }

        // Stable ordering: by URI, then by method.
        usort(
            $routes,
            fn($a, $b) => strcmp($a['uri'], $b['uri']) ?: strcmp($a['method'], $b['method'])
        );

        return $routes;
    }

    /**
     * Produce a human-readable description of a route action.
     *
     * @param  \Closure|array|string $action
     * @return string
     */
    private function describeHandler($action): string
    {
        if ($action instanceof \Closure) {
            return '(Closure)';
        }

        if (is_array($action)) {
            $controller = $action[0] ?? '';
            if (is_object($controller)) {
                $controller = get_class($controller);
            }
            $callable = $action[1] ?? '';
            $controller = (string) $controller;
            $callable   = (string) $callable;

            return $callable !== '' ? $controller . '@' . $callable : $controller;
        }

        return (string) $action;
    }

    /**
     * @param array<int, array{method: string, uri: string, handler: string, name: string, permissions: array<int, string>}> $routes
     */
    private function renderTable(OutputInterface $output, array $routes): void
    {
        $output->writeln('');

        if (empty($routes)) {
            $output->writeln('<comment>No routes are registered.</comment>');
            $output->writeln('');
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Method', 'URI', 'Handler', 'Permissions']);

        foreach ($routes as $row) {
            $permissions = !empty($row['permissions'])
                ? implode(', ', $row['permissions'])
                : '<comment>none</comment>';

            $handler = $row['handler'];
            if ($row['name'] !== '') {
                // Surface the route name alongside its handler when present.
                $handler .= "\n<info>name:</info> " . $row['name'];
            }

            $table->addRow([
                "<info>{$row['method']}</info>",
                $row['uri'],
                $handler,
                $permissions,
            ]);
        }

        $table->render();
        $output->writeln('');
        $output->writeln(sprintf('<info>%d route(s) registered.</info>', count($routes)));
        $output->writeln('');
    }
}
