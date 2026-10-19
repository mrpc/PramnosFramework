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
     * `body_bytes` counts the bodies kept in the row; one kept in the body store is counted in
     * `with_body` and not in bytes.
     *
     * @return array<string, mixed>
     */
    public static function stats(int $stripAfter = 0, int $deleteAfter = 0): array
    {
        try {
            $db     = Factory::getDatabase();
            // Raw: conditional aggregates the query builder cannot express.
            $result = $db->query(
                'SELECT COUNT(*) AS rows_total,
                        COUNT(CASE WHEN content <> \'\' OR path <> \'\' THEN 1 END) AS with_body,
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

        if ($stripAfter > 0) {
            $stats['would_strip'] = self::count(self::older($stripAfter, true));
        }

        if ($deleteAfter > 0) {
            $stats['would_delete'] = self::count(self::older($deleteAfter, false));
        }

        return $stats;
    }

    /**
     * Empty the body of messages older than this, keeping the row — the body in the row, and
     * the one in the body store, its file or its `mail_bodies` row.
     *
     * @param  int $olderThan Seconds
     * @return int How many rows were stripped
     */
    public static function strip(int $olderThan): int
    {
        if ($olderThan <= 0) {
            return 0;
        }

        $columns = ['content' => '', 'path' => ''];
        if (BodyStore::hasBodyVars()) {
            $columns['bodyvars'] = null;
        }

        return self::sweep(self::older($olderThan, true), static fn (\Pramnos\Database\QueryBuilder $rows) => $rows->update($columns));
    }

    /**
     * Remove messages older than this entirely, and their bodies with them.
     *
     * @param  int $olderThan Seconds
     * @return int How many rows were removed
     */
    public static function prune(int $olderThan): int
    {
        if ($olderThan <= 0) {
            return 0;
        }

        return self::sweep(self::older($olderThan, false), static fn (\Pramnos\Database\QueryBuilder $rows) => $rows->delete());
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

        $db = Factory::getDatabase();
        try {
            $campaigns = $db->queryBuilder()->table('#PREFIX#massmessages')->select(['messageid'])
                ->where('status', \Pramnos\Messaging\MassMessage::STATUS_SENT)
                ->where('created', '>', 0)->where('created', '<', time() - $olderThan)
                ->get()->fetchAll();
            $removed = 0;
            foreach (array_chunk(array_map(static fn (array $row): int => (int) $row['messageid'], $campaigns), 500) as $ids) {
                $result   = $db->queryBuilder()->table('#PREFIX#massmessagerecipients')->whereIn('messageid', $ids)->delete();
                $removed += $result ? (int) $result->getAffectedRows() : 0;
            }

            return $removed;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Mail retention failed: ' . $exception->getMessage(), 'email');

            return 0;
        }
    }

    /**
     * Apply `$apply` to the matching rows a batch at a time, their kept bodies removed first.
     *
     * A neglected table is millions of rows, and one statement over all of them holds a lock
     * long enough to time out every request behind it — the maintenance being the outage. Read
     * by id and applied by id, the same on every database.
     *
     * A failure is logged and ends the run with what was done so far: this runs from a
     * scheduled job, and a zero is a number the next run can act on where a fatal is a cron mail
     * nobody reads.
     *
     * @param callable(\Pramnos\Database\QueryBuilder): mixed $apply The write; false when refused
     */
    protected static function sweep(\Pramnos\Database\QueryBuilder $matching, callable $apply): int
    {
        $db      = Factory::getDatabase();
        $touched = 0;
        $after   = 0;

        try {
            do {
                $rows = (clone $matching)->select(['id', 'date', 'path'])->where('id', '>', $after)
                    ->orderBy('id')->limit(self::BATCH)->get()->fetchAll();
                if ($rows === []) {
                    break;
                }
                $after = (int) end($rows)['id'];
                BodyStore::discard($rows);
                if ($apply($db->queryBuilder()->table('#PREFIX#mails')->whereIn('id', array_map(static fn (array $row): int => (int) $row['id'], $rows))) === false) {
                    throw new \RuntimeException($db->getError()['message'] ?: 'the write was refused');
                }
                $touched += count($rows);
            } while (count($rows) === self::BATCH);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Mail retention failed: ' . $exception->getMessage(), 'email');
        }

        return $touched;
    }

    /**
     * The messages older than this — with a body still kept, when `$withBody`.
     *
     * `date > 0` is not redundant. A row whose timestamp was never set has `date = 0`, which is
     * older than every cutoff — so without it the first run of a policy quietly takes the rows
     * of every malformed message in the table, including ones written this morning.
     */
    protected static function older(int $seconds, bool $withBody): \Pramnos\Database\QueryBuilder
    {
        $query = Factory::getDatabase()->queryBuilder()->table('#PREFIX#mails')
            ->where('date', '>', 0)->where('date', '<', time() - $seconds);
        if ($withBody) {
            $query->where(static function ($q): void {
                $q->where('content', '<>', '')->orWhere('path', '<>', '');
            });
        }

        return $query;
    }

    /** How many rows a query matches; 0 when it cannot be asked. */
    protected static function count(\Pramnos\Database\QueryBuilder $query): int
    {
        try {
            return $query->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
