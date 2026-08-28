<?php

declare(strict_types=1);

namespace Pramnos\Support;

/**
 * Which lines of which files have changed, for tools that should only judge your own work.
 *
 * Written because two rules the framework documents were being ignored in practice, and for the
 * same reason both times: the output was unusable. `pramnos-check` run over `src/` reports
 * seventy-six findings that are all older than the change being made, and a coverage report is
 * a percentage of the whole project when the rule is about the lines just written. A check whose
 * baseline is noise is a check nobody runs.
 *
 * So this narrows to the diff. Not a git wrapper in general — it answers one question, in the
 * shape a per-line filter needs.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class GitChanges
{
    /**
     * @param string $root Absolute path to the working tree
     */
    public function __construct(private string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * The changed line numbers, keyed by project-relative path.
     *
     * `since` accepts:
     *
     * - `HEAD` — everything uncommitted, staged or not. What "my current change" means.
     * - `staged` — the index only, for a check run as a pre-commit gate.
     * - any ref — `main`, `HEAD~3`, a tag: the whole branch's worth of work.
     *
     * A new file has no diff to read, so every line of it counts as changed. That is not a
     * technicality: a new file is exactly where new violations live, and a filter that skipped
     * untracked files would pass every freshly written class.
     *
     * @param  string $since
     * @return array{files: array<string, list<int>>, ref: string, error: ?string}
     */
    public function changedLines(string $since = 'HEAD'): array
    {
        if (!$this->isRepository()) {
            return [
                'files' => [],
                'ref'   => $since,
                'error' => 'Not a git working tree: ' . $this->root,
            ];
        }

        $arguments = $since === 'staged'
            ? ['diff', '--cached', '--unified=0', '--no-color', 'HEAD']
            : ['diff', '--unified=0', '--no-color', $since];

        $diff = $this->git($arguments);

        if ($diff === null) {
            return [
                'files' => [],
                'ref'   => $since,
                'error' => 'git could not describe the changes since ' . $since
                    . '. Is it a real ref?',
            ];
        }

        $files = $this->parse($diff);

        // `git diff HEAD` covers staged changes too, so `staged` is the only mode that needs
        // the index asked for separately — and untracked files are in neither.
        if ($since !== 'staged') {
            foreach ($this->untracked() as $path) {
                $files[$path] = $this->everyLine($path);
            }
        }

        ksort($files);

        return ['files' => $files, 'ref' => $since, 'error' => null];
    }

    /**
     * Is a given line one of the changed ones?
     *
     * @param array<string, list<int>> $changed
     */
    public static function touches(array $changed, string $file, int $line): bool
    {
        return isset($changed[$file]) && in_array($line, $changed[$file], true);
    }

    /**
     * Hunk headers into line numbers.
     *
     * `@@ -12,3 +12,5 @@` says five lines starting at 12 on the new side. A pure deletion is
     * `+12,0`, which contributes nothing — there is no line left to judge.
     *
     * @return array<string, list<int>>
     */
    private function parse(string $diff): array
    {
        $files   = [];
        $current = null;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+++ ')) {
                $path = substr($line, 4);

                // `/dev/null` is a deleted file; `b/` prefixes the new side.
                $current = $path === '/dev/null'
                    ? null
                    : (str_starts_with($path, 'b/') ? substr($path, 2) : $path);

                continue;
            }

            if ($current === null || !str_starts_with($line, '@@')) {
                continue;
            }

            $match = [];

            if (preg_match('~^@@ -\S+ \+(\d+)(?:,(\d+))? @@~', $line, $match) !== 1) {
                continue;
            }

            $start = (int) $match[1];
            $count = isset($match[2]) ? (int) $match[2] : 1;

            for ($i = 0; $i < $count; $i++) {
                $files[$current][] = $start + $i;
            }
        }

        return $files;
    }

    /** @return list<string> Project-relative paths */
    private function untracked(): array
    {
        $output = $this->git(['ls-files', '--others', '--exclude-standard']);

        if ($output === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $path): bool => $path !== ''
        ));
    }

    /** @return list<int> */
    private function everyLine(string $relative): array
    {
        $path = $this->root . '/' . $relative;

        if (!is_file($path)) {
            return [];
        }

        $count = count(file($path) ?: []);

        return $count === 0 ? [] : range(1, $count);
    }

    private function isRepository(): bool
    {
        return $this->git(['rev-parse', '--is-inside-work-tree']) !== null;
    }

    /**
     * Run git in the working tree, or null if it failed.
     *
     * `safe.directory` is passed per-invocation rather than written anywhere. Inside a
     * container the files belong to the host user and git refuses with "detected dubious
     * ownership", which is the correct default and not ours to change globally — but this is
     * reading a repository the caller already handed us the path to.
     *
     * @param list<string> $arguments
     */
    private function git(array $arguments): ?string
    {
        $command = 'git -c ' . escapeshellarg('safe.directory=' . $this->root)
            . ' -C ' . escapeshellarg($this->root) . ' '
            . implode(' ', array_map('escapeshellarg', $arguments))
            . ' 2>/dev/null';

        $output = [];
        $status = 0;
        @exec($command, $output, $status);

        return $status === 0 ? implode("\n", $output) : null;
    }
}
