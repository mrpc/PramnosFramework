<?php

declare(strict_types=1);

namespace Pramnos\Routing;

/**
 * The `@api` blocks of apidoc (apidocjs.com) in an application's controllers, read as OpenAPI
 * operations — so an API documented that way before it was registered on the router is described
 * in `api:docs` by what it already says, not by its method and path alone.
 *
 * ```php
 * /**
 *  * @api {get} 1.0/me/notifications/:id Get a notification
 *  * @apiGroup Notifications
 *  * @apiDescription The notification, if it is the person's.
 *  * @apiParam {Number} id The notification
 *  * @apiParam {Boolean} [read] Mark it read
 *  * @apiSuccess {String} title Its title
 *  * @apiError NotFound No such notification
 *  *\/
 * ```
 *
 * - the title → `summary`, `@apiDescription` → `description`, `@apiGroup` → `tags`;
 * - `@apiParam` → a path parameter where the path names it, else the query (GET, DELETE) or the
 *   JSON body (POST, PUT, PATCH); `[name]` is optional;
 * - `@apiSuccess` → the 200 response's fields, `@apiError` → a 4XX response naming the errors.
 *
 * Operations are keyed by method and path shape (`get /me/notifications/{}`): a version prefix
 * (`1.0/`) left out, every parameter alike — `:id` and `{notificationid}` are the same place.
 */
final class ApidocReader
{
    /** apidoc's types, as OpenAPI's. */
    private const TYPES = ['number' => 'number', 'integer' => 'integer', 'int' => 'integer', 'string' => 'string', 'boolean' => 'boolean',
        'bool' => 'boolean', 'object' => 'object', 'array' => 'array', 'file' => 'string'];

    /**
     * Every `@api` block in the PHP files under a directory, by method and path shape.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function read(string $dir): array
    {
        $operations = [];
        if (!is_dir($dir)) {
            return $operations;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, '@api ')) {
                continue;
            }
            preg_match_all('#/\*\*.*?\*/#s', $source, $blocks);
            foreach ($blocks[0] as $block) {
                $operation = self::parse($block);
                if ($operation !== null) {
                    $operations[$operation['key']] = $operation['operation'];
                }
            }
        }

        return $operations;
    }

    /** A route's key: its method, and its path's shape. */
    public static function key(string $verb, string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if ($segments !== [] && preg_match('/^v?\d+(\.\d+)*$/u', $segments[0]) === 1) {
            array_shift($segments);
        }
        $segments = array_map(static fn (string $s): string => preg_match('/^(:\w+|\{\w+\??\})$/u', $s) === 1 ? '{}' : strtolower($s), $segments);

        return strtolower($verb) . ' /' . implode('/', $segments);
    }

    /**
     * One block, as an operation; null for a block that is no `@api`.
     *
     * @return array{key: string, operation: array<string, mixed>}|null
     */
    public static function parse(string $block): ?array
    {
        // With /u throughout: without it \R and \s take the byte 0x85 — the second byte of the
        // Greek «υ» (CF 85) — for a line break, and cut the text into what is no longer UTF-8.
        $lines = array_map(static fn (string $l): string => (string) preg_replace('~^\s*(/\*\*|\*/|\*)\s?~u', '', $l), preg_split('/\R/u', $block) ?: []);
        $tags  = [];
        foreach ($lines as $line) {
            if (preg_match('/^@(\w+)\s*(.*)$/u', trim($line), $m) === 1) {
                $tags[] = [$m[1], trim($m[2])];
            } elseif ($tags !== [] && trim($line) !== '' && !str_ends_with(trim($line), '/')) {
                $tags[count($tags) - 1][1] .= "\n" . trim($line);
            }
        }
        $api = null;
        foreach ($tags as [$tag, $text]) {
            if ($tag === 'api' && preg_match('/^\{(\w+)\}\s+(\S+)\s*(.*)$/su', $text, $m) === 1) {
                $api = [strtolower($m[1]), '/' . ltrim($m[2], '/'), trim($m[3])];
            }
        }
        if ($api === null) {
            return null;
        }
        [$verb, $path, $title] = $api;
        $inPath    = preg_match_all('/:(\w+)/u', $path, $p) ? $p[1] : [];
        $operation = ['summary' => $title !== '' ? $title : strtoupper($verb) . ' ' . $path];
        $body      = [];
        $required  = [];
        $success   = [];
        $errors    = [];
        foreach ($tags as [$tag, $text]) {
            match ($tag) {
                'apiDescription' => $operation['description'] = trim(strip_tags($text)),
                'apiGroup'       => $operation['tags'] = [trim($text)],
                default          => null,
            };
            if ($tag === 'apiParam' && ($field = self::field($text)) !== null) {
                if (in_array($field['name'], $inPath, true)) {
                    $operation['parameters'][] = ['name' => $field['name'], 'in' => 'path', 'required' => true, 'schema' => ['type' => $field['type']]]
                        + ($field['description'] !== '' ? ['description' => $field['description']] : []);
                } elseif (in_array($verb, ['get', 'delete'], true)) {
                    $operation['parameters'][] = ['name' => $field['name'], 'in' => 'query', 'required' => !$field['optional'], 'schema' => ['type' => $field['type']]]
                        + ($field['description'] !== '' ? ['description' => $field['description']] : []);
                } else {
                    $body[$field['name']] = ['type' => $field['type']] + ($field['description'] !== '' ? ['description' => $field['description']] : []);
                    if (!$field['optional']) {
                        $required[] = $field['name'];
                    }
                }
            } elseif ($tag === 'apiSuccess' && ($field = self::field($text)) !== null) {
                $success[$field['name']] = ['type' => $field['type']] + ($field['description'] !== '' ? ['description' => $field['description']] : []);
            } elseif ($tag === 'apiError' && preg_match('/^(?:\([^)]*\)\s*)?(?:\{[^}]*\}\s*)?(\S+)\s*(.*)$/su', $text, $m) === 1) {
                $errors[] = $m[1] . ($m[2] !== '' ? ': ' . trim($m[2]) : '');
            }
        }
        if ($body !== []) {
            $operation['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $body] + ($required !== [] ? ['required' => $required] : [])]]];
        }
        $operation['responses']['200'] = ['description' => 'Successful response']
            + ($success !== [] ? ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $success]]]] : []);
        if ($errors !== []) {
            $operation['responses']['4XX'] = ['description' => implode("\n", $errors)];
        }

        return ['key' => self::key($verb, $path), 'operation' => $operation];
    }

    /**
     * A `{Type} [name=default] description` field.
     *
     * @return array{name: string, type: string, optional: bool, description: string}|null
     */
    private static function field(string $text): ?array
    {
        if (preg_match('/^(?:\([^)]*\)\s*)?(?:\{([^}]*)\}\s*)?(\[?)([\w.\-]+)(?:=[^\]\s]*)?\]?\s*(.*)$/su', $text, $m) !== 1) {
            return null;
        }
        $type = strtolower(trim(explode('{', $m[1])[0]));
        $type = str_ends_with($type, '[]') ? 'array' : (self::TYPES[$type] ?? 'string');

        return ['name' => $m[3], 'type' => $type, 'optional' => $m[2] === '[', 'description' => trim(strip_tags($m[4]))];
    }
}
