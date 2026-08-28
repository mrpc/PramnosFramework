<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: which tests cover this class, and how to run them.
 *
 * Not a test runner. Running tests is something a shell does well, and wrapping it would only
 * hide the project's own rule about *how* — this framework's projects have a `./dockertest`
 * that holds a lock, and a tool shelling out to `phpunit` behind its back would corrupt the
 * shared test databases it exists to protect. So this reports the command and does not run it.
 *
 * What it answers is the part a shell cannot: **where is the test for this**. Guessed by
 * filename, that question has a wrong answer often enough to matter — `Pramnos\Logs\LogManager`
 * is tested in `tests/Unit/Pramnos/Logs/`, not `tests/Unit/Logs/`, and a directory that does
 * not exist is a file-write that silently lands in the wrong place. Read from the
 * `#[CoversClass]` attributes instead, which is where the answer actually is.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class FindTestsTool implements McpToolInterface
{
    /**
     * Where tests live, in the order they are searched.
     *
     * The framework's own tests are included when this runs inside a project, because a
     * framework class is covered there and nowhere else — and "no tests" for a framework class
     * would otherwise be a confident lie.
     *
     * @var list<string>
     */
    private const TEST_PATHS = [
        'tests',
        'vendor/mrpc/pramnosframework/tests',
    ];

    private string $root;

    /**
     * The test-file listing, memoised per instance.
     *
     * Per **instance**, not a function static, which is what it was: a `static` inside the
     * method is shared by every instance in the process, so a second call with a different
     * root got the first call's answer. Wrong in a test, and wrong in production too — the MCP
     * server is long-lived, so a test file added during a session would never have been seen.
     *
     * @var array<string, string>|null
     */
    private ?array $testFiles = null;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(
            $root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()),
            DIRECTORY_SEPARATOR
        );
    }

    public function name(): string
    {
        return 'find-tests';
    }

    public function description(): string
    {
        return 'Find the tests that cover a class, from their #[CoversClass] attributes rather '
            . 'than by guessing a filename, and the exact command to run them. Ask before '
            . 'writing a test — the file may exist somewhere other than where the name '
            . 'suggests. Does not run anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'description' => 'A class name — `LogManager` or the fully-qualified form.',
                ],
                'uncovered' => [
                    'type' => 'boolean',
                    'description' => 'Instead: list classes that no test declares it covers.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'With `uncovered`, restrict to a subtree, e.g. `src/Mcp`.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $index = $this->index();

        if (!empty($input['uncovered'])) {
            return $this->uncovered($index, trim((string) ($input['path'] ?? '')));
        }

        $wanted = trim((string) ($input['class'] ?? ''));

        if ($wanted === '') {
            return [
                'error' => 'A class name is required, or `uncovered: true` for the classes no '
                    . 'test declares it covers.',
                'tests' => [
                    'files'   => $index['files'],
                    'classes' => count($index['covers']),
                    'command' => $this->command(null),
                ],
            ];
        }

        $short   = $this->shortName($wanted);
        $matches = [];

        foreach ($index['covers'] as $covered => $tests) {
            if (strcasecmp($this->shortName($covered), $short) === 0) {
                $matches[$covered] = $tests;
            }
        }

        if ($matches === []) {
            return array_filter([
                'class'   => $wanted,
                'covered' => false,
                // The weaker signal, clearly labelled. A test that merely *mentions* the class
                // is not a test of it, but it is where somebody would start looking.
                'mentioned_in' => $this->mentions($short),
                'command' => $this->command($short),
                'note'    => 'No test declares `#[CoversClass]` for this. That is not proof it '
                    . 'is untested — a test can exercise a class without declaring it — but it '
                    . 'is what the coverage report goes by.',
            ], static fn ($value): bool => $value !== [] && $value !== null);
        }

        $answer = ['class' => $wanted, 'covered' => true, 'coveredBy' => []];

        foreach ($matches as $covered => $tests) {
            $answer['coveredBy'][] = [
                'class' => $covered,
                'tests' => $tests,
            ];
        }

        /*
         * A filter that runs *all* of them, not the first one found.
         *
         * PHPUnit's `--filter` takes a regular expression, so an alternation of the test class
         * names is the whole set. Naming one of three was worse than useless: it looks like the
         * command to verify a change and silently skips two thirds of the evidence.
         */
        $testClasses = [];

        foreach ($matches as $tests) {
            foreach ($tests as $test) {
                if (isset($test['class'])) {
                    $testClasses[] = $this->shortName((string) $test['class']);
                }
            }
        }

        $answer['command'] = $this->command(
            implode('|', array_values(array_unique($testClasses)))
        );

        return $answer;
    }

    /**
     * Classes with no test declaring coverage of them.
     *
     * Structural, not a coverage measurement: it reads declarations, not execution. Which makes
     * it the cheap half of the question — a class nothing claims to cover is worth looking at
     * before anybody runs a coverage report.
     *
     * @param array{covers: array<string, list<array<string, mixed>>>, files: int} $index
     * @return array<string, mixed>
     */
    private function uncovered(array $index, string $path): array
    {
        $covered = [];

        foreach (array_keys($index['covers']) as $class) {
            $covered[strtolower($this->shortName($class))] = true;
        }

        $subtree = $path !== '' ? trim($path, '/') : 'src';
        $missing = [];

        foreach ($this->sourceFiles($subtree) as $relative => $class) {
            if (!isset($covered[strtolower($this->shortName($class))])) {
                $missing[] = ['class' => $class, 'file' => $relative];
            }
        }

        return [
            'path'      => $subtree,
            'uncovered' => array_slice($missing, 0, 60),
            'count'     => count($missing),
            'complete'  => count($missing) <= 60,
            'note'      => 'Classes that no test names in `#[CoversClass]`. A class can be '
                . 'exercised without being declared, so this is a list to look at rather than '
                . 'a list of failures — but the coverage report goes by the same declarations.',
        ];
    }

    /**
     * The `#[CoversClass]` / `@covers` declarations across every test file.
     *
     * @return array{covers: array<string, list<array<string, mixed>>>, files: int}
     */
    private function index(): array
    {
        $covers = [];
        $files  = 0;

        foreach ($this->testFiles() as $relative => $absolute) {
            $contents = @file_get_contents($absolute);

            if ($contents === false) {
                continue;
            }

            $files++;

            $testClass = $this->declaredClass($contents);
            $matches   = [];

            // `#[CoversClass(Foo::class)]` and the older `@covers Foo`.
            /*
             * The attribute may be written fully qualified.
             *
             * `#[CoversClass(...)]` with a `use` at the top, and
             * `#[\PHPUnit\Framework\Attributes\CoversClass(...)]` written out, are the same
             * declaration. Matching only the short form reported half the codebase as
             * undeclared — the direction of error that gets code deleted.
             */
            preg_match_all(
                '~#\[\s*[\\\\A-Za-z0-9_]*CoversClass\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class~',
                $contents,
                $matches
            );

            $found = $matches[1] ?? [];

            preg_match_all('~@covers\s+\\\\?([A-Za-z0-9_\\\\]+)~', $contents, $matches);
            $found = array_merge($found, $matches[1] ?? []);

            foreach (array_unique($found) as $covered) {
                $covers[$covered][] = array_filter([
                    'class'   => $testClass,
                    'file'    => $relative,
                    'methods' => $this->countTests($contents),
                ], static fn ($value): bool => $value !== null);
            }
        }

        ksort($covers);

        return ['covers' => $covers, 'files' => $files];
    }

    /**
     * Test files that merely name the class.
     *
     * @return list<string>
     */
    private function mentions(string $short): array
    {
        $found = [];

        foreach ($this->testFiles() as $relative => $absolute) {
            $contents = (string) @file_get_contents($absolute);

            if (str_contains($contents, $short)) {
                $found[] = $relative;
            }

            if (count($found) >= 12) {
                break;
            }
        }

        return $found;
    }

    /**
     * The command that runs them here.
     *
     * `./dockertest` when the project has one, because that is the project's rule and it holds
     * a lock: two concurrent runs corrupt the shared test databases. Reported rather than run —
     * this tool executes nothing, and the script would not work from inside the container it
     * would have to start.
     */
    private function command(?string $filter): string
    {
        $runner = is_file($this->root . '/dockertest') ? './dockertest' : 'vendor/bin/phpunit';

        if ($filter === null || $filter === '') {
            return $runner;
        }

        // Quoted when it is an alternation, because a bare `|` is a shell pipe.
        return str_contains($filter, '|')
            ? $runner . " --filter '" . $filter . "'"
            : $runner . ' --filter ' . $this->shortName($filter);
    }

    /**
     * Every test file, keyed by project-relative path.
     *
     * @return array<string, string>
     */
    private function testFiles(): array
    {
        if ($this->testFiles !== null) {
            return $this->testFiles;
        }

        $files = [];

        foreach (self::TEST_PATHS as $directory) {
            $absolute = $this->root . '/' . $directory;

            if (!is_dir($absolute)) {
                continue;
            }

            /*
             * `CATCH_GET_CHILD`, because a tests tree is not stable while tests run.
             *
             * The suite creates and deletes fixture directories, so a subdirectory listed a
             * moment ago can be gone by the time the iterator opens it — which threw
             * `Failed to open directory: tests/Fixtures/…` and took the whole answer with it.
             * A tool that reads the filesystem has to survive the filesystem changing.
             */
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
                    continue;
                }

                $files[$this->relative($file->getPathname())] = $file->getPathname();
            }
        }

        ksort($files);

        return $this->testFiles = $files;
    }

    /**
     * Source classes under a subtree, keyed by relative path.
     *
     * @return array<string, string> relative path => class name
     */
    private function sourceFiles(string $subtree): array
    {
        $absolute = $this->root . '/' . $subtree;

        if (!is_dir($absolute)) {
            return [];
        }

        $classes  = [];
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

            $class = $this->declaredClass((string) @file_get_contents($file->getPathname()));

            if ($class !== null) {
                $classes[$this->relative($file->getPathname())] = $class;
            }
        }

        return $classes;
    }

    /**
     * The class a file declares, with its namespace, without loading it.
     *
     * Loading to find out is how route discovery came to execute the view templates.
     */
    private function declaredClass(string $contents): ?string
    {
        /*
         * Tokens, not a line-anchored regex.
         *
         * The regex required `namespace` and `class` to begin a line, so a single-line file —
         * `<?php namespace App; class Thing {}` — declared nothing as far as this was
         * concerned, and every such class was reported as having no test. Tokens also mean a
         * `class` inside a comment or a string is not a declaration.
         */
        $tokens    = @token_get_all($contents);
        $count     = count($tokens);
        $namespace = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (!is_array($tokens[$j])) {
                        break;
                    }

                    if (in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $tokens[$j][1];
                        break;
                    }
                }

                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` and `new class {` are not declarations.
            for ($j = $i - 1; $j >= 0; $j--) {
                if (is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                ) {
                    continue;
                }

                if (is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_DOUBLE_COLON, T_NEW], true)
                ) {
                    continue 2;
                }

                break;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }

                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    return $namespace === '' ? $tokens[$j][1] : $namespace . '\\' . $tokens[$j][1];
                }

                break;
            }
        }

        return null;
    }

    /** How many test methods a file holds. */
    private function countTests(string $contents): ?int
    {
        $count = preg_match_all('~function\s+test[A-Z][A-Za-z0-9_]*\s*\(~', $contents);

        return $count > 0 ? $count : null;
    }

    private function shortName(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));

        return (string) end($parts);
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }
}
