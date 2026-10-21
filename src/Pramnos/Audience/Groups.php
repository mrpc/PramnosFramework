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
        $in    = self::membersAmong($group, $people);
        $added = array_values(array_diff($people, $in));
        foreach (array_chunk($added, 500) as $chunk) {
            self::db()->queryBuilder()->table('#PREFIX#userstogroups')->insertMany(
                array_map(static fn (int $userid): array => ['userid' => $userid, 'groupid' => $group], $chunk)
            );
        }
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
     * The groups a person is in, by name — the person card's panel.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function of(int $userid): array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#userstogroups AS userstogroups')
            ->join('#PREFIX#usergroups AS usergroups', 'usergroups.groupid', '=', 'userstogroups.groupid')
            ->select(['usergroups.groupid', 'usergroups.name'])
            ->where('userstogroups.userid', $userid)->orderBy('usergroups.name')->getAllOrFail();

        return array_map(static fn (array $row): array => ['id' => (int) $row['groupid'], 'name' => (string) $row['name']], $rows);
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
