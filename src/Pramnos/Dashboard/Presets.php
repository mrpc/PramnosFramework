<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

use Pramnos\Database\Database;

/**
 * Named dashboard layouts: the application's, from app.php, and the ones people save and share.
 *
 * ```php
 * // app/app.php — a board's presets, by name
 * 'dashboard' => ['boards' => ['wcm' => ['presets' => [
 *     'Operations' => ['wcm.map', 'wcm.stats'],
 *     'Management' => [['id' => 'wcm.stats', 'size' => 'full']],
 * ]]]],
 *
 * $presets = new Presets();
 * $presets->visibleTo($user, 'wcm');                    // the app's, then the saved ones this person may see
 * $id = $presets->save($user, 'wcm', 'Mine', $layout, Presets::ROLE, 'manager');
 * ```
 *
 * Applying one copies its layout into the person's own — later changes are theirs, and the preset
 * is not touched. Every layout is cleaned for the person it lands on, so a shared preset never
 * shows a card its receiver may not have: the ones dropped are counted, and said.
 *
 * Kept in `pramnos.dashboard_presets`: state, the presets that exist now. An installation that has
 * not migrated has the app's presets only, and saving says what is missing.
 */
final class Presets
{
    /** Seen by its owner only. */
    public const PRIVATE = 'private';

    /** Seen from a role up: `audience` is the usertype it starts at (`90`), or a role's name. */
    public const ROLE = 'role';

    /** Seen by the members of an organisation — the application's kind ({@see organizations()}): `audience` is its id. */
    public const ORGANIZATION = 'organization';

    /** Seen by everybody who has the board. */
    public const EVERYONE = 'everyone';

    /** Who a saved preset may be shown to. */
    public const VISIBILITIES = [self::PRIVATE, self::ROLE, self::ORGANIZATION, self::EVERYONE];

    /** The table. */
    public const TABLE = 'pramnos.dashboard_presets';

    /** The id prefix of a preset app.php defines, before its name. */
    public const APP = 'app:';

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * The presets of a board this person may see and apply: the application's first, then the
     * saved ones — their own, and those shared with them.
     *
     * @return list<array{id: string, name: string, kind: string, ownerid: int|null, mine: bool, visibility: string, audience: string|null, default_for_role: string|null, layout: list<mixed>}>
     */
    public function visibleTo(object $user, string $board): array
    {
        $presets = [];
        foreach ((array) (WidgetRegistry::config($board)['presets'] ?? []) as $name => $layout) {
            if (is_array($layout)) {
                $presets[] = [
                    'id' => self::APP . $name, 'name' => (string) $name, 'kind' => 'app', 'ownerid' => null, 'mine' => false,
                    'visibility' => self::EVERYONE, 'audience' => null, 'default_for_role' => null, 'layout' => array_values($layout),
                ];
            }
        }
        foreach ($this->rows(['board' => $board]) as $row) {
            if (self::canSee($row, $user)) {
                $presets[] = self::shape($row, $user);
            }
        }

        return $presets;
    }

    /**
     * One preset by id, if this person may see it on this board.
     *
     * @return array<string, mixed>|null As {@see visibleTo()} gives each
     */
    public function find(string $id, object $user, string $board): ?array
    {
        foreach ($this->visibleTo($user, $board) as $preset) {
            if ($preset['id'] === $id) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Keep a preset of a layout, cleaned for its owner.
     *
     * @param list<mixed> $layout As a board keeps one
     * @return int Its id
     * @throws \InvalidArgumentException For no name, an unknown visibility, or an audience that is
     *         not a role (for `role`) or an organisation id (for `organization`)
     */
    public function save(object $user, string $board, string $name, array $layout, string $visibility = self::PRIVATE, ?string $audience = null): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('A preset needs a name of up to 120 characters.');
        }
        $audience = self::audience($visibility, $audience);
        $now      = time();

        $this->db()->queryBuilder()->table(self::TABLE)->insert([
            'board'      => $board,
            'name'       => $name,
            'layout'     => (string) json_encode(Layouts::clean($layout, $user, $board)),
            'ownerid'    => (int) ($user->userid ?? 0),
            'visibility' => $visibility,
            'audience'   => $audience,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db()->getInsertId();
    }

    /** Remove a saved preset: its owner, or an administrator. */
    public function delete(int $id, object $user, bool $asAdministrator = false): bool
    {
        $query = $this->db()->queryBuilder()->table(self::TABLE)->where('presetid', $id);
        if (!$asAdministrator) {
            $query->where('ownerid', (int) ($user->userid ?? 0));
        }

        return self::affected($query->delete()) > 0;
    }

    /**
     * Make a saved preset what a new board starts from for a role, or stop (null). One preset per
     * role and board: marking another one takes the mark from the first.
     *
     * @throws \InvalidArgumentException For a role nobody defined
     */
    public function setDefaultForRole(int $id, ?string $role): bool
    {
        $row = $this->rows(['presetid' => $id])[0] ?? null;
        if ($row === null) {
            return false;
        }
        if ($role !== null) {
            self::roleLevel($role);
            $this->db()->queryBuilder()->table(self::TABLE)->where('board', (string) $row['board'])
                ->where('default_for_role', $role)->update(['default_for_role' => null]);
        }

        return self::affected($this->db()->queryBuilder()->table(self::TABLE)->where('presetid', $id)
            ->update(['default_for_role' => $role, 'updated_at' => time()])) > 0;
    }

    /**
     * The layout a new board starts from for this person: the preset marked default for the highest
     * role they hold, or null when none is — the board's own default then applies.
     *
     * @return list<mixed>|null
     */
    public function defaultLayoutFor(object $user, string $board): ?array
    {
        $best  = null;
        $level = -1;
        foreach ($this->rows(['board' => $board]) as $row) {
            $role = (string) ($row['default_for_role'] ?? '');
            if ($role === '') {
                continue;
            }
            try {
                $at = self::roleLevel($role);
                if ($at > $level && self::holdsRole($user, $role)) {
                    [$best, $level] = [$row, $at];
                }
            } catch (\Throwable) {
                // A role app.php no longer defines marks nothing.
            }
        }
        $layout = $best !== null ? json_decode((string) $best['layout'], true) : null;

        return is_array($layout) ? $layout : null;
    }

    /**
     * Every saved preset, for the administration's list.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->rows([]);
    }

    /** Whether a person may see a saved preset. */
    public static function canSee(array $row, object $user): bool
    {
        $me = (int) ($user->userid ?? 0);
        if ($me > 1 && (int) ($row['ownerid'] ?? 0) === $me) {
            return true;
        }
        $audience = (string) ($row['audience'] ?? '');

        return match ((string) ($row['visibility'] ?? self::PRIVATE)) {
            self::EVERYONE     => $me > 1,
            self::ROLE         => $audience !== '' && self::holdsRole($user, $audience),
            self::ORGANIZATION => $audience !== '' && self::isMemberOf($user, $audience),
            default            => false,
        };
    }

    /**
     * The audience a visibility keeps, checked — shared with {@see UserBoards}.
     *
     * @throws \InvalidArgumentException For an unknown visibility, or a wrong audience for it
     */
    public static function audience(string $visibility, ?string $audience): ?string
    {
        $audience = trim((string) $audience);

        return match ($visibility) {
            self::PRIVATE, self::EVERYONE => null,
            self::ROLE => (static function () use ($audience): string {
                self::roleLevel($audience);

                return $audience;
            })(),
            self::ORGANIZATION => self::organizations() !== null && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $audience) === 1
                ? $audience
                : throw new \InvalidArgumentException('Choose the organisation to share it with.'),
            default => throw new \InvalidArgumentException('Unknown visibility: ' . $visibility),
        };
    }

    /**
     * Whether a person holds a role: a usertype (`90`, from the application's own types —
     * {@see \Pramnos\User\UserTypes}) is held from that number up; a role's name (`manager`) as
     * {@see \Pramnos\User\Roles} says; one nobody defined is held by nobody.
     */
    public static function holdsRole(object $user, string $role): bool
    {
        if (ctype_digit($role)) {
            return (int) ($user->usertype ?? 0) >= (int) $role;
        }
        try {
            return \Pramnos\User\Roles::atLeast($user, $role);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The usertype a role starts at: its number, or a role's level; an exception for one nobody defined. */
    public static function roleLevel(string $role): int
    {
        return ctype_digit($role) ? (int) $role : \Pramnos\User\Roles::level($role);
    }

    /**
     * What an organisation is here: the application's {@see OrganizationSource} (`'dashboard' =>
     * ['organizations' => Class::class]`), the framework's own when it says nothing, or null when
     * it says `false` — then nothing is shared with one, and the choice is not offered.
     */
    /**
     * Whether a person belongs to an organisation, as the source says — a source that fails is
     * logged (the `dashboard` log) and answers no, rather than failing every dashboard drawn.
     */
    private static function isMemberOf(object $user, string $id): bool
    {
        try {
            return self::organizations()?->isMember($user, $id) ?? false;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Dashboard organisation source failed in isMember(): ' . $e::class . ': ' . $e->getMessage(), 'dashboard');

            return false;
        }
    }

    public static function organizations(): ?OrganizationSource
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $chosen = is_object($app) ? ($app->applicationInfo['dashboard']['organizations'] ?? null) : null;
        if ($chosen === false) {
            return null;
        }
        if ($chosen instanceof OrganizationSource) {
            return $chosen;
        }
        if (is_string($chosen) && is_a($chosen, OrganizationSource::class, true)) {
            return new $chosen();
        }

        return new FrameworkOrganizations();
    }

    /**
     * A saved row as {@see visibleTo()} gives it.
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row, object $user): array
    {
        $layout = json_decode((string) ($row['layout'] ?? ''), true);

        return [
            'id'               => (string) $row['presetid'],
            'name'             => (string) $row['name'],
            'kind'             => 'saved',
            'ownerid'          => (int) $row['ownerid'],
            'mine'             => (int) $row['ownerid'] === (int) ($user->userid ?? 0),
            'visibility'       => (string) $row['visibility'],
            'audience'         => $row['audience'] !== null ? (string) $row['audience'] : null,
            'default_for_role' => $row['default_for_role'] !== null ? (string) $row['default_for_role'] : null,
            'layout'           => is_array($layout) ? array_values($layout) : [],
        ];
    }

    /**
     * Saved rows matching the conditions, oldest first; none when the table is not there yet.
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

            return array_values($query->orderBy('presetid')->getAllOrFail());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Dashboard presets unavailable: ' . $e->getMessage(), 'dashboard');

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
