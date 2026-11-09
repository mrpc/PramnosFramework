<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * What somebody has begun writing, kept on the server so it follows them to every device, as
 * Slack's drafts.
 *
 * ```php
 * Drafts::save($me, $general, null, 'Καλημέρα, σήμερα…');  // the conversation's; an empty body lets it go
 * Drafts::save($me, $general, $root, 'Το κοιτάζω');        // a thread's
 * Drafts::get($me, $general);                             // '' when there is none
 * Drafts::mine($me);                                      // every one, newest first
 * ```
 *
 * One for each person, conversation and thread. Sending a message there lets it go
 * ({@see Messages::post()}); each change is told on the person's own channel as
 * `conversation.draft` — `{conversationId, thread, body, updatedAt}`, `body` '' when it went — so
 * their other devices show it.
 */
final class Drafts
{
    /**
     * Keep what somebody has written so far in a conversation, or a thread of it; nothing written
     * lets it go.
     *
     * @throws \InvalidArgumentException Not one of its members, or too long
     */
    public static function save(int $person, int $conversationId, ?int $thread, string $body): void
    {
        if (Conversations::member($conversationId, $person) === null) {
            throw new \InvalidArgumentException(t('Only its members write in a conversation.'));
        }
        if (mb_strlen($body) > Messages::MAX_LENGTH) {
            throw new \InvalidArgumentException(t('A message is at most %s characters.', number_format(Messages::MAX_LENGTH)));
        }
        if (trim($body) === '') {
            self::clear($person, $conversationId, $thread);

            return;
        }
        $now = time();
        self::table()->upsert(['userid' => $person, 'conversationid' => $conversationId, 'threadrootid' => (int) $thread, 'body' => $body, 'updatedat' => $now],
            ['userid', 'conversationid', 'threadrootid'], ['body', 'updatedat']);
        self::tell($person, $conversationId, $thread, $body, $now);
    }

    /**
     * What somebody has written so far in a conversation, or a thread of it — '' when nothing, or
     * when the drafts cannot be read: a conversation still opens on an installation that has not
     * run the migration.
     */
    public static function get(int $person, int $conversationId, ?int $thread = null): string
    {
        try {
            $row = self::table()->select(['body'])->where('userid', $person)->where('conversationid', $conversationId)
                ->where('threadrootid', (int) $thread)->limit(1)->getAllOrFail()[0] ?? null;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: drafts cannot be read: ' . $e->getMessage(), 'conversations');

            return '';
        }

        return $row === null ? '' : (string) $row['body'];
    }

    /**
     * Every draft somebody has, newest first.
     *
     * @return list<array{conversationId: int, thread: ?int, body: string, updatedAt: int}>
     */
    public static function mine(int $person): array
    {
        return array_map(static fn (array $r): array => ['conversationId' => (int) $r['conversationid'],
            'thread' => (int) $r['threadrootid'] === 0 ? null : (int) $r['threadrootid'], 'body' => (string) $r['body'], 'updatedAt' => (int) $r['updatedat']],
            self::table()->where('userid', $person)->orderBy('updatedat', 'desc')->getAllOrFail());
    }

    /**
     * Let a draft go — written and sent, or emptied — and tell the person's other devices. Best
     * effort: a draft that cannot be let go is no reason a message is not written.
     */
    public static function clear(int $person, int $conversationId, ?int $thread): void
    {
        try {
            $gone = self::table()->where('userid', $person)->where('conversationid', $conversationId)->where('threadrootid', (int) $thread)->delete();
            // Told only when there was one: every message sent passes through here.
            if (is_object($gone) && (int) $gone->getAffectedRows() > 0) {
                self::tell($person, $conversationId, $thread, '', time());
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: a draft of ' . $person . ' was not let go: ' . $e->getMessage(), 'conversations');
        }
    }

    /** Tell somebody's own channel a draft changed. */
    private static function tell(int $person, int $conversationId, ?int $thread, string $body, int $at): void
    {
        Realtime::draft($person, ['conversationId' => $conversationId, 'thread' => $thread, 'body' => $body, 'updatedAt' => $at]);
    }

    /** The table. */
    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return Conversations::db()->queryBuilder()->table('chat.conversation_drafts');
    }
}
