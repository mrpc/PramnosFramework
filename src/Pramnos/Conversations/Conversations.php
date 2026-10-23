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
     * have one already is that one.
     *
     * @param list<int> $members Accounts in it besides its maker
     */
    public static function create(string $kind, ?string $name, array $members = [], ?int $by = null, string $topic = ''): int
    {
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
            if (count($people) !== 2) {
                throw new \InvalidArgumentException(t('A direct conversation is between two people.'));
            }
            $existing = self::dmBetween($people[0], $people[1]);
            if ($existing !== null) {
                return $existing;
            }
        } elseif (count($people) < 3 || count($people) > self::GROUP_MAX) {
            throw new \InvalidArgumentException(t('A group conversation has three to %s people.', (string) self::GROUP_MAX));
        }
        $now = time();
        $db  = self::db();
        $db->queryBuilder()->table('#PREFIX#conversations')->insert([
            'kind' => $kind, 'slug' => $slug, 'name' => $name === null ? null : mb_substr(trim($name), 0, 150),
            'topic' => $topic === '' ? null : $topic, 'createdby' => $by, 'createdat' => $now,
        ]);
        $id = (int) $db->getInsertId();
        foreach ($people as $i => $person) {
            self::addRow($id, $person, $by !== null && $i === 0 ? 'owner' : 'member');
        }
        \Pramnos\Event\Event::dispatch(new ConversationCreated($id, $kind, $by));

        return $id;
    }

    /**
     * A conversation, or null.
     *
     * @return array{id: int, kind: string, slug: ?string, name: ?string, topic: ?string, retentionDays: ?int, createdBy: ?int, createdAt: int, archivedAt: ?int, lastMessageId: ?int, lastMessageAt: ?int}|null
     */
    public static function get(int $id): ?array
    {
        $row = self::db()->queryBuilder()->table('#PREFIX#conversations')->where('conversationid', $id)->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /** A channel by its name for `#`, or null. @return array<string, mixed>|null */
    public static function bySlug(string $slug): ?array
    {
        $row = self::db()->queryBuilder()->table('#PREFIX#conversations')->where('slug', self::slug($slug))->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? null : self::row($row);
    }

    /** Join an open conversation — a channel, a system one. */
    public static function join(int $id, int $person): void
    {
        $conversation = self::get($id) ?? throw new \InvalidArgumentException(t('That conversation no longer exists.'));
        if (!in_array($conversation['kind'], self::OPEN, true)) {
            throw new \InvalidArgumentException(t('This conversation is joined by being added to it.'));
        }
        self::addRow($id, $person, 'member');
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
        self::addRow($id, $person, 'member');
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
        $open = array_column(self::db()->queryBuilder()->table('#PREFIX#conversations')->select(['conversationid'])->whereIn('kind', self::OPEN)
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
        foreach (self::db()->queryBuilder()->table('#PREFIX#conversations')->whereIn('conversationid', $ids)->getAllOrFail() as $row) {
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
            $names[$id] = implode(', ', array_filter($people));
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

    /** Star a conversation for somebody, or not: their sidebar shows it first. */
    public static function star(int $id, int $person, bool $starred = true): void
    {
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)->update(['starred' => $starred ? 1 : 0]);
    }

    /** Somebody has read up to a message: never back. */
    public static function markRead(int $id, int $person, int $messageId): void
    {
        self::members_()->where('conversationid', $id)->where('participanttype', 'user')->where('participantid', $person)
            ->where('lastreadid', '<', $messageId)->update(['lastreadid' => $messageId]);
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
        $previous = self::db()->queryBuilder()->table('#PREFIX#conversation_messages')->select(['messageid'])->where('conversationid', $id)
            ->whereNull('threadrootid')->where('messageid', '<', $from)->orderBy('messageid', 'desc')->limit(1)->getAllOrFail()[0]['messageid'] ?? 0;
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
        $db = self::db();
        $unread = (int) (Messages::inStream($db->queryBuilder()->table('#PREFIX#conversation_messages'))->select([new Expression('COUNT(*) AS n')])
            ->where('conversationid', $id)->where('messageid', '>', $after)->whereNull('deletedat')
            ->where(static function ($q) use ($person): void {
                $q->whereNull('authorid')->orWhere('authorid', '!=', $person);
            })->getAllOrFail()[0]['n'] ?? 0);
        $mentions = (int) ($db->queryBuilder()->table('#PREFIX#conversation_mentions')->select([new Expression('COUNT(*) AS n')])
            ->where('conversationid', $id)->where('messageid', '>', $after)
            ->where(static function ($q) use ($person): void {
                $q->where('participantid', $person)->whereIn('kind', ['all', 'online'], 'or');
            })->getAllOrFail()[0]['n'] ?? 0);

        return ['unread' => $unread, 'mentions' => $mentions];
    }

    /** Archive a conversation: read, no longer written to. */
    public static function archive(int $id, bool $archived = true): void
    {
        self::db()->queryBuilder()->table('#PREFIX#conversations')->where('conversationid', $id)->update(['archivedat' => $archived ? time() : null]);
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
        $rows = $db->queryBuilder()->table('#PREFIX#conversation_members AS m')
            ->join('#PREFIX#conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['c.*', 'm.lastreadid', 'm.muted', 'm.notify', 'm.starred'])
            ->where('m.participanttype', 'user')->where('m.participantid', $person)->whereNull('m.leftat')
            ->orderBy('c.lastmessageat', 'desc')->getAllOrFail();
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['conversationid'];
            ['unread' => $unread, 'mentions' => $mentions] = self::counts($id, $person, (int) $row['lastreadid']);
            $out[] = self::row($row) + ['unread' => $unread, 'mentions' => $mentions, 'muted' => (bool) $row['muted'], 'starred' => (bool) ($row['starred'] ?? false), 'notify' => (string) $row['notify'],
                'lastReadId' => (int) $row['lastreadid'], 'members' => in_array($row['kind'], self::PERSONAL, true) ? self::members($id) : null];
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
        $ids = array_map('intval', array_column(self::db()->queryBuilder()->table('#PREFIX#conversation_members AS m')
            ->join('#PREFIX#conversation_members AS o', 'o.conversationid', '=', 'm.conversationid')
            ->join('#PREFIX#conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->whereIn('c.kind', ['channel', 'private', 'group'])->whereNull('c.archivedat')
            ->where('m.participanttype', 'user')->where('m.participantid', $a)->whereNull('m.leftat')
            ->where('o.participanttype', 'user')->where('o.participantid', $b)->whereNull('o.leftat')->getAllOrFail(), 'conversationid'));
        $names = self::names($ids);
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(static fn (int $id, string $title): array => ['id' => $id, 'title' => $title], array_keys($names), $names);
    }

    /**
     * The open conversations — channels and system ones — for somebody to browse and join, with
     * whether they are in each.
     *
     * @return list<array<string, mixed>>
     */
    public static function browse(int $person): array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#conversations')->whereIn('kind', self::OPEN)->whereNull('archivedat')
            ->orderBy('slug')->getAllOrFail();

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
        $rows = self::db()->queryBuilder()->table('#PREFIX#conversation_members AS m')
            ->join('#PREFIX#conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->where('c.kind', 'bot')->where('c.name', mb_substr(trim($name), 0, 150))
            ->where('m.participanttype', 'user')->where('m.participantid', $person)->limit(1)->getAllOrFail();

        return $rows === [] ? null : (int) $rows[0]['conversationid'];
    }

    /** The dm two people have, or null. */
    public static function dmBetween(int $a, int $b): ?int
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#conversation_members AS m')
            ->join('#PREFIX#conversations AS c', 'c.conversationid', '=', 'm.conversationid')
            ->select(['m.conversationid'])->where('c.kind', 'dm')->where('m.participanttype', 'user')->whereIn('m.participantid', [$a, $b])
            ->groupBy(['m.conversationid'])->havingRaw('COUNT(*) = 2')->limit(1)->getAllOrFail();

        return $rows === [] ? null : (int) $rows[0]['conversationid'];
    }

    /** Put somebody in, or back in. */
    private static function addRow(int $id, int $person, string $role): void
    {
        $now = time();
        self::members_()->insertOrIgnore(['conversationid' => $id, 'participanttype' => 'user', 'participantid' => $person, 'role' => $role,
            'lastreadid' => 0, 'muted' => 0, 'notify' => 'all', 'joinedat' => $now]);
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
            'archivedAt' => $int($row['archivedat']), 'lastMessageId' => $int($row['lastmessageid']), 'lastMessageAt' => $int($row['lastmessageat'])]
            // The analyst's own conversation: shown by its name and picture, kept under Ask::NAME.
            + ($row['kind'] === 'bot' && $row['name'] === Ask::NAME
                ? ['displayName' => \Pramnos\Llm\Analyst\Analyst::name(), 'avatar' => \Pramnos\Llm\Analyst\Analyst::avatar()] : []);
    }

    /** The members table. */
    private static function members_(): \Pramnos\Database\QueryBuilder
    {
        return self::db()->queryBuilder()->table('#PREFIX#conversation_members');
    }

    /** The application's connection. */
    public static function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
