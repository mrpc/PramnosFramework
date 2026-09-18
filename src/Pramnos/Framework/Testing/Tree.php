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
        $files = self::walk($directory, $extension);

        if ($files === null) {
            // The mount, or a genuinely missing directory. One more look decides which.
            usleep(self::RETRY_PAUSE_MICROSECONDS);
            $files = self::walk($directory, $extension);
        }

        if ($files === null) {
            throw new \RuntimeException(
                'Could not read ' . $directory . ' — twice, '
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
