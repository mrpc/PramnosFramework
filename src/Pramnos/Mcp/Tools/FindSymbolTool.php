<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: where a symbol is defined, and who calls it.
 *
 * The question `grep` cannot answer. Grep finds **strings**; this finds **calls**, and tells
 * you which function each one is inside — which is the part that turns a list of line numbers
 * into an explanation.
 *
 * It exists because of a specific afternoon. Tracing which code ran
 * `SELECT EXISTS(SELECT 1 FROM permissions)` took eight greps and then a patch to
 * `QueryBuilder::exists()` that dumped a backtrace, in a framework where every line of the
 * source was sitting on disk. Grep could not find it: the caller was
 * `ApiCrudController::legacyAclExists()`, whose calling line contains neither the word
 * `permissions` nor the word `exists` — it reads `$database->queryBuilder()->table($table)`.
 * The name was in a constant three lines up.
 *
 * Token-based rather than regex, so a match inside a comment, a doc-block or a string literal
 * is not reported. Those are most of what grep returns when the name is a common English word
 * like `logs` or `table`.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class FindSymbolTool implements McpToolInterface
{
    /** How many of each kind to report before saying it stopped. */
    private const MAX_RESULTS = 60;

    /** How much of the calling line to quote. */
    private const SNIPPET = 160;

    /**
     * Where the search looks, per scope.
     *
     * `app` is the consuming project, `framework` is this package. Both are searched by
     * default because the interesting call usually crosses that boundary: a project method
     * calling a framework one, or the reverse through an override.
     *
     * @var array<string, list<string>>
     */
    private const ROOTS = [
        'app'       => ['src', 'app', 'database', 'tests'],
        // The framework's own tests are included on purpose: "who calls this" is answered in
        // part by "a test asserts it", and that is exactly what tells you whether changing it
        // is safe. Absent from a `--no-dev` install, which `is_dir()` handles.
        'framework' => [
            'vendor/mrpc/pramnosframework/src',
            'vendor/mrpc/pramnosframework/tests',
            'src/Pramnos',
        ],
    ];

    private string $root;

    /**
     * @param string|null $root Project root; defaults to `ROOT`, as the other tools do
     */
    public function __construct(?string $root = null)
    {
        $this->root = rtrim(
            $root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()),
            DIRECTORY_SEPARATOR
        );
    }

    public function name(): string
    {
        return 'find-symbol';
    }

    public function description(): string
    {
        return 'Find where a PHP class, method or function is defined and every place that '
            . 'calls it, with the enclosing function of each call. Token-based, so matches in '
            . 'comments and strings are excluded. Use this instead of grep whenever the '
            . 'question is "who calls this" or "where does this live" — grep finds strings, '
            . 'not calls, and cannot say which function a call sits in. Call sites only: type '
            . 'hints, `use` statements and `instanceof` are not reported.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'A method, function or class name. `hasTable`, '
                        . '`SchemaBuilder::hasTable` or the fully-qualified form all work.',
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['all', 'app', 'framework'],
                    'description' => 'Where to look. Defaults to all.',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['both', 'definitions', 'callers'],
                    'description' => 'Defaults to both, which is usually what is wanted.',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $input): mixed
    {
        $requested = trim((string) ($input['name'] ?? ''));

        if ($requested === '') {
            return ['error' => 'A name is required — a method, function or class name.'];
        }

        // `Namespace\Class::method`, `Class::method`, or a bare name.
        $class  = null;
        $symbol = $requested;

        if (str_contains($requested, '::')) {
            [$class, $symbol] = explode('::', $requested, 2);
            $class  = trim($class, '\\');
            $symbol = trim($symbol);
        }

        if ($symbol === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $symbol)) {
            return ['error' => 'Not a PHP identifier: ' . $requested];
        }

        $scope = is_string($input['scope'] ?? null) ? $input['scope'] : 'all';
        $kind  = is_string($input['kind'] ?? null) ? $input['kind'] : 'both';

        $definitions = [];
        $callers     = [];
        $searched    = 0;
        $containing  = 0;

        foreach ($this->files($scope) as [$relative, $absolute, $source]) {
            $searched++;
            $contents = @file_get_contents($absolute);

            if ($contents === false || !str_contains($contents, $symbol)) {
                // A cheap reject before tokenising. The name has to appear *somewhere* in the
                // file for any token in it to be the name.
                continue;
            }

            $containing++;
            $found = $this->scan($contents, $symbol);

            foreach ($found['definitions'] as $definition) {
                $definitions[] = ['source' => $source, 'file' => $relative] + $definition;
            }

            foreach ($found['callers'] as $caller) {
                $callers[] = ['source' => $source, 'file' => $relative] + $caller;
            }
        }

        if ($class !== null) {
            // A class was named, so the definitions can be narrowed. The *callers* cannot:
            // knowing that `$thing->hasTable()` is a `SchemaBuilder` would need type
            // inference, so they are matched by method name and this is said out loud rather
            // than presented as a precise answer.
            $definitions = array_values(array_filter(
                $definitions,
                static fn (array $d): bool => str_ends_with(
                    strtolower((string) ($d['name'] ?? '')),
                    strtolower($class . '::' . $symbol)
                ) || strtolower((string) ($d['name'] ?? '')) === strtolower($symbol)
            ));
        }

        $answer = [
            'symbol' => $requested,
            'scope'  => isset(self::ROOTS[$scope]) || $scope === 'all' ? $scope : 'all',
            // Both numbers, because they answer different questions: `searched` is how much
            // of the codebase this looked at — which is what makes "no callers" trustworthy —
            // and `containing` is how many files the name appears in at all.
            'files'  => ['searched' => $searched, 'containing' => $containing],
        ];

        if ($kind !== 'callers') {
            $answer['definitions'] = array_slice($definitions, 0, self::MAX_RESULTS);
        }

        if ($kind !== 'definitions') {
            $answer['callers'] = array_slice($callers, 0, self::MAX_RESULTS);
        }

        $answer['counts'] = [
            'definitions' => count($definitions),
            'callers'     => count($callers),
        ];

        // Never a silent cap: a truncated list reads as "that is all there is", and this tool
        // is used to decide whether a change is safe.
        $answer['complete'] = count($definitions) <= self::MAX_RESULTS
            && count($callers) <= self::MAX_RESULTS;

        if ($class !== null) {
            $answer['note'] = 'Callers are matched by the name `' . $symbol . '` alone: '
                . 'establishing that a given call is a ' . $class . ' would need type '
                . 'inference. Read the enclosing function in each result.';
        }

        if ($definitions === [] && $callers === []) {
            $answer['note'] = 'Nothing found. The name is matched exactly and '
                . 'case-sensitively for methods and functions; a symbol only ever used '
                . 'through a variable (`$method()`, `call_user_func`) cannot be found this '
                . 'way.';
        }

        return $answer;
    }

    /**
     * One file's definitions and calls of `$symbol`.
     *
     * A single pass. The brace-depth bookkeeping is what produces the enclosing function for
     * each call, and it is the whole reason this is not a regex: `in` is the field that turns
     * a line number into an explanation.
     *
     * @return array{definitions: list<array<string, mixed>>, callers: list<array<string, mixed>>}
     */
    private function scan(string $contents, string $symbol): array
    {
        $tokens = @token_get_all($contents);
        $lines  = explode("\n", $contents);

        $definitions = [];
        $callers     = [];

        $namespace = '';
        $depth     = 0;

        /** @var list<array{kind: string, name: string, depth: int}> $stack */
        $stack = [];

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Braces first: everything else depends on the depth being right.
            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;

                    while ($stack !== [] && $stack[count($stack) - 1]['depth'] > $depth) {
                        array_pop($stack);
                    }
                }

                continue;
            }

            [$id, $text, $line] = [$token[0], $token[1], $token[2]];

            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
                continue;
            }

            /*
             * The asymmetry that quietly broke the enclosing-function column.
             *
             * `"{$user->name}"` opens with a `T_CURLY_OPEN` **token** and closes with a plain
             * `}` character. So a class containing one interpolated string appeared to close a
             * brace it had never opened, the scope stack unwound, and every call after that
             * point was reported as a bare function — `hasContinuousAggregatePolicy` instead of
             * `SchemaBuilder::hasContinuousAggregatePolicy`. Counting these as opens keeps the
             * depth honest. `${…}` has the same shape.
             */
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }

            // namespace Foo\Bar;
            if ($id === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];

                    if (!is_array($next)) {
                        break;
                    }

                    if (in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $next[1];
                        break;
                    }
                }

                continue;
            }

            // class / interface / trait / enum
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $previous = $this->previousSignificant($tokens, $i);

                // `Foo::class` is not a declaration, and neither is `new class {…}` a named
                // one — but the anonymous case still opens a brace, so it gets a scope.
                if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue;
                }

                $name = $this->nextName($tokens, $i);

                $stack[] = [
                    'kind'  => 'class',
                    'name'  => $name ?? 'class@anonymous',
                    'depth' => $depth + 1,
                ];

                if ($name !== null && strcasecmp($name, $symbol) === 0) {
                    $definitions[] = [
                        'kind' => 'class',
                        'name' => $this->qualify($namespace, $name),
                        'line' => $line,
                    ];
                }

                continue;
            }

            if ($id === T_FUNCTION) {
                $name = $this->nextName($tokens, $i);

                $stack[] = [
                    'kind'  => 'function',
                    'name'  => $name ?? 'closure',
                    'depth' => $depth + 1,
                ];

                if ($name !== null && strcasecmp($name, $symbol) === 0) {
                    $owner = $this->enclosingClass($stack, $namespace);

                    $definitions[] = [
                        'kind' => $owner === '' ? 'function' : 'method',
                        'name' => $owner === '' ? $this->qualify($namespace, $name) : $owner . '::' . $name,
                        'line' => $line,
                    ];
                }

                continue;
            }

            // A use of the name: `name(`, `Name::`, or `new Name`.
            if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            // A qualified name matches on its last segment, so `Logs\LogAnalytics::summary()`
            // is found by searching for `LogAnalytics`.
            $tail = $text;

            if (str_contains($tail, '\\')) {
                $parts = explode('\\', $tail);
                $tail  = (string) end($parts);
            }

            if (strcasecmp($tail, $symbol) !== 0) {
                continue;
            }

            $next     = $this->nextSignificant($tokens, $i);
            $previous = $this->previousSignificant($tokens, $i);

            /*
             * `Foo::` is a use of `Foo` even though no `(` follows the name — the parentheses
             * belong to the method. Without this, "who uses this class" came back empty for a
             * class only ever reached statically, which in this framework is most of them.
             */
            $isStaticReference = is_array($next) && $next[0] === T_DOUBLE_COLON;
            $isNew = is_array($previous) && $previous[0] === T_NEW;

            if ($next !== '(' && !$isStaticReference && !$isNew) {
                continue;
            }

            $type = 'call';

            if ($isStaticReference) {
                $type = 'class-ref';
            } elseif ($isNew) {
                $type = 'new';
            } elseif (is_array($previous)) {
                $type = match ($previous[0]) {
                    T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR => 'method',
                    T_DOUBLE_COLON => 'static',
                    // A declaration, already recorded above.
                    T_FUNCTION     => 'declaration',
                    default        => 'call',
                };
            }

            if ($type === 'declaration') {
                continue;
            }

            $callers[] = [
                'line' => $line,
                'in'   => $this->enclosingName($stack, $namespace),
                'type' => $type,
                'code' => $this->snippet($lines, $line),
            ];
        }

        return ['definitions' => $definitions, 'callers' => $callers];
    }

    /**
     * The identifier declared after `class`/`function`, or null for an anonymous one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextName(array $tokens, int $from): ?string
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT) {
                    continue;
                }

                return $token[0] === T_STRING ? $token[1] : null;
            }

            // `function (` — a closure; `function &name(` — by-reference return.
            if ($token === '&') {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * The next token that is not whitespace or a comment.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function nextSignificant(array $tokens, int $from): array|string|null
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            if (is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * The previous token that is not whitespace or a comment.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function previousSignificant(array $tokens, int $from): array|string|null
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * `Namespace\Class` for the innermost class on the stack, or '' outside one.
     *
     * @param list<array{kind: string, name: string, depth: int}> $stack
     */
    private function enclosingClass(array $stack, string $namespace): string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['kind'] === 'class') {
                return $this->qualify($namespace, $stack[$i]['name']);
            }
        }

        return '';
    }

    /**
     * What a call is inside, as a reader would name it.
     *
     * The field that makes this tool worth having. `Permissions::tableExists` explains a line
     * number; `src/Pramnos/Auth/Permissions.php:413` does not.
     *
     * @param list<array{kind: string, name: string, depth: int}> $stack
     */
    private function enclosingName(array $stack, string $namespace): string
    {
        $class    = $this->enclosingClass($stack, $namespace);
        $function = null;

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['kind'] === 'function') {
                $function = $stack[$i]['name'];
                break;
            }
        }

        if ($function === null) {
            return $class !== '' ? $class : '(file scope)';
        }

        // Namespaced either way: two `loose()` functions in different namespaces are
        // different functions, and an unqualified name in the answer cannot tell them apart.
        return $class !== ''
            ? $class . '::' . $function
            : $this->qualify($namespace, $function);
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * The calling line, trimmed and capped.
     *
     * @param list<string> $lines
     */
    private function snippet(array $lines, int $line): string
    {
        $text = trim($lines[$line - 1] ?? '');

        return strlen($text) > self::SNIPPET
            ? substr($text, 0, self::SNIPPET) . '…'
            : $text;
    }

    /**
     * Every PHP file in scope.
     *
     * No caching, deliberately: tokenising all 557 files of the framework measured at 60ms,
     * and a cache is a second source of truth that can be stale about the one thing this tool
     * exists to be right about.
     *
     * @return list<array{0: string, 1: string, 2: string}> relative, absolute, source
     */
    private function files(string $scope): array
    {
        $wanted = match ($scope) {
            'app'       => ['app' => self::ROOTS['app']],
            'framework' => ['framework' => self::ROOTS['framework']],
            // Framework first, so that inside the framework's *own* repository `src/Pramnos`
            // claims its files before the broader `src` entry does and the `source` label is
            // accurate. In a consuming project the two trees do not overlap and order is moot.
            default     => ['framework' => self::ROOTS['framework'], 'app' => self::ROOTS['app']],
        };

        $files = [];
        $seen  = [];

        foreach ($wanted as $source => $directories) {
            foreach ($directories as $directory) {
                $absolute = $this->root . DIRECTORY_SEPARATOR . $directory;

                if (!is_dir($absolute)) {
                    continue;
                }

                /*
                 * Inside the framework's own repository `src/Pramnos` *is* the framework and
                 * `src` would be the same tree listed twice — once as the application. The
                 * duplicate is dropped by absolute path, and the framework entry wins because
                 * it is scanned second only if it was not already claimed.
                 */
                // CATCH_GET_CHILD: a tests tree is not stable while tests run — the suite
                // creates and deletes fixture directories, and one that disappears between
                // being listed and being opened would otherwise throw away the whole answer.
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );

                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                        continue;
                    }

                    $path = $file->getPathname();

                    if (isset($seen[$path])) {
                        continue;
                    }

                    $seen[$path] = true;
                    $files[] = [
                        ltrim(str_replace($this->root, '', $path), DIRECTORY_SEPARATOR),
                        $path,
                        $source,
                    ];
                }
            }
        }

        return $files;
    }
}
