<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Conversations\Events\ConversationCreated;
use Pramnos\Database\Expression;

/**
 * The conversations and who is in them.
 *
 * ```php
 * $general = Conversations::create('channel', 'General', [], $me);          // #general, open to every member of the team
 * $dm      = Conversations::create('dm', null, [$me, $maria]);              // the same one, if the two have one already
 * Conversations::join($general, $nikos);
 * Conversations::forPerson($me);                                            // the sidebar: unread, mentions, newest first
 * ```
 *
 * | kind      | who is in it                          | who reads it                         |
 * |-----------|---------------------------------------|--------------------------------------|
 * | `channel` | whoever joins                         | everybody who may use conversations  |
 * | `private` | whoever its members add               | its members                          |
 * | `dm`      | two people, for good                  | the two                              |
 * | `group`   | three to nine people                  | them                                 |
 * | `system`  | whoever joins; the system writes       | everybody who may use conversations  |
 *
 * Whoever reads every conversation ({@see Access::READ_ALL}) reads all of them besides. A channel,
 * a private channel and a system one have a name for `#` — unique; a dm and a group have none.
 */
final class Conversations
{
    /** The kinds of conversation. */
    public const KINDS = ['channel', 'private', 'dm', 'group', 'system', 'bot'];

    /** The kinds that are people's own, listed with direct messages: two people, a few, or one and a bot. */
    public const PERSONAL = ['dm', 'group', 'bot'];

    /** The kinds anybody who may use conversations reads, and may join. */
    public const OPEN = ['channel', 'system'];

    /** The most people a group conversation has. */
    public const GROUP_MAX = 9;

    /**
     * Make a conversation: its maker is its owner and first member. A dm between two people who
     * have one already is that one. A dm of one person is theirs alone — one each, their notes.
     *
     * @param list<int> $members Accounts in it besides its maker
     */
    public static function create(string $kind, ?string $name, array $members = [], ?int $by = null, string $topic = '', array $formers = []): int
    {
        // Somebody with no account here — a colleague who left — kept by name, and by their id
        // where they came from (`slack:U123`), so a second import finds the same conversation.
        $formers = array_values(array_filter(array_map(static fn ($f): array => ['name' => mb_substr(trim((string) ($f['name'] ?? '')), 0, 150),
            'externalid' => trim((string) ($f['externalid'] ?? '')) === '' ? null : mb_substr(trim((string) $f['externalid']), 0, 100)], $formers),
            static fn (array $f): bool => $f['name'] !== ''));
        if ($formers !== [] && !in_array($kind, ['dm', 'group'], true)) {
            throw new \InvalidArgumentException(t('Only a direct or group conversation keeps a member with no account.'));
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('A conversation is a ' . implode(', ', self::KINDS) . '.');
        }
        $people = array_values(array_unique(array_filter(array_map('intval', array_merge($by !== null ? [$by] : [], $members)), static fn (int $id): bool => $id > 0)));
        $slug   = null;
        if (in_array($kind, ['channel', 'private', 'system'], true)) {
            $slug = self::slug((string) $name);
            if ($slug === '') {
                throw new \InvalidArgumentException(t('A channel needs a name.'));
            }
            if (self::bySlug($slug) !== null) {
                throw new \InvalidArgumentException(t('There is a channel called #%s already.', $slug));
            }
        } elseif ($kind === 'bot') {
            // One person and what writes to them — named for it: "Nannuka Bot".
            if (count($people) !== 1 || $by !== null || trim((string) $name) === '') {
                throw new \InvalidArgumentException(t('A bot conversation is one person and a named bot.'));
            }
            $existing = self::botFor($people[0], (string) $name);
            if ($existing !== null) {
                return $existing;
            }
        } elseif ($kind === 'dm') {
            // One person alone is their own dm: their notes, as Slack keeps one for each.
            if (count($people) + count($formers) > 2 || $people === []) {
                throw new \InvalidArgumentException(t('A direct conversation is between two people.'));
            }
            $existing = $formers !== [] ? self::dmWithFormer($people[0], $formers[0]['externalid'] ?? $formers[0]['name'])
                : self::dmBetween($people[0], $people[1] ?? $people[0]);
            if ($existing !== null) {
                return $existing;
            }
        } elseif ($people === [] || count($people) + count($formers) < 3 || count($people) + count($formers) > self::GROUP_MAX) {
            throw new \InvalidArgumentException(t('A group conversation has three to %s people.', (string) self::GROUP_MAX));
        }
        $now = time();
        $db  = self::db();
        // A group's name is its own (up to 80), a direct conversation has none: its title is the other person.
        $name = match ($kind) {
            'dm'    => null,
            'group' => $name === null || trim($name) === '' ? null : mb_substr(trim($name), 0, 80),
            default => $name === null ? null : mb_substr(trim($name), 0, 150),
        };
        $db->queryBuilder()->table('chat.conversations')->insert([
            'kind' => $kind, 'slug' => $slug, 'name' => $name,
            'topic' => $topic === '' ? null : $topic, 'createdby' => $by, 'createdat' => $now,
        ]);
        $id = (int) $db->getInsertId();
        foreach ($people as $i => $person) {
            self::addRow($id, $person, $by !== null && $i === 0 ? 'owner' : 'member');
        }
        foreach ($formers as $i => $former) {
            // Their own negative ids, so each is a row of its own; they never read.
            self::members_()->insert(['conversationid' => $id, 'participanttype' => 'former', 'participantid' => -($i + 1), 'role' => 'member',
                'lastreadid' => 0, 'muted' => 1, 'notify' => 'none', 'joinedat' => $now, 'name' => $former['name'], 'externalid' => $former['externalid']]);
        }
        \Pramnos\Event\Event::dispatch(new ConversationCreated($id, $kind, $by));

        return $id;
    }

    /**
     * Name a group conversation, or take its name away (`null` or empty: its title is its members
     * again). Any member may. A line in the conversation says who did it and to what, its members
     * see the new title at once (`conversation.changed`), and the change is in the changelog
     * (`conversation.renamed`). A direct or bot conversation is refused: neither has a name. A
     * channel is renamed by its owner, an admin of it or a moderator ({@see Details::renameChannel()}).
     *
     * @throws \DomainException When the conversation cannot be named, or `$by` is not in it
     */
    public static function rename(int $id, ?string $name, int $by): void
    {
        $conversation = self::get($id);
        if ($conversation !== null && in_array($conversation['kind'], ['channel', 'private', 'system'], true)) {
            Details::renameChannel($conversation, (string) $name, $by);

            return;
        }
        if ($conversation === null || $conversation['kind'] !== 'group') {
            throw new \DomainException(t('Only a group conversation is named.'));
        }
        if (self::member($id, $by) === null) {
            throw new \DomainException(t('Only its members rename a group.'));
        }
        $name = $name === null || trim($name) === '' ? null : mb_substr(trim($name), 0, 80);
        if ($name === $conversation['name']) {
            return;
        }
        self::db()->queryBuilder()->table('chat.conversations')->where('conversationid', $id)->update(['name' => $name]);
        $who = People::names([$by])[$by] ?? '';
        Messages::post($id, null, $name === null
            ? sprintf(t('%s took the group\'s name away.'), $who)
            : sprintf(t('%s renamed the group to “%s”.'), $who, $name), ['notice' => true]);
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $id, 'conversation.renamed', ['from' => $conversation['name'], 'to' => $name], by: $by);
        Realtime::conversationChanged($id, self::members($id), null, $name);
    }

    /**
     * A conversation, or null.
     *
     * @return array{id: int, kind: string, slug: ?string, name: ?string, topic: ?string, retentionDays: ?int, createdBy: ?int, createdAt: int, archivedAt: ?int, lastMessageId: ?int, lastMessageAt: ?int}|null
     */
    public static function get(int $id): ?array
    {
        $row = self::db()->queryBuilder()->table('chat.conversations')->where('conversationid', $id)->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /** A channel by its name for `#`, or null. @return array<string, mixed>|null */
    public static function bySlug(string $slug): ?array
    {
        $row = self::db()->queryBuilder()->table('chat.conversations')->where('slug', self::slug($slug))->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /** Join an open conversation — a channel, a system one. */
    public static function join(int $id, int $person): void
    {
        $conversation = self::get($id) ?? throw new \InvalidArgumentException(t('That conversation no longer exists.'));
        if (!in_array($conversation['kind'], self::OPEN, true)) {
            throw new \InvalidArgumentException(t('This conversation is joined by being added to it.'));
        }
        $new = self::member($id, $person) === null;
        self::addRow($id, $person, 'member');
        if ($new) {
            self::cameIn($id, $person, null);
        }
    }

    /** Add somebody to a private channel or a group — by one of its members. */
    public static function add(int $id, int $person, int $by): void
    {
        $conversation = self::get($id) ?? throw new \InvalidArgumentException(t('That conversation no longer exists.'));
        if ($conversation['kind'] === 'dm') {
            throw new \InvalidArgumentException(t('A direct conversation stays between its two people.'));
        }
        if (self::member($id, $by) === null) {
            throw new \InvalidArgumentException(t('Only its members add people to it.'));
        }
        if ($conversation['kind'] === 'group' && count(self::members($id)) >= self::GROUP_MAX) {
            throw new \InvalidArgumentException(t('A group conversation has three to %s people.', (string) self::GROUP_MAX));
        }
        $new = self::member($id, $person) === null;
        self::addRow($id, $person, 'member');
        if ($new) {
            self::cameIn($id, $person, $by);
        }
    }

    /** Somebody came in: told to whoever listens, and welcomed where the conversation does. */
    private static function cameIn(int $id, int $person, ?int $by): void
    {
        \Pramnos\Event\Event::dispatch(new Events\MemberJoined($id, $person, $by));
        Automations::welcome($id, $person);
    }

    /** Leave a conversation; a dm cannot be left. */
    public static function leave(int $id, int $person): void
    {
        if ((self::get($id)['kind'] ?? null) === 'dm') {
            throw new \InvalidArgumentException(t('A direct conversation stays between its two people.'));
        }
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)->update(['leftat' => time()]);
    }

    /**
     * Somebody's membership, while they are in it, or null.
     *
     * @return array{role: string, lastReadId: int, muted: bool, notify: string, joinedAt: int}|null
     */
    public static function member(int $id, int $person): ?array
    {
        $row = self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)
            ->whereNull('leftat')->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : ['role' => (string) $row['role'], 'lastReadId' => (int) $row['lastreadid'], 'muted' => (bool) $row['muted'],
            'notify' => (string) $row['notify'], 'joinedAt' => (int) $row['joinedat']];
    }

    /** Who is in a conversation now, by account id. @return list<int> */
    public static function members(int $id): array
    {
        return array_map('intval', array_column(self::members_()->select(['participantid'])->where('conversationid', $id)
            ->where('participanttype', 'user')->whereNull('leftat')->orderBy('participantid')->getAllOrFail(), 'participantid'));
    }

    /**
     * The conversations a person reads without reading them all: the ones they are in and the open
     * ones.
     *
     * @return list<int>
     */
    public static function readable(int $person): array
    {
        $in   = array_column(self::members_()->select(['conversationid'])->where('participanttype', 'user')->where('participantid', $person)
            ->whereNull('leftat')->getAllOrFail(), 'conversationid');
        $open = array_column(self::db()->queryBuilder()->table('chat.conversations')->select(['conversationid'])->whereIn('kind', self::OPEN)
            ->getAllOrFail(), 'conversationid');

        return array_values(array_unique(array_map('intval', array_merge($in, $open))));
    }

    /**
     * What a conversation is called: `#name` for one with a name, its members' names otherwise.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (self::db()->queryBuilder()->table('chat.conversations')->whereIn('conversationid', $ids)->getAllOrFail() as $row) {
            $id = (int) $row['conversationid'];
            if ($row['kind'] === 'bot') {
                $names[$id] = Ask::displayName((string) $row['name']);
                continue;
            }
            if ($row['slug'] !== null && $row['slug'] !== '') {
                $names[$id] = '#' . $row['slug'];
                continue;
            }
            $people     = People::names(self::members($id));
            $names[$id] = implode(', ', array_filter(array_merge($people, array_column(self::formers([$id])[$id] ?? [], 'name'))));
        }

        return $names;
    }

    /** Whether an account reads a conversation: it is in it, it is open, or it reads them all. */
    public static function canRead(int $id, object $user): bool
    {
        if (!Access::canUse($user)) {
            return false;
        }
        $conversation = self::get($id);

        return $conversation !== null && (in_array($conversation['kind'], self::OPEN, true)
            || self::member($id, (int) $user->userid) !== null || Access::readsAll($user));
    }

    /** Whether an account writes in a conversation: it may use conversations and is in it. */
    public static function canWrite(int $id, object $user): bool
    {
        $conversation = self::get($id);

        return Access::canUse($user) && $conversation !== null && $conversation['archivedAt'] === null
            && self::member($id, (int) $user->userid) !== null;
    }

    /** What somebody is told of a conversation: everything, their mentions, or nothing. */
    public static function notify(int $id, int $person, string $notify): void
    {
        if (!in_array($notify, ['all', 'mentions', 'none'], true)) {
            throw new \InvalidArgumentException(t('Told of all of it, of mentions, or of nothing.'));
        }
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)->update(['notify' => $notify]);
    }

    /** Mute a conversation for somebody, or not. */
    public static function mute(int $id, int $person, bool $muted = true): void
    {
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)->update(['muted' => $muted ? 1 : 0]);
    }

    /**
     * Star one of one's conversations, or not. `$before` places it among one's starred, before that
     * one — the reader's own order ({@see starOrder()}); without it, a starred one keeps its place,
     * if it had one. Unstarred, it has none.
     */
    public static function star(int $id, int $person, bool $starred = true, ?int $before = null): void
    {
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)
            // Its place let go — where the migration has run: starring still works before it.
            ->update(['starred' => $starred ? 1 : 0] + ($starred || !self::db()->schema()->hasColumn('chat.conversation_members', 'starorder') ? [] : ['starorder' => null]));
        if ($starred && $before !== null) {
            $order = array_values(array_diff(array_keys(self::starOrders($person)), [$id]));
            $at    = array_search($before, $order, true);
            array_splice($order, $at === false ? count($order) : (int) $at, 0, [$id]);
            self::starOrder($person, $order);
        }
    }

    /**
     * The reader's own order of their starred conversations, all at once: each listed takes its
     * place; any other starred one has none. Ids that are not their starred conversations are let be.
     *
     * @param list<int> $ids
     */
    public static function starOrder(int $person, array $ids): void
    {
        $mine = self::members_()->select(['conversationid'])->where('participanttype', 'user')->where('participantid', $person)
            ->where('starred', 1)->whereNull('leftat')->getAllOrFail();
        $mine = array_map('intval', array_column($mine, 'conversationid'));
        $ids  = array_values(array_intersect(array_unique(array_map('intval', $ids)), $mine));
        self::members_()->where('participanttype', 'user')->where('participantid', $person)->where('starred', 1)->update(['starorder' => null]);
        foreach ($ids as $place => $conversation) {
            self::members_()->where('conversationid', $conversation)->where('participanttype', 'user')->where('participantid', $person)
                ->update(['starorder' => $place + 1]);
        }
    }

    /**
     * One's starred conversations and their places, placed ones first in order, then the rest.
     *
     * @return array<int, int|null>
     */
    private static function starOrders(int $person): array
    {
        $rows = self::members_()->select(['conversationid', 'starorder'])->where('participanttype', 'user')->where('participantid', $person)
            ->where('starred', 1)->whereNull('leftat')->getAllOrFail();
        usort($rows, static fn (array $a, array $b): int => [$a['starorder'] === null, (int) $a['starorder'], (int) $a['conversationid']]
            <=> [$b['starorder'] === null, (int) $b['starorder'], (int) $b['conversationid']]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['conversationid']] = $row['starorder'] === null ? null : (int) $row['starorder'];
        }

        return $out;
    }

    /**
     * Somebody has read up to a message: `lastreadid` never moves back, by where the messages
     * stand, so reading history brought in later moves nothing — but `readat`, their read point
     * (FW-497), is set every time regardless, including the message they are pointed at
     * already: a reaction arriving while they are caught up on every message leaves
     * `lastreadid` where it was, and `readat` is the only thing that tells a reader apart from
     * a reaction nobody has looked at since.
     */
    public static function markRead(int $id, int $person, int $messageId): void
    {
        $read = self::member($id, $person)['lastReadId'] ?? null;
        if ($read === null) {
            return;
        }
        $update = ['readat' => time()];
        if (Messages::isAfter($messageId, $read)) {
            $update['lastreadid'] = $messageId;
        }
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)
            ->update($update);
    }

    /**
     * Mark a conversation unread from a message on: what the person has read goes back to the
     * top-level message before it — before its root, for a reply in a thread — or to nothing, even
     * when they had read further. Told on their own channel as `conversation.read`.
     */
    public static function markUnread(int $id, int $person, int $messageId): void
    {
        $message = Messages::get($messageId);
        if ($message === null || $message['conversationId'] !== $id) {
            throw new \InvalidArgumentException(t('That message no longer exists.'));
        }
        $from     = $message['threadRootId'] ?? $message['id'];
        $previous = Messages::inOrder(Messages::beyond(self::db()->queryBuilder()->table('chat.conversation_messages')->select(['messageid'])
            ->where('conversationid', $id)->whereNull('threadrootid'), (int) $from, false), 'desc')->limit(1)->getAllOrFail()[0]['messageid'] ?? 0;
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)
            ->update(['lastreadid' => (int) $previous]);
        Realtime::read($id, $person);
    }

    /**
     * What a person has not read in a conversation after a message: the top-level messages others
     * wrote, and the mentions of them, `@all` and `@online` among them.
     *
     * @return array{unread: int, mentions: int}
     */
    public static function counts(int $id, int $person, int $after): array
    {
        $db       = self::db();
        $position = Messages::position($after);
        $unread = (int) (Messages::inStream($db->queryBuilder()->table('chat.conversation_messages'))->select([new Expression('COUNT(*) AS n')])
            ->where('conversationid', $id)->where(static fn ($q) => Messages::beyondPosition($q, $after, $position))->whereNull('deletedat')
            ->where(static function ($q) use ($person): void {
                $q->whereNull('authorid')->orWhere('authorid', '!=', $person);
            })->getAllOrFail()[0]['n'] ?? 0);
        $mentions = (int) ($db->queryBuilder()->table('chat.conversation_mentions AS mn')
            ->join('chat.conversation_messages AS m', 'm.messageid', '=', 'mn.messageid')->select([new Expression('COUNT(*) AS n')])
            ->where('mn.conversationid', $id)->where(static fn ($q) => Messages::beyondPosition($q, $after, $position, true, 'm.'))
            ->where(static function ($q) use ($person): void {
                $q->where('mn.participantid', $person)->whereIn('mn.kind', ['all', 'online'], 'or');
            })->getAllOrFail()[0]['n'] ?? 0);

        return ['unread' => $unread, 'mentions' => $mentions];
    }

    /** Archive a conversation: read, no longer written to. */
    public static function archive(int $id, bool $archived = true): void
    {
        self::db()->queryBuilder()->table('chat.conversations')->where('conversationid', $id)->update(['archivedat' => $archived ? time() : null]);
    }

    /**
     * The conversations somebody is in, newest first, each with how many messages they have not
     * read and how many of those mention them — what a sidebar shows.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPerson(int $person): array
    {
        $db   = self::db();
        $rows = $db->queryBuilder()->table('chat.conversation_members AS m')
            ->join('chat.conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            // starorder where the migration has run: the list still opens before it.
            ->select(['c.*', 'm.lastreadid', 'm.muted', 'm.notify', 'm.starred', ...($db->schema()->hasColumn('chat.conversation_members', 'starorder') ? ['m.starorder'] : [])])
            ->where('m.participanttype', 'user')->where('m.participantid', $person)->whereNull('m.leftat')
            // An archived one leaves the lists: browse(…, archived: true) finds it.
            ->whereNull('c.archivedat')
            ->orderBy('c.lastmessageat', 'desc')->getAllOrFail();
        // A sidebar is asked for every few seconds, so its counts are read for all the conversations
        // at once — not two counts, two position lookups and the members per conversation, which
        // over a hypertable of many chunks cost seconds for a hundred conversations.
        $positions = Messages::positions(array_merge(array_column($rows, 'lastreadid'), array_column($rows, 'lastmessageid')));
        $unread    = self::unreadIn($rows, $person, $positions);
        $mentions  = self::mentionsIn($rows, $person, $positions);
        $personal  = array_map(static fn (array $r): int => (int) $r['conversationid'],
            array_filter($rows, static fn (array $r): bool => in_array($r['kind'], self::PERSONAL, true)));
        $members   = [];
        if ($personal !== []) {
            foreach (self::members_()->select(['conversationid', 'participantid'])->whereIn('conversationid', $personal)
                ->where('participanttype', 'user')->whereNull('leftat')->orderBy('participantid')->getAllOrFail() as $m) {
                $members[(int) $m['conversationid']][] = (int) $m['participantid'];
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['conversationid'];
            $out[] = self::row($row) + ['unread' => $unread[$id] ?? 0, 'mentions' => $mentions[$id] ?? 0, 'muted' => (bool) $row['muted'], 'starred' => (bool) ($row['starred'] ?? false),
                'starOrder' => !empty($row['starred']) && isset($row['starorder']) ? (int) $row['starorder'] : null, 'notify' => (string) $row['notify'],
                'lastReadId' => (int) $row['lastreadid'], 'members' => in_array($row['kind'], self::PERSONAL, true) ? ($members[$id] ?? []) : null];
        }

        return $out;
    }

    /**
     * {@see counts()}'s unread, for many conversations in one query: only those whose newest
     * message stands after what the person read are asked about, since the newest message is the
     * newest one a count could find.
     *
     * @param list<array<string, mixed>> $rows The person's memberships, with the conversation
     * @param array<int, array{at: string, id: int}> $positions
     * @return array<int, int> By conversation
     */
    private static function unreadIn(array $rows, int $person, array $positions): array
    {
        $behind = [];
        foreach ($rows as $row) {
            $read = (int) $row['lastreadid'];
            $last = (int) ($row['lastmessageid'] ?? 0);
            if ($last > 0 && Messages::standsAfter($last, $positions[$last] ?? null, $read, $positions[$read] ?? null)) {
                $behind[(int) $row['conversationid']] = $read;
            }
        }
        if ($behind === []) {
            return [];
        }
        $query = Messages::inStream(self::db()->queryBuilder()->table('chat.conversation_messages'))
            ->select(['conversationid', new Expression('COUNT(*) AS n')])
            ->whereIn('conversationid', array_keys($behind))->whereNull('deletedat')
            ->where(static function ($q) use ($person): void {
                $q->whereNull('authorid')->orWhere('authorid', '!=', $person);
            })
            ->where(static function ($q) use ($behind, $positions): void {
                foreach ($behind as $id => $read) {
                    $q->orWhere(static function ($one) use ($id, $read, $positions): void {
                        $one->where('conversationid', $id);
                        Messages::beyondPosition($one, $read, $positions[$read] ?? null);
                    });
                }
            })
            ->groupBy('conversationid');
        $out = [];
        foreach ($query->getAllOrFail() as $row) {
            $out[(int) $row['conversationid']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * {@see counts()}'s mentions, for many conversations in one query: the person's mentions,
     * `@all` and `@online`, from the oldest point any of them was read, counted here against
     * each conversation's own.
     *
     * @param list<array<string, mixed>> $rows The person's memberships, with the conversation
     * @param array<int, array{at: string, id: int}> $positions
     * @return array<int, int> By conversation
     */
    private static function mentionsIn(array $rows, int $person, array $positions): array
    {
        if ($rows === []) {
            return [];
        }
        $read  = [];
        $since = null;
        $bound = true;
        foreach ($rows as $row) {
            $id = (int) $row['lastreadid'];
            $read[(int) $row['conversationid']] = $id;
            if (!isset($positions[$id])) {
                // A conversation never read, or read up to no message: nothing bounds it by time.
                $bound = false;
            } elseif ($since === null || Messages::standsAfter(0, ['at' => $since, 'id' => 0], 0, ['at' => $positions[$id]['at'], 'id' => 0])) {
                $since = $positions[$id]['at'];
            }
        }
        $since = $bound ? $since : null;
        $query = self::db()->queryBuilder()->table('chat.conversation_mentions AS mn')
            ->join('chat.conversation_messages AS m', 'm.messageid', '=', 'mn.messageid')
            ->select(['mn.conversationid', 'm.messageid', 'm.created_at'])
            ->whereIn('mn.conversationid', array_keys($read))
            ->where(static function ($q) use ($person): void {
                $q->where('mn.participantid', $person)->whereIn('mn.kind', ['all', 'online'], 'or');
            });
        if (is_string($since)) {
            $query->where('m.created_at', '>=', $since);
        }
        $out = [];
        foreach ($query->getAllOrFail() as $row) {
            $conversation = (int) $row['conversationid'];
            $message      = (int) $row['messageid'];
            $last         = $read[$conversation];
            if (Messages::standsAfter($message, ['at' => (string) $row['created_at'], 'id' => $message], $last, $positions[$last] ?? null)) {
                $out[$conversation] = ($out[$conversation] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * The channels, private channels and groups two people are both in, by name.
     *
     * @return list<array{id: int, title: string}>
     */
    public static function shared(int $a, int $b): array
    {
        $ids = array_map('intval', array_column(self::db()->queryBuilder()->table('chat.conversation_members AS m')
            ->join('chat.conversation_members AS o', 'o.conversationid', '=', 'm.conversationid')
            ->join('chat.conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->whereIn('c.kind', ['channel', 'private', 'group'])->whereNull('c.archivedat')
            ->where('m.participanttype', 'user')->where('m.participantid', $a)->whereNull('m.leftat')
            ->where('o.participanttype', 'user')->where('o.participantid', $b)->whereNull('o.leftat')->getAllOrFail(), 'conversationid'));
        $names = self::names($ids);
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(static fn (int $id, string $title): array => ['id' => $id, 'title' => $title], array_keys($names), $names);
    }

    /**
     * The open conversations — channels and system ones — for somebody to browse and join, with
     * whether they are in each; or, with `$archived`, the archived ones they read, to open and
     * restore.
     *
     * @return list<array<string, mixed>>
     */
    public static function browse(int $person, bool $archived = false): array
    {
        $query = self::db()->queryBuilder()->table('chat.conversations');
        if ($archived) {
            // The archived ones the person reads: open, or private and theirs.
            $mine = array_column(self::members_()->select(['conversationid'])->where('participanttype', 'user')->where('participantid', $person)
                ->getAllOrFail(), 'conversationid');
            $query->whereNotNull('archivedat')->where(static fn ($q) => $q->whereIn('kind', self::OPEN)
                ->orWhere(static fn ($p) => $p->where('kind', 'private')->whereIn('conversationid', $mine === [] ? [0] : $mine)));
        } else {
            $query->whereIn('kind', self::OPEN)->whereNull('archivedat');
        }
        $rows = $query->orderBy('slug')->getAllOrFail();

        return array_map(static fn (array $row): array => self::row($row) + ['joined' => self::member((int) $row['conversationid'], $person) !== null,
            'memberCount' => count(self::members((int) $row['conversationid']))], $rows);
    }

    /** A name as a channel's name for `#`: lower case, words joined by dashes, letters of any script. */
    public static function slug(string $name): string
    {
        $slug = mb_strtolower(trim(ltrim($name, '#')));
        $slug = (string) preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $slug);

        return mb_substr(trim($slug, '-'), 0, 80);
    }

    /**
     * Put somebody in a conversation, whatever its kind and whoever asks — for code that knows
     * they were there: an import bringing back who wrote where. People choosing use `join()` and
     * `add()`, which ask.
     */
    public static function enroll(int $id, int $person): void
    {
        if (self::member($id, $person) === null) {
            self::addRow($id, $person, 'member');
        }
    }

    /** The conversation a person has with a bot of this name, or null. */
    public static function botFor(int $person, string $name): ?int
    {
        $rows = self::db()->queryBuilder()->table('chat.conversation_members AS m')
            ->join('chat.conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->where('c.kind', 'bot')->where('c.name', mb_substr(trim($name), 0, 150))
            ->where('m.participanttype', 'user')->where('m.participantid', $person)->limit(1)->getAllOrFail();

        return $rows === [] ? null : (int) $rows[0]['conversationid'];
    }

    /** The dm two people have — one person's own, when both are the same — or null. */
    public static function dmBetween(int $a, int $b): ?int
    {
        if ($a === $b) {
            return self::ownDm($a);
        }
        $rows = self::db()->queryBuilder()->table('chat.conversation_members AS m')
            ->join('chat.conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->where('c.kind', 'dm')->where('m.participanttype', 'user')->whereIn('m.participantid', [$a, $b])
            ->groupBy(['m.conversationid'])->havingRaw('COUNT(*) = 2')->limit(1)->getAllOrFail();

        return $rows === [] ? null : (int) $rows[0]['conversationid'];
    }

    /** A person's own dm — them alone, their notes — other than one, or null. */
    private static function ownDm(int $person, int $except = 0): ?int
    {
        $rows = self::db()->queryBuilder()->table('chat.conversation_members AS m')
            ->join('chat.conversation_members AS a', 'a.conversationid', '=', 'm.conversationid')
            ->join('chat.conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->where('c.kind', 'dm')->where('m.conversationid', '<>', $except)
            ->where('m.participanttype', 'user')->where('m.participantid', $person)
            ->groupBy(['m.conversationid'])->havingRaw('COUNT(*) = 1')->limit(1)->getAllOrFail();

        return $rows === [] ? null : (int) $rows[0]['conversationid'];
    }

    /**
     * The dm a person has with somebody who has no account here — by their id where they came
     * from, or by name — or null.
     */
    public static function dmWithFormer(int $person, string $former): ?int
    {
        $rows = self::db()->queryBuilder()->table('chat.conversation_members AS m')
            ->join('chat.conversation_members AS f', 'f.conversationid', '=', 'm.conversationid')
            ->join('chat.conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->where('c.kind', 'dm')->where('m.participanttype', 'user')->where('m.participantid', $person)
            ->where('f.participanttype', 'former')
            ->where(static fn ($q) => $q->where('f.externalid', $former)->orWhere(static fn ($n) => $n->whereNull('f.externalid')->where('f.name', $former)))
            ->limit(1)->getAllOrFail();

        return $rows === [] ? null : (int) $rows[0]['conversationid'];
    }

    /**
     * Somebody kept with no account here turns out to have one: wherever they are kept by this id,
     * the account takes their place — read up to the newest message, so years of history are not
     * unread — and the messages written as them become the account's. Where the account is in that
     * conversation already, the former member just goes. A dm that now duplicates one the two
     * accounts have is merged into the older of the two; a group stays a group. A second call finds
     * nothing to do.
     *
     * @return array{conversations: int, messages: int, merged: int}
     */
    public static function claimFormer(string $externalid, int $person, ?int $by = null): array
    {
        $done = ['conversations' => 0, 'messages' => 0, 'merged' => 0];
        if (trim($externalid) === '' || $person <= 0) {
            return $done;
        }
        $db = self::db();
        foreach (self::members_()->where('participanttype', 'former')->where('externalid', $externalid)->getAllOrFail() as $former) {
            $id   = (int) $former['conversationid'];
            $name = (string) $former['name'];
            // What they wrote: written with their name and no account.
            foreach ($db->queryBuilder()->table('chat.conversation_messages')->select(['messageid', 'card'])->where('conversationid', $id)
                ->whereNull('authorid')->whereNotNull('card')->getAllOrFail() as $message) {
                $card = json_decode((string) $message['card'], true);
                if (is_array($card) && !empty($card['former']) && ($card['from'] ?? null) === $name) {
                    $db->queryBuilder()->table('chat.conversation_messages')->where('messageid', (int) $message['messageid'])
                        ->update(['authortype' => 'user', 'authorid' => $person, 'card' => null]);
                    $done['messages']++;
                }
            }
            $row = self::members_()->where('conversationid', $id)->where('participanttype', 'former')->where('participantid', (int) $former['participantid']);
            if (self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)->limit(1)->getAllOrFail() !== []) {
                $row->delete();
                self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)->update(['leftat' => null]);
            } else {
                $row->update(['participanttype' => 'user', 'participantid' => $person, 'name' => null, 'externalid' => null, 'muted' => 0, 'notify' => 'all',
                    'lastreadid' => (int) (self::get($id)['lastMessageId'] ?? 0)]);
            }
            $done['conversations']++;
            $kept = $id;
            $conversation = self::get($id);
            $other = array_values(array_diff(self::members($id), [$person]))[0] ?? null;
            if ($conversation !== null && $conversation['kind'] === 'dm') {
                // Somebody claiming who they were in a dm with themselves: that dm is now their own.
                $twin = $other === null ? self::ownDm($person, $id) : self::db()->queryBuilder()->table('chat.conversation_members AS m')
                    ->join('chat.conversation_members AS o', 'o.conversationid', '=', 'm.conversationid')
                    ->join('chat.conversations AS c', 'c.conversationid', '=', 'm.conversationid')
                    ->select(['m.conversationid'])->where('c.kind', 'dm')->where('m.conversationid', '<>', $id)
                    ->where('m.participanttype', 'user')->where('m.participantid', $person)
                    ->where('o.participanttype', 'user')->where('o.participantid', $other)->limit(1)->getAllOrFail()[0]['conversationid'] ?? null;
                if ($twin !== null) {
                    $kept = self::merge(min($id, (int) $twin), max($id, (int) $twin));
                    $done['merged']++;
                }
            }
            \Pramnos\Auth\AuditLog::about('conversations.former_claimed', $by, 'user', (string) $person,
                ['externalid' => $externalid, 'name' => $name, 'conversation' => $id, 'kept' => $kept], 'conversation', $kept);
            Realtime::conversationChanged($kept, self::members($kept), $kept === $id ? null : $id);
        }

        return $done;
    }

    /**
     * Put the newer of two dms into the older: its messages, mentions, pins and files, each
     * member's reading the further of the two, and the newer gone.
     *
     * The moved messages keep their ids; a conversation lists by when each was written, so they
     * interleave with the older dm's.
     */
    private static function merge(int $older, int $newer): int
    {
        $db = self::db();
        foreach (['conversation_messages', 'conversation_mentions', 'conversation_pins', 'conversation_files'] as $table) {
            $db->queryBuilder()->table('chat.' . $table)->where('conversationid', $newer)->update(['conversationid' => $older]);
        }
        foreach (self::members_()->where('conversationid', $newer)->where('participanttype', 'user')->getAllOrFail() as $member) {
            self::markRead($older, (int) $member['participantid'], (int) $member['lastreadid']);
        }
        $a = self::get($older);
        $b = self::get($newer);
        if ($b['lastMessageId'] !== null && ($a['lastMessageId'] === null || Messages::isAfter($b['lastMessageId'], $a['lastMessageId']))) {
            $db->queryBuilder()->table('chat.conversations')->where('conversationid', $older)
                ->update(['lastmessageid' => $b['lastMessageId'], 'lastmessageat' => $b['lastMessageAt']]);
        }
        self::members_()->where('conversationid', $newer)->delete();
        $db->queryBuilder()->table('chat.conversations')->where('conversationid', $newer)->delete();

        return $older;
    }

    /**
     * The members with no account here, by conversation: what each was called.
     *
     * @param list<int> $ids
     * @return array<int, list<array{former: true, name: string}>>
     */
    public static function formers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (self::members_()->select(['conversationid', 'name'])->whereIn('conversationid', $ids)->where('participanttype', 'former')
            ->orderBy('participantid', 'desc')->getAllOrFail() as $row) {
            $out[(int) $row['conversationid']][] = ['former' => true, 'name' => (string) $row['name']];
        }

        return $out;
    }

    /**
     * Put somebody in, or back in — back in with the setting they had. They are notified of
     * everything in a direct, group or bot conversation, and of what concerns them in any other,
     * as Slack does ({@see Notify}).
     */
    private static function addRow(int $id, int $person, string $role): void
    {
        $now    = time();
        $notify = in_array(self::get($id)['kind'] ?? '', self::PERSONAL, true) ? 'all' : 'mentions';
        self::members_()->insertOrIgnore(['conversationid' => $id, 'participanttype' => 'user', 'participantid' => $person, 'role' => $role,
            'lastreadid' => 0, 'muted' => 0, 'notify' => $notify, 'joinedat' => $now]);
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)
            ->whereNotNull('leftat')->update(['leftat' => null, 'joinedat' => $now]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function row(array $row): array
    {
        $int = static fn ($v): ?int => $v === null ? null : (int) $v;

        return ['id' => (int) $row['conversationid'], 'kind' => (string) $row['kind'], 'slug' => $row['slug'] === null ? null : (string) $row['slug'],
            'name' => $row['name'] === null ? null : (string) $row['name'], 'topic' => $row['topic'] === null ? null : (string) $row['topic'],
            'retentionDays' => $int($row['retentiondays']), 'createdBy' => $int($row['createdby']), 'createdAt' => (int) $row['createdat'],
            'archivedAt' => $int($row['archivedat']), 'lastMessageId' => $int($row['lastmessageid']), 'lastMessageAt' => $int($row['lastmessageat']),
            'image' => Images::url($row), 'imageOwn' => (string) ($row['image'] ?? '') !== '',
            // Its details, where the migration has run (FW-369): the defaults before it.
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'suggestMembers' => !array_key_exists('suggestmembers', $row) || (bool) $row['suggestmembers'],
            'tabsBy' => (string) ($row['tabsby'] ?? 'everyone')]
            // The analyst's own conversation: shown by its name and picture, kept under Ask::NAME.
            + ($row['kind'] === 'bot' && $row['name'] === Ask::NAME
                ? ['displayName' => \Pramnos\Llm\Analyst\Analyst::name(), 'avatar' => \Pramnos\Llm\Analyst\Analyst::avatar()] : []);
    }

    /** The members table. */
    private static function members_(): \Pramnos\Database\QueryBuilder
    {
        return self::db()->queryBuilder()->table('chat.conversation_members');
    }

    /**
     * Whether the conversations are installed here — their tables made — for code of the
     * application's that asks before it reads them.
     */
    public static function installed(): bool
    {
        try {
            return self::db()->schema()->hasTable('chat.conversations');
        } catch (\Throwable) {
            return false;
        }
    }

    /** The application whose `conversations` block {@see settings()} reads where the one running has none. */
    public const AREA = 'admin';

    /** @var array<string, mixed>|null The area's application settings, read once a process */
    private static ?array $area = null;

    /**
     * The `conversations` settings: this application's block, or — in a process run as another
     * application, a queue worker or the scheduler, whose configuration has none — the block of
     * the administration's (`app/admin.php`, {@see AREA}), where the conversations are. A worker
     * notifying of a message, or the scheduler sending one, keeps the same `quiet` hook, groups and
     * rules as the page that wrote it.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $app = \Pramnos\Application\Application::currentInstance();
        $own = is_object($app) ? ($app->applicationInfo['conversations'] ?? null) : null;
        if (is_array($own)) {
            return $own;
        }
        self::$area ??= \Pramnos\Application\Application::readApplicationConfig(self::AREA) ?? [];

        return is_array(self::$area['conversations'] ?? null) ? self::$area['conversations'] : [];
    }

    /**
     * The area's application settings ({@see settings()}): `[]` where there is no such file. A
     * test hands its own, or null to read the file again.
     *
     * @param array<string, mixed>|null $settings
     */
    public static function useArea(?array $settings): void
    {
        self::$area = $settings;
    }

    /** The area's own administration prefix (`admin.prefix` of `app/admin.php`), '' without one. */
    public static function areaPrefix(): string
    {
        self::settings();

        return trim((string) (self::$area['admin']['prefix'] ?? ''), '/');
    }

    /** The application's connection. */
    public static function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
