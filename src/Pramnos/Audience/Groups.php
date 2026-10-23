<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;
use Pramnos\Event\Event;

/**
 * Putting people in groups and taking them out — one path for the Groups screen, a bulk action
 * over a filter, and a rule.
 *
 * ```php
 * Groups::add($groupId, [$userid], by: $adminId);                 // 1
 * Groups::addMatching($groupId, $filter, by: $adminId);           // everybody the filter matches
 * Groups::remove($groupId, $userid, source: 'rule');
 * Groups::of($userid);                                            // [['id' => 4, 'name' => 'Press'], …]
 * ```
 *
 * Membership lives in the framework's `userstogroups`. Each change flushes the cache
 * `User::getGroups()` reads, tells the applications an account uses when the group holds roles
 * (its permissions changed), and dispatches {@see GroupJoined} / {@see GroupLeft} for each person
 * whose membership changed — never for one who was already in, or already out.
 */
final class Groups
{
    /** The SQL-cache category `User::getGroups()` reads under. */
    public const CACHE_CATEGORY = 'usergroups';

    /** Roles held by a group; a membership change there changes permissions. */
    private const ROLES_TABLE = 'authserver.group_roles';

    /** @var array<string, callable(int): list<string|array{name: string, url?: ?string}>> What the application said uses groups */
    private static array $usage = [];

    /** The changelog's entity for a group: `group.joined` / `group.left` are written on it. */
    public const ENTITY = 'usergroup';

    /** Whether memberships record when they began, once asked; see {@see keepsHistory()}. */
    private static ?bool $history = null;

    /** Whether the changelog is here, once asked; see {@see changelogHere()}. */
    private static ?bool $changelog = null;

    /**
     * Put people in a group; those already in are left as they are.
     *
     * @param  int|list<int> $people
     * @return int How many were added
     */
    public static function add(int $group, int|array $people, ?int $by = null, string $source = 'admin'): int
    {
        $people = self::ids($people);
        if ($people === []) {
            return 0;
        }
        $in      = self::membersAmong($group, $people);
        $added   = array_values(array_diff($people, $in));
        $now     = time();
        $history = self::keepsHistory();
        foreach (array_chunk($added, 500) as $chunk) {
            self::db()->queryBuilder()->table('#PREFIX#userstogroups')->insertMany(array_map(
                static fn (int $userid): array => ['userid' => $userid, 'groupid' => $group]
                    + ($history ? ['addedat' => $now, 'addedby' => $by, 'addedsource' => mb_substr($source, 0, 20)] : []),
                $chunk
            ));
        }
        self::log($group, $added, GroupJoined::EVENT, $by, $source, $now);
        self::changed($group, $added, 'group_joined', static fn (int $userid) => new GroupJoined($group, $userid, $by, $source));

        return count($added);
    }

    /**
     * Take people out of a group; those not in it are left as they are.
     *
     * @param  int|list<int> $people
     * @return int How many were removed
     */
    public static function remove(int $group, int|array $people, ?int $by = null, string $source = 'admin'): int
    {
        $people  = self::ids($people);
        $removed = $people === [] ? [] : self::membersAmong($group, $people);
        foreach (array_chunk($removed, 500) as $chunk) {
            self::db()->queryBuilder()->table('#PREFIX#userstogroups')
                ->where('groupid', $group)->whereIn('userid', $chunk)->delete();
        }
        self::log($group, $removed, GroupLeft::EVENT, $by, $source, time());
        self::changed($group, $removed, 'group_left', static fn (int $userid) => new GroupLeft($group, $userid, $by, $source));

        return count($removed);
    }

    /** Put everybody a filter matches in a group — the bulk action. */
    public static function addMatching(int $group, Filter $filter, ?int $by = null, string $source = 'bulk'): int
    {
        $added = 0;
        for ($page = $filter->ids(0, 1000); $page !== []; $page = $filter->ids(end($page), 1000)) {
            $added += self::add($group, $page, $by, $source);
        }

        return $added;
    }

    /** Take everybody a filter matches out of a group. */
    public static function removeMatching(int $group, Filter $filter, ?int $by = null, string $source = 'bulk'): int
    {
        $removed = 0;
        for ($page = $filter->ids(0, 1000); $page !== []; $page = $filter->ids(end($page), 1000)) {
            $removed += self::remove($group, $page, $by, $source);
        }

        return $removed;
    }

    /**
     * The groups a person is in, by name — the person card's panel — with when they joined, by
     * whom and how, where that is known (null for a membership older than its record).
     *
     * @return list<array{id: int, name: string, addedAt: ?int, addedBy: ?int, source: ?string}>
     */
    public static function of(int $userid): array
    {
        $history = self::keepsHistory();
        $rows    = self::db()->queryBuilder()->table('#PREFIX#userstogroups AS userstogroups')
            ->join('#PREFIX#usergroups AS usergroups', 'usergroups.groupid', '=', 'userstogroups.groupid')
            ->select(array_merge(['usergroups.groupid', 'usergroups.name'], $history ? ['userstogroups.addedat', 'userstogroups.addedby', 'userstogroups.addedsource'] : []))
            ->where('userstogroups.userid', $userid)->orderBy('usergroups.name')->getAllOrFail();
        $int     = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return array_map(static fn (array $row): array => ['id' => (int) $row['groupid'], 'name' => (string) $row['name'],
            'addedAt' => $int($row['addedat'] ?? null), 'addedBy' => $int($row['addedby'] ?? null), 'source' => $row['addedsource'] ?? null], $rows);
    }

    /**
     * Who joined and who left every group over the last days — the changelog's `group.joined` and
     * `group.left` — in one query: what the library sorts by. Empty where the changelog is not
     * there.
     *
     * @return array<int, array{entered: int, left: int}> By group id; a group nobody moved in is absent
     */
    public static function movements(int $days = 7): array
    {
        if (!self::changelogHere()) {
            return [];
        }
        $db  = self::db();
        $out = [];
        foreach ($db->queryBuilder()->table(\Pramnos\Changelog\ChangelogWriter::EVENTS_TABLE)
            ->select(['itemid', 'event', new \Pramnos\Database\Expression('COUNT(*) AS n')])
            ->where('entity', self::ENTITY)->whereIn('event', [GroupJoined::EVENT, GroupLeft::EVENT])
            ->where('created_at', '>=', \Pramnos\Database\Instant::at($db, time() - $days * 86400))
            ->groupBy(['itemid', 'event'])->getAllOrFail() as $row) {
            $out[(int) $row['itemid']] ??= ['entered' => 0, 'left' => 0];
            $out[(int) $row['itemid']][$row['event'] === GroupJoined::EVENT ? 'entered' : 'left'] += (int) $row['n'];
        }

        return $out;
    }

    /**
     * Tell the library where groups are used, so one in use is not deleted and the library's
     * "used by" names it — a coupon that puts its buyers in a group, a package, a support team.
     *
     * ```php
     * Groups::usage('coupons', fn (int $id): array => array_map(
     *     fn ($c) => ['name' => 'coupon ' . $c->code, 'url' => adminUrl('Coupons/edit/') . $c->id],
     *     Coupon::addingTo($id),
     * ));
     * ```
     *
     * @param callable(int): list<string|array{name: string, url?: ?string}> $finder What uses the group
     */
    public static function usage(string $source, callable $finder): void
    {
        self::$usage[$source] = $finder;
    }

    /**
     * What uses a group: segments whose filter names it, rules that put people in it or take them
     * out, and what {@see usage()} reports.
     *
     * @return list<array{name: string, url: ?string}>
     */
    public static function uses(int $id): array
    {
        $uses = [];
        if (Installed::segments()) {
            foreach (Segments::all() as $segment) {
                if (in_array($id, Filter::groupsIn($segment['filter']), true)) {
                    $uses[] = ['name' => t('segment %s', $segment['name']), 'url' => adminUrl('Segments/view/') . $segment['id']];
                }
            }
        }
        foreach (self::$usage as $finder) {
            foreach ($finder($id) as $use) {
                $uses[] = is_array($use) ? ['name' => (string) $use['name'], 'url' => $use['url'] ?? null] : ['name' => (string) $use, 'url' => null];
            }
        }

        return array_merge($uses, Rules\Rules::linksUsingGroup($id));
    }

    /** Forget what {@see usage()} was told: tests. */
    public static function reset(): void
    {
        self::$usage     = [];
        self::$history   = null;
        self::$changelog = null;
    }

    /**
     * Whether memberships record when, by whom and how they began: `add_membership_history_to_usergroups`
     * has run. Asked once a request.
     */
    public static function keepsHistory(): bool
    {
        return self::$history ??= self::db()->schema()->hasColumn('userstogroups', 'addedat');
    }

    /** Whether the changelog's tables are here, to tell who joined and left. Asked once a request. */
    private static function changelogHere(): bool
    {
        return self::$changelog ??= self::db()->schema()->hasTable(\Pramnos\Changelog\ChangelogWriter::EVENTS_TABLE);
    }

    /**
     * People joining or leaving a group, in the changelog: `group.joined` or `group.left` on the
     * group, with who and how.
     *
     * @param list<int> $people
     */
    private static function log(int $group, array $people, string $event, ?int $by, string $source, int $at): void
    {
        if ($people === [] || !self::changelogHere()) {
            return;
        }
        foreach ($people as $userid) {
            \Pramnos\Changelog\ChangelogWriter::event(self::ENTITY, $group, $event, ['userid' => $userid, 'source' => $source], by: $by ?? 0, at: $at);
        }
    }

    /**
     * Which of these people are in the group.
     *
     * @param  list<int> $people
     * @return list<int>
     */
    private static function membersAmong(int $group, array $people): array
    {
        $in = [];
        foreach (array_chunk($people, 1000) as $chunk) {
            foreach (self::db()->queryBuilder()->table('#PREFIX#userstogroups')->select(['userid'])
                ->where('groupid', $group)->whereIn('userid', $chunk)->getAllOrFail() as $row) {
                $in[] = (int) $row['userid'];
            }
        }

        return $in;
    }

    /**
     * What follows a change: the cache, the permissions, the events.
     *
     * @param list<int>                    $people Whose membership changed
     * @param callable(int): object        $event  The event for one of them
     */
    private static function changed(int $group, array $people, string $operation, callable $event): void
    {
        if ($people === []) {
            return;
        }
        self::db()->cacheflush(self::CACHE_CATEGORY);
        if (self::holdsRoles($group)) {
            foreach ($people as $userid) {
                \Pramnos\Auth\WebhookService::permissionsChanged('user', $userid, ['operation' => $operation, 'groupid' => $group]);
            }
        }
        foreach ($people as $userid) {
            Event::dispatch($event($userid));
        }
    }

    /** Whether the group holds an active role, where roles can be given to groups at all. */
    private static function holdsRoles(int $group): bool
    {
        $schema = self::db()->schema();
        if (!$schema->hasTable(self::ROLES_TABLE) || !$schema->hasTable('authserver.roles')) {
            return false;
        }

        return self::db()->queryBuilder()->table(self::ROLES_TABLE)
            ->where('groupid', $group)->where('is_active', true)->count() > 0;
    }

    /**
     * Positive ids, once each.
     *
     * @param  int|list<int> $people
     * @return list<int>
     */
    private static function ids(int|array $people): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $people), static fn (int $id): bool => $id > 0)));
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
