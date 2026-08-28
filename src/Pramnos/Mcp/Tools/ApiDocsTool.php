<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: the documented API surface, and whether the document still matches the code.
 *
 * `route-list` answers *what URIs exist*. This answers *what the API promises*: parameters,
 * request bodies, response codes, which operations require which credential. That is a
 * different question and it is the one an integration is written against.
 *
 * And the same freshness problem as the stylesheet, for the same reason. The OpenAPI document
 * is a **generated file that is committed**, so a controller can gain a parameter while the
 * published document goes on describing the old shape. Nothing fails: the API works and the
 * documentation lies, which is the worst available outcome because somebody believes it.
 * Measured on one project the first time this ran: a document generated on the 25th, a
 * controller changed on the 26th.
 *
 * Two generators exist and both are recognised, because a project uses one or the other:
 * `api:docs` reads `#[Route]` attributes through {@see \Pramnos\Routing\OpenApiGenerator}, and
 * the `openapi:generate` npm script converts apiDoc annotations. Reporting only the one this
 * framework ships would tell half the projects that they have no API documentation.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ApiDocsTool implements McpToolInterface
{
    /** Operations listed before the answer becomes a document dump. */
    private const MAX_OPERATIONS = 80;

    /** Files newer than the document, at most. */
    private const MAX_STALE = 12;

    /**
     * The keys under a path that are operations.
     *
     * OpenAPI allows others at that level — `servers`, `parameters`, `summary`, `$ref` — and
     * treating every key as a method invented an operation called `SERVERS /oauth/token`. A
     * fabricated row in a list of endpoints is the same failure as a wrong URI: somebody would
     * have tried to call it.
     *
     * @var list<string>
     */
    private const HTTP_METHODS = [
        'get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace',
    ];

    /**
     * Where a generated document is published, in the order they are looked for.
     *
     * @var list<string>
     */
    private const DOCUMENT_PATHS = [
        'www/api/openapi.json',
        'www/openapi.json',
        'public/api/openapi.json',
        'openapi.json',
    ];

    /**
     * Where API controllers live.
     *
     * @var list<string>
     */
    private const CONTROLLER_PATHS = ['src/Api/Controllers', 'src/Controllers'];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(
            $root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()),
            DIRECTORY_SEPARATOR
        );
    }

    public function name(): string
    {
        return 'api-docs';
    }

    public function description(): string
    {
        return 'The application\'s documented API: every operation with its method, path, '
            . 'summary and required credential, one operation in full with its parameters and '
            . 'responses, the command that regenerates the document, and whether the published '
            . 'document is older than the controllers it describes. Use route-list for what '
            . 'URIs exist; use this for what the API promises.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Only operations whose path, summary or tag contains this.',
                ],
                'operation' => [
                    'type' => 'string',
                    'description' => 'One operation in full, as `GET /me/tokens`.',
                ],
                'summary_only' => [
                    'type' => 'boolean',
                    'description' => 'Counts and freshness, without the operation list.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $file = $this->documentPath();

        if ($file === null) {
            return [
                'error'    => 'No generated OpenAPI document found.',
                'looked'   => self::DOCUMENT_PATHS,
                'generate' => $this->generator(null),
            ];
        }

        $document = json_decode((string) @file_get_contents($file), true);

        if (!is_array($document)) {
            return [
                'error' => $this->relative($file) . ' is not readable JSON — regenerate it.',
                'generate' => $this->generator($file),
            ];
        }

        $operations = $this->operations($document);
        $wanted     = trim((string) ($input['operation'] ?? ''));

        if ($wanted !== '') {
            return $this->oneOperation($document, $operations, $wanted, $file);
        }

        $filter = strtolower(trim((string) ($input['filter'] ?? '')));

        if ($filter !== '') {
            $operations = array_values(array_filter(
                $operations,
                static fn (array $operation): bool =>
                    str_contains(strtolower($operation['path']), $filter)
                    || str_contains(strtolower((string) ($operation['summary'] ?? '')), $filter)
                    || str_contains(strtolower(implode(' ', (array) ($operation['tags'] ?? []))), $filter)
            ));
        }

        $answer = [
            'document' => array_filter([
                'file'         => $this->relative($file),
                'generated_at' => date('d/m/Y H:i', (int) filemtime($file)),
                'openapi'      => $document['openapi'] ?? null,
                'title'        => $document['info']['title'] ?? null,
                'version'      => $document['info']['version'] ?? null,
                'paths'        => count((array) ($document['paths'] ?? [])),
                'operations'   => count($this->operations($document)),
                'servers'      => array_column((array) ($document['servers'] ?? []), 'url'),
                'security_schemes' => array_keys(
                    (array) ($document['components']['securitySchemes'] ?? [])
                ),
            ], static fn ($value): bool => $value !== null && $value !== []),
            'generate' => $this->generator($file),
        ];

        if (empty($input['summary_only'])) {
            $answer['listed']     = min(count($operations), self::MAX_OPERATIONS);
            $answer['complete']   = count($operations) <= self::MAX_OPERATIONS;
            $answer['operationList'] = array_slice($operations, 0, self::MAX_OPERATIONS);
            $answer['note'] = 'Ask again with `operation` — e.g. `GET /me/tokens` — for one '
                . 'operation\'s parameters, request body and responses.';
        }

        return $answer;
    }

    /**
     * One operation, in the detail an integration is written against.
     *
     * @param list<array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    private function oneOperation(
        array $document,
        array $operations,
        string $wanted,
        string $file
    ): array {
        $parts  = preg_split('~\s+~', strtoupper(trim($wanted))) ?: [];
        $method = strtolower($parts[0] ?? '');
        $path   = $parts[1] ?? '';

        if ($method === '' || $path === '') {
            return [
                'error' => 'Give the operation as `GET /me/tokens` — a method and a path.',
            ];
        }

        // Case-insensitive on the path, because a caller reads it off a list and retypes it.
        foreach ((array) ($document['paths'] ?? []) as $documented => $methods) {
            if (strcasecmp((string) $documented, $path) !== 0) {
                continue;
            }

            foreach ((array) $methods as $documentedMethod => $operation) {
                if (strcasecmp((string) $documentedMethod, $method) !== 0
                    || !in_array(strtolower((string) $documentedMethod), self::HTTP_METHODS, true)
                ) {
                    continue;
                }

                return [
                    'document'  => $this->relative($file),
                    'method'    => strtoupper($documentedMethod),
                    'path'      => $documented,
                    'operation' => $operation,
                ];
            }
        }

        return [
            'error' => 'No documented operation ' . strtoupper($method) . ' ' . $path . '.',
            // The list, because the usual cause is a path that differs by a prefix or a
            // parameter's name.
            'operations' => array_map(
                static fn (array $o): string => $o['method'] . ' ' . $o['path'],
                array_slice($operations, 0, self::MAX_OPERATIONS)
            ),
        ];
    }

    /**
     * Every operation in the document, one line each.
     *
     * `security` is reduced to the scheme names: whether an operation needs a credential, and
     * which, is the first thing anybody asks and the full object buries it.
     *
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    private function operations(array $document): array
    {
        $operations = [];

        foreach ((array) ($document['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $operation) {
                if (!is_array($operation)
                    || !in_array(strtolower((string) $method), self::HTTP_METHODS, true)
                ) {
                    continue;
                }

                $security = [];

                foreach ((array) ($operation['security'] ?? []) as $requirement) {
                    foreach (array_keys((array) $requirement) as $scheme) {
                        $security[] = (string) $scheme;
                    }
                }

                $operations[] = array_filter([
                    'method'  => strtoupper((string) $method),
                    'path'    => (string) $path,
                    'summary' => $operation['summary'] ?? null,
                    'tags'    => $operation['tags'] ?? null,
                    'security' => $security !== [] ? array_values(array_unique($security)) : null,
                    'parameters' => isset($operation['parameters'])
                        ? count((array) $operation['parameters'])
                        : null,
                ], static fn ($value): bool => $value !== null && $value !== []);
            }
        }

        usort(
            $operations,
            static fn (array $a, array $b): int =>
                strcmp($a['path'], $b['path']) ?: strcmp($a['method'], $b['method'])
        );

        return $operations;
    }

    /**
     * How the document is generated here, and whether it has been since the code changed.
     *
     * @return array<string, mixed>
     */
    private function generator(?string $document): array
    {
        $npm = $this->npmScript();

        if ($npm !== null) {
            return array_filter([
                'pipeline' => 'apidoc-annotations',
                'command'  => 'npm run ' . $npm,
                'sources'  => $this->controllerDirectory(),
                'note'     => 'Converted from the apiDoc (`@api*`) annotations in the API '
                    . 'controllers. Configuration is in `src/Api/apidoc.json`, and a '
                    . '`src/Api/openapi-overrides.json` is deep-merged over the result.',
                'freshness' => $document === null ? null : $this->freshness($document),
            ], static fn ($value): bool => $value !== null);
        }

        return array_filter([
            'pipeline' => 'route-attributes',
            'command'  => 'php <cli> api:docs',
            'sources'  => $this->controllerDirectory(),
            'note'     => $this->hasRouteAttributes()
                ? 'Generated from the `#[Route]` attributes on the API controllers.'
                : 'No `#[Route]` attributes were found in the controllers, and no apiDoc '
                    . 'conversion script is configured — so `api:docs` would produce an empty '
                    . 'document. An application that registers routes in a `routes.php` is '
                    . 'listed by `route-list`, which parses that file instead.',
            'freshness' => $document === null ? null : $this->freshness($document),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Is the published document newer than the controllers it describes?
     *
     * The check that matters, because the failure is silent in the worst way: the API keeps
     * working and the documentation keeps being read.
     *
     * @return array<string, mixed>
     */
    private function freshness(string $document): array
    {
        $generatedAt = (int) filemtime($document);
        $directory   = $this->controllerDirectory();

        if ($directory === null) {
            return ['known' => false, 'why' => 'No API controllers directory was found.'];
        }

        $newer = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->root . '/' . $directory,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                if ($file->getMTime() > $generatedAt) {
                    $newer[] = $this->relative($file->getPathname());

                    if (count($newer) > self::MAX_STALE) {
                        break;
                    }
                }
            }
        } catch (\Throwable $exception) {
            return ['known' => false, 'why' => $exception->getMessage()];
        }

        return array_filter([
            'stale' => $newer !== [],
            'controllers_newer_than_the_document' => $newer === []
                ? null
                : array_slice($newer, 0, self::MAX_STALE),
            'why' => $newer === []
                ? null
                : 'These changed after the document was generated, so it may describe an older '
                    . 'shape of the API. Nothing breaks when it does — the API works and the '
                    . 'documentation is wrong, which is why it is worth checking.',
        ], static fn ($value): bool => $value !== null);
    }

    /** The npm script that generates the document, if there is one. */
    private function npmScript(): ?string
    {
        $file = $this->root . '/package.json';

        if (!is_file($file)) {
            return null;
        }

        $package = json_decode((string) file_get_contents($file), true);
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];

        foreach ($scripts as $name => $command) {
            if (is_string($command)
                && (str_contains($command, 'apidoc-to-openapi') || str_contains($command, 'openapi'))
            ) {
                return (string) $name;
            }
        }

        return null;
    }

    /** Where the published document is, or null. */
    private function documentPath(): ?string
    {
        foreach (self::DOCUMENT_PATHS as $candidate) {
            $path = $this->root . '/' . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** The API controllers directory, project-relative. */
    private function controllerDirectory(): ?string
    {
        foreach (self::CONTROLLER_PATHS as $candidate) {
            if (is_dir($this->root . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Does any controller carry a `#[Route]` attribute?
     *
     * By reading, not by loading: requiring controllers to find out is how route discovery
     * came to execute the view templates.
     */
    private function hasRouteAttributes(): bool
    {
        $directory = $this->controllerDirectory();

        if ($directory === null) {
            return false;
        }

        foreach ((array) glob($this->root . '/' . $directory . '/*.php') as $file) {
            if (is_string($file) && str_contains((string) @file_get_contents($file), '#[Route')) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }
}
