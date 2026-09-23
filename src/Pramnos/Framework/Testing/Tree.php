<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

/**
 * Reading a directory tree from a test, on a mount that occasionally says it is not there.
 *
 * ## The failure this exists for
 *
 * A full suite run produced two errors and nothing else:
 *
 * ```
 * UnexpectedValueException: RecursiveDirectoryIterator::__construct(
 *   /var/www/html/scaffolding/themes/tailwind/views): Failed to open directory:
 *   No such file or directory
 * ```
 *
 * The directory is tracked in git, is not ignored, and **its mtime on the host was
 * seventeen days earlier** — so it had not been removed and recreated. Nothing in `src/` or
 * in any test writes under `scaffolding/`. What went missing was the container's *view* of
 * it: the repository is a bind mount, and on macOS Docker Desktop that is VirtioFS
 * (`fakeowner` over `/run/host_mark/Users`), which under load can answer `ENOENT` for a
 * directory that is on the disk the whole time.
 *
 * It is rare. A watcher stat-ing the same path as fast as the mount allows for the length
 * of a full 16,000-test run did not reproduce it once. Two consecutive methods of one test
 * class saw it, and every other class reading the same path in the same run did not.
 *
 * ## What this does about it, and what it refuses to do
 *
 * **One retry**, after a short pause, and then it fails. The retry is for a mount that
 * blinked; it is not a way to tolerate a directory that is genuinely gone, which is why
 * there is exactly one and why the failure names the path and says what was checked.
 *
 * **It is not a swallow.** The two shapes this replaces are both wrong in the same way:
 * `RecursiveDirectoryIterator` throws an `UnexpectedValueException` that reads like a bug
 * in the test, and `glob()` returns `false` so a sweep quietly passes over nothing. Neither
 * tells you that a directory could not be read, which is the one fact worth having.
 *
 * Static, because the sweeps that need it build their file lists in data providers and
 * other static context, where `$this` does not exist.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
final class Tree
{
    /**
     * How long to wait before the one retry.
     *
     * Long enough for the mount to settle and short enough that nobody notices it on a run
     * where it never fires — which is every run but one.
     */
    private const RETRY_PAUSE_MICROSECONDS = 50_000;

    /**
     * How many times a read is attempted before it is reported as a failure.
     *
     * It was **two**, on the reasoning that one retry is for a mount that blinked and a
     * loop is a way to tolerate a directory that is genuinely gone. The reasoning holds;
     * the number was wrong. A new sweep over `scaffolding/` — a large tree, walked twice
     * per run — lost both attempts twice in eight runs, which is a flaky test rather than
     * a rare one, and the flake is indistinguishable from a real failure to whoever reads
     * it next.
     *
     * Three is still a small number chosen for the same reason two was: a directory that
     * is actually missing costs 100ms and then raises, which is nothing once per run and
     * is not a licence to lose it.
     */
    private const ATTEMPTS = 3;

    /**
     * Every file under `$directory`, recursively, with the given extension.
     *
     * @param  string      $directory Absolute path to walk
     * @param  string|null $extension Extension to keep, without the dot; null keeps everything
     * @return list<string> Absolute paths, sorted, so a failure message is stable between runs
     *
     * @throws \RuntimeException When the directory cannot be read twice running
     */
    public static function files(string $directory, ?string $extension = 'php'): array
    {
        $files = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $files = self::walk($directory, $extension);

            if ($files !== null) {
                break;
            }

            // The mount, or a genuinely missing directory. Another look decides which.
            if ($attempt < self::ATTEMPTS) {
                usleep(self::RETRY_PAUSE_MICROSECONDS);
            }
        }

        if ($files === null) {
            throw new \RuntimeException(
                'Could not read ' . $directory . ' — ' . self::ATTEMPTS . ' times, '
                . (int) (self::RETRY_PAUSE_MICROSECONDS / 1000) . 'ms apart. '
                . 'Look at the host before the test: if the directory is there and its mtime '
                . 'predates this run, it never went away and the bind mount is what blinked. '
                . 'If it is not there, something removed it.'
            );
        }

        sort($files);

        return $files;
    }

    /**
     * The contents of a file, with the same one retry.
     *
     * The third place the blink shows up, and the one that is easiest to write without
     * thinking about it: a sweep lists a directory, then reads each file it was given.
     * The listing succeeds and a read of a path that was in it answers `false` with a
     * warning — which PHPUnit turns into an error, on a file whose host mtime has not
     * moved in weeks.
     *
     * `false` is not "empty file" here: an empty file reads as `''`. So the two are
     * distinguishable, and only the failure is worth a second look.
     *
     * @param  string $path
     * @return string
     *
     * @throws \RuntimeException When the file cannot be read twice running
     */
    public static function read(string $path): string
    {
        $body = false;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $body = @file_get_contents($path);

            if ($body !== false) {
                break;
            }

            if ($attempt < self::ATTEMPTS) {
                usleep(self::RETRY_PAUSE_MICROSECONDS);
            }
        }

        if ($body === false) {
            throw new \RuntimeException(
                'Could not read ' . $path . ' — ' . self::ATTEMPTS . ' times, '
                . (int) (self::RETRY_PAUSE_MICROSECONDS / 1000) . 'ms apart. '
                . 'If the file is on the host with an mtime older than this run, the bind '
                . 'mount blinked; if it is gone, something removed it.'
            );
        }

        return $body;
    }

    /**
     * The paths matching a glob pattern, with the same one retry.
     *
     * `glob()` is the other half of {@see files()}, and it fails more quietly: it returns
     * `false` — indistinguishable from "nothing matched" to any caller that writes
     * `glob($pattern) ?: []`, which is all of them.
     *
     * The sweeps that use it already assert their result is non-empty, so a blink is a
     * red test rather than a silent pass. That is the right half of the fix and not the
     * whole of it: the red is still a failure nobody can act on, and one fired in a full
     * run the day after `files()` landed —
     * `AdminUrlInViewsTest … the sweep found nothing to check` — over a directory that
     * has not changed since August.
     *
     * So: look twice, and only then answer. An empty match is still a legitimate answer
     * and comes back as `[]`; the caller decides whether that is allowed.
     *
     * @param  string $pattern A glob pattern
     * @param  int    $flags   Passed to `glob()`
     * @return list<string>
     */
    public static function matching(string $pattern, int $flags = 0): array
    {
        $found = glob($pattern, $flags);

        if ($found === false || $found === []) {
            // `false` is a failure and `[]` may be one: on this mount a directory that
            // is there can answer either. A second look costs 50ms once and separates
            // them the only way available.
            usleep(self::RETRY_PAUSE_MICROSECONDS);
            $found = glob($pattern, $flags);
        }

        return $found === false ? [] : array_values($found);
    }

    /**
     * One attempt.
     *
     * @return list<string>|null `null` when the directory could not be opened
     */
    private static function walk(string $directory, ?string $extension): ?array
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException) {
            // What `RecursiveDirectoryIterator` throws when it cannot open the path.
            return null;
        }

        $files = [];

        /*
         * No try/catch around the walk itself, deliberately. A blink *during* it would
         * raise the same `UnexpectedValueException` and the retry would be just as
         * applicable — but the failure this class was written for happened at `open`, a
         * mid-walk one has never been seen here, and the branch cannot be exercised: the
         * only way to make a descend fail on demand is a mode bit, and this container runs
         * as root. A guard whose test can only skip is a guard nobody has checked.
         */
        /*
         * No `isFile()` check, and that is measured rather than assumed: in the default
         * `LEAVES_ONLY` mode a directory is never yielded — not even an empty one, which
         * is descended into and produces nothing. Under `SELF_FIRST` it would be, and the
         * check would be needed; `TreeTest` pins the mode by asserting an empty
         * subdirectory does not appear, so switching it fails there rather than here.
         */
        foreach ($iterator as $file) {
            if ($extension !== null && $file->getExtension() !== $extension) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
