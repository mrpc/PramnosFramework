<?php

declare(strict_types=1);

namespace Pramnos\Bulk;

/**
 * The framework's bulk action on people: put them in a user group — one that exists, or a new one
 * made by name. Behind the Groups screen's ability (`admin.groups`); each person joins through
 * {@see \Pramnos\Audience\Groups::add()}, so what joining a group does elsewhere (the changelog,
 * the events a rule waits for, roles a group carries) happens here too.
 */
final class GroupAdd
{
    /** A new group's name, at most, as the Groups screen allows. */
    public const NAME = 80;

    /**
     * The groups to choose from, by id.
     *
     * @return array<int, string>
     */
    public static function groups(int $userId = 0): array
    {
        $out = [];
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#usergroups')
                ->select(['groupid', 'name'])->orderBy('name')->get();
        } catch (\Throwable) {
            return [];
        }
        while (($row = $result->fetch()) !== null) {
            $out[(int) $row['groupid']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Put the people in the group chosen, or in a new one named.
     *
     * @param list<int|string>     $ids
     * @param array<string, mixed> $params group (an id) or new_group (a name)
     * @return array{done: int, says: string}
     */
    public static function run(array $ids, array $params, int $by): array
    {
        $db    = \Pramnos\Framework\Factory::getDatabase();
        $name  = trim((string) ($params['new_group'] ?? ''));
        $group = (int) ($params['group'] ?? 0);
        if ($name !== '') {
            $db->queryBuilder()->table('#PREFIX#usergroups')->insert(['name' => mb_substr($name, 0, self::NAME), 'description' => '']);
            $group = (int) $db->getInsertId();
        } elseif ($group <= 0 || !array_key_exists($group, self::groups())) {
            return ['done' => 0, 'says' => 'Choose a group, or name a new one.'];
        }
        $people = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $added  = \Pramnos\Audience\Groups::add($group, $people, $by, 'bulk');
        $title  = self::groups()[$group] ?? ('#' . $group);

        return ['done' => $added, 'says' => sprintf('%d added to %s; %d were already in it.', $added, $title, count($people) - $added)];
    }
}
