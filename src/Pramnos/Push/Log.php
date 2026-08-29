<?php

declare(strict_types=1);

namespace Pramnos\Push;

/**
 * What was pushed, to whom, and what the push service said.
 *
 * Email has had `mails` since the beginning and it is the table that answers *why did they not
 * get it*. Push had nothing of the kind: `pushsubscriptions` records when a browser was last
 * reached and how many failures it has since — a fact about the **browser**, not about a
 * message — and `massmessagerecipients` covers one send path out of two. Everything a
 * `notify()` sent left no trace at all, so the only way to find out whether a notification had
 * gone out was to ask the person it was for.
 *
 * ### It records the refusals too
 *
 * The interesting rows are the ones where nothing was sent. No VAPID pair, no encryption
 * library, no subscribed browser, a payload with no title — each one is silent by design
 * elsewhere, and each one is exactly what somebody is looking for when they ask why a
 * notification never arrived. {@see refused()} writes one row with no endpoint and the reason.
 *
 * ### The endpoint is not stored
 *
 * Whoever holds it can push to that browser, so it is a credential, and a log is the last place
 * to copy one to. The hash is enough to join a row to its subscription and to recognise the
 * same browser twice, which is all this is asked.
 *
 * ### Writing here never breaks a send
 *
 * Every method swallows its own failure. A notification that was delivered and not logged is a
 * gap in an audit trail; a notification not delivered because the audit trail could not be
 * written is a broken feature.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Log
{
    /** The reasons a send stops before it reaches a subscription. */
    public const NO_SUBSCRIPTION = 'No browser on this account is subscribed';

    public const NO_KEYS = 'This installation has no VAPID key pair — run `push:vapid-generate`';

    public const NO_LIBRARY = 'The payload encryption library is not installed';

    public const NO_PAYLOAD = 'The notification produced no title, so nothing could be shown';

    /**
     * One delivery attempt against one subscription.
     *
     * @param  array<string, mixed> $payload The decoded push payload
     * @return bool false when the row could not be written
     */
    public static function record(
        int $userId,
        string $endpointHash,
        array $payload,
        int $status,
        string $notification = ''
    ): bool {
        return static::write([
            'userid'        => $userId,
            'endpoint_hash' => $endpointHash,
            'notification'  => static::shorten($notification, 160),
            'title'         => static::shorten((string) ($payload['title'] ?? ''), 200),
            'body'          => static::shorten((string) ($payload['body'] ?? ''), 500),
            'url'           => static::shorten((string) ($payload['url'] ?? ''), 500),
            'tag'           => static::shorten((string) ($payload['tag'] ?? ''), 80),
            'status'        => $status,
            'error'         => '',
        ]);
    }

    /**
     * A send that stopped before any subscription was reached.
     *
     * The row somebody is actually looking for. Without it, an installation with no key pair
     * and an installation whose notifications are all arriving look identical from the outside:
     * nothing in any table, and one line in a log file that nobody reads until they already
     * suspect the answer.
     *
     * @param array<string, mixed> $payload
     */
    public static function refused(
        int $userId,
        string $reason,
        array $payload = [],
        string $notification = ''
    ): bool {
        return static::write([
            'userid'        => $userId,
            'endpoint_hash' => '',
            'notification'  => static::shorten($notification, 160),
            'title'         => static::shorten((string) ($payload['title'] ?? ''), 200),
            'body'          => static::shorten((string) ($payload['body'] ?? ''), 500),
            'url'           => '',
            'tag'           => '',
            'status'        => 0,
            'error'         => static::shorten($reason, 255),
        ]);
    }

    /**
     * The most recent attempts, newest first.
     *
     * @param  array{userid?: int, status?: int, failed?: bool} $filter
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 100, array $filter = []): array
    {
        try {
            $query = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#pushlog')
                ->orderBy('sent', 'desc')
                ->orderBy('pushid', 'desc')
                ->limit(max(1, $limit));

            if (isset($filter['userid'])) {
                $query->where('userid', (int) $filter['userid']);
            }

            if (isset($filter['status'])) {
                $query->where('status', (int) $filter['status']);
            }

            if (!empty($filter['failed'])) {
                /*
                 * "Failed" is not one status.
                 *
                 * A 410 is a dead subscription, a 429 is a busy service and a 0 never reached
                 * one — three different problems with three different answers, and an operator
                 * looking for "what went wrong" wants all of them. Only a 2xx is delivery.
                 */
                $query->where('status', '<', 200, 'and')
                    ->orWhere('status', '>=', 300);
            }

            $rows   = [];
            $result = $query->get();

            while (($row = $result->fetch()) !== null) {
                $rows[] = $row;
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * How the last `$days` of pushes went.
     *
     * @return array{total: int, delivered: int, gone: int, refused: int, failed: int}
     */
    public static function stats(int $days = 7): array
    {
        $empty = ['total' => 0, 'delivered' => 0, 'gone' => 0, 'refused' => 0, 'failed' => 0];

        try {
            $since  = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#pushlog')
                ->select(['status', 'endpoint_hash'])
                ->where('sent', '>=', $since)
                ->get();

            $stats = $empty;

            while (($row = $result->fetch()) !== null) {
                $status = (int) ($row['status'] ?? 0);
                $stats['total']++;

                if ($status >= 200 && $status < 300) {
                    $stats['delivered']++;
                } elseif ($status === 404 || $status === 410) {
                    $stats['gone']++;
                } elseif ((string) ($row['endpoint_hash'] ?? '') === '') {
                    $stats['refused']++;
                } else {
                    $stats['failed']++;
                }
            }

            return $stats;
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Drop rows older than `$days`.
     *
     * **For an installation without TimescaleDB.** With it, the retention policy declared in the
     * migration does this — and does it by dropping whole chunks, which is why a hypertable is
     * the right shape here: a `DELETE` over a large append-only table rewrites index pages and
     * leaves bloat only a `VACUUM FULL` reclaims.
     *
     * Ninety days by default either way. A notification is cheap to send so applications send
     * many, and nobody needs to know which one a browser acknowledged last spring.
     *
     * @return int rows removed, or 0 when nothing could be read
     */
    public static function prune(int $days = 90): int
    {
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#pushlog')
                ->where('sent', '<', date('Y-m-d H:i:s', time() - max(1, $days) * 86400))
                ->delete();

            return $result instanceof \Pramnos\Database\Result
                ? (int) $result->getAffectedRows()
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * A row's `sent` as a unix timestamp, whatever the driver handed back.
     *
     * PostgreSQL returns `2026-08-29 14:49:32.517335+00`, MySQL returns `2026-08-29 14:49:32`,
     * and a screen wants neither. One reader, so a view never parses a date itself — which is
     * how `d/m/Y` ended up being sorted as a string elsewhere in this framework.
     */
    public static function sentAt(array $row): int
    {
        $value = (string) ($row['sent'] ?? '');

        if ($value === '') {
            return 0;
        }

        return is_numeric($value) ? (int) $value : (int) strtotime($value);
    }

    /** @param array<string, mixed> $row */
    private static function write(array $row): bool
    {
        try {
            // A timestamp string: `sent` is a `timestamptz` — it is the hypertable's partition
            // column, and the compression and retention policies take interval strings that only
            // mean anything against one.
            $row['sent'] = date('Y-m-d H:i:s');

            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#pushlog')
                ->insert($row);

            return true;
        } catch (\Throwable $exception) {
            /*
             * Logged, never thrown.
             *
             * A notification that was delivered and not recorded is a gap in an audit trail. A
             * notification not delivered because the audit trail could not be written is a
             * broken feature, and this is called from inside a send.
             */
            \Pramnos\Logs\Logger::log(
                'Could not record a push in the push log: ' . $exception->getMessage(),
                'push'
            );

            return false;
        }
    }

    private static function shorten(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
