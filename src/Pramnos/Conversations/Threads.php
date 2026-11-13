<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * A person's own read point in one thread (FW-498) — `chat.thread_reads`, state, not history
 * (rule 14): one row per thread and person, replaced as they read further.
 *
 * A conversation's `lastreadid` moves with its top-level messages, never with a reply: a reply
 * in a thread open and in front still counted as unread activity, or — the opposite fault — a
 * reply older than the newest top-level message read as "read" without the thread ever being
 * opened. This is the thread's own read point, the same idea {@see Conversations::markRead()}
 * already is for the conversation itself.
 *
 * ```php
 * Threads::markRead($root, $me, $replyId);  // never back on lastReadId; readAt always moves
 * Threads::read($root, $me);                // ['lastReadId' => …, 'readAt' => …] or null: never opened
 * ```
 */
final class Threads
{
    /** The table. */
    public const TABLE = 'chat.thread_reads';

    /**
     * Somebody has read a thread up to a reply: `lastReadId` never moves back, by the same order
     * {@see Conversations::markRead()} uses; `readAt` always moves, even when it does not — a
     * reply that arrived while they were already caught up on the thread otherwise has nothing
     * that ever marks it read.
     */
    public static function markRead(int $root, int $person, int $messageId): void
    {
        $message = Messages::get($root);
        if ($message === null) {
            return;
        }
        $existing   = self::read($root, $person);
        $lastReadId = $existing === null || Messages::isAfter($messageId, $existing['lastReadId']) ? $messageId : $existing['lastReadId'];

        Conversations::db()->queryBuilder()->table(self::TABLE)->upsert(
            ['conversationid' => $message['conversationId'], 'rootid' => $root, 'userid' => $person, 'lastreadid' => $lastReadId, 'readat' => time()],
            ['rootid', 'userid'],
            ['lastreadid', 'readat']
        );
    }

    /** One person's read point in one thread, or null: they have never read it. */
    public static function read(int $root, int $person): ?array
    {
        $row = Conversations::db()->queryBuilder()->table(self::TABLE)->where('rootid', $root)->where('userid', $person)
            ->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /**
     * One person's read point in each of these threads, keyed by root — only the ones they have
     * read at all. For a caller checking several threads at once ({@see Inbox}), one query
     * rather than one per thread.
     *
     * @param list<int> $roots
     * @return array<int, array{lastReadId: int, readAt: int}>
     */
    public static function readMany(array $roots, int $person): array
    {
        if ($roots === []) {
            return [];
        }
        $out = [];
        foreach (Conversations::db()->queryBuilder()->table(self::TABLE)->whereIn('rootid', $roots)->where('userid', $person)->getAllOrFail() as $row) {
            $out[(int) $row['rootid']] = self::row($row);
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array{lastReadId: int, readAt: int} */
    private static function row(array $row): array
    {
        return ['lastReadId' => (int) $row['lastreadid'], 'readAt' => (int) $row['readat']];
    }
}
