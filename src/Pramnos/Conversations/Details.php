<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * A channel's details, as Slack's "Channel details" keeps them: its name, topic and description,
 * its members and their roles, private or public, archived, deleted for good, its settings, and the
 * links and files said in it.
 *
 * ```php
 * Details::describe($id, ['topic' => 'Q4 launch', 'description' => 'Everything about the launch'], $by);
 * Details::setPrivate($id, true, $by);              // channel → private
 * Details::archive($id, true, $by);                 // for everyone: off the lists, read only
 * Details::remove($id, $person, $by);
 * Details::setRole($id, $person, 'admin', $by);     // owner, admin, member
 * Details::roster($id);                             // who, with their role and since when
 * Details::settings($id, ['suggestMembers' => false, 'tabsBy' => 'admins'], $by);
 * Details::links($id, q: 'figma', before: null);    // newest first
 * Details::destroy($id, $superuser);                // messages, files on disk, members — gone
 * ```
 *
 * **Who:** the channel's owner, one of its admins, or a moderator ({@see Access::moderates()}). A
 * group is changed by any of its members, as its name always was. A direct or bot conversation has
 * none of these. Deleting for good is a superuser's alone, and never a system channel's.
 *
 * Each change is a line in the conversation, an event in the changelog, and `conversation.changed`
 * to its members.
 */
final class Details
{
    /** A member's roles: the owner, an admin of the channel, a member. */
    public const ROLES = ['owner', 'admin', 'member'];

    /** Who manages a channel's tabs. */
    public const TABS_BY = ['everyone', 'admins'];

    /** The longest description. */
    public const DESCRIPTION = 250;

    /** The longest topic. */
    public const TOPIC = 250;

    /** The most links or files one page gives. */
    public const PAGE = 100;

    /** The kinds with details: the channels, the system ones, and groups. */
    private const KINDS = ['channel', 'private', 'system', 'group'];

    /**
     * Rename a channel: a new `#name`, unique, made as {@see Conversations::create()} makes one. A
     * system channel keeps its `#name` — the application finds it by it — and only the name shown
     * changes.
     *
     * @param array<string, mixed> $conversation
     * @throws \DomainException For somebody who may not
     * @throws \InvalidArgumentException For an empty name, or one another channel has
     */
    public static function renameChannel(array $conversation, string $name, int $by): void
    {
        $id = (int) $conversation['id'];
        self::mayManage($conversation, $by);
        $name = mb_substr(trim($name), 0, 150);
        if ($conversation['kind'] === 'system') {
            if ($name === '' || $name === $conversation['name']) {
                return;
            }
            self::table()->where('conversationid', $id)->update(['name' => $name]);
            self::told($id, sprintf(t('%s renamed the channel to “%s”.'), self::who($by), $name), 'conversation.renamed',
                ['from' => $conversation['name'], 'to' => $name], $by);

            return;
        }
        $slug = Conversations::slug($name);
        if ($slug === '') {
            throw new \InvalidArgumentException(t('A channel needs a name.'));
        }
        if ($slug === $conversation['slug']) {
            return;
        }
        $other = Conversations::bySlug($slug);
        if ($other !== null && $other['id'] !== $id) {
            throw new \InvalidArgumentException(t('There is a channel called #%s already.', $slug));
        }
        self::table()->where('conversationid', $id)->update(['slug' => $slug, 'name' => $name]);
        self::told($id, sprintf(t('%s renamed the channel to #%s.'), self::who($by), $slug), 'conversation.renamed',
            ['from' => $conversation['slug'], 'to' => $slug], $by);
    }

    /**
     * Set a channel's topic and description — each given, '' or null clearing it.
     *
     * @param array{topic?: ?string, description?: ?string} $changes
     */
    public static function describe(int $id, array $changes, int $by): void
    {
        $conversation = self::changeable($id, $by);
        $row = [];
        foreach (['topic' => self::TOPIC, 'description' => self::DESCRIPTION] as $field => $most) {
            if (array_key_exists($field, $changes)) {
                $value = trim((string) $changes[$field]);
                $value = $value === '' ? null : mb_substr($value, 0, $most);
                if ($value !== $conversation[$field]) {
                    $row[$field] = $value;
                }
            }
        }
        if ($row === []) {
            return;
        }
        self::table()->where('conversationid', $id)->update($row);
        $who = self::who($by);
        if (array_key_exists('topic', $row)) {
            Messages::post($id, null, $row['topic'] === null ? sprintf(t('%s cleared the topic.'), $who)
                : sprintf(t('%s set the topic: %s'), $who, $row['topic']), ['notice' => true]);
        }
        if (array_key_exists('description', $row)) {
            Messages::post($id, null, sprintf(t('%s changed the description.'), $who), ['notice' => true]);
        }
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $id, 'conversation.described', $row, by: $by);
        Realtime::conversationChanged($id, Conversations::members($id));
    }

    /**
     * A channel made private, or public again. A system channel stays open; a group, a direct and
     * a bot conversation are neither.
     */
    public static function setPrivate(int $id, bool $private, int $by): void
    {
        $conversation = self::changeable($id, $by);
        if (!in_array($conversation['kind'], ['channel', 'private'], true)) {
            throw new \DomainException(t('Only a channel is made private or public.'));
        }
        $kind = $private ? 'private' : 'channel';
        if ($kind === $conversation['kind']) {
            return;
        }
        self::table()->where('conversationid', $id)->update(['kind' => $kind]);
        self::told($id, sprintf($private ? t('%s made the channel private.') : t('%s made the channel public.'), self::who($by)),
            'conversation.privacy', ['from' => $conversation['kind'], 'to' => $kind], $by);
    }

    /**
     * Archive a channel for everyone — off the lists, read and not written — or bring it back.
     * {@see Conversations::browse()} with `archived` finds it.
     */
    public static function archive(int $id, bool $archived, int $by): void
    {
        $conversation = self::changeable($id, $by);
        if ($archived === ($conversation['archivedAt'] !== null)) {
            return;
        }
        $line = sprintf($archived ? t('%s archived the channel.') : t('%s brought the channel back from the archive.'), self::who($by));
        // The line written while it can still be: before archiving, after bringing back.
        if ($archived) {
            Messages::post($id, null, $line, ['notice' => true]);
        }
        Conversations::archive($id, $archived);
        if (!$archived) {
            Messages::post($id, null, $line, ['notice' => true]);
        }
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $id, $archived ? 'conversation.archived' : 'conversation.unarchived', [], by: $by);
        Realtime::conversationChanged($id, Conversations::members($id));
    }

    /**
     * Take somebody out of a channel or a group. Never its last owner, and never from a direct
     * conversation.
     */
    public static function remove(int $id, int $person, int $by): void
    {
        self::changeable($id, $by);
        $member = Conversations::member($id, $person) ?? throw new \InvalidArgumentException(t('They are not in it.'));
        if ($member['role'] === 'owner' && self::owners($id) === [$person]) {
            throw new \InvalidArgumentException(t('Its last owner stays: make somebody else its owner first.'));
        }
        $people = Conversations::members($id);
        Conversations::leave($id, $person);
        self::told($id, sprintf(t('%s removed %s.'), self::who($by), self::who($person)), 'conversation.member_removed', ['person' => $person], $by, $people);
    }

    /** Make a member its owner, an admin of it, or a member; never leaving it without an owner. */
    public static function setRole(int $id, int $person, string $role, int $by): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException(t('A member is its owner, an admin or a member.'));
        }
        $conversation = self::changeable($id, $by);
        if ($conversation['kind'] === 'group') {
            throw new \DomainException(t('A group\'s members have no roles.'));
        }
        $member = Conversations::member($id, $person) ?? throw new \InvalidArgumentException(t('They are not in it.'));
        if ($member['role'] === $role) {
            return;
        }
        if ($member['role'] === 'owner' && self::owners($id) === [$person]) {
            throw new \InvalidArgumentException(t('Its last owner stays: make somebody else its owner first.'));
        }
        Conversations::db()->queryBuilder()->table('chat.conversation_members')->where('conversationid', $id)->where('participanttype', 'user')
            ->where('participantid', $person)->update(['role' => $role]);
        $said = match ($role) {
            'owner' => t('%s made %s an owner of the channel.'),
            'admin' => t('%s made %s an admin of the channel.'),
            default => t('%s made %s a member of the channel.'),
        };
        self::told($id, sprintf($said, self::who($by), self::who($person)), 'conversation.role', ['person' => $person, 'from' => $member['role'], 'to' => $role], $by);
    }

    /**
     * Who is in a conversation: their name, role and since when — owners first, then admins, then
     * by name.
     *
     * @return list<array{id: int, name: string, role: string, joinedAt: int}>
     */
    public static function roster(int $id): array
    {
        $rows  = Conversations::db()->queryBuilder()->table('chat.conversation_members')->select(['participantid', 'role', 'joinedat'])
            ->where('conversationid', $id)->where('participanttype', 'user')->whereNull('leftat')->getAllOrFail();
        $names = People::names(array_map(static fn (array $r): int => (int) $r['participantid'], $rows));
        $out   = array_map(static fn (array $r): array => ['id' => (int) $r['participantid'], 'name' => $names[(int) $r['participantid']] ?? '#' . $r['participantid'],
            'role' => (string) $r['role'], 'joinedAt' => (int) $r['joinedat']], $rows);
        $rank  = array_flip(self::ROLES);
        usort($out, static fn (array $a, array $b): int => [$rank[$a['role']] ?? 3, mb_strtolower($a['name'])] <=> [$rank[$b['role']] ?? 3, mb_strtolower($b['name'])]);

        return $out;
    }

    /**
     * A channel's settings: `suggestMembers` (a mention of somebody not in it offers to add them)
     * and `tabsBy` (who manages its tabs: everyone, or its admins).
     *
     * @param array{suggestMembers?: bool, tabsBy?: string} $settings
     */
    public static function settings(int $id, array $settings, int $by): void
    {
        self::changeable($id, $by);
        $row = [];
        if (array_key_exists('suggestMembers', $settings)) {
            $row['suggestmembers'] = (bool) $settings['suggestMembers'];
        }
        if (array_key_exists('tabsBy', $settings)) {
            if (!in_array($settings['tabsBy'], self::TABS_BY, true)) {
                throw new \InvalidArgumentException(t('Tabs are managed by everyone or by its admins.'));
            }
            $row['tabsby'] = (string) $settings['tabsBy'];
        }
        if ($row === []) {
            return;
        }
        self::table()->where('conversationid', $id)->update($row);
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $id, 'conversation.settings', $row, by: $by);
        Realtime::conversationChanged($id, Conversations::members($id));
    }

    /**
     * The people a message mentions who are not in its channel, where the channel suggests them:
     * the writer is offered to add them. Nobody for a direct, group or bot conversation, nor for
     * an open channel anyone joins by reading.
     *
     * @param list<int> $mentioned
     * @return list<int>
     */
    public static function notMembers(int $id, array $mentioned): array
    {
        $conversation = Conversations::get($id);
        if ($conversation === null || !in_array($conversation['kind'], ['channel', 'private'], true) || !$conversation['suggestMembers']) {
            return [];
        }
        $in = Conversations::members($id);

        return array_values(array_filter(array_unique(array_map('intval', $mentioned)), static fn (int $p): bool => $p > 0 && !in_array($p, $in, true)));
    }

    /**
     * The links said in a conversation, newest first: each address with its host and path, its
     * card's title and image where it has one, the message, who and when. `q` matches the address
     * or the title; `before` a message id, for the next page.
     *
     * @return list<array{url: string, host: string, path: string, title: ?string, image: ?string, messageId: int, authorId: ?int, at: int}>
     */
    public static function links(int $id, string $q = '', ?int $before = null, int $limit = 50): array
    {
        $limit = max(1, min(self::PAGE, $limit));
        $q     = mb_strtolower(trim($q));
        $out   = [];
        $last  = $before;
        // Read in pages until enough links are found: a page of messages may say none.
        for ($round = 0; $round < 10 && count($out) < $limit; $round++) {
            $db    = Conversations::db();
            $query = $db->queryBuilder()->table('chat.conversation_messages')->select(['messageid', 'authorid', 'body', 'unfurls', \Pramnos\Database\Instant::unix($db, 'created_at', 'at_unix')])
                ->where('conversationid', $id)->whereNull('deletedat')->where('body', 'LIKE', '%http%')->orderBy('messageid', 'desc')->limit(200);
            if ($last !== null) {
                $query->where('messageid', '<', $last);
            }
            $rows = $query->getAllOrFail();
            if ($rows === []) {
                break;
            }
            $cards = Unfurls::cards(array_combine(array_map(static fn (array $r): int => (int) $r['messageid'], $rows),
                array_map(static fn (array $r): array => Unfurls::decode($r['unfurls'] ?? null), $rows)));
            foreach ($rows as $row) {
                $message = (int) $row['messageid'];
                $last    = $message;
                preg_match_all('#https?://[^\s<>()\[\]"\'`]+#i', (string) $row['body'], $m);
                $byUrl = array_column($cards[$message] ?? [], null, 'url');
                foreach (array_unique(array_map(static fn (string $u): string => rtrim($u, '.,;:!?*_~'), $m[0])) as $url) {
                    $card  = $byUrl[$url] ?? null;
                    $title = isset($card['title']) ? (string) $card['title'] : null;
                    if ($q !== '' && !str_contains(mb_strtolower($url . ' ' . $title), $q)) {
                        continue;
                    }
                    $out[] = ['url' => $url, 'host' => (string) parse_url($url, PHP_URL_HOST), 'path' => (string) (parse_url($url, PHP_URL_PATH) ?? ''),
                        'title' => $title, 'image' => isset($card['image']['url']) ? (string) $card['image']['url'] : null, 'messageId' => $message,
                        'authorId' => $row['authorid'] === null ? null : (int) $row['authorid'], 'at' => (int) $row['at_unix']];
                    if (count($out) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Delete a conversation for good: its messages, their reactions, mentions, pins and saves, its
     * files and their files on disk, what was scheduled and drafted in it, its canvases, its members,
     * its picture.
     * A superuser's alone; never a system channel, which the application finds by its name.
     *
     * @return array{messages: int, files: int}
     */
    public static function destroy(int $id, object $by): array
    {
        $conversation = Conversations::get($id) ?? throw new \DomainException(t('That conversation no longer exists.'));
        if (!Access::canUse($by) || (int) ($by->usertype ?? 0) < \Pramnos\Auth\AdminAccess::superuserUsertype()) {
            throw new \DomainException(t('Only a superuser deletes a conversation for good.'));
        }
        if ($conversation['kind'] === 'system') {
            throw new \DomainException(t('A system channel is not deleted: the application writes to it.'));
        }
        $db     = Conversations::db();
        $q      = static fn (string $table) => $db->queryBuilder()->table($table);
        $people = Conversations::members($id);
        $files  = $q('chat.conversation_files')->select(['path', 'thumbpath'])->where('conversationid', $id)->getAllOrFail();
        foreach ($files as $file) {
            foreach ([$file['path'], $file['thumbpath']] as $path) {
                if ($path !== null && $path !== '') {
                    @unlink(Files::root() . '/' . $path);
                }
            }
        }
        $messages = array_map(static fn (array $r): int => (int) $r['messageid'], $q('chat.conversation_messages')->select(['messageid'])->where('conversationid', $id)->getAllOrFail());
        foreach (array_chunk($messages, 500) as $chunk) {
            $q('chat.conversation_reactions')->whereIn('messageid', $chunk)->delete();
            $q('chat.conversation_saved')->whereIn('messageid', $chunk)->delete();
        }
        foreach (['chat.conversation_mentions', 'chat.conversation_pins', 'chat.conversation_files', 'chat.conversation_scheduled',
            'chat.conversation_drafts', 'chat.conversation_canvases', 'chat.conversation_messages', 'chat.conversation_members'] as $table) {
            if ($db->schema()->hasTable($table)) {
                $q($table)->where('conversationid', $id)->delete();
            }
        }
        $image = (string) ($q('chat.conversations')->where('conversationid', $id)->value('image') ?? '');
        if ($image !== '') {
            @unlink(Files::root() . '/' . $image);
        }
        $q('chat.conversations')->where('conversationid', $id)->delete();
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $id, 'conversation.destroyed',
            ['kind' => $conversation['kind'], 'name' => $conversation['slug'] ?? $conversation['name'], 'messages' => count($messages), 'files' => count($files)], by: (int) $by->userid);
        Realtime::conversationChanged($id, $people);

        return ['messages' => count($messages), 'files' => count($files)];
    }

    /**
     * A conversation somebody may change the details of — its owner, an admin, a moderator; any
     * member of a group.
     *
     * @return array<string, mixed>
     * @throws \DomainException
     */
    private static function changeable(int $id, int $by): array
    {
        $conversation = Conversations::get($id) ?? throw new \DomainException(t('That conversation no longer exists.'));
        self::mayManage($conversation, $by);

        return $conversation;
    }

    /** @param array<string, mixed> $conversation @throws \DomainException */
    private static function mayManage(array $conversation, int $by): void
    {
        if (!in_array($conversation['kind'], self::KINDS, true)) {
            throw new \DomainException(t('A direct conversation has no details to change.'));
        }
        $may = $conversation['kind'] === 'group'
            ? Conversations::member((int) $conversation['id'], $by) !== null
            : Access::moderates(new \Pramnos\User\User($by), (int) $conversation['id']);
        if (!$may) {
            throw new \DomainException(t('Only its owner, an admin of it or a moderator changes that.'));
        }
    }

    /** Its owners, by account id. @return list<int> */
    private static function owners(int $id): array
    {
        return array_map('intval', array_column(Conversations::db()->queryBuilder()->table('chat.conversation_members')->select(['participantid'])
            ->where('conversationid', $id)->where('participanttype', 'user')->where('role', 'owner')->whereNull('leftat')->getAllOrFail(), 'participantid'));
    }

    /**
     * Say a change: a line in the conversation, the changelog, its members told.
     *
     * @param array<string, mixed> $data
     * @param list<int>|null $people Who to tell, when not who is in it now
     */
    private static function told(int $id, string $line, string $event, array $data, int $by, ?array $people = null): void
    {
        Messages::post($id, null, $line, ['notice' => true]);
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $id, $event, $data, by: $by);
        Realtime::conversationChanged($id, $people ?? Conversations::members($id));
    }

    /** A person's name. */
    private static function who(int $person): string
    {
        return People::names([$person])[$person] ?? '#' . $person;
    }

    /** The conversations table. */
    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return Conversations::db()->queryBuilder()->table('chat.conversations');
    }
}
