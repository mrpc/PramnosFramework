<?php

declare(strict_types=1);

namespace Pramnos\Routing;

/**
 * Turn an OpenAPI document into a typed front-end client.
 *
 * The gap this closes, reported by a project building an admin panel against the
 * scaffolding: screens hand-write path strings (`` `${RESOURCE}/${id}` ``) and field
 * names, while the OpenAPI document sitting in the same repository knows both. So the
 * "wrong field name" and "wrong path" classes of bug are found in the browser, one at
 * a time, when they could be found by the editor.
 *
 * Three decisions worth stating, because each rules out something plausible:
 *
 * **JSDoc and a `.d.ts`, not TypeScript.** A scaffolded project is plain JavaScript —
 * Vite, Vitest, `type: module`. Emitting TypeScript would buy the same editor
 * type-checking at the cost of a second toolchain in every project, so the types are
 * emitted as declarations that editors read and the runtime ignores.
 *
 * **It sits on top of the hand-written client rather than replacing it.**
 * `lib/api.js` holds the `apiKey` header, the bearer token, the session cookie, the
 * `ApiError`, the two-factor dance and the debug recording. Those are the project's
 * and they are not derivable from a document. The generated file adds one function per
 * documented operation and delegates the actual call.
 *
 * **It is regenerated, never edited.** Staying in sync with the backend is the whole
 * point, and the way to stay in sync is to be rewritten from the document — so the
 * file says so at the top and the generator is idempotent.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
class OpenApiClientGenerator
{
    /**
     * How deep a schema is expanded before it becomes `any`.
     *
     * A deeply nested type generated from a document nobody wrote by hand is harder
     * to read than the JSON it describes, and the value of a type falls off a cliff
     * once it stops fitting on screen.
     */
    private const MAX_DEPTH = 3;

    /** The methods an operation can be. */
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @param array<string, mixed> $document The decoded OpenAPI document
     */
    public function __construct(private readonly array $document) {}

    /**
     * Every operation the document describes, in a shape the emitters can use.
     *
     * @return list<array<string, mixed>>
     */
    public function operations(): array
    {
        $operations = [];
        $paths      = $this->document['paths'] ?? [];

        if (!is_array($paths)) {
            return [];
        }

        foreach ($paths as $path => $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach (self::METHODS as $method) {
                if (!isset($item[$method]) || !is_array($item[$method])) {
                    continue;
                }

                $operations[] = $this->describe(
                    (string) $path,
                    $method,
                    $item[$method],
                    is_array($item['parameters'] ?? null) ? $item['parameters'] : []
                );
            }
        }

        // Sorted by name, so a regeneration produces the same file for the same
        // document — a generated file whose diff depends on key order is a generated
        // file nobody can review.
        usort($operations, fn(array $a, array $b) => strcmp($a['name'], $b['name']));

        return $operations;
    }

    /**
     * The JavaScript module: one function per operation.
     *
     * @param  string $clientModule Where the hand-written client lives, for the import
     * @return string
     */
    public function javaScript(string $clientModule = './api.js'): string
    {
        $operations = $this->operations();
        $title      = (string) ($this->document['info']['title'] ?? 'this application');

        $out = "/**\n"
            . " * Typed endpoints for {$title} — GENERATED, do not edit.\n"
            . " *\n"
            . " * One function per operation in the project's OpenAPI document, so a path or a\n"
            . " * field name is checked by the editor rather than by the browser. Regenerate with\n"
            . " *   ./<cli> create:api-client\n"
            . " * after changing the API, and never edit this file: it is rewritten from the\n"
            . " * document, which is what keeps it in step with the backend.\n"
            . " *\n"
            . " * The call itself goes through lib/api.js, which holds the things a document\n"
            . " * cannot describe — the apiKey header, the bearer token, the session cookie, the\n"
            . " * ApiError, the two-factor flow and the debug-panel recording.\n"
            . " */\n"
            . "import { api } from '{$clientModule}';\n\n";

        if ($operations === []) {
            return $out . "// The document describes no operations.\n";
        }

        // Only when something needs it: an unused helper in a generated file is a
        // lint warning in every project that receives one.
        $needsQuery = false;
        foreach ($operations as $operation) {
            foreach ($operation['parameters'] as $parameter) {
                if ($parameter['in'] === 'query') {
                    $needsQuery = true;
                    break 2;
                }
            }
        }

        if ($needsQuery) {
            $out .= $this->queryHelper();
        }

        foreach ($operations as $operation) {
            $out .= $this->emitFunction($operation);
        }

        return $out;
    }

    /**
     * The TypeScript declarations an editor reads.
     *
     * @return string
     */
    public function declarations(): string
    {
        $operations = $this->operations();
        $title      = (string) ($this->document['info']['title'] ?? 'this application');

        $out = "/**\n"
            . " * Types for {$title}'s endpoints — GENERATED, do not edit.\n"
            . " *\n"
            . " * Declarations rather than TypeScript source: a scaffolded project is plain\n"
            . " * JavaScript, and this gives an editor the same checking without adding a\n"
            . " * compiler to the build.\n"
            . " */\n\n";

        foreach ($this->schemas() as $name => $schema) {
            $out .= "export interface {$name} " . $this->typeOf($schema, self::MAX_DEPTH, true) . "\n\n";
        }

        foreach ($operations as $operation) {
            $out .= $this->emitDeclaration($operation);
        }

        return $out;
    }

    /**
     * The named schemas the document declares, if any.
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemas(): array
    {
        $schemas = $this->document['components']['schemas'] ?? [];
        if (!is_array($schemas)) {
            return [];
        }

        $named = [];
        foreach ($schemas as $name => $schema) {
            if (is_array($schema) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                $named[(string) $name] = $schema;
            }
        }
        ksort($named);

        return $named;
    }

    /**
     * One operation, reduced to what the emitters need.
     *
     * @param  string               $path
     * @param  string               $method
     * @param  array<string, mixed> $operation
     * @param  array<int, mixed>    $pathLevelParameters
     * @return array<string, mixed>
     */
    private function describe(string $path, string $method, array $operation, array $pathLevelParameters): array
    {
        // A doubled slash is a generator artefact rather than a path, and one that
        // has shipped: an endpoint documented as `/status` became `//status`.
        $cleanPath = preg_replace('#/{2,}#', '/', $path) ?? $path;

        $parameters = [];
        foreach ([$pathLevelParameters, $operation['parameters'] ?? []] as $list) {
            foreach (is_array($list) ? $list : [] as $spec) {
                if (!is_array($spec) || !isset($spec['name'], $spec['in'])) {
                    continue;
                }
                if (!in_array($spec['in'], ['path', 'query'], true)) {
                    // A header parameter is the transport's business, and lib/api.js
                    // already sets the ones this framework uses.
                    continue;
                }
                $parameters[$spec['in'] . ':' . $spec['name']] = [
                    'name'     => (string) $spec['name'],
                    'in'       => (string) $spec['in'],
                    'required' => (bool) ($spec['required'] ?? false),
                    'type'     => $this->typeOf($spec['schema'] ?? ['type' => 'string'], self::MAX_DEPTH),
                    'summary'  => (string) ($spec['description'] ?? ''),
                ];
            }
        }

        return [
            'name'       => $this->functionName($operation, $method, $cleanPath),
            'method'     => strtoupper($method),
            'path'       => $cleanPath,
            'summary'    => trim((string) ($operation['summary'] ?? '')),
            'parameters' => array_values($parameters),
            'body'       => $this->bodyType($operation),
            'returns'    => $this->responseType($operation),
        ];
    }

    /**
     * What to call the function for an operation.
     *
     * `operationId` when the document has one, because that is the name the API's
     * own author chose. Otherwise built from the method and path, which is stable
     * for the same document and readable enough to use.
     *
     * @param  array<string, mixed> $operation
     * @param  string               $method
     * @param  string               $path
     * @return string
     */
    private function functionName(array $operation, string $method, string $path): string
    {
        $id = (string) ($operation['operationId'] ?? '');
        if ($id !== '') {
            return $this->camelCase($id);
        }

        $segments = preg_replace('/\{[^}]*\}/', 'By ' . ' ', $path) ?? $path;

        return $this->camelCase($method . ' ' . str_replace('/', ' ', (string) $segments));
    }

    /**
     * `some thing-here_now` → `someThingHereNow`.
     *
     * @param  string $value
     * @return string
     */
    private function camelCase(string $value): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return 'call';
        }

        // Internal capitals are preserved, because an `operationId` is usually
        // already camelCase and lowercasing it produced `listthings` from
        // `listThings` — a name the API's own author did not choose. An all-capitals
        // word is the exception: `GET` should not become `GET`.
        $recase = static fn(string $word): string => $word === strtoupper($word)
            ? strtolower($word)
            : $word;

        $name = lcfirst($recase(array_shift($words)));
        foreach ($words as $word) {
            $name .= ucfirst($recase($word));
        }

        // A JavaScript identifier cannot start with a digit.
        return preg_match('/^[0-9]/', $name) ? 'call' . ucfirst($name) : $name;
    }

    /**
     * The request body's type, or null when the operation takes none.
     *
     * @param  array<string, mixed> $operation
     * @return string|null
     */
    private function bodyType(array $operation): ?string
    {
        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return isset($operation['requestBody']) ? 'any' : null;
        }

        return $this->typeOf($schema, self::MAX_DEPTH);
    }

    /**
     * The type of the successful response, or `any` when the document is silent.
     *
     * @param  array<string, mixed> $operation
     * @return string
     */
    private function responseType(array $operation): string
    {
        foreach (['200', '201', '202', 200, 201, 202] as $status) {
            $schema = $operation['responses'][$status]['content']['application/json']['schema'] ?? null;
            if (is_array($schema)) {
                return $this->typeOf($schema, self::MAX_DEPTH);
            }
        }

        // A 204 carries nothing, and lib/api.js returns null for it.
        if (isset($operation['responses']['204']) || isset($operation['responses'][204])) {
            return 'null';
        }

        return 'any';
    }

    /**
     * A JSON schema as a TypeScript type.
     *
     * Deliberately narrow: objects, arrays, primitives, enums and `$ref`s into
     * `components.schemas`. Anything else — `oneOf`, `allOf`, a schema with no type —
     * becomes `any`, which is honest. A generated type that is confidently wrong is
     * worse than one that admits it does not know.
     *
     * @param  mixed $schema
     * @param  int   $depth   How much further to expand
     * @param  bool  $inline  Emit a bare object body, for an interface declaration
     * @return string
     */
    private function typeOf(mixed $schema, int $depth, bool $inline = false): string
    {
        if (!is_array($schema) || $depth <= 0) {
            return 'any';
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = substr((string) strrchr($schema['$ref'], '/'), 1);

            return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : 'any';
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $values = array_map(
                fn($value) => is_string($value) ? "'" . str_replace("'", "\\'", $value) . "'" : json_encode($value),
                $schema['enum']
            );

            return implode(' | ', $values);
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : null);

        switch ($type) {
            case 'string':
                return 'string';
            case 'integer':
            case 'number':
                return 'number';
            case 'boolean':
                return 'boolean';
            case 'array':
                return $this->typeOf($schema['items'] ?? null, $depth - 1) . '[]';
            case 'object':
                $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
                if ($properties === []) {
                    return $inline ? "{\n    [key: string]: any;\n}" : 'Record<string, any>';
                }

                $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
                $lines    = [];
                foreach ($properties as $name => $property) {
                    $optional = in_array((string) $name, $required, true) ? '' : '?';
                    $lines[]  = '    ' . $this->propertyName((string) $name) . $optional . ': '
                        . $this->typeOf($property, $depth - 1) . ';';
                }

                return "{\n" . implode("\n", $lines) . "\n}";
            default:
                return 'any';
        }
    }

    /**
     * A property name, quoted when it is not a bare identifier.
     *
     * `columns[0][data]` is a real field name in this framework's datatable payloads.
     *
     * @param  string $name
     * @return string
     */
    private function propertyName(string $name): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name)
            ? $name
            : "'" . str_replace("'", "\\'", $name) . "'";
    }

    /**
     * One function, with the JSDoc an editor reads.
     *
     * @param  array<string, mixed> $operation
     * @return string
     */
    private function emitFunction(array $operation): string
    {
        $pathParams  = array_filter($operation['parameters'], fn(array $p) => $p['in'] === 'path');
        $queryParams = array_filter($operation['parameters'], fn(array $p) => $p['in'] === 'query');

        $signature = [];
        foreach ($pathParams as $param) {
            $signature[] = $this->camelCase($param['name']);
        }
        if ($operation['body'] !== null) {
            $signature[] = 'body';
        }
        if ($queryParams !== []) {
            $signature[] = 'query = {}';
        }

        $doc = "/**\n";
        $doc .= ' * ' . ($operation['summary'] !== ''
            ? $operation['summary']
            : $operation['method'] . ' ' . $operation['path']) . "\n";
        $doc .= " *\n";
        foreach ($pathParams as $param) {
            $doc .= ' * @param {' . $param['type'] . '} ' . $this->camelCase($param['name'])
                . ($param['summary'] !== '' ? ' ' . $param['summary'] : '') . "\n";
        }
        if ($operation['body'] !== null) {
            $doc .= ' * @param {' . $operation['body'] . "} body\n";
        }
        if ($queryParams !== []) {
            $names = implode(', ', array_map(fn(array $p) => $p['name'], $queryParams));
            $doc .= ' * @param {Object} [query] ' . $names . "\n";
        }
        $doc .= ' * @returns {Promise<' . $operation['returns'] . ">}\n";
        $doc .= " */\n";

        $path = $operation['path'];
        foreach ($pathParams as $param) {
            $path = str_replace(
                '{' . $param['name'] . '}',
                '${encodeURIComponent(' . $this->camelCase($param['name']) . ')}',
                $path
            );
        }

        $target = $queryParams !== []
            ? '`' . $path . '${toQuery(query)}`'
            : ($pathParams !== [] ? '`' . $path . '`' : "'" . $path . "'");

        // The body argument only when the operation has one. A POST that documents no
        // request body used to emit `api.post(path, body)` with `body` absent from the
        // signature — valid JavaScript, so `node --check` passed, and a ReferenceError
        // the first time the function was called. Found by generating against a real
        // document rather than a fixture, every POST in which happened to have a body.
        $takesBody = $operation['body'] !== null
            && in_array($operation['method'], ['POST', 'PUT', 'PATCH'], true);

        $call = 'api.' . strtolower($operation['method']) . '(' . $target
            . ($takesBody ? ', body' : '') . ')';

        return $doc
            . 'export function ' . $operation['name'] . '(' . implode(', ', $signature) . ") {\n"
            . '    return ' . $call . ";\n"
            . "}\n\n";
    }

    /**
     * One operation's declaration.
     *
     * @param  array<string, mixed> $operation
     * @return string
     */
    private function emitDeclaration(array $operation): string
    {
        $pathParams  = array_filter($operation['parameters'], fn(array $p) => $p['in'] === 'path');
        $queryParams = array_filter($operation['parameters'], fn(array $p) => $p['in'] === 'query');

        $args = [];
        foreach ($pathParams as $param) {
            $args[] = $this->camelCase($param['name']) . ': ' . $param['type'];
        }
        if ($operation['body'] !== null) {
            $args[] = 'body: ' . $operation['body'];
        }
        if ($queryParams !== []) {
            $fields = array_map(
                fn(array $p) => $this->propertyName($p['name']) . ($p['required'] ? '' : '?') . ': ' . $p['type'],
                $queryParams
            );
            $args[] = 'query?: { ' . implode(' ', array_map(fn($f) => $f . ';', $fields)) . ' }';
        }

        $summary = $operation['summary'] !== ''
            ? "/** {$operation['summary']} */\n"
            : "/** {$operation['method']} {$operation['path']} */\n";

        return $summary
            . 'export function ' . $operation['name'] . '(' . implode(', ', $args) . '): Promise<'
            . $operation['returns'] . ">;\n\n";
    }

    /**
     * The query-string helper the generated module needs.
     *
     * Emitted rather than imported from `lib/api.js`: that file is the project's, and
     * a generated file must not require an edit to it. Blank values are omitted,
     * because `?status=` and "no status filter" are different requests.
     *
     * @return string
     */
    public function queryHelper(): string
    {
        return "/**\n"
            . " * Build a query string, leaving out anything blank.\n"
            . " *\n"
            . " * `?status=` and \"no status filter\" are different requests, and an empty field\n"
            . " * means the second.\n"
            . " *\n"
            . " * @param {Object} params\n"
            . " * @returns {string} `?a=1&b=2`, or '' when there is nothing to send\n"
            . " */\n"
            . "function toQuery(params) {\n"
            . "    const pairs = Object.entries(params || {})\n"
            . "        .filter(([, value]) => value !== undefined && value !== null && value !== '')\n"
            . "        .map(([key, value]) => `\${encodeURIComponent(key)}=\${encodeURIComponent(value)}`);\n"
            . "\n"
            . "    return pairs.length ? `?\${pairs.join('&')}` : '';\n"
            . "}\n\n";
    }
}
