<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * Messages written to be sent later, as Slack's "Schedule message".
 *
 * ```php
 * $id = Scheduled::create($general, $me, 'Καλημέρα σε όλους', strtotime('tomorrow 09:00'));
 * Scheduled::create($general, $me, 'Το είδα', $at, ['thread' => $root]);
 * Scheduled::mine($me);                         // what one is waiting to send, and what could not be sent
 * Scheduled::change($id, $me, 'Καλημέρα!', $at); // its text, its time, or both
 * Scheduled::cancel($id, $me);
 * Scheduled::due();                             // `conversations:send-scheduled`, every minute
 * ```
 *
 * It is sent by {@see Messages::post()} as its writer, when its time comes — while they are still
 * in the conversation. One that cannot be sent stays, with why, for its writer to see; changing it
 * tries again.
 */
final class Scheduled
{
    /**
     * Keep a message to send at `$at`.
     *
     * @param array{thread?: int|null, broadcast?: bool, files?: list<int>} $options
     * @return int The scheduled message
     * @throws \InvalidArgumentException With the reason, for its writer
     */
    public static function create(int $conversationId, int $author, string $body, int $at, array $options = []): int
    {
        $body   = rtrim($body);
        $files  = array_values(array_map('intval', (array) ($options['files'] ?? [])));
        $thread = isset($options['thread']) ? (int) $options['thread'] : null;
        self::check($conversationId, $author, $body, $at, $files);
        if ($thread !== null) {
            $root = Messages::get($thread);
            if ($root === null || $root['conversationId'] !== $conversationId || $root['threadRootId'] !== null) {
                throw new \InvalidArgumentException(t('A reply answers a message of the same conversation.'));
            }
        }
        $db     = Conversations::db();
        $insert = $db->queryBuilder()->table('chat.conversation_scheduled');
        if ($db->type === 'postgresql') {
            $insert->returning('scheduledid');
        }
        $result = $insert->insert(['conversationid' => $conversationId, 'authorid' => $author, 'body' => $body, 'threadrootid' => $thread,
            'broadcast' => !empty($options['broadcast']) && $thread !== null ? 1 : 0, 'files' => $files === [] ? null : (string) json_encode($files),
            'sendat' => $at, 'createdat' => time()]);

        return $db->type === 'postgresql' ? (int) ($result->fields['scheduledid'] ?? 0) : (int) $db->getInsertId();
    }

    /**
     * What one is waiting to send — and what could not be sent, with why — soonest first.
     *
     * @return list<array{id: int, conversationId: int, authorId: int, body: string, thread: ?int, broadcast: bool, files: list<int>, at: int, createdAt: int, error: ?string}>
     */
    public static function mine(int $person): array
    {
        return array_map(self::row(...), self::table()->where('authorid', $person)->orderBy('sendat')->orderBy('scheduledid')->getAllOrFail());
    }

    /**
     * Change one's own scheduled message: its text, its time, or both. One that could not be sent
     * is tried again.
     */
    public static function change(int $id, int $person, ?string $body = null, ?int $at = null): void
    {
        $row  = self::own($id, $person);
        $body = $body === null ? $row['body'] : rtrim($body);
        $at ??= $row['at'];
        self::check($row['conversationId'], $person, $body, $at, $row['files']);
        self::table()->where('scheduledid', $id)->update(['body' => $body, 'sendat' => $at, 'error' => null]);
    }

    /** Let go of one's own scheduled message. */
    public static function cancel(int $id, int $person): void
    {
        self::own($id, $person);
        self::table()->where('scheduledid', $id)->delete();
    }

    /**
     * Send what is due, each as its writer: gone once sent; kept with why when it cannot be.
     *
     * @return int How many were sent
     */
    public static function due(?int $now = null): int
    {
        $sent = 0;
        foreach (self::table()->whereNull('error')->where('sendat', '<=', $now ?? time())->orderBy('sendat')->orderBy('scheduledid')->limit(500)->getAllOrFail() as $raw) {
            $row = self::row($raw);
            try {
                if (Conversations::member($row['conversationId'], $row['authorId']) === null) {
                    throw new \InvalidArgumentException(t('You are no longer in that conversation.'));
                }
                Messages::post($row['conversationId'], $row['authorId'], $row['body'], array_filter(['thread' => $row['thread'],
                    'broadcast' => $row['broadcast'] ?: null, 'files' => $row['files']]));
                self::table()->where('scheduledid', $row['id'])->delete();
                $sent++;
            } catch (\Throwable $e) {
                self::table()->where('scheduledid', $row['id'])->update(['error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : t('It could not be sent.')]);
                if (!$e instanceof \InvalidArgumentException) {
                    \Pramnos\Logs\Logger::log('Conversations: scheduled message ' . $row['id'] . ' was not sent: ' . $e->getMessage(), 'queue');
                }
            }
        }

        return $sent;
    }

    /** What a message to be sent later has to be: in a conversation one writes in, something in it, in the future. */
    private static function check(int $conversationId, int $author, string $body, int $at, array $files): void
    {
        if ($body === '' && $files === []) {
            throw new \InvalidArgumentException(t('A message needs something in it.'));
        }
        if (mb_strlen($body) > Messages::MAX_LENGTH) {
            throw new \InvalidArgumentException(t('A message is at most %s characters.', number_format(Messages::MAX_LENGTH)));
        }
        if ($at <= time()) {
            throw new \InvalidArgumentException(t('A message is scheduled for later than now.'));
        }
        if (Conversations::member($conversationId, $author) === null) {
            throw new \InvalidArgumentException(t('Only its members write in a conversation.'));
        }
    }

    /** @return array<string, mixed> One's own scheduled message, or why not */
    private static function own(int $id, int $person): array
    {
        $raw = self::table()->where('scheduledid', $id)->limit(1)->getAllOrFail()[0] ?? null;
        if ($raw === null || (int) $raw['authorid'] !== $person) {
            throw new \DomainException(t('That scheduled message no longer exists.'));
        }

        return self::row($raw);
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private static function row(array $raw): array
    {
        return ['id' => (int) $raw['scheduledid'], 'conversationId' => (int) $raw['conversationid'], 'authorId' => (int) $raw['authorid'],
            'body' => (string) $raw['body'], 'thread' => $raw['threadrootid'] === null ? null : (int) $raw['threadrootid'], 'broadcast' => (bool) $raw['broadcast'],
            'files' => $raw['files'] === null ? [] : array_map('intval', (array) json_decode((string) $raw['files'], true)),
            'at' => (int) $raw['sendat'], 'createdAt' => (int) $raw['createdat'], 'error' => $raw['error'] === null ? null : (string) $raw['error']];
    }

    /** The table. */
    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return Conversations::db()->queryBuilder()->table('chat.conversation_scheduled');
    }
}
