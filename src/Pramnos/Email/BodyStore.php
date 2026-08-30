<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * Where a sent message's body lives, once it stops living in the database.
 *
 * `mails` stores the rendered body, and the body is the whole size of that table: a
 * password-reset mail is maybe two hundred bytes of facts — when, to whom, which module, did it
 * send — wrapped around forty kilobytes of HTML. The facts are what anybody queries. The HTML is
 * read by one screen, occasionally, and never joined, filtered or aggregated on.
 *
 * So it goes to a **gzipped file** and the row keeps a path to it. That is what most projects do
 * by hand; this is the same thing with the reading made transparent, which is the part that
 * usually is not — {@see bodyOf()} takes a `mails` row and returns the body wherever it is, so
 * the preview screen, the message report and anything an application wrote keep working.
 *
 * Nothing is lost. That is the difference from emptying the column, which is the other way to
 * make this table small and costs you every question `/admin/emails/show` can answer.
 *
 * ### Content-addressed, inside a dated partition
 *
 * `mails/2026/08/3f/3f8a…c1.html.gz` — the year and month, two characters of the digest, then
 * the digest. Two decisions, pulling in opposite directions, and both worth having:
 *
 * - **The digest** means one file per distinct body. A campaign to forty thousand people is one
 *   body written once, not forty thousand copies of it — and that is the send that makes this
 *   table large in the first place.
 * - **The date** means an operator can look at, back up or remove a period without consulting
 *   the database. It costs the dedup across months: the same body sent in August and in October
 *   is two files. That is the right way round — a campaign is one moment, and "delete 2023" is
 *   a thing people actually need to do.
 *
 * A file that is already there is not written again. That is the deduplication: no index, no
 * reference count, no bookkeeping that can drift out of step with the rows.
 *
 * ### Which also means files are shared
 *
 * Two rows can name the same file, so removing one row must not remove it. Files are collected
 * by {@see orphans()} against the rows that remain, never deleted per row.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class BodyStore
{
    /** Below this, a body costs more in a file than in the row. */
    public const MIN_BYTES = 512;

    /**
     * Is the store switched on?
     *
     * Off by default. An installation that has not thought about where its mail bodies live
     * should keep them where they have always been — turning this on silently would move an
     * audit trail onto a disk nobody has decided to back up.
     */
    public static function enabled(): bool
    {
        $configured = \Pramnos\Application\Application::currentInstance()
            ?->applicationInfo['mail']['body_store']['enabled'] ?? null;

        return $configured === true;
    }

    /**
     * Where the files go.
     *
     * Under `var/`, which is outside the web root and already excluded from git by the
     * scaffolded `.gitignore`. A mail body is the contents of somebody's message: it is
     * personal data, and the one thing it must never be is served.
     */
    public static function root(): string
    {
        $configured = \Pramnos\Application\Application::currentInstance()
            ?->applicationInfo['mail']['body_store']['path'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        $base = defined('VAR_PATH')
            ? (string) VAR_PATH
            : (defined('ROOT') ? (string) ROOT . DIRECTORY_SEPARATOR . 'var' : sys_get_temp_dir());

        return $base . DIRECTORY_SEPARATOR . 'mails';
    }

    /**
     * Store a body, and return the path to record on the row.
     *
     * @param  string $html The rendered body
     * @param  int    $when The message's timestamp, which decides the partition
     * @return ?string The relative path, or null when it could not be stored
     */
    public static function put(string $html, int $when = 0): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $when   = $when > 0 ? $when : time();
        $digest = hash('sha256', $html);
        $path   = date('Y', $when) . '/' . date('m', $when) . '/' . substr($digest, 0, 2)
            . '/' . $digest . '.html.gz';
        $full   = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (is_file($full)) {
            // Already stored — the same body, sent again. This is the deduplication, and it
            // needs no index and no reference count to be correct.
            return $path;
        }

        $directory = dirname($full);

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            \Pramnos\Logs\Logger::log('Could not create a mail body directory: ' . $directory, 'email');

            return null;
        }

        /*
         * Written to a temporary name and moved into place.
         *
         * The file is addressed by the digest of its contents, so a half-written one is a file
         * whose name is a promise it does not keep — and the next send of the same body would
         * find it there and record a path to a truncated document. `rename()` within one
         * filesystem is atomic; a reader either sees the whole file or no file.
         */
        $temporary = $full . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written   = @file_put_contents($temporary, gzencode($html, 9));

        if ($written === false || !@rename($temporary, $full)) {
            @unlink($temporary);
            \Pramnos\Logs\Logger::log('Could not write a mail body to ' . $full, 'email');

            return null;
        }

        @chmod($full, 0640);

        return $path;
    }

    /**
     * Read a stored body back.
     */
    public static function get(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || !self::isSafe($path)) {
            return null;
        }

        $full = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (!is_file($full)) {
            return null;
        }

        $raw = @file_get_contents($full);

        if ($raw === false) {
            return null;
        }

        $html = @gzdecode($raw);

        return $html === false ? null : $html;
    }

    /**
     * The body of a `mails` row, wherever it is.
     *
     * The whole point of the class. Every reader goes through here, so moving a body out of the
     * database changes nothing anybody can see — which is what separates this from emptying the
     * column, where the screen simply stops having an answer.
     *
     * @param array<string, mixed> $row
     */
    public static function bodyOf(array $row): string
    {
        $inline = (string) ($row['content'] ?? $row['body'] ?? $row['mailbody'] ?? '');

        if ($inline !== '') {
            return $inline;
        }

        $path = (string) ($row['bodypath'] ?? '');

        return $path === '' ? '' : (string) (self::get($path) ?? '');
    }

    /**
     * How many bytes one stored body occupies.
     */
    public static function bytes(string $path): int
    {
        if (!self::isSafe($path)) {
            return 0;
        }

        $full = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($path));

        return is_file($full) ? (int) filesize($full) : 0;
    }

    /**
     * Stored files that no row names any more.
     *
     * Files are shared — two rows with the same body name the same file — so a row being
     * deleted says nothing about whether its file should go. What does say so is the whole set
     * of paths still recorded, and this is the difference.
     *
     * Reads every `bodypath` into memory. At a million messages that is about sixty megabytes
     * of strings, which is the point at which this wants a temporary table instead; it is not
     * the point at which anybody has run it.
     *
     * @return list<string> Relative paths, ready for {@see forget()}
     */
    public static function orphans(): array
    {
        $referenced = [];

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#mails')
                ->select(['bodypath'])
                ->where('bodypath', '!=', '')
                ->get();

            while ($result && $result->fetch()) {
                $path = (string) ($result->fields['bodypath'] ?? '');

                if ($path !== '') {
                    $referenced[$path] = true;
                }
            }
        } catch (\Throwable $exception) {
            /*
             * Refused rather than guessed at.
             *
             * "Which files does nothing reference" answered from a failed query is "all of
             * them", and the caller deletes the lot.
             */
            \Pramnos\Logs\Logger::log(
                'Could not list referenced mail bodies: ' . $exception->getMessage(),
                'email'
            );

            return [];
        }

        $root    = self::root();
        $orphans = [];

        if (!is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.html.gz')) {
                continue;
            }

            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen($root) + 1)
            );

            if (!isset($referenced[$relative])) {
                $orphans[] = $relative;
            }
        }

        sort($orphans);

        return $orphans;
    }

    /**
     * Remove one stored file.
     *
     * Only ever called with a path {@see orphans()} returned. There is no per-row delete,
     * because a per-row delete would remove a file another row still names.
     */
    public static function forget(string $path): bool
    {
        if (!self::isSafe($path)) {
            return false;
        }

        $full = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($path));

        return is_file($full) && @unlink($full);
    }

    /**
     * Is this a path this store could have written?
     *
     * The value comes from a database column, and a column is whatever was put in it. Checked
     * against the shape rather than resolved and compared, because `realpath()` on a file that
     * does not exist returns false and the check would pass for every deleted body.
     */
    private static function isSafe(string $path): bool
    {
        return preg_match('~^\d{4}/\d{2}/[0-9a-f]{2}/[0-9a-f]{64}\.html\.gz$~', trim($path)) === 1;
    }
}
