<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * Where a person is: the client — a browser tab, the desktop application — they last did
 * something in, so a message's notification goes there alone, as Slack, Teams and Discord do,
 * and to every client once they have been away longer than they chose.
 *
 * ```php
 * Clients::active($userId, $clientId);                        // "I did something" — from each client, now and then
 * Clients::active($userId, $clientId, idle: 240, front: false); // the desktop app: the system idle 4 min, window hidden
 * Clients::target($userId);                                    // the client to notify, or null: every client
 * Clients::awayAfter($userId, 10);                             // their choice: 0 (at once), 5, 10 or 30 minutes
 * ```
 *
 * A client is a random id a tab or an application keeps for itself (`window.pfClientId` in
 * pf-utils.js). A push subscription made in that tab carries it ({@see \Pramnos\Push\Subscriptions::tagClient()}),
 * so a push goes to that subscription alone; the realtime telling of a message carries `notify`,
 * the id or `all`, and each client shows a notification only when it is the one. A client with no
 * id — an older version, a cached page — is told as before, so nobody loses a notification.
 *
 * Away is: nothing reported for longer than the person's minutes, or the system reported idle
 * (or locked) longer than that. With "at once" (0 minutes) a client that is not in front — a
 * hidden tab, a minimised application — counts as away. The bell is told of every message, always.
 *
 * The desktop application — the client that reports the system's idle seconds — is kept apart
 * too: while it runs, and its person is not away, it is the client told, unless a tab is in front,
 * and no web push goes to the browser beside it ({@see appRunning()}), as Slack's does.
 *
 * State, not history (rule 14): one row per person, `pramnos.conversation_clients`, replaced at
 * each report.
 */
final class Clients
{
    public const TABLE = 'pramnos.conversation_clients';

    /** The minutes away before every client is told, when the person chose none. */
    public const AWAY_AFTER = 5;

    /** The choices of minutes. */
    public const CHOICES = [0, 5, 10, 30];

    /**
     * A client of the person did something.
     *
     * @param int|null $idle  The system's idle seconds, where the client can tell (the desktop application)
     * @param bool     $front Whether the client is in front — a visible tab, a window not minimised
     * @param bool     $locked Whether the screen is locked
     */
    public static function active(int $userId, string $clientId, ?int $idle = null, bool $front = true, bool $locked = false, ?int $now = null): void
    {
        $clientId = self::clean($clientId);
        if ($userId < 1 || $clientId === '') {
            return;
        }
        $now     = $now ?? time();
        $fromApp = $idle !== null;
        $idle    = $locked ? 31_536_000 : max(0, (int) $idle);
        $row  = ['client' => $clientId, 'seenat' => $now, 'idle' => $idle, 'front' => $front ? 1 : 0];
        $db   = Conversations::db();
        $was  = $db->queryBuilder()->table(self::TABLE)->where('userid', $userId)->first()?->fetch();
        // Only the desktop application tells the system's idle seconds: its report is kept apart, and
        // its minute's "still here" does not take the place of a tab in front. Any report of the
        // application's own id is its own too — the screen locked, say.
        if (($fromApp || (is_array($was) && (string) ($was['app'] ?? '') === $clientId)) && self::tracksApp()) {
            $tab = is_array($was) && (string) $was['client'] !== '' && (string) $was['client'] !== $clientId
                && (int) $was['front'] === 1 && $now - (int) $was['seenat'] <= 60;
            $row = ($tab ? [] : $row) + ['app' => $clientId, 'appseenat' => $now, 'appidle' => $idle];
        }
        if (is_array($was)) {
            $db->queryBuilder()->table(self::TABLE)->where('userid', $userId)->update($row);

            return;
        }
        $db->queryBuilder()->table(self::TABLE)->insert(['userid' => $userId] + $row);
    }

    /**
     * Whether the person's desktop application is running and they are at it: it reported within
     * three minutes (it does every minute), the screen is not locked, and the system has not been
     * idle longer than their minutes away. Then it notifies them, and no web push is sent.
     */
    public static function appRunning(int $userId, ?int $now = null): bool
    {
        try {
            $row = Conversations::db()->queryBuilder()->table(self::TABLE)->where('userid', $userId)->first()?->fetch();
        } catch (\Throwable) {
            return false;
        }

        return is_array($row) && self::appOn($row, $now ?? time());
    }

    /** {@see appRunning()} of a row. @param array<string, mixed> $row */
    private static function appOn(array $row, int $now): bool
    {
        $since = $now - (int) ($row['appseenat'] ?? 0);
        if ((string) ($row['app'] ?? '') === '' || $since > 180) {
            return false;
        }
        $minutes = $row['awayafter'] !== null ? (int) $row['awayafter'] : self::AWAY_AFTER;

        // Idle as it said, and for as long since: the same away as any client's.
        return (int) ($row['appidle'] ?? 0) + $since < max(60, 60 * $minutes);
    }

    /** Whether the application's columns are there: before their migration, nothing is kept apart. */
    private static function tracksApp(): bool
    {
        try {
            return Conversations::db()->schema()->hasColumn(self::TABLE, 'app');
        } catch (\Throwable) {
            return false;
        }
    }

    /** The person's choice of minutes away before every client is told; with `$minutes`, set it. */
    public static function awayAfter(int $userId, ?int $minutes = null): int
    {
        $db = Conversations::db();
        if ($minutes !== null) {
            if (!in_array($minutes, self::CHOICES, true)) {
                throw new \InvalidArgumentException(sprintf(t('Choose one of: %s minutes.'), implode(', ', self::CHOICES)));
            }
            if ($db->queryBuilder()->table(self::TABLE)->where('userid', $userId)->count() > 0) {
                $db->queryBuilder()->table(self::TABLE)->where('userid', $userId)->update(['awayafter' => $minutes]);
            } else {
                $db->queryBuilder()->table(self::TABLE)->insert(['userid' => $userId, 'client' => '', 'seenat' => 0, 'idle' => 0, 'front' => 0, 'awayafter' => $minutes]);
            }

            return $minutes;
        }
        $row = $db->queryBuilder()->table(self::TABLE)->where('userid', $userId)->first()?->fetch();

        return is_array($row) && $row['awayafter'] !== null ? (int) $row['awayafter'] : self::AWAY_AFTER;
    }

    /** The client to notify of a message, or null for every client. */
    public static function target(int $userId, ?int $now = null): ?string
    {
        return self::targets([$userId], $now)[$userId];
    }

    /**
     * {@see target()} for several people at once.
     *
     * @param list<int> $userIds
     * @return array<int, string|null>
     */
    public static function targets(array $userIds, ?int $now = null): array
    {
        $now = $now ?? time();
        $out = array_fill_keys($userIds, null);
        if ($userIds === []) {
            return $out;
        }
        try {
            $rows = Conversations::db()->queryBuilder()->table(self::TABLE)->whereIn('userid', array_values(array_unique($userIds)))->get();
        } catch (\Throwable) {
            // Before its migration has run: everybody told everywhere, as before.
            return $out;
        }
        while ($rows && ($row = $rows->fetch()) !== null && $row !== false) {
            $out[(int) $row['userid']] = self::choose($row, $now);
        }

        return $out;
    }

    /**
     * Whether the person's client is in front now — a visible tab or a window not minimised, seen
     * within a minute and not idle: it tells of a message itself, from the realtime connection,
     * so no push is sent to it.
     */
    public static function inFront(int $userId, ?int $now = null): bool
    {
        $now = $now ?? time();
        try {
            $row = Conversations::db()->queryBuilder()->table(self::TABLE)->where('userid', $userId)->first()?->fetch();
        } catch (\Throwable) {
            // Before its migration has run: nobody is known to be in front.
            return false;
        }

        return is_array($row) && (string) $row['client'] !== '' && (int) $row['front'] === 1
            && $now - (int) $row['seenat'] <= 60 && (int) $row['idle'] < 60;
    }

    /** One person's row decided: their client, or null when they are away. @param array<string, mixed> $row */
    private static function choose(array $row, int $now): ?string
    {
        $quiet = $now - (int) $row['seenat'];
        // The application, while it runs and its person is at it — unless a tab is in front now.
        if (self::appOn($row, $now) && !((string) $row['client'] !== (string) $row['app']
            && (string) $row['client'] !== '' && (int) $row['front'] === 1 && $quiet <= 60 && (int) $row['idle'] < 60)) {
            return (string) $row['app'];
        }
        if ((string) $row['client'] === '') {
            return null;
        }
        $limit = 60 * ($row['awayafter'] !== null ? (int) $row['awayafter'] : self::AWAY_AFTER);
        if ($limit === 0) {
            // "At once": the client must be in front, and have been seen within a minute.
            return (int) $row['front'] === 1 && $quiet <= 60 && (int) $row['idle'] < 60 ? (string) $row['client'] : null;
        }
        if ($quiet > $limit || (int) $row['idle'] + $quiet > $limit) {
            return null;
        }

        return (string) $row['client'];
    }

    /**
     * Whether the person reported activity on any client — a tab, the desktop application — at
     * or after `$since`: unlocks the hold-unopened rule (FW-495) without the person having
     * touched the notification at all, the way doing anything in the app would mark them "not
     * away" for any other purpose.
     */
    public static function activeSince(int $userId, int $since): bool
    {
        try {
            $row = Conversations::db()->queryBuilder()->table(self::TABLE)->where('userid', $userId)->first()?->fetch();
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($row)) {
            return false;
        }

        return max((int) ($row['seenat'] ?? 0), (int) ($row['appseenat'] ?? 0)) >= $since;
    }

    /** An id as a client may give it: letters, digits, `-` and `_`, up to 64. */
    public static function clean(string $clientId): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clientId) === 1 ? $clientId : '';
    }
}
