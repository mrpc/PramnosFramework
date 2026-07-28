<?php

declare(strict_types=1);

namespace Pramnos\Routing;

use Pramnos\Routing\Attributes\Route as RouteAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Generates an OpenAPI 3.0 document from attribute-routed controllers.
 *
 * This is the documentation counterpart of the modern attribute-routing engine
 * ({@see Router::loadFromDirectory()} + {@see RouteAttribute}). It reflects the
 * `#[Route]` attributes on controller methods — the same source of truth the
 * router dispatches from — so the API docs never drift from the routes. It is the
 * attribute-native alternative to the older apidoc.json/JSDoc-comment flow: apps
 * that route with `#[Route]` (rather than a hand-written routes.php + `@api`
 * comment blocks) get their OpenAPI spec from the code directly.
 *
 * What is derived automatically:
 * - **paths / methods** from each route's uri + HTTP methods (`{param}` /
 *   `{param?}` path segments become OpenAPI path parameters);
 * - **operationId** from the route name (falling back to Controller_method);
 * - **summary / description** from the method's docblock (first line vs. the rest,
 *   `@tag` lines stripped);
 * - **security** — a `bearerAuth` requirement whenever a route declares
 *   permissions or an auth middleware;
 * - **tags** from the controller's short name.
 *
 * What it cannot infer (request/response schemas, examples) is supplied via an
 * `overrides` document that is deep-merged over the generated one — the same
 * escape hatch other Pramnos apps use through openapi-overrides.json.
 */
class OpenApiGenerator
{
    /** @var array<string,mixed> */
    private array $info;

    /** @var list<array<string,mixed>> */
    private array $servers;

    /** @var array<string,mixed> */
    private array $overrides;

    /**
     * @param array<string,mixed>        $info      OpenAPI info object (title, version, description, …)
     * @param list<array<string,mixed>>  $servers   OpenAPI servers array
     * @param array<string,mixed>        $overrides Document deep-merged over the generated one
     */
    public function __construct(array $info = [], array $servers = [], array $overrides = [])
    {
        $this->info      = $info + ['title' => 'API', 'version' => '1.0.0'];
        $this->servers   = $servers;
        $this->overrides = $overrides;
    }

    /**
     * Scan a PSR-4 controllers directory and generate the document.
     *
     * @param string $path      Absolute path to the controllers directory
     * @param string $namespace Namespace the directory maps to (trailing slash optional)
     * @return array<string,mixed>
     */
    public function fromDirectory(string $path, string $namespace): array
    {
        return $this->fromClasses($this->discoverClasses($path, $namespace));
    }

    /**
     * Generate the document from an explicit list of controller classes.
     *
     * @param list<class-string> $classes
     * @return array<string,mixed>
     */
    public function fromClasses(array $classes): array
    {
        $paths   = [];
        $secured = false;

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            $tag        = $reflection->getShortName();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                    /** @var RouteAttribute $route */
                    $route = $attribute->newInstance();
                    [$path, $params] = $this->normalizePath($route->uri);
                    $operation = $this->buildOperation($route, $method, $params, $tag);

                    if (isset($operation['security'])) {
                        $secured = true;
                    }

                    foreach ((array) $route->methods as $httpMethod) {
                        $verb = strtolower((string) $httpMethod);
                        // OPTIONS/HEAD are transport concerns, not documented operations.
                        if ($verb === 'options' || $verb === 'head') {
                            continue;
                        }
                        $paths[$path][$verb] = $operation;
                    }
                }
            }
        }

        ksort($paths);

        $document = ['openapi' => '3.0.3', 'info' => $this->info];
        if ($this->servers !== []) {
            $document['servers'] = $this->servers;
        }
        $document['paths'] = $paths;
        if ($secured) {
            $document['components']['securitySchemes']['bearerAuth'] = [
                'type'   => 'http',
                'scheme' => 'bearer',
            ];
        }

        return $this->deepMerge($document, $this->overrides);
    }

    /**
     * Build one OpenAPI operation object for a route + handler method.
     *
     * @param list<string>      $params Path parameter names
     * @return array<string,mixed>
     */
    private function buildOperation(RouteAttribute $route, ReflectionMethod $method, array $params, string $tag): array
    {
        [$summary, $description] = $this->parseDocblock($method->getDocComment() ?: '');

        $operation = [
            'operationId' => $route->name ?? ($tag . '_' . $method->getName()),
            'tags'        => [$tag],
        ];
        if ($summary !== '') {
            $operation['summary'] = $summary;
        }
        if ($description !== '') {
            $operation['description'] = $description;
        }

        if ($params !== []) {
            $operation['parameters'] = array_map(
                static fn (string $name): array => [
                    'name'     => $name,
                    'in'       => 'path',
                    'required' => true,
                    'schema'   => ['type' => 'string'],
                ],
                $params
            );
        }

        if ($this->isSecured($route)) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        $operation['responses'] = ['200' => ['description' => 'Successful response']];

        return $operation;
    }

    /**
     * A route is documented as secured when it declares permissions or attaches
     * a middleware whose class name looks like authentication.
     */
    private function isSecured(RouteAttribute $route): bool
    {
        if ($route->permissions !== []) {
            return true;
        }
        foreach ($route->middleware as $middleware) {
            if (stripos((string) $middleware, 'auth') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert a framework route uri to an OpenAPI path and extract its path
     * parameter names. Optional markers (`{id?}`) become required path params
     * (OpenAPI has no optional path segments).
     *
     * @return array{0:string,1:list<string>}
     */
    private function normalizePath(string $uri): array
    {
        $path = '/' . ltrim($uri, '/');
        $path = (string) preg_replace('/\{(\w+)\?\}/', '{$1}', $path);

        $params = [];
        if (preg_match_all('/\{(\w+)\}/', $path, $matches)) {
            $params = $matches[1];
        }

        return [$path, $params];
    }

    /**
     * Split a docblock into a one-line summary and a longer description, dropping
     * the comment markers and any `@tag` lines.
     *
     * @return array{0:string,1:string}
     */
    private function parseDocblock(string $docComment): array
    {
        if ($docComment === '') {
            return ['', ''];
        }

        $text  = (string) preg_replace(['#^\s*/\*+#', '#\*+/\s*$#'], '', $docComment);
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = ltrim(trim($line), '*');
            $line = trim($line);
            if ($line === '' && $lines === []) {
                continue; // skip leading blanks
            }
            if (str_starts_with($line, '@')) {
                break; // stop at the first annotation
            }
            $lines[] = $line;
        }

        // Drop trailing blank lines.
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return ['', ''];
        }

        $summary     = array_shift($lines);
        $description = trim(implode("\n", $lines));

        return [$summary, $description];
    }

    /**
     * Recursively merge $override into $base ($override wins on scalar conflicts;
     * lists are replaced wholesale).
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Map every PHP file in a PSR-4 directory to its fully-qualified class name.
     *
     * @return list<class-string>
     */
    private function discoverClasses(string $path, string $namespace): array
    {
        if (!is_dir($path)) {
            return [];
        }
        $namespace = rtrim($namespace, '\\') . '\\';
        $classes   = [];
        foreach (new \DirectoryIterator($path) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            /** @var class-string $fqcn */
            $fqcn      = $namespace . $file->getBasename('.php');
            $classes[] = $fqcn;
        }
        sort($classes);
        return $classes;
    }
}
