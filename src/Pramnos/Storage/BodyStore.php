<?php

declare(strict_types=1);

namespace Pramnos\Storage;

/**
 * Where a message's body lives, once it stops living in the database.
 *
 * Two tables have the same shape of problem. `mails` stores the rendered body of every message
 * this installation sent, and `messages` stores the body of every one it delivered to somebody's
 * inbox — including one row per recipient of a mass send. In both, the body is the whole size of
 * the table and the only column nobody queries.
 *
 * Take `mails`: a
 * password-reset mail is maybe two hundred bytes of facts — when, to whom, which module, did it
 * send — wrapped around forty kilobytes of HTML. The facts are what anybody queries. The HTML is
 * read by one screen, occasionally, and never joined, filtered or aggregated on.
 *
 * So it goes to a **gzipped file** and the row keeps a path to it. That is what most projects do
 * by hand; this is the same thing with the reading made transparent, which is the part that
 * usually is not — {@see bodyOf()} takes a row from either table and returns the body wherever it
 * is, so the preview screen, the message report and anything an application wrote keep working.
 *
 * ### One store, several tables
 *
 * They share it deliberately. A mass message that goes out as both mail and inbox message is one
 * body, and the digest makes it one file no matter how many rows name it. What that sharing costs
 * is that "which files does nothing reference any more" can no longer be answered from one table:
 * {@see REFERENCED_BY} is the list every reader of that question has to consult, and forgetting to
 * add a table to it is how a garbage collection deletes bodies that are still in use. It is a
 * constant rather than a lookup for exactly that reason — it is short, and it is the only place
 * the answer lives.
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
    /**
     * Every table that keeps a path into this store, and the column it keeps it in.
     *
     * {@see orphans()} deletes a file that no row names. If a table is missing from this list its
     * bodies look unreferenced, and a garbage collection removes messages people can still open.
     * Adding a table here is not optional bookkeeping — it is the whole safety of the sweep.
     *
     * @var array<string, string> table => column holding the relative path
     */
    public const REFERENCED_BY = [
        '#PREFIX#mails'    => 'bodypath',
        '#PREFIX#messages' => 'bodypath',
    ];

    /**
     * The table whose absence means something is wrong rather than switched off.
     *
     * A missing table is two different events wearing the same face, and {@see orphans()} has to
     * tell them apart because it deletes files.
     *
     * `messages` belongs to a feature an installation can turn off. If it is not there, no row
     * ever pointed into this store from it, and skipping it costs nothing — refusing instead
     * would mean `--gc` reports "nothing to collect" for ever, with the reason invisible.
     *
     * `mails` is different. The store was built for it, every file in the archive is presumed to
     * have come from it, and an installation running this code without that table is broken, not
     * configured. Reading "no row references anything" out of its absence hands the caller the
     * whole archive to delete — so its absence stops the sweep.
     */
    public const REQUIRED_TABLE = '#PREFIX#mails';

    /**
     * No longer a threshold.
     *
     * It was 512: below that, a body costs more in a file — a 4KB block and an inode for two
     * hundred bytes — than it does in the row. That trade was real and it was the wrong one to
     * take, because it bought a little disk at the price of the only invariant that makes this
     * store worth having: *the body is not in the database*. With a threshold that sentence has
     * an "unless" in it, and every answer that depends on it — what a GDPR erasure has to clear,
     * how large `mails` can get, whether `var/mails` is the backup that matters — has the same
     * "unless". One rule that always holds is worth more than a block per short message.
     *
     * Kept at zero rather than removed: it is public, and something outside this framework may
     * name it.
     *
     * @deprecated Every non-empty body goes to the store now, whatever its size.
     */
    public const MIN_BYTES = 0;

    /**
     * Is the store switched on?
     *
     * **On unless an installation says otherwise.** It was opt-in, on the reasoning that moving
     * an audit trail onto a disk nobody has decided to back up should be somebody's decision.
     * That reasoning had the default backwards: the installations that never made the decision
     * are exactly the ones whose `mails` table grows without anybody watching it, and the body
     * is the whole of that growth. An installation that has not thought about this is better
     * served by the arrangement it would have chosen had it thought about it.
     *
     * Nothing is at risk in the switch. A body that cannot be written falls back to the column
     * it used to live in, so an installation with no writable `var/mails` behaves exactly as it
     * did; {@see bodyOf()} reads a row from wherever its body is, so rows written before and
     * after the change are read the same way; and `'enabled' => false` still turns it off.
     */
    public static function enabled(): bool
    {
        $configured = \Pramnos\Application\Application::currentInstance()
            ?->applicationInfo['mail']['body_store']['enabled'] ?? null;

        return $configured !== false;
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
     * The opening of a body as plain text, for a row that has to show a preview.
     *
     * A listing wants a summary, not a body. Once the body is in a file, drawing a preview from
     * it costs one decompression per row — two hundred to paint an inbox — so the summary is
     * computed once, at write time, and kept on the row where a listing can select it.
     *
     * Tags out, entities decoded, whitespace collapsed. What is left is what a person reads at a
     * glance, and it survives the body being HTML, which it usually is.
     */
    public static function excerpt(string $html, int $length = 255): string
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        return mb_substr($plain, 0, $length);
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
        $inline = (string) ($row['content'] ?? $row['text'] ?? $row['body']
            ?? $row['mailbody'] ?? '');

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
        $database   = \Pramnos\Framework\Factory::getDatabase();

        /*
         * Taken before the tables are read, and it is what stops this deleting live bodies.
         *
         * The two halves of this method cannot be one instant: the rows are read first, the
         * files second. A message sent in between writes a file that no row named *at the time
         * the rows were read* — so it looks exactly like an orphan, and it is the body of a
         * message somebody received a second ago.
         *
         * On an installation that sends all day the window is not theoretical, and the damage is
         * silent: the row keeps its `bodypath` and the file behind it is gone.
         *
         * So a file is only ever a candidate if it already existed when the sweep began, less a
         * minute of margin for clock skew between the database host and this one. Nothing is
         * lost by waiting — a genuine orphan is still an orphan on the next run.
         */
        $startedAt = time() - 60;

        foreach (self::REFERENCED_BY as $table => $column) {
            try {
                /*
                 * An absent table is not a failed query.
                 *
                 * Refusing the sweep is the right answer when a table *should* have been read
                 * and could not be — the files it would have named are then indistinguishable
                 * from files nothing names. It is the wrong answer for a table this installation
                 * simply does not have, because that refusal never lifts: `--gc` would report
                 * nothing to collect, for ever, and the reason would be invisible.
                 *
                 * {@see REQUIRED_TABLE} is which of the two this is.
                 */
                if (!$database->schema()->hasTable($table)) {
                    if ($table === self::REQUIRED_TABLE) {
                        \Pramnos\Logs\Logger::log(
                            'Refusing to collect unreferenced bodies: ' . $table . ' is missing, '
                            . 'so every file in the archive would look unreferenced.',
                            'email'
                        );

                        return [];
                    }

                    continue;
                }

                $result = $database->queryBuilder()
                    ->table($table)
                    ->select([$column])
                    ->where($column, '!=', '')
                    ->get();

                while ($result && $result->fetch()) {
                    $path = (string) ($result->fields[$column] ?? '');

                    if ($path !== '') {
                        $referenced[$path] = true;
                    }
                }
            } catch (\Throwable $exception) {
                /*
                 * Refused rather than guessed at, and refused for the whole sweep.
                 *
                 * "Which files does nothing reference" answered from a failed query is "all of
                 * them", and the caller deletes the lot. One unreadable table is enough to make
                 * the answer wrong, because the files it would have named are indistinguishable
                 * from files nothing names — so a partial answer here is not a smaller version
                 * of the right one, it is the deletion of somebody's messages.
                 */
                \Pramnos\Logs\Logger::log(
                    'Could not list referenced bodies in ' . $table . ': '
                    . $exception->getMessage(),
                    'email'
                );

                return [];
            }
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

            if (isset($referenced[$relative])) {
                continue;
            }

            // Newer than the sweep: written after the rows were read, so its row was never in
            // the list. Not evidence of an orphan — evidence of a send.
            if ((int) $file->getMTime() >= $startedAt) {
                continue;
            }

            $orphans[] = $relative;
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
