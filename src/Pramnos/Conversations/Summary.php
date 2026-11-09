<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Database\Instant;

/**
 * A conversation summed up by the analyst for one reader: what they have not read, or the last
 * hours, with the messages it rests on.
 *
 * ```php
 * Summary::of($conversationId, $reader, 'unread', 'el');
 * Summary::of($conversationId, $reader, ['hours' => 24]);
 * // ['summary' => '…markdown…', 'messages' => [ids it cites], 'from' => unix, 'to' => unix, 'count' => n, 'cut' => bool]
 * ```
 *
 * Only what the reader may read: the conversation must be one they read
 * ({@see Conversations::canRead()}), deleted messages are left out, replies in threads are in.
 * At most {@see MAX_MESSAGES} messages and {@see MAX_CHARACTERS} characters go to the model —
 * the newest — and `cut` says when older ones were left out. Nothing to sum up is answered
 * without a call. The call is logged and costed to the reader, as every call of the model is;
 * the summary is kept nowhere else.
 */
final class Summary
{
    /** The most messages read. */
    public const MAX_MESSAGES = 400;

    /** The most characters of messages read. */
    public const MAX_CHARACTERS = 60000;

    /** @var (\Closure(): \Pramnos\Llm\Client)|null A test's own client */
    private static ?\Closure $client = null;

    /** Use another client — a test's; null restores the analyst's. */
    public static function useClient(?\Closure $client): void
    {
        self::$client = $client;
    }

    /**
     * The summary.
     *
     * @param string|array{hours?: int, since?: int} $since `unread`, `['hours' => n]` or `['since' => unix]`
     * @param string $language The reader's language (`el`, `en`); the messages' own when empty
     * @return array{summary: string, messages: list<int>, from: ?int, to: ?int, count: int, cut: bool}
     * @throws \DomainException          A conversation the reader does not read
     * @throws \InvalidArgumentException `since` is none of these, or the model gave no answer
     */
    public static function of(int $conversationId, object $reader, string|array $since = 'unread', string $language = ''): array
    {
        if (!Conversations::canRead($conversationId, $reader)) {
            throw new \DomainException(t('That conversation no longer exists.'));
        }
        [$messages, $cut] = self::read($conversationId, $reader, $since);
        $empty = ['summary' => '', 'messages' => [], 'from' => null, 'to' => null, 'count' => 0, 'cut' => false];
        if ($messages === []) {
            return $empty;
        }
        $names = People::names(array_merge(array_column($messages, 'authorid'), self::mentioned($messages)));
        $lines = [];
        foreach ($messages as $m) {
            $who     = $m['authorid'] === null ? 'system' : ($names[(int) $m['authorid']] ?? '#' . $m['authorid']);
            $thread  = $m['threadrootid'] !== null ? ' (reply to #' . $m['threadrootid'] . ')' : '';
            $lines[] = '[#' . $m['messageid'] . '] ' . gmdate('Y-m-d H:i', (int) $m['at_unix']) . ' ' . $who . $thread . ': '
                . Messages::plain((string) $m['body'], $names, 4000);
        }
        $client = self::$client !== null ? (self::$client)() : new \Pramnos\Llm\Client(
            new \Pramnos\Llm\Connection(null, isset(\Pramnos\Llm\Connection::features()['analyst']) ? 'analyst' : null));
        $answer = $client->about(['purpose' => 'conversation_summary', 'userid' => (int) $reader->userid])->askCheaply(
            'You sum up a team conversation for one of its members. Markdown, short: the decisions, the questions still open, '
            . 'what is asked of people, by name. Cite the messages each point rests on as [#id], with the ids given. '
            . 'Do not invent anything that is not in the messages. '
            . ($language !== '' ? 'Write in the language with code "' . $language . '".' : 'Write in the language most messages are in.')
            . ($cut ? ' Older messages were left out; say so in one line at the start.' : ''),
            [['role' => 'user', 'content' => implode("\n", $lines)]],
        );
        if (!($answer['ok'] ?? false)) {
            throw new \InvalidArgumentException((string) ($answer['says'] ?? t('The assistant could not answer.')));
        }
        $summary = trim((string) $answer['reply']);
        preg_match_all('/#(\d+)/', $summary, $found);
        $ids = array_column($messages, 'messageid');

        return ['summary' => $summary, 'messages' => array_values(array_unique(array_map('intval', array_intersect($found[1], $ids)))),
            'from' => (int) $messages[0]['at_unix'], 'to' => (int) end($messages)['at_unix'], 'count' => count($messages), 'cut' => $cut];
    }

    /**
     * The messages to sum up, oldest first, and whether older ones were left out.
     *
     * @param string|array{hours?: int, since?: int} $since
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private static function read(int $conversationId, object $reader, string|array $since): array
    {
        $db    = Conversations::db();
        $query = $db->queryBuilder()->table('chat.conversation_messages')
            ->select(['messageid', 'authorid', 'body', 'threadrootid', Instant::unix($db, 'created_at', 'at_unix')])
            ->where('conversationid', $conversationId)->whereNull('deletedat');
        if ($since === 'unread') {
            $member = Conversations::member($conversationId, (int) $reader->userid);
            if ($member === null) {
                // Somebody who reads it without being in it has read nothing of it: its last day.
                $query->where('created_at', '>=', Instant::at($db, time() - 86400));
            } else {
                Messages::beyond($query, $member['lastReadId']);
                $query->where(static fn ($q) => $q->whereNull('authorid')->orWhere('authorid', '<>', (int) $reader->userid));
            }
        } elseif (is_array($since) && (isset($since['hours']) || isset($since['since']))) {
            $from = isset($since['since']) ? (int) $since['since'] : time() - 3600 * max(1, min(24 * 31, (int) $since['hours']));
            $query->where('created_at', '>=', Instant::at($db, $from));
        } else {
            throw new \InvalidArgumentException(t('Sum up what is unread, or the last hours.'));
        }
        $rows = Messages::inOrder($query, 'desc')->limit(self::MAX_MESSAGES + 1)->getAllOrFail();
        $cut  = count($rows) > self::MAX_MESSAGES;
        $kept = [];
        $size = 0;
        foreach (array_slice($rows, 0, self::MAX_MESSAGES) as $row) {
            $size += mb_strlen((string) $row['body']);
            if ($size > self::MAX_CHARACTERS && $kept !== []) {
                $cut = true;
                break;
            }
            $kept[] = $row;
        }

        return [array_reverse($kept), $cut];
    }

    /** @param list<array<string, mixed>> $messages @return list<int> The people the messages mention */
    private static function mentioned(array $messages): array
    {
        preg_match_all('/<@(\d+)>/', implode(' ', array_column($messages, 'body')), $m);

        return array_map('intval', $m[1]);
    }
}
