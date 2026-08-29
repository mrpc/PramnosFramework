<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;
use Pramnos\Support\GitChanges;

/**
 * MCP tool: which lines of *this change* are not covered by a test.
 *
 * The rule in these projects is coverage above 95% on the code you changed. It was unverifiable.
 * A coverage run produces an HTML report of the whole project, and a project-wide percentage
 * answers a different question — one that barely moves when you add fifty uncovered lines to a
 * codebase of twenty thousand. So the rule was followed by assumption, which is to say not
 * followed: two thousand lines were written in a day without it being checked once.
 *
 * This reads the clover report and intersects it with the diff. The answer is a list of line
 * numbers, which is short enough to act on.
 *
 * It does not run the tests. `./dockertest --coverage` holds a lock — two concurrent runs
 * corrupt the shared test databases — and it could not run from inside the container it would
 * have to start. So the report is read, its age is reported, and a stale report is called stale
 * rather than trusted.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class CoverageTool implements McpToolInterface
{
    /**
     * Where a clover report is looked for.
     *
     * @var list<string>
     */
    private const REPORT_PATHS = [
        'coverage/clover.xml',
        'build/logs/clover.xml',
        'clover.xml',
    ];

    /** Uncovered lines listed per file before the answer becomes a report of its own. */
    private const MAX_LINES = 40;

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
        return 'coverage';
    }

    public function description(): string
    {
        return 'Which lines of your current change are not covered by any test, from the clover '
            . 'report intersected with the git diff. Use this before calling a change finished '
            . '— a project-wide coverage percentage cannot answer it. Reads an existing report '
            . 'and runs nothing; it says so when the report is older than the code.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'since' => [
                    'type' => 'string',
                    'description' => 'What counts as "your change": `HEAD` (default) for '
                        . 'everything uncommitted, `staged`, or any ref such as `main`.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Restrict to a subtree. Omit for the whole diff.',
                ],
                'project' => [
                    'type' => 'boolean',
                    'description' => 'Instead: the project-wide figure, for context.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $report = $this->reportPath();

        if ($report === null) {
            return [
                'error'  => 'No clover coverage report found.',
                'looked' => self::REPORT_PATHS,
                'note'   => 'Produce one with `./dockertest --coverage`, which writes '
                    . '`coverage/clover.xml` beside the HTML report. This tool reads it and '
                    . 'does not run anything: the test script holds a lock, and two concurrent '
                    . 'runs corrupt the shared test databases.',
            ];
        }

        $lines = $this->parse($report);

        if ($lines === null) {
            return ['error' => $this->relative($report) . ' could not be parsed as clover XML.'];
        }

        $generatedAt = (int) filemtime($report);

        if (!empty($input['project'])) {
            return $this->projectWide($lines, $report, $generatedAt);
        }

        $since   = trim((string) ($input['since'] ?? 'HEAD'));
        $changes = (new GitChanges($this->root))->changedLines($since === '' ? 'HEAD' : $since);

        if ($changes['error'] !== null) {
            return [
                'error' => $changes['error'],
                'note'  => 'Ask with `project: true` for the whole-project figure instead.',
            ];
        }

        $path = trim((string) ($input['path'] ?? ''));

        $covered    = 0;
        $uncovered  = 0;
        $files      = [];
        $unmeasured = [];
        $roots      = $this->measuredRoots($lines);

        foreach ($changes['files'] as $file => $changedLines) {
            if ($path !== '' && !str_starts_with($file, trim($path, '/'))) {
                continue;
            }

            if (!isset($lines[$file])) {
                /*
                 * A file the report says nothing about at all.
                 *
                 * Usually that is correct and uninteresting — a guide, a stub, a test, a
                 * migration: nothing outside the coverage whitelist is in clover, and counting
                 * those as uncovered would fail every honest change.
                 *
                 * But a **new class under a measured root** is absent for the opposite reason:
                 * no test ever loaded it, so PHPUnit never saw a line of it. Skipped quietly,
                 * that file reports as fully covered — the one answer a coverage gate must never
                 * give, and precisely the case it exists to catch. So it is named instead.
                 */
                if ($this->isMeasurable($file, $roots)) {
                    $unmeasured[] = $file;
                }

                continue;
            }

            $missing = [];
            $hit     = 0;

            foreach ($changedLines as $line) {
                if (!array_key_exists($line, $lines[$file])) {
                    continue;
                }

                if ($lines[$file][$line] > 0) {
                    $hit++;
                    continue;
                }

                $missing[] = $line;
            }

            if ($hit === 0 && $missing === []) {
                continue;   // nothing executable changed in this file
            }

            $covered   += $hit;
            $uncovered += count($missing);

            $files[] = array_filter([
                'file'      => $file,
                'covered'   => $hit,
                'uncovered' => count($missing),
                'uncovered_lines' => $missing === []
                    ? null
                    : array_slice($missing, 0, self::MAX_LINES),
                'truncated' => count($missing) > self::MAX_LINES ?: null,
            ], static fn ($value): bool => $value !== null && $value !== false);
        }

        sort($unmeasured);

        $total   = $covered + $uncovered;
        $percent = $total === 0 ? null : round($covered / $total * 100, 1);

        return array_filter([
            'report' => [
                'file'         => $this->relative($report),
                'generated_at' => date('d/m/Y H:i', $generatedAt),
                'stale'        => $this->staleness($changes['files'], $generatedAt),
            ],
            'since'   => $changes['ref'],
            'changed_executable_lines' => $total,
            'covered'   => $covered,
            'uncovered' => $uncovered,
            'percent'   => $percent,
            'files'     => $files,
            'unmeasured' => $unmeasured === [] ? null : $unmeasured,
            'unmeasured_note' => $unmeasured === [] ? null
                : 'These files are under a directory the report measures and are not in it at '
                . 'all, which means no test loaded them. Treat them as 0% until a test does — '
                . 'the percentage above is the coverage of everything else.',
            'verdict'   => $unmeasured === []
                ? $this->verdict($percent, $total, $uncovered)
                : count($unmeasured) . ' changed ' . (count($unmeasured) === 1 ? 'file is' : 'files are')
                    . ' not covered by any test at all. ' . $this->verdict($percent, $total, $uncovered),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * The top-level directories this report actually measures.
     *
     * Read from the report rather than configured, because the whitelist lives in
     * `phpunit.xml` and a tool that guessed `src/` would be wrong for any project that put its
     * code somewhere else.
     *
     * @param  array<string, array<int, int>> $lines
     * @return list<string>
     */
    private function measuredRoots(array $lines): array
    {
        $roots = [];

        foreach (array_keys($lines) as $file) {
            $first = strtok($file, '/');

            if ($first !== false && $first !== $file) {
                $roots[$first] = true;
            }
        }

        return array_keys($roots);
    }

    /**
     * Should this file have been in the report?
     *
     * @param list<string> $roots
     */
    private function isMeasurable(string $file, array $roots): bool
    {
        if (!str_ends_with($file, '.php')) {
            return false;
        }

        foreach ($roots as $root) {
            if (str_starts_with($file, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole-project figure, asked for explicitly.
     *
     * Available but not the default, because it is the number that made the rule
     * unverifiable: adding fifty uncovered lines to twenty thousand covered ones barely moves
     * it, so it cannot fail when your change should.
     *
     * @param array<string, array<int, int>> $lines
     * @return array<string, mixed>
     */
    private function projectWide(array $lines, string $report, int $generatedAt): array
    {
        $covered = 0;
        $total   = 0;

        foreach ($lines as $fileLines) {
            foreach ($fileLines as $count) {
                $total++;

                if ($count > 0) {
                    $covered++;
                }
            }
        }

        return [
            'report' => [
                'file'         => $this->relative($report),
                'generated_at' => date('d/m/Y H:i', $generatedAt),
            ],
            'files'     => count($lines),
            'lines'     => $total,
            'covered'   => $covered,
            'percent'   => $total === 0 ? null : round($covered / $total * 100, 1),
            'note'      => 'The project-wide figure. It is not the rule: a change can add '
                . 'fifty uncovered lines and move this by a tenth of a point. Ask without '
                . '`project` for the lines you actually changed.',
        ];
    }

    /**
     * Is the report older than the code it describes?
     *
     * A coverage report is a measurement with a timestamp, and reading a stale one is worse
     * than reading none: it reports the previous version of the file as covered.
     *
     * @param array<string, list<int>> $changed
     */
    private function staleness(array $changed, int $generatedAt): bool|string
    {
        foreach (array_keys($changed) as $file) {
            $path = $this->root . '/' . $file;

            if (is_file($path) && (int) filemtime($path) > $generatedAt) {
                return 'At least one changed file is newer than the report ('
                    . $file . '). Re-run `./dockertest --coverage` — these figures describe '
                    . 'the previous version of it.';
            }
        }

        return false;
    }

    /**
     * What the numbers mean, in a sentence.
     */
    private function verdict(?float $percent, int $total, int $uncovered): string
    {
        if ($total === 0) {
            return 'No executable lines changed — nothing for coverage to say. Blank lines, '
                . 'closing braces, comments and property declarations are not measurable, and '
                . 'a change made only of those is not untested.';
        }

        if ($uncovered === 0) {
            return 'Every executable line you changed is covered (' . $total . ' lines).';
        }

        $sentence = $uncovered . ' of ' . $total . ' changed lines are not covered by any test '
            . '— ' . $percent . '%.';

        // A verdict that reads like a failure at 99% is a tool crying wolf, and the next
        // reading gets ignored. The threshold is stated either way; which side of it we are on
        // is the part that has to be unambiguous.
        return $percent !== null && $percent >= 95.0
            ? $sentence . ' Above the 95% these projects ask for on changed code.'
            : $sentence . ' The rule in these projects is above 95% on changed code.';
    }

    /**
     * Clover into `file => [line => hit count]`.
     *
     * @return array<string, array<int, int>>|null
     */
    private function parse(string $report): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string((string) @file_get_contents($report));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        $lines = [];

        foreach ($xml->xpath('//file') ?: [] as $file) {
            $name = (string) ($file['name'] ?? '');

            if ($name === '') {
                continue;
            }

            /*
             * Clover records the path the *test run* saw, which inside a container is
             * `/var/www/html/src/...` while the caller thinks in project-relative paths. Making
             * them meet is the whole join, and getting it wrong reports every line as
             * unmeasurable rather than failing loudly.
             */
            $relative = $this->relativeFromReport($name);

            foreach ($file->line ?? [] as $line) {
                $number = (int) ($line['num'] ?? 0);

                if ($number > 0) {
                    $lines[$relative][$number] = (int) ($line['count'] ?? 0);
                }
            }
        }

        return $lines;
    }

    /**
     * A path out of the report, as a project-relative one.
     *
     * The report may have been produced inside a container with a different root, so the
     * project root is trimmed if it matches and otherwise the path is cut at the first
     * recognisable source directory.
     */
    private function relativeFromReport(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, $this->root . '/')) {
            return substr($path, strlen($this->root) + 1);
        }

        foreach (['/src/', '/app/', '/database/', '/tests/'] as $marker) {
            $at = strpos($path, $marker);

            if ($at !== false) {
                return ltrim(substr($path, $at + 1), '/');
            }
        }

        return ltrim($path, '/');
    }

    private function reportPath(): ?string
    {
        foreach (self::REPORT_PATHS as $candidate) {
            $path = $this->root . '/' . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }
}
