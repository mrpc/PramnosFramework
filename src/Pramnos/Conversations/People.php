<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * People as the conversations name them: first and last name, or the username where there is none;
 * and who is inactive — has not signed in for longer than `conversations.inactive_after_days`.
 *
 * Somebody inactive is not offered for a new conversation or an `@`, unless asked for by their whole
 * name or with inactive people asked for too; is not counted in `@all` or `@online`; and is shown
 * greyed where they already are. Nothing is removed: they are active again when they sign in.
 */
final class People
{
    /**
     * The names of accounts, by id.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (Conversations::db()->queryBuilder()->table(\Pramnos\Audience\Attributes::FROM)
            ->select(['users.userid', 'users.username', 'users.firstname', 'users.lastname'])->whereIn('users.userid', $ids)->getAllOrFail() as $row) {
            $full = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
            $names[(int) $row['userid']] = $full !== '' ? $full : (string) $row['username'];
        }

        return $names;
    }

    /** Days without signing in after which somebody is inactive: `conversations.inactive_after_days`; 0, the default, never. */
    public static function inactiveAfterDays(): int
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) ? max(0, (int) ($app->applicationInfo['conversations']['inactive_after_days'] ?? 0)) : 0;
    }

    /**
     * Of these accounts, the inactive ones — neither signed in nor registered within the last
     * {@see inactiveAfterDays()} days — each with when they last signed in, 0 for never.
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    public static function inactive(array $ids, ?int $now = null): array
    {
        $days = self::inactiveAfterDays();
        $ids  = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($days === 0 || $ids === []) {
            return [];
        }
        $since = ($now ?? time()) - $days * 86400;
        $out   = [];
        foreach (Conversations::db()->queryBuilder()->table(\Pramnos\Audience\Attributes::FROM)
            ->select(['users.userid', 'users.lastlogin', 'users.regdate'])->whereIn('users.userid', $ids)->getAllOrFail() as $row) {
            $last = (int) $row['lastlogin'];
            if (max($last, (int) $row['regdate']) < $since) {
                $out[(int) $row['userid']] = $last;
            }
        }

        return $out;
    }

    /**
     * The people who may use the conversations whose name or username holds `$term`, by name — for a
     * mention or a new conversation. Active accounts only, and only those `Access::canUse()` admits;
     * somebody inactive only when `$inactive` asks for them or `$term` is their whole name or
     * username, and then with when they last signed in.
     *
     * @return list<array{id: int, name: string, inactive: ?int}>
     */
    public static function search(string $term, int $limit = 20, bool $inactive = false): array
    {
        $query = Conversations::db()->queryBuilder()->table(\Pramnos\Audience\Attributes::FROM)
            ->select(['users.userid', 'users.username', 'users.firstname', 'users.lastname', 'users.usertype', 'users.active'])
            ->where('users.active', 1)->where('users.userid', '>', 1);
        if (!\Pramnos\Auth\AdminAccess::usesPermissions()) {
            $query->where('users.usertype', '>=', Access::floor());
        }
        $term = trim($term);
        // Each word in the first name, the last name or the username: a whole name finds its person.
        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $query->where(static function ($q) use ($like): void {
                $q->whereLike('users.firstname', $like)->orWhereLike('users.lastname', $like)->orWhereLike('users.username', $like);
            });
        }
        $people = [];
        // ponytail: scans up to 200 candidates and asks Access of each; a permission index if teams grow past that.
        $rows   = $query->orderBy('users.firstname')->limit(200)->getAllOrFail();
        $asleep = self::inactive(array_map('intval', array_column($rows, 'userid')));
        $whole  = mb_strtolower($term);
        foreach ($rows as $row) {
            if (count($people) >= $limit || !Access::canUse((object) $row)) {
                continue;
            }
            $id   = (int) $row['userid'];
            $full = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
            if (isset($asleep[$id]) && !$inactive && $whole !== mb_strtolower($full) && $whole !== mb_strtolower((string) $row['username'])) {
                continue;
            }
            $people[] = ['id' => $id, 'name' => $full !== '' ? $full : (string) $row['username'], 'inactive' => $asleep[$id] ?? null];
        }

        return $people;
    }

    /**
     * Somebody as a reader sees them when they hover over or click a name: the name, when they last
     * signed in and whether they are inactive, the offset of their local time where their account
     * names a time zone, the two people's direct conversation and whether the reader muted it, the
     * conversations both are in, and what the application adds — `conversations.profile`, given the
     * person and the reader, answering `fields` (label => value) and `links` (`label`, `url`, `icon`):
     *
     * ```php
     * 'conversations' => ['profile' => fn (int $person, object $reader): array => [
     *     'fields' => [t('Role') => Staff::role($person)],
     *     'links'  => [['label' => t('Account in the admin'), 'url' => adminUrl('Users/edit/' . $person), 'icon' => '👤']],
     * ]],
     * ```
     *
     * Somebody who may not use the conversations is still shown to a reader who sees them anyway —
     * they are in a conversation the reader reads (history brought in, somebody whose access ended),
     * or the reader reads them all — with `canMessage` false and no direct conversation. Null for
     * nobody, and for a stranger.
     *
     * @return array{id: int, name: string, lastSignIn: int, inactive: ?int, utcOffset: ?int, canMessage: bool, dm: ?int, muted: bool,
     *     shared: list<array{id: int, title: string}>, fields: array<string, string>, links: list<array{label: string, url: string, icon: string}>}|null
     */
    public static function profile(int $person, object $reader, ?int $now = null): ?array
    {
        $row = Conversations::db()->queryBuilder()->table(\Pramnos\Audience\Attributes::FROM)
            ->select(['users.userid', 'users.username', 'users.firstname', 'users.lastname', 'users.usertype', 'users.active', 'users.lastlogin', 'users.timezone'])
            ->where('users.userid', $person)->limit(1)->getAllOrFail()[0] ?? null;
        if ($row === null || !Access::canUse($reader)) {
            return null;
        }
        $me     = (int) $reader->userid;
        $usable = Access::canUse((object) $row);
        if (!$usable && !self::seenBy($person, $reader)) {
            return null;
        }
        $offset = null;
        if (trim((string) $row['timezone']) !== '') {
            try {
                $offset = (new \DateTimeZone(trim((string) $row['timezone'])))->getOffset(new \DateTimeImmutable('@' . ($now ?? time())));
            } catch (\Exception) {
                $offset = null;
            }
        }
        $dm    = $person === $me || !$usable ? null : Conversations::dmBetween($me, $person);
        $extra = self::extra($person, $reader);

        return [
            'id'         => $person,
            'name'       => self::names([$person])[$person] ?? '#' . $person,
            'lastSignIn' => (int) $row['lastlogin'],
            'inactive'   => self::inactive([$person], $now)[$person] ?? null,
            'utcOffset'  => $offset,
            'canMessage' => $usable && $person !== $me,
            'dm'         => $dm,
            'muted'      => $dm !== null && (bool) (Conversations::member($dm, $me)['muted'] ?? false),
            'shared'     => $person === $me ? [] : Conversations::shared($me, $person),
        ] + $extra;
    }

    /** Whether a reader sees somebody anyway: they are in a conversation the reader reads, or the reader reads them all. */
    private static function seenBy(int $person, object $reader): bool
    {
        $query = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_members')->select(['conversationid'])
            ->where('participanttype', 'user')->where('participantid', $person);
        if (!Access::readsAll($reader)) {
            $readable = Conversations::readable((int) $reader->userid);
            if ($readable === []) {
                return false;
            }
            $query->whereIn('conversationid', $readable);
        }

        return $query->limit(1)->getAllOrFail() !== [];
    }

    /**
     * What the application adds to a profile, for this reader: its fields and its links — an
     * address of the site or http(s), never a script.
     *
     * @return array{fields: array<string, string>, links: list<array{label: string, url: string, icon: string}>}
     */
    private static function extra(int $person, object $reader): array
    {
        $hook  = \Pramnos\Application\Application::currentInstance()?->applicationInfo['conversations']['profile'] ?? null;
        $given = is_callable($hook) ? (array) $hook($person, $reader) : [];
        $fields = [];
        foreach ((array) ($given['fields'] ?? []) as $label => $value) {
            if (trim((string) $label) !== '' && trim((string) $value) !== '') {
                $fields[trim((string) $label)] = trim((string) $value);
            }
        }
        $links = [];
        foreach ((array) ($given['links'] ?? []) as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if (is_array($link) && trim((string) ($link['label'] ?? '')) !== '' && preg_match('#^(https?://|/(?!/))#i', $url)) {
                $links[] = ['label' => trim((string) $link['label']), 'url' => $url, 'icon' => (string) ($link['icon'] ?? '')];
            }
        }

        return ['fields' => $fields, 'links' => $links];
    }
}
