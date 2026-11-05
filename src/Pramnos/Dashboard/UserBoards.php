<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

use Pramnos\Database\Database;

/**
 * Dashboards people make for themselves, shown in their menu — and in the menus of those they
 * share them with.
 *
 * ```php
 * $boards = new UserBoards();
 * $id = $boards->create($user, 'Water losses');                       // an empty board, mine only
 * $boards->update($id, $user, 'Water losses', Presets::ROLE, 'manager'); // in every manager's menu
 * $boards->visibleTo($user);                                          // the ones in this person's menu
 * ```
 *
 * A board is named `my-{id}` wherever a board name goes (`?board=my-7`), and offers the cards of
 * the administration's dashboard. Each person who sees it arranges it for themselves; somebody
 * opening one they were shared starts from its owner's arrangement, cleaned for them. Who sees one
 * is a preset's choice — {@see Presets::VISIBILITIES}.
 *
 * Kept in `pramnos.dashboard_boards`: state, the boards that exist now.
 */
final class UserBoards
{
    /** What a person's board's name starts with, before its id. */
    public const PREFIX = 'my-';

    /** The table. */
    public const TABLE = 'pramnos.dashboard_boards';

    /** The board whose catalogue a person's board offers. */
    public const CATALOGUE = 'admin';

    /** Whether the table's absence has been logged in this process. */
    private static bool $said = false;

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /** Whether a board name is a person's board: `my-` and an id. */
    public static function isUserBoard(?string $board): bool
    {
        return $board !== null && preg_match('/^' . preg_quote(self::PREFIX, '/') . '[1-9][0-9]*$/', $board) === 1;
    }

    /** A person's board's name for an id: `my-7`. */
    public static function name(int $id): string
    {
        return self::PREFIX . $id;
    }

    /**
     * Make a board, its owner's only until they share it — empty, or arranged as a layout.
     *
     * @param list<mixed>|null $layout Where the owner starts: a preset's layout, or none
     * @return int Its id
     * @throws \InvalidArgumentException For no name
     */
    public function create(object $user, string $name, ?array $layout = null): int
    {
        $name = self::checkedName($name);
        $now  = time();
        $this->db()->queryBuilder()->table(self::TABLE)->insert([
            'name' => $name, 'ownerid' => (int) ($user->userid ?? 0), 'visibility' => Presets::PRIVATE,
            'audience' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->db()->getInsertId();
        if ($layout !== null) {
            (new Layouts($this->db))->save($user, $layout, self::name($id));
        }

        return $id;
    }

    /**
     * Rename a board and say who sees it — its owner only.
     *
     * @throws \InvalidArgumentException For no name, or a wrong visibility or audience
     */
    public function update(int $id, object $user, string $name, string $visibility, ?string $audience = null): bool
    {
        $values = [
            'name'       => self::checkedName($name),
            'visibility' => $visibility,
            'audience'   => Presets::audience($visibility, $audience),
            'updated_at' => time(),
        ];

        return self::affected($this->db()->queryBuilder()->table(self::TABLE)->where('boardid', $id)
            ->where('ownerid', (int) ($user->userid ?? 0))->update($values)) > 0;
    }

    /**
     * Remove a board, and every viewer's arrangement of it: its owner, or an administrator.
     */
    public function delete(int $id, object $user, bool $asAdministrator = false): bool
    {
        $query = $this->db()->queryBuilder()->table(self::TABLE)->where('boardid', $id);
        if (!$asAdministrator) {
            $query->where('ownerid', (int) ($user->userid ?? 0));
        }
        if (self::affected($query->delete()) === 0) {
            return false;
        }
        $this->db()->queryBuilder()->table(defined('DB_USERSETTINGSTABLE') ? DB_USERSETTINGSTABLE : '#PREFIX#usersettings')
            ->where('setting', Layouts::SETTING . self::name($id))->delete();

        return true;
    }

    /**
     * A board by its name (`my-7`) or id, if this person may see it.
     *
     * @return array<string, mixed>|null The row, with `board` (its name) and `mine`
     */
    public function find(string|int $board, object $user): ?array
    {
        $id = is_int($board) ? $board : (self::isUserBoard($board) ? (int) substr($board, strlen(self::PREFIX)) : 0);
        if ($id < 1) {
            return null;
        }
        $row = $this->rows(['boardid' => $id])[0] ?? null;

        return $row !== null && Presets::canSee($row, $user) ? self::shape($row, $user) : null;
    }

    /**
     * The boards in this person's menu: their own, then those shared with them, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(object $user): array
    {
        $boards = [];
        foreach ($this->rows([]) as $row) {
            if (Presets::canSee($row, $user)) {
                $boards[] = self::shape($row, $user);
            }
        }
        usort($boards, static fn (array $a, array $b): int => [$b['mine'], $a['name']] <=> [$a['mine'], $b['name']]);

        return $boards;
    }

    /**
     * Where somebody with no arrangement of a board starts: empty for its owner (they add the
     * cards they want), its owner's arrangement for anybody else.
     *
     * @return list<mixed>
     */
    public function startingLayout(object $user, string $board): array
    {
        $row = $this->find($board, $user);
        if ($row === null || $row['mine']) {
            return [];
        }

        return (new Layouts($this->db))->savedFor((int) $row['ownerid'], $board) ?? [];
    }

    /** A name a board may have. */
    private static function checkedName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('A dashboard needs a name of up to 120 characters.');
        }

        return $name;
    }

    /**
     * A row as the callers read it.
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row, object $user): array
    {
        return $row + [
            'board' => self::name((int) $row['boardid']),
            'mine'  => (int) $row['ownerid'] === (int) ($user->userid ?? 0),
        ];
    }

    /**
     * Rows matching the conditions, oldest first; none when the table is not there yet.
     *
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private function rows(array $where): array
    {
        try {
            $query = $this->db()->queryBuilder()->table(self::TABLE);
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            return array_values($query->orderBy('boardid')->getAllOrFail());
        } catch (\Throwable $e) {
            // Once per process: the menu asks on every page, and an installation that has not
            // migrated would write the same line on each.
            if (!self::$said) {
                self::$said = true;
                \Pramnos\Logs\Logger::log('Dashboard boards unavailable: ' . $e->getMessage(), 'dashboard');
            }

            return [];
        }
    }

    /** How many rows a write changed: a Result's count, never just "it ran". */
    private static function affected(mixed $result): int
    {
        return is_object($result) && method_exists($result, 'getAffectedRows') ? (int) $result->getAffectedRows() : (int) (bool) $result;
    }

    private function db(): Database
    {
        return $this->db ?? Database::getInstance();
    }
}
