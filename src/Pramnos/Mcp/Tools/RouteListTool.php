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
        $filter = strtolower(trim($input['filter'] ?? ''));

        // Read the routes files first, because on most applications they *are* the routes.
        $fromFiles = $this->routesFromFiles($filter);
        $router    = $this->resolveRouter();

        if (is_array($router)) {
            /*
             * No attribute routes. That used to be the whole answer — "No routes found" on an
             * application with fifty of them, which is worse than an error because it reads as
             * a fact about the application rather than a limitation of the tool.
             */
            if ($fromFiles['routes'] !== []) {
                return [
                    'routes' => $fromFiles['routes'],
                    'count'  => count($fromFiles['routes']),
                    'files'  => $fromFiles['files'],
                    'note'   => 'Read from the routes files by parsing them, not by running '
                        . 'them: these files end in `$router->dispatch()`, so including one '
                        . 'would serve a request instead of describing it. A route whose URI or '
                        . 'prefix is built from an expression is reported as that expression, '
                        . 'because its value is only known at runtime. No `#[Route]` attribute '
                        . 'controllers were found.',
                ];
            }

            $router['routes_files_searched'] = $fromFiles['files'];

            return $router;   // an explanation of why there are no routes to list
        }

        $routeMap   = $router->getRoutesWithPermissions();
        $routes     = $fromFiles['routes'];

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
                    'source'      => 'attribute',
                ];
            }
        }

        usort($routes, fn($a, $b) => strcmp($a['uri'], $b['uri']) ?: strcmp($a['method'], $b['method']));

        return $routes;
    }

    /**
     * Routes read out of the `routes.php` files, by parsing rather than running them.
     *
     * The tool used to answer "No routes found" on an application with fifty of them, with a
     * note explaining that including a routes file would serve a request rather than describe
     * one. The note was true — this project's file ends in `return $router->dispatch($request)`
     * — and the answer was still useless: it reads as a fact about the application.
     *
     * The routes are statically readable. `$r->get('/me', …)` is a literal method name and a
     * literal string, and `->group(['prefix' => '/admin'], function () { … })` nests them.
     * Tokenising costs microseconds and cannot dispatch anything, which is the same trade that
     * fixed route *discovery* an hour earlier.
     *
     * What it cannot resolve is said rather than guessed: a prefix like
     * `'/' . (defined('APIVERSION') ? APIVERSION : '1.0')` has no value until runtime, so it is
     * reported as the expression it is.
     *
     * @param  string $filter Lower-cased substring, or ''
     * @return array{routes: list<array<string, mixed>>, files: list<string>}
     */
    private function routesFromFiles(string $filter): array
    {
        $routes = [];
        $files  = [];

        foreach ($this->routeFiles() as $file) {
            $files[]  = str_replace($this->projectRoot() . '/', '', $file);
            $contents = @file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            foreach ($this->parseRoutes($contents) as $route) {
                $route['file'] = str_replace($this->projectRoot() . '/', '', $file);

                if ($filter !== ''
                    && !str_contains(strtolower($route['uri']), $filter)
                    && !str_contains(strtolower((string) $route['action']), $filter)
                ) {
                    continue;
                }

                $routes[] = $route;
            }
        }

        return ['routes' => $routes, 'files' => $files];
    }

    /**
     * Where an application keeps its route registrations.
     *
     * @return list<string> Absolute paths
     */
    private function routeFiles(): array
    {
        $root  = $this->projectRoot();
        $found = [];

        foreach ([
            '/app/routes.php',
            '/routes.php',
            '/routes/web.php',
            '/routes/api.php',
        ] as $candidate) {
            if (is_file($root . $candidate)) {
                $found[] = $root . $candidate;
            }
        }

        // `src/Api/routes.php`, `src/Admin/routes.php` — an area with its own routing.
        foreach ((array) glob($root . '/src/*/routes.php') as $match) {
            if (is_string($match)) {
                $found[] = $match;
            }
        }

        return $found;
    }

    /**
     * The route registrations in one file's source.
     *
     * A single token pass. The brace bookkeeping is for `group()`: a prefix applies until its
     * closure closes, and without tracking that every route in a group is reported at the wrong
     * URI — which is worse than not reporting it.
     *
     * @return list<array<string, mixed>>
     */
    private function parseRoutes(string $contents): array
    {
        $verbs  = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];
        $tokens = @token_get_all($contents);
        $lines  = explode("\n", $contents);
        $count  = count($tokens);

        $routes = [];
        $depth  = 0;

        /** @var list<array{prefix: string, depth: int}> $groups */
        $groups = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;

                    while ($groups !== [] && $groups[count($groups) - 1]['depth'] > $depth) {
                        array_pop($groups);
                    }
                }

                continue;
            }

            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }

            if ($token[0] !== T_STRING) {
                continue;
            }

            $name     = strtolower($token[1]);
            $previous = $tokens[$i - 1] ?? null;

            // Only `->verb(` and `->group(` — a bare `get(` is somebody else's function.
            if (!is_array($previous)
                || !in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                continue;
            }

            if ($name === 'group') {
                $groups[] = [
                    'prefix' => $this->prefixFromGroup($tokens, $i),
                    // The closure's brace has not been seen yet, so the group owns everything
                    // from the next depth down.
                    'depth'  => $depth + 1,
                ];

                continue;
            }

            if (!in_array($name, $verbs, true)) {
                continue;
            }

            $arguments = $this->argumentTexts($tokens, $i);

            if ($arguments === []) {
                continue;
            }

            // `match(['get','post'], '/uri', …)` puts the methods first.
            $method = $name === 'match' ? trim($arguments[0], "[]' \"") : strtoupper($name);
            $uri    = $name === 'match' ? ($arguments[1] ?? '') : $arguments[0];
            $action = $name === 'match' ? ($arguments[2] ?? '') : ($arguments[1] ?? '');

            $prefix = '';

            foreach ($groups as $group) {
                $prefix .= $group['prefix'];
            }

            $routes[] = [
                'method' => strtoupper((string) $method),
                'uri'    => $prefix . $this->literal($uri),
                'action' => $this->describeAction($action),
                'source' => 'routes-file',
                'line'   => $token[2],
            ];
        }

        return $routes;
    }

    /**
     * The `prefix` out of `->group(['prefix' => '/admin'], …)`.
     *
     * Returns the expression verbatim when it is not a literal — `'/' . APIVERSION` cannot be
     * resolved without running the file, and inventing a value would put every route in the
     * group at an address that does not exist.
     */
    private function prefixFromGroup(array $tokens, int $from): string
    {
        /*
         * The first argument only.
         *
         * `rawArguments()` returns everything between the parentheses, and for `group()` that
         * includes the entire closure — hundreds of lines of other routes. Searching it for
         * `'prefix'` then matched whatever came first and anchors like `]$` never matched at
         * all, so the prefix came back empty and every route in the group was reported at the
         * wrong address. The array literal is argument zero; nothing else can be.
         */
        $arguments = $this->argumentTexts($tokens, $from);
        $text      = $arguments[0] ?? '';
        $match     = [];

        /*
         * A literal only when the value *ends* at the closing quote.
         *
         * The first version accepted the leading literal of a concatenation, so
         * `'prefix' => '/' . (defined('APIVERSION') ? APIVERSION : '1.0')` came back as `/`
         * and every route in the group was reported at `//me` instead of `/1.0/me`. A URI that
         * is confidently wrong is worse than one marked unknown — somebody would have called
         * it.
         */
        if (preg_match('~[\'"]prefix[\'"]\s*=>\s*([\'"])([^\'"]*)\1\s*(?:,|\]|$)~', $text, $match) === 1) {
            return $match[2];
        }

        if (preg_match('~[\'"]prefix[\'"]\s*=>\s*(.+?)(?:,\s*[\'"]\w|\]\s*$)~s', trim($text), $match) === 1) {
            // Reported as the expression it is: its value exists only at runtime.
            return '{' . trim(rtrim(trim($match[1]), ',')) . '}';
        }

        return '';
    }

    /**
     * The top-level argument expressions of the call starting at `$from`.
     *
     * @return list<string>
     */
    private function argumentTexts(array $tokens, int $from): array
    {
        $raw = $this->rawArguments($tokens, $from);

        if ($raw === '') {
            return [];
        }

        // Split on top-level commas only: a closure body and an array both contain their own.
        $arguments = [];
        $current   = '';
        $nesting   = 0;

        foreach (str_split($raw) as $character) {
            if (in_array($character, ['(', '[', '{'], true)) {
                $nesting++;
            } elseif (in_array($character, [')', ']', '}'], true)) {
                $nesting--;
            }

            if ($character === ',' && $nesting === 0) {
                $arguments[] = trim($current);
                $current     = '';

                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $arguments[] = trim($current);
        }

        return $arguments;
    }

    /**
     * Everything between the parentheses of the call whose name is at `$from`.
     */
    private function rawArguments(array $tokens, int $from): string
    {
        $count = count($tokens);
        $text  = '';
        $depth = 0;
        $open  = false;

        for ($i = $from + 1; $i < $count; $i++) {
            /*
             * Comments are skipped rather than concatenated.
             *
             * They are tokens with text, and the text goes on to be split on commas — so a
             * comment between `group(` and its array, which this project's own routes file
             * has, became argument zero up to the first comma inside the *prose*. The prefix
             * was then not found and every route in the group lost it.
             */
            if (is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $piece = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if (!$open) {
                if ($piece === '(') {
                    $open  = true;
                    $depth = 1;
                }

                continue;
            }

            if ($piece === '(' || $piece === '[' || $piece === '{') {
                $depth++;
            } elseif ($piece === ')' || $piece === ']' || $piece === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            $text .= $piece;
        }

        return $text;
    }

    /** A quoted string as its value, or the expression as written. */
    private function literal(string $expression): string
    {
        $expression = trim($expression);

        if (preg_match('~^([\'"])(.*)\1$~s', $expression, $match) === 1) {
            return $match[2];
        }

        return '{' . $expression . '}';
    }

    /**
     * What a route's action is, in a line.
     *
     * A closure is the common shape here, and `'(closure)'` says nothing — so the controller
     * call inside it is dug out, which is the part somebody is looking for.
     */
    private function describeAction(string $expression): string
    {
        $expression = trim($expression);

        if ($expression === '') {
            return '';
        }

        if (preg_match('~^([\'"])(.*)\1$~s', $expression, $match) === 1) {
            return $match[2];
        }

        if (str_starts_with($expression, 'function') || str_starts_with($expression, 'fn')) {
            $match = [];

            if (preg_match(
                '~new\s+\\\\?([A-Za-z0-9_\\\\]+)\s*\([^)]*\)\s*\)?\s*->\s*(\w+)~',
                $expression,
                $match
            ) === 1) {
                // The short name: `App\Api\Controllers\Me@display` is the answer,
                // and the namespace in front of it is noise on every row.
                $parts = explode('\\', $match[1]);

                return '(closure) ' . end($parts) . '@' . $match[2];
            }

            return '(closure)';
        }

        return $expression;
    }
}
