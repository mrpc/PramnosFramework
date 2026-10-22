<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Conversations\Events\MentionReceived;
use Pramnos\Conversations\Events\MessagePosted;
use Pramnos\Database\Expression;
use Pramnos\Database\Instant;

/**
 * What is written in the conversations.
 *
 * ```php
 * $id = Messages::post($general, $me, 'Το <@48211> ζητά επιστροφή, το βλέπει κάποιος;');
 * Messages::post($general, $maria, 'Το κοιτάζω', ['thread' => $id]);         // a reply in its thread
 * Messages::react($id, $nikos, ':eyes:');
 * Messages::list($general, ['after' => $lastSeen]);                         // what a reconnect missed
 * ```
 *
 * A person is mentioned as `<@id>` — how a composer writes a chosen name, and how an import writes
 * another system's — so a name can change without a message changing; `@all` and `@online` mention the
 * conversation's members. Each mention is a row, and an event ({@see MentionReceived}); each message
 * is {@see MessagePosted}. An import passes `source` and `externalid`, and the same message twice is
 * written once.
 */
final class Messages
{
    /** The longest message, in characters. */
    public const MAX_LENGTH = 40000;

    /** The most messages one read returns. */
    public const MAX_LIST = 200;

    /**
     * Write a message. Its writer must be in the conversation, unless it is the system's. `quiet`
     * writes it without telling anybody — no live update, no events, no mentions — for history
     * brought in from elsewhere, which nobody should be notified of again.
     *
     * History is written as it was: `source` and `externalid` say where it came from, once — the
     * same pair again is the message already written ({@see find()}); `at` is when it was written,
     * `editedAt` when it was last changed; `authorName` is a writer with no account here, shown by
     * name as somebody who has left. Files go in through {@see Files::store()} and `files`.
     *
     * @param array{thread?: int, card?: array<string, mixed>, source?: string, externalid?: string, at?: int, editedAt?: int, authorName?: string, files?: list<int>, quiet?: bool} $options
     * @return int The message
     */
    public static function post(int $conversationId, ?int $author, string $body, array $options = []): int
    {
        $body  = rtrim($body);
        $card  = $options['card'] ?? null;
        if ($author === null && $card === null && trim((string) ($options['authorName'] ?? '')) !== '') {
            $card = ['from' => trim((string) $options['authorName']), 'former' => true];
        }
        $files = array_map('intval', (array) ($options['files'] ?? []));
        if ($body === '' && $card === null && $files === []) {
            throw new \InvalidArgumentException(t('A message needs something in it.'));
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(t('A message is at most %s characters.', number_format(self::MAX_LENGTH)));
        }
        $conversation = Conversations::get($conversationId) ?? throw new \InvalidArgumentException(t('That conversation no longer exists.'));
        if ($author !== null && Conversations::member($conversationId, $author) === null) {
            throw new \InvalidArgumentException(t('Only its members write in a conversation.'));
        }
        $db = Conversations::db();
        if (isset($options['source'], $options['externalid'])) {
            $existing = self::find((string) $options['source'], (string) $options['externalid']);
            if ($existing !== null) {
                return $existing;
            }
        }
        $thread = isset($options['thread']) ? (int) $options['thread'] : null;
        if ($thread !== null) {
            $root = self::get($thread);
            if ($root === null || $root['conversationId'] !== $conversationId || $root['threadRootId'] !== null) {
                throw new \InvalidArgumentException(t('A reply answers a message of the same conversation.'));
            }
        }
        $at   = (int) ($options['at'] ?? time());
        $refs = self::refs($body);
        $db->queryBuilder()->table('#PREFIX#conversation_messages')->insert([
            'conversationid' => $conversationId, 'authortype' => $author === null ? 'system' : 'user', 'authorid' => $author,
            'body' => $body, 'card' => $card === null ? null : (string) json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'threadrootid' => $thread, 'source' => $options['source'] ?? null, 'externalid' => $options['externalid'] ?? null,
            'refs' => $refs === [] ? null : (string) json_encode($refs, JSON_UNESCAPED_UNICODE), 'created_at' => Instant::at($db, $at),
            'editedat' => isset($options['editedAt']) ? (int) $options['editedAt'] : null,
        ]);
        $id = (int) $db->getInsertId();
        if ($thread !== null) {
            $db->queryBuilder()->table('#PREFIX#conversation_messages')->where('messageid', $thread)
                ->update(['replycount' => new Expression('replycount + 1'), 'lastreplyat' => $at]);
        } else {
            $db->queryBuilder()->table('#PREFIX#conversations')->where('conversationid', $conversationId)
                ->update(['lastmessageid' => $id, 'lastmessageat' => $at]);
        }
        if ($author !== null) {
            Conversations::markRead($conversationId, $author, $id);
        }
        Files::attach($files, $id, $conversationId, $author);
        if (!empty($options['quiet'])) {
            return $id;
        }
        $mentioned = self::mention($id, $conversationId, $author, $body, $at);
        Realtime::posted($id, $mentioned);
        \Pramnos\Event\Event::dispatch(new MessagePosted($id, $conversationId, $author, $thread));
        foreach ($mentioned as $person => $kind) {
            \Pramnos\Event\Event::dispatch(new MentionReceived($id, $person, $kind));
        }
        unset($conversation);

        return $id;
    }

    /** The message brought in from `source` as `externalId`, or null — history written once. */
    public static function find(string $source, string $externalId): ?int
    {
        $id = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_messages')->select(['messageid'])->where('source', $source)
            ->where('externalid', $externalId)->limit(1)->getAllOrFail()[0]['messageid'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** Change a message's text — its writer only — keeping what it said before. */
    public static function edit(int $id, int $by, string $body): void
    {
        $message = self::get($id) ?? throw new \InvalidArgumentException(t('That message no longer exists.'));
        if ($message['authorId'] !== $by || $message['deletedAt'] !== null) {
            throw new \InvalidArgumentException(t('Only its writer changes a message.'));
        }
        $body = rtrim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(t('A message needs something in it.'));
        }
        $db = Conversations::db();
        $db->queryBuilder()->table('#PREFIX#conversation_message_edits')->insert(['messageid' => $id, 'body' => $message['body'], 'editedby' => $by, 'editedat' => time()]);
        $refs = self::refs($body);
        $db->queryBuilder()->table('#PREFIX#conversation_messages')->where('messageid', $id)->update([
            'body' => $body, 'editedat' => time(), 'refs' => $refs === [] ? null : (string) json_encode($refs, JSON_UNESCAPED_UNICODE),
        ]);
        $db->queryBuilder()->table('#PREFIX#conversation_mentions')->where('messageid', $id)->delete();
        self::mention($id, $message['conversationId'], $by, $body, time());
        Realtime::changed($id, 'message.edited');
    }

    /** Delete a message — its writer, an owner of the conversation, or whoever reads them all. */
    public static function delete(int $id, object $by): void
    {
        $message = self::get($id) ?? throw new \InvalidArgumentException(t('That message no longer exists.'));
        $who     = (int) $by->userid;
        if ($message['authorId'] !== $who && (Conversations::member($message['conversationId'], $who)['role'] ?? null) !== 'owner' && !Access::readsAll($by)) {
            throw new \InvalidArgumentException(t('Only its writer deletes a message.'));
        }
        $db = Conversations::db();
        $db->queryBuilder()->table('#PREFIX#conversation_messages')->where('messageid', $id)->update(['body' => '', 'card' => null, 'refs' => null, 'deletedat' => time()]);
        $db->queryBuilder()->table('#PREFIX#conversation_mentions')->where('messageid', $id)->delete();
        Realtime::changed($id, 'message.deleted');
    }

    /**
     * Put an emoji on a message, or take it off. History brought in says when it was put, `$at`,
     * and is `$quiet`: nobody's page is told.
     */
    public static function react(int $id, int $person, string $emoji, bool $on = true, ?int $at = null, bool $quiet = false): void
    {
        $emoji = trim($emoji);
        if ($emoji === '' || mb_strlen($emoji) > 64) {
            throw new \InvalidArgumentException(t('That is not an emoji.'));
        }
        $table = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_reactions');
        if ($on) {
            $table->insertOrIgnore(['messageid' => $id, 'participanttype' => 'user', 'participantid' => $person, 'emoji' => $emoji, 'createdat' => $at ?? time()]);
        } else {
            $table->where('messageid', $id)->where('participanttype', 'user')->where('participantid', $person)->where('emoji', $emoji)->delete();
        }
        if (!$quiet) {
            Realtime::changed($id, 'message.reacted');
        }
    }

    /** Pin a message to its conversation, or take it off. */
    public static function pin(int $id, int $by, bool $on = true): void
    {
        $message = self::get($id) ?? throw new \InvalidArgumentException(t('That message no longer exists.'));
        $table   = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_pins');
        if ($on) {
            $table->insertOrIgnore(['conversationid' => $message['conversationId'], 'messageid' => $id, 'pinnedby' => $by, 'pinnedat' => time()]);
        } else {
            $table->where('conversationid', $message['conversationId'])->where('messageid', $id)->delete();
        }
        Realtime::changed($id, 'message.pinned');
    }

    /**
     * A message as stored, or null.
     *
     * @return array{id: int, conversationId: int, authorType: string, authorId: ?int, body: string, card: ?array, threadRootId: ?int, replyCount: int, lastReplyAt: ?int, editedAt: ?int, deletedAt: ?int, source: ?string, externalId: ?string, refs: list<string>, at: int}|null
     */
    public static function get(int $id): ?array
    {
        $db  = Conversations::db();
        $row = $db->queryBuilder()->table('#PREFIX#conversation_messages')->select(['*', Instant::unix($db, 'created_at', 'at_unix')])
            ->where('messageid', $id)->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /**
     * Messages of a conversation, oldest first: the newest top-level ones, or those before or after
     * a message — what a reconnect missed — or a thread's replies.
     *
     * @param array{before?: int, after?: int, thread?: int, limit?: int} $options
     * @return list<array<string, mixed>> Each with its reactions, files, pinned and HTML
     */
    public static function list(int $conversationId, array $options = []): array
    {
        $db    = Conversations::db();
        $limit = max(1, min(self::MAX_LIST, (int) ($options['limit'] ?? 50)));
        $query = $db->queryBuilder()->table('#PREFIX#conversation_messages')->select(['*', Instant::unix($db, 'created_at', 'at_unix')])
            ->where('conversationid', $conversationId);
        if (isset($options['thread'])) {
            $query->where(static function ($q) use ($options): void {
                $q->where('threadrootid', (int) $options['thread'])->orWhere('messageid', (int) $options['thread']);
            });
        } elseif (!isset($options['after'])) {
            $query->whereNull('threadrootid');
        }
        if (isset($options['after'])) {
            $rows = $query->where('messageid', '>', (int) $options['after'])->orderBy('messageid')->limit($limit)->getAllOrFail();
        } else {
            if (isset($options['before'])) {
                $query->where('messageid', '<', (int) $options['before']);
            }
            $rows = array_reverse($query->orderBy('messageid', 'desc')->limit($limit)->getAllOrFail());
        }

        return self::present(array_map(self::row(...), $rows));
    }

    /**
     * The messages an account may read that hold every one of the words, newest first, with their
     * conversation's name.
     *
     * Each word matches at its start: `τιμολ` finds `τιμολόγιο`. A word of three or more letters goes
     * through the full-text index (`FULLTEXT` on MySQL and MariaDB, `to_tsvector('simple')` on
     * PostgreSQL); a shorter one would not be in it — the index keeps words of three letters up — so
     * a search with one is a `LIKE` over the readable conversations instead.
     *
     * `$from` keeps what one person wrote, `$mentioning` what mentions one person; with either, no
     * words are their newest.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(object $user, string $terms, ?int $conversationId = null, int $limit = 50, ?int $from = null, ?int $mentioning = null): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($terms)) ?: [], static fn (string $w): bool => $w !== ''));
        if (($words === [] && $from === null && $mentioning === null) || !Access::canUse($user)) {
            return [];
        }
        $db    = Conversations::db();
        $query = $db->queryBuilder()->table('#PREFIX#conversation_messages')->select(['*', Instant::unix($db, 'created_at', 'at_unix')])
            ->whereNull('deletedat');
        if ($conversationId !== null) {
            if (!Conversations::canRead($conversationId, $user)) {
                return [];
            }
            $query->where('conversationid', $conversationId);
        } elseif (!Access::readsAll($user)) {
            $readable = Conversations::readable((int) $user->userid);
            if ($readable === []) {
                return [];
            }
            $query->whereIn('conversationid', $readable);
        }
        if ($from !== null) {
            $query->where('authortype', 'user')->where('authorid', $from);
        }
        if ($mentioning !== null) {
            // ponytail: the 500 newest mentions of them; a join if anybody needs further back.
            $mentions = array_map('intval', array_column(Conversations::db()->queryBuilder()->table('#PREFIX#conversation_mentions')->select(['messageid'])
                ->where('participantid', $mentioning)->orderBy('messageid', 'desc')->limit(500)->getAllOrFail(), 'messageid'));
            if ($mentions === []) {
                return [];
            }
            $query->whereIn('messageid', $mentions);
        }
        if ($words !== [] && min(array_map('mb_strlen', $words)) < 3) {
            foreach ($words as $word) {
                $query->whereLike('body', '%' . addcslashes($word, '%_\\') . '%');
            }
        } elseif ($words !== [] && $db->schema()->getCapabilities()->isPostgreSQL()) {
            // Raw: the builder has no full-text match; the words are letters and digits only.
            $query->whereRaw("to_tsvector('simple', body) @@ to_tsquery('simple', ?)", [implode(' & ', array_map(static fn (string $w): string => $w . ':*', $words))]);
        } elseif ($words !== []) {
            $query->whereRaw('MATCH(body) AGAINST (? IN BOOLEAN MODE)', [implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', $words))]);
        }
        $messages = self::present(array_map(self::row(...), $query->orderBy('messageid', 'desc')->limit(max(1, min(self::MAX_LIST, $limit)))->getAllOrFail()));
        $names    = Conversations::names(array_values(array_unique(array_column($messages, 'conversationId'))));

        return array_map(static fn (array $m): array => $m + ['conversation' => $names[$m['conversationId']] ?? ''], $messages);
    }

    /**
     * Messages as a reader sees them: the writer's name, the HTML, the reactions counted with who,
     * the files, whether pinned. A deleted one keeps its place and nothing else.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function present(array $messages): array
    {
        if ($messages === []) {
            return [];
        }
        $db        = Conversations::db();
        $ids       = array_column($messages, 'id');
        $reactions = [];
        foreach ($db->queryBuilder()->table('#PREFIX#conversation_reactions')->whereIn('messageid', $ids)->orderBy('id')->getAllOrFail() as $r) {
            $reactions[(int) $r['messageid']][(string) $r['emoji']][] = (int) $r['participantid'];
        }
        $pinned = array_flip(array_map('intval', array_column($db->queryBuilder()->table('#PREFIX#conversation_pins')->select(['messageid'])
            ->whereIn('messageid', $ids)->getAllOrFail(), 'messageid')));
        $files = [];
        foreach (Files::forMessages($ids) as $file) {
            $files[$file['messageId']][] = $file;
        }
        $names = People::names(array_merge(array_filter(array_column($messages, 'authorId')), self::mentionedIn($messages)));

        return array_map(static fn (array $m): array => array_merge($m, [
            'author'    => $m['authorId'] === null ? null : ($names[$m['authorId']] ?? '#' . $m['authorId']),
            'html'      => $m['deletedAt'] !== null ? '' : Markdown::render($m['body'], static fn (int $id): ?string => $names[$id] ?? null),
            'reactions' => array_map(static fn (string $emoji, array $who): array => ['emoji' => $emoji, 'count' => count($who), 'people' => $who],
                array_keys($reactions[$m['id']] ?? []), $reactions[$m['id']] ?? []),
            'files'     => $files[$m['id']] ?? [],
            'pinned'    => isset($pinned[$m['id']]),
            // The application's patterns, read now: one declared later finds older messages too.
            'refs'      => array_values(array_unique(array_merge($m['refs'], $m['deletedAt'] !== null ? [] : References::find($m['body'])))),
        ]), $messages);
    }

    /**
     * Messages by id, as a reader sees them, in the order asked, each with its conversation's name;
     * one that is gone is left out.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function many(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $db   = Conversations::db();
        $rows = [];
        foreach ($db->queryBuilder()->table('#PREFIX#conversation_messages')->select(['*', Instant::unix($db, 'created_at', 'at_unix')])
            ->whereIn('messageid', array_values(array_unique($ids)))->getAllOrFail() as $row) {
            $rows[(int) $row['messageid']] = self::row($row);
        }
        $messages = self::present(array_values(array_filter(array_map(static fn (int $id): ?array => $rows[$id] ?? null, $ids))));
        $names    = Conversations::names(array_values(array_unique(array_column($messages, 'conversationId'))));

        return array_map(static fn (array $m): array => $m + ['conversation' => $names[$m['conversationId']] ?? ''], $messages);
    }

    /** The pinned messages of a conversation, newest pin first. @return list<array<string, mixed>> */
    public static function pinned(int $conversationId): array
    {
        $ids = array_map('intval', array_column(Conversations::db()->queryBuilder()->table('#PREFIX#conversation_pins')->select(['messageid'])
            ->where('conversationid', $conversationId)->orderBy('pinnedat', 'desc')->getAllOrFail(), 'messageid'));

        return self::present(array_values(array_filter(array_map(self::get(...), $ids))));
    }

    /** What a message said before each of its edits, oldest first. @return list<array{body: string, editedBy: ?int, editedAt: int}> */
    public static function edits(int $id): array
    {
        return array_map(static fn (array $r): array => ['body' => (string) $r['body'], 'editedBy' => $r['editedby'] === null ? null : (int) $r['editedby'],
            'editedAt' => (int) $r['editedat']], Conversations::db()->queryBuilder()->table('#PREFIX#conversation_message_edits')->where('messageid', $id)->orderBy('id')->getAllOrFail());
    }

    /**
     * The objects a message refers to: `#type:id` — `#user:48211` — for the application's cards.
     *
     * @return list<string>
     */
    public static function refs(string $body): array
    {
        preg_match_all('/(?<![\w#])#([a-z][a-z0-9_]*:\w[\w-]*)/i', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Record who a message mentions: `<@id>` of its members, `@all` and `@online` for all of them
     * but its writer and those inactive ({@see People::inactive()}) — named, they are still told.
     *
     * @return array<int, string> The people mentioned, with how
     */
    private static function mention(int $id, int $conversationId, ?int $author, string $body, int $at): array
    {
        $members = Conversations::members($conversationId);
        $people  = [];
        preg_match_all('/<@(\d+)>/', $body, $m);
        foreach (array_map('intval', $m[1]) as $person) {
            if (in_array($person, $members, true) && $person !== $author) {
                $people[$person] = 'person';
            }
        }
        $table  = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_mentions');
        $asleep = null;
        foreach (['all', 'online'] as $kind) {
            if (preg_match('/(?<![\w@])@' . $kind . '\b/', $body)) {
                $table->insert(['messageid' => $id, 'conversationid' => $conversationId, 'kind' => $kind, 'participantid' => null, 'createdat' => $at]);
                $asleep ??= People::inactive($members);
                foreach ($members as $person) {
                    if ($person !== $author && !isset($asleep[$person])) {
                        $people[$person] ??= $kind;
                    }
                }
            }
        }
        foreach ($people as $person => $kind) {
            if ($kind === 'person') {
                Conversations::db()->queryBuilder()->table('#PREFIX#conversation_mentions')
                    ->insert(['messageid' => $id, 'conversationid' => $conversationId, 'kind' => 'person', 'participantid' => $person, 'createdat' => $at]);
            }
        }

        return $people;
    }

    /** The people mentioned in messages. @param list<array<string, mixed>> $messages @return list<int> */
    private static function mentionedIn(array $messages): array
    {
        preg_match_all('/<@(\d+)>/', implode("\n", array_column($messages, 'body')), $m);

        return array_map('intval', $m[1]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function row(array $row): array
    {
        $int = static fn ($v): ?int => $v === null ? null : (int) $v;

        return ['id' => (int) $row['messageid'], 'conversationId' => (int) $row['conversationid'], 'authorType' => (string) $row['authortype'],
            'authorId' => $int($row['authorid']), 'body' => (string) $row['body'], 'card' => $row['card'] === null ? null : json_decode((string) $row['card'], true),
            'threadRootId' => $int($row['threadrootid']), 'replyCount' => (int) $row['replycount'], 'lastReplyAt' => $int($row['lastreplyat']),
            'editedAt' => $int($row['editedat']), 'deletedAt' => $int($row['deletedat']), 'source' => $row['source'] === null ? null : (string) $row['source'],
            'externalId' => $row['externalid'] === null ? null : (string) $row['externalid'],
            'refs' => $row['refs'] === null ? [] : (array) json_decode((string) $row['refs'], true), 'at' => (int) $row['at_unix']];
    }
}
