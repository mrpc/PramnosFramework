<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * What the sidebar's entries above the conversations show one reader, across all of theirs: the
 * threads they take part in, what happened to them (mentions, reactions, replies), every unread
 * message, what they sent, and what they kept for later.
 *
 * Only conversations the reader is in count — an `@all` in a channel they never joined is not
 * theirs. Each list is newest first and bounded; messages come as {@see Messages::present()} gives
 * them, with their conversation's name.
 */
final class Inbox
{
    /** The most a list gives. */
    public const LIMIT = 50;

    /**
     * The threads somebody takes part in — they wrote the root or a reply — newest reply first,
     * each with how many replies came from others since their own last word there, and the latest.
     *
     * @return list<array{root: array<string, mixed>, latest: ?array<string, mixed>, replies: int, newReplies: int}>
     */
    public static function threads(int $person, int $limit = 30): array
    {
        $roots = self::roots($person);
        if ($roots === []) {
            return [];
        }
        $rows = self::messages()->select(['messageid'])->whereIn('messageid', $roots)->whereIn('conversationid', self::mine($person))
            ->where('replycount', '>', 0)->orderBy('lastreplyat', 'desc')->limit(max(1, min(self::LIMIT, $limit)))->getAllOrFail();
        $ids  = array_map('intval', array_column($rows, 'messageid'));
        $out  = [];
        foreach (Messages::many($ids) as $root) {
            $mine = (int) (self::messages()->select([new \Pramnos\Database\Expression('MAX(messageid) AS n')])->where('threadrootid', $root['id'])
                ->where('authorid', $person)->getAllOrFail()[0]['n'] ?? 0);
            $since = max($mine, $root['authorId'] === $person ? $root['id'] : 0);
            $new   = (int) (self::messages()->select([new \Pramnos\Database\Expression('COUNT(*) AS n')])->where('threadrootid', $root['id'])
                ->where('messageid', '>', $since)->whereNull('deletedat')->where(self::others($person))->getAllOrFail()[0]['n'] ?? 0);
            $latest = self::messages()->select(['messageid'])->where('threadrootid', $root['id'])->whereNull('deletedat')
                ->orderBy('messageid', 'desc')->limit(1)->getAllOrFail()[0]['messageid'] ?? null;
            $out[] = ['root' => $root, 'latest' => $latest === null ? null : (Messages::many([(int) $latest])[0] ?? null),
                'replies' => $root['replyCount'], 'newReplies' => $new];
        }

        return $out;
    }

    /**
     * What happened to somebody, newest first: messages that mention them, `@all` or `@online`
     * where they are; reactions to what they wrote; replies in the threads they take part in. Their
     * own doing is left out.
     *
     * @return list<array{kind: string, at: int, message: array<string, mixed>, by: ?int, emoji: ?string, byName: ?string}>
     */
    public static function activity(int $person, int $limit = self::LIMIT): array
    {
        $limit = max(1, min(self::LIMIT, $limit));
        $mine  = self::mine($person);
        if ($mine === []) {
            return [];
        }
        $db    = Conversations::db();
        $items = [];
        foreach ($db->queryBuilder()->table('#PREFIX#conversation_mentions')->select(['messageid', 'createdat'])->whereIn('conversationid', $mine)
            ->where(static function ($q) use ($person): void {
                $q->where('participantid', $person)->whereIn('kind', ['all', 'online'], 'or');
            })->orderBy('id', 'desc')->limit($limit)->getAllOrFail() as $row) {
            $items['m' . $row['messageid']] = ['kind' => 'mention', 'at' => (int) $row['createdat'], 'id' => (int) $row['messageid'], 'by' => null, 'emoji' => null];
        }
        foreach ($db->queryBuilder()->table('#PREFIX#conversation_reactions AS r')
            ->join('#PREFIX#conversation_messages AS m', 'm.messageid', '=', 'r.messageid')
            ->select(['r.messageid', 'r.participantid', 'r.emoji', 'r.createdat'])
            ->where('m.authorid', $person)->where('r.participantid', '!=', $person)->whereIn('m.conversationid', $mine)
            ->orderBy('r.createdat', 'desc')->limit($limit)->getAllOrFail() as $row) {
            $items[] = ['kind' => 'reaction', 'at' => (int) $row['createdat'], 'id' => (int) $row['messageid'],
                'by' => (int) $row['participantid'], 'emoji' => (string) $row['emoji']];
        }
        $roots = self::roots($person);
        if ($roots !== []) {
            foreach (self::messages()->select(['messageid'])->whereIn('threadrootid', $roots)->whereIn('conversationid', $mine)
                ->whereNull('deletedat')->where(self::others($person))->orderBy('messageid', 'desc')->limit($limit)->getAllOrFail() as $row) {
                // A reply that mentions them is told once, as the mention.
                $items['m' . $row['messageid']] ??= ['kind' => 'reply', 'at' => 0, 'id' => (int) $row['messageid'], 'by' => null, 'emoji' => null];
            }
        }
        $messages = [];
        foreach (Messages::many(array_values(array_unique(array_column($items, 'id')))) as $message) {
            $messages[$message['id']] = $message;
        }
        $out = [];
        foreach ($items as $item) {
            $message = $messages[$item['id']] ?? null;
            // Gone, or what they wrote themselves: not something that happened to them.
            if ($message === null || $message['deletedAt'] !== null || ($item['kind'] !== 'reaction' && $message['authorId'] === $person)) {
                continue;
            }
            $out[] = ['kind' => $item['kind'], 'at' => $item['at'] ?: $message['at'], 'message' => $message, 'by' => $item['by'], 'emoji' => $item['emoji']];
        }
        usort($out, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $out   = array_slice($out, 0, $limit);
        $names = People::names(array_values(array_unique(array_filter(array_column($out, 'by')))));

        return array_map(static fn (array $item): array => $item + ['byName' => $item['by'] === null ? null : ($names[$item['by']] ?? '#' . $item['by'])], $out);
    }

    /**
     * Every message somebody has not read, conversation by conversation — the newest first, each
     * conversation's messages oldest first, at most `$each` of them. Muted ones are left out.
     *
     * @return list<array{id: int, title: string, unread: int, lastMessageId: ?int, messages: list<array<string, mixed>>}>
     */
    public static function unreads(int $person, int $each = 20): array
    {
        $out = [];
        foreach (Conversations::forPerson($person) as $c) {
            if ($c['muted'] || $c['unread'] === 0 || $c['archivedAt'] !== null) {
                continue;
            }
            $ids = array_map('intval', array_column(Messages::inStream(self::messages())->select(['messageid'])->where('conversationid', $c['id'])
                ->where('messageid', '>', $c['lastReadId'])->whereNull('deletedat')->where(self::others($person))
                ->orderBy('messageid', 'desc')->limit(max(1, min(self::LIMIT, $each)))->getAllOrFail(), 'messageid'));
            $out[] = ['id' => $c['id'], 'title' => Conversations::names([$c['id']])[$c['id']] ?? '', 'unread' => $c['unread'],
                'lastMessageId' => $c['lastMessageId'], 'messages' => Messages::many(array_reverse($ids))];
        }

        return $out;
    }

    /** What somebody wrote, newest first, where they can still read it. @return list<array<string, mixed>> */
    public static function sent(int $person, int $limit = self::LIMIT): array
    {
        $mine = self::mine($person);
        if ($mine === []) {
            return [];
        }

        return Messages::many(array_map('intval', array_column(self::messages()->select(['messageid'])->where('authorid', $person)
            ->whereIn('conversationid', $mine)->whereNull('deletedat')->orderBy('messageid', 'desc')
            ->limit(max(1, min(self::LIMIT, $limit)))->getAllOrFail(), 'messageid')));
    }

    /** Keep a message for later, or let it go. */
    public static function save(int $messageId, int $person, bool $on = true): void
    {
        $table = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_saved');
        if ($on) {
            $table->insertOrIgnore(['participantid' => $person, 'messageid' => $messageId, 'savedat' => time()]);

            return;
        }
        $table->where('participantid', $person)->where('messageid', $messageId)->delete();
    }

    /**
     * What somebody kept for later, the newest kept first — those they can still read.
     *
     * @return list<array<string, mixed>>
     */
    public static function saved(int $person): array
    {
        $readable = array_flip(Conversations::readable($person));

        return array_values(array_filter(Messages::many(self::savedIds($person)),
            static fn (array $m): bool => isset($readable[$m['conversationId']])));
    }

    /**
     * Which of these messages somebody kept, or all they kept.
     *
     * @param list<int>|null $among
     * @return list<int>
     */
    public static function savedIds(int $person, ?array $among = null): array
    {
        if ($among === []) {
            return [];
        }
        $query = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_saved')->select(['messageid'])->where('participantid', $person);
        if ($among !== null) {
            $query->whereIn('messageid', $among);
        }

        return array_map('intval', array_column($query->orderBy('savedat', 'desc')->orderBy('messageid', 'desc')->limit(self::LIMIT * 4)->getAllOrFail(), 'messageid'));
    }

    /**
     * The roots of the threads somebody takes part in: theirs with replies, and those they replied in.
     *
     * @return list<int>
     */
    private static function roots(int $person): array
    {
        $replied = array_column(self::messages()->select(['threadrootid'])->where('authorid', $person)->whereNotNull('threadrootid')
            ->orderBy('messageid', 'desc')->limit(500)->getAllOrFail(), 'threadrootid');
        $own     = array_column(self::messages()->select(['messageid'])->where('authorid', $person)->whereNull('threadrootid')
            ->where('replycount', '>', 0)->orderBy('messageid', 'desc')->limit(500)->getAllOrFail(), 'messageid');

        return array_values(array_unique(array_map('intval', array_merge($replied, $own))));
    }

    /** The conversations somebody is in. @return list<int> */
    private static function mine(int $person): array
    {
        return array_map('intval', array_column(Conversations::db()->queryBuilder()->table('#PREFIX#conversation_members')->select(['conversationid'])
            ->where('participanttype', 'user')->where('participantid', $person)->whereNull('leftat')->getAllOrFail(), 'conversationid'));
    }

    /** Written by somebody else, or by the system. */
    private static function others(int $person): \Closure
    {
        return static function ($q) use ($person): void {
            $q->whereNull('authorid')->orWhere('authorid', '!=', $person);
        };
    }

    /** The messages table. */
    private static function messages(): \Pramnos\Database\QueryBuilder
    {
        return Conversations::db()->queryBuilder()->table('#PREFIX#conversation_messages');
    }
}
