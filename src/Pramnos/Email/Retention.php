<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Framework\Factory;

/**
 * What to keep of a sent message, and for how long.
 *
 * `mails` is the table that grows without limit in every installation of this framework, and it
 * grows for a reason that is easy to miss: it stores the **rendered body**. A password-reset
 * mail is maybe two hundred bytes of facts — when, to whom, which module, did it send — wrapped
 * around forty kilobytes of HTML. At a thousand messages a day that is fifteen gigabytes a year
 * of markup nobody will read, and about eighty megabytes of the answers people actually ask.
 *
 * So the policy is **two stages, not one**:
 *
 * 1. **Strip the body** after a while. The row stays, and with it every question an operator
 *    asks months later — "did they ever get the code", "when did we last write to this
 *    address", "how many did we send that week". What is lost is
 *    {@see MessageReport}: nothing can be read back out of a message whose body is gone.
 * 2. **Delete the row** eventually, because an audit log with no horizon is not a policy.
 *
 * Deleting alone is the version people write, and it is the wrong shape: it throws away the
 * cheap thing to save the expensive one. Stripping alone is also wrong — it leaves a table
 * growing at eighty megabytes a year for ever.
 *
 * Nothing here runs on its own. It is a command and a scheduled job, because a retention policy
 * that fires from a web request is a request that occasionally takes four minutes.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Retention
{
    /** How many rows one pass touches, so a long-neglected table does not lock for minutes. */
    public const BATCH = 5000;

    /**
     * What the mail log currently costs, and what a policy would reclaim.
     *
     * The number that decides whether anybody bothers. `bodies` against `rows` is the whole
     * argument for stripping rather than deleting: they are two orders of magnitude apart.
     *
     * @return array<string, mixed>
     */
    public static function stats(int $stripAfter = 0, int $deleteAfter = 0): array
    {
        try {
            $db     = Factory::getDatabase();
            $result = $db->query(
                'SELECT COUNT(*) AS rows_total,
                        COUNT(CASE WHEN content <> \'\' THEN 1 END) AS with_body,
                        SUM(LENGTH(content)) AS body_bytes,
                        MIN(date) AS oldest,
                        MAX(date) AS newest
                 FROM ' . $db->prefix . 'mails'
            );
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }

        $row = (array) ($result?->fetch() ?? []);

        $stats = [
            'rows'       => (int) ($row['rows_total'] ?? 0),
            'with_body'  => (int) ($row['with_body'] ?? 0),
            'body_bytes' => (int) ($row['body_bytes'] ?? 0),
            'oldest'     => (int) ($row['oldest'] ?? 0),
            'newest'     => (int) ($row['newest'] ?? 0),
        ];

        /*
         * What is already archived, read from the column rather than from the disk.
         *
         * `bodybytes` is recorded when the body is stored precisely so this is a `SUM()` and
         * not a `stat()` per row — the answer to "what is this costing" should not itself cost
         * a million file lookups.
         */
        try {
            $archive = $db->query(
                'SELECT COUNT(*) AS archived, SUM(bodybytes) AS archived_bytes FROM '
                . $db->prefix . 'mails WHERE bodypath <> \'\''
            );

            $row = (array) ($archive?->fetch() ?? []);
            $stats['archived']       = (int) ($row['archived'] ?? 0);
            $stats['archived_bytes'] = (int) ($row['archived_bytes'] ?? 0);

            /*
             * And what it actually occupies, which is a different number.
             *
             * Bodies are stored once per distinct body, so a campaign to forty thousand people
             * is one file that forty thousand rows point at. Summing `bodybytes` over the rows
             * counts that file forty thousand times: on this project's first archive run it
             * reported 3.1 MB for 792 KB of disk, which is a report that overstates the cost by
             * four times and hides the entire reason to store them this way.
             */
            $distinct = $db->query(
                'SELECT COUNT(*) AS files, SUM(bytes) AS bytes FROM ('
                . 'SELECT DISTINCT bodypath, bodybytes AS bytes FROM ' . $db->prefix . 'mails '
                . 'WHERE bodypath <> \'\') AS distinct_bodies'
            );

            $row = (array) ($distinct?->fetch() ?? []);
            $stats['archive_files'] = (int) ($row['files'] ?? 0);
            $stats['archive_bytes'] = (int) ($row['bytes'] ?? 0);
        } catch (\Throwable) {
            // The columns arrive with a migration; an installation that has not run it yet has
            // nothing archived, which is the honest answer rather than an error.
            $stats['archived']       = 0;
            $stats['archived_bytes'] = 0;
            $stats['archive_files']  = 0;
            $stats['archive_bytes']  = 0;
        }

        if ($stripAfter > 0) {
            $stats['would_strip'] = self::count('content <> \'\' AND date > 0 AND date < ' . (time() - $stripAfter));
        }

        if ($deleteAfter > 0) {
            $stats['would_delete'] = self::count('date > 0 AND date < ' . (time() - $deleteAfter));
        }

        return $stats;
    }

    /**
     * Move stored bodies out of the database and into the archive, keeping every one of them.
     *
     * The alternative to stripping, and the better one: the body is compressed onto disk and the
     * row keeps a path to it, so `/admin/emails/show` answers exactly what it answered before.
     * A typical HTML mail gzips to about a tenth of its size, and identical bodies — a campaign
     * to forty thousand people — are stored once.
     *
     * @param  int $olderThan Seconds; 0 archives everything with a body
     * @param  int $limit     How many rows this pass moves
     * @return array{moved: int, freed: int, failed: int}
     */
    public static function archive(int $olderThan = 0, int $limit = self::BATCH): array
    {
        $moved = $freed = $failed = 0;

        if (!BodyStore::enabled()) {
            return ['moved' => 0, 'freed' => 0, 'failed' => 0];
        }

        try {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $cutoff = $olderThan > 0 ? ' AND date < ' . (time() - $olderThan) : '';
            $result = $db->query(
                'SELECT id, content, date FROM ' . $db->prefix . 'mails '
                . 'WHERE content <> \'\' AND LENGTH(content) >= ' . BodyStore::MIN_BYTES
                . ' AND date > 0' . $cutoff . ' ORDER BY id ASC LIMIT ' . max(1, $limit)
            );
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Could not read mail bodies to archive: '
                . $exception->getMessage(), 'email');

            return ['moved' => 0, 'freed' => 0, 'failed' => 0];
        }

        while ($result && $result->fetch()) {
            $id      = (int) ($result->fields['id'] ?? 0);
            $content = (string) ($result->fields['content'] ?? '');
            $path    = BodyStore::put($content, (int) ($result->fields['date'] ?? 0));

            if ($id < 1 || $path === null) {
                $failed++;

                continue;
            }

            /*
             * The row is updated only after the file is on disk.
             *
             * The other order — clear the column, then write the file — loses the body if
             * anything goes wrong in between, and what it loses is the message somebody will
             * later ask about.
             */
            try {
                $db->queryBuilder()->table('#PREFIX#mails')->where('id', $id)->update([
                    'content'   => '',
                    'bodypath'  => $path,
                    'bodybytes' => BodyStore::bytes($path),
                ]);

                $moved++;
                $freed += strlen($content);
            } catch (\Throwable $exception) {
                \Pramnos\Logs\Logger::log('Could not point mail ' . $id . ' at its archived body: '
                    . $exception->getMessage(), 'email');
                $failed++;
            }
        }

        return ['moved' => $moved, 'freed' => $freed, 'failed' => $failed];
    }

    /**
     * How many rows still have their body in the database.
     */
    public static function archivable(int $olderThan = 0): int
    {
        $cutoff = $olderThan > 0 ? ' AND date < ' . (time() - $olderThan) : '';

        return self::count(
            'content <> \'\' AND LENGTH(content) >= ' . BodyStore::MIN_BYTES
            . ' AND date > 0' . $cutoff
        );
    }

    /**
     * Empty the body of messages older than this, keeping the row.
     *
     * @param  int $olderThan Seconds
     * @return int How many rows were stripped
     */
    public static function strip(int $olderThan): int
    {
        if ($olderThan <= 0) {
            return 0;
        }

        /*
         * `date > 0` is not redundant.
         *
         * A row whose timestamp was never set has `date = 0`, which is older than every cutoff
         * — so without this the first run of a policy quietly strips the bodies of every
         * malformed row in the table, including ones written this morning.
         */
        return self::run(
            'UPDATE ' . self::prefix() . 'mails SET content = \'\' '
            . 'WHERE content <> \'\' AND date > 0 AND date < ' . (time() - $olderThan)
        );
    }

    /**
     * Remove messages older than this entirely.
     *
     * @param  int $olderThan Seconds
     * @return int How many rows were removed
     */
    public static function prune(int $olderThan): int
    {
        if ($olderThan <= 0) {
            return 0;
        }

        return self::run(
            'DELETE FROM ' . self::prefix() . 'mails WHERE date > 0 AND date < ' . (time() - $olderThan)
        );
    }

    /**
     * Remove delivered mass-message recipient rows for campaigns older than this.
     *
     * The other table that grows without limit, and for a different reason: one row per
     * recipient per campaign. A send to forty thousand people is forty thousand rows whose only
     * remaining purpose, once the campaign is finished, is the count on its own page — and the
     * count is on the campaign row.
     *
     * Only **finished** campaigns, and only their recipients: the campaign itself is the record
     * of what was sent, and it is one row.
     *
     * @param  int $olderThan Seconds
     * @return int How many rows were removed
     */
    public static function pruneRecipients(int $olderThan): int
    {
        if ($olderThan <= 0) {
            return 0;
        }

        $prefix = self::prefix();
        $cutoff = time() - $olderThan;

        return self::run(
            'DELETE FROM ' . $prefix . 'massmessagerecipients WHERE messageid IN ('
            . 'SELECT messageid FROM ' . $prefix . 'massmessages '
            . 'WHERE status = ' . (int) \Pramnos\Messaging\MassMessage::STATUS_SENT
            . ' AND created > 0 AND created < ' . $cutoff . ')'
        );
    }

    /**
     * Run a statement in batches until it stops matching.
     *
     * A neglected table is millions of rows, and one statement over all of them holds a lock
     * long enough to time out every request behind it — the maintenance being the outage.
     */
    protected static function run(string $sql): int
    {
        $db      = Factory::getDatabase();
        $touched = 0;

        try {
            /*
             * PostgreSQL has no `LIMIT` on UPDATE or DELETE.
             *
             * Branched on the driver rather than attempted and caught, because "run it and see
             * whether it was a syntax error" makes a real failure — a lock timeout, a
             * connection drop — indistinguishable from a dialect difference, and quietly
             * escalates it to the unbatched statement it was trying to avoid.
             *
             * On PostgreSQL that means one statement over the whole range and a longer lock.
             * The `WHERE ctid IN (SELECT … LIMIT n)` form would batch there too, at the cost of
             * a second dialect of every statement here — and the one that is wrong is always
             * the one nobody runs.
             */
            if (static::batches()) {
                for ($pass = 0; $pass < 1000; $pass++) {
                    // The count comes off the `Result`, not off the connection: `Database` has
                    // no `getAffectedRows()`, and reading one from it returns nothing at all —
                    // so every pass reported zero and the whole policy looked like a no-op that
                    // had run successfully.
                    $result   = $db->query($sql . ' LIMIT ' . self::BATCH);
                    $affected = $result ? (int) $result->getAffectedRows() : 0;
                    $touched += $affected;

                    if ($affected < self::BATCH) {
                        break;
                    }
                }

                return $touched;
            }

            $result = $db->query($sql);

            return $result ? (int) $result->getAffectedRows() : 0;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Mail retention failed: ' . $exception->getMessage(),
                'email'
            );

            return 0;
        }
    }

    /** Can this engine put a `LIMIT` on an UPDATE or a DELETE? */
    protected static function batches(): bool
    {
        return (string) (Factory::getDatabase()->type ?? '') !== 'postgresql';
    }

    protected static function count(string $where): int
    {
        try {
            $db     = Factory::getDatabase();
            $result = $db->query(
                'SELECT COUNT(*) AS total FROM ' . $db->prefix . 'mails WHERE ' . $where
            );

            return (int) (((array) ($result?->fetch() ?? []))['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    protected static function prefix(): string
    {
        return (string) (Factory::getDatabase()->prefix ?? '');
    }
}
