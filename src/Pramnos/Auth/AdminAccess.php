<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\Application\Settings;

/**
 * Who may open which administration screen.
 *
 * Two models, chosen by the `admin_access` setting:
 *
 * - **`usertype`** (the default) — each screen has a usertype floor, 80 or 90, and everybody
 *   above it sees the same area. This is how the framework has always worked, and an
 *   installation that sets nothing keeps it.
 * - **`permissions`** — each screen is an ability named after its menu item, `admin.users`,
 *   `admin.roles`, and is open to whoever holds it, granted directly or through a role. Two
 *   administrators can then see different areas. Nothing granted means nothing open: deny by
 *   default, because in this mode the grant *is* the access.
 *
 * The superuser — usertype ≥ `admin_superuser_usertype`, 98 unless set — sees every screen in
 * both models, so an installation that switches to permissions before granting anything still
 * has somebody who can grant.
 *
 * The screen and its menu item ask the same question with the same name, so a screen cannot be
 * open while its link is hidden, or listed while it refuses.
 */
final class AdminAccess
{
    public const MODE_SETTING = 'admin_access';

    public const SUPERUSER_SETTING = 'admin_superuser_usertype';

    public const DEFAULT_SUPERUSER = 98;

    /** The privilege an administration ability is granted as. */
    public const PRIVILEGE = 'view';

    /** Whether screens are granted by permission rather than by usertype. */
    public static function usesPermissions(): bool
    {
        return strtolower(trim((string) Settings::getSetting(self::MODE_SETTING, 'usertype'))) === 'permissions';
    }

    /** The usertype that opens every screen. */
    public static function superuserUsertype(): int
    {
        $value = (int) Settings::getSetting(self::SUPERUSER_SETTING, self::DEFAULT_SUPERUSER);

        return $value > 0 ? $value : self::DEFAULT_SUPERUSER;
    }

    /**
     * May this user open the screen behind `$ability`?
     *
     * @param object|null $user        The signed-in user, or null
     * @param string      $ability     The screen's ability, e.g. `admin.users`
     * @param int         $minUserType The screen's usertype floor, which decides in `usertype` mode
     */
    public static function allows(?object $user, string $ability, int $minUserType): bool
    {
        if ($user === null || (int) ($user->userid ?? 0) < 2) {
            return false;
        }

        $usertype = (int) ($user->usertype ?? 0);

        if (!self::usesPermissions()) {
            return $usertype >= $minUserType;
        }

        if ($usertype >= self::superuserUsertype()) {
            return true;
        }

        try {
            return Permissions::getInstance()->isAllowed(
                (int) $user->userid,
                $ability,
                self::PRIVILEGE
            ) === true;
        } catch (\Throwable $e) {
            // Closed, not open: in this mode a permission is the only thing standing between
            // an account and the screen, so a store that cannot answer answers no.
            \Pramnos\Logs\Logger::logError('AdminAccess: ' . $e->getMessage(), $e);

            return false;
        }
    }

    /**
     * The administration screens there are to grant, as `ability => label`.
     *
     * Read from the Admin section of the navigation, so a screen an application registers
     * becomes grantable by registering its menu item — there is no second list to forget.
     *
     * @param list<string> $features Enabled features; items gated on another are left out
     * @return array<string, string>
     */
    public static function abilities(array $features = []): array
    {
        $abilities = [];
        foreach (NavRegistry::all() as $item) {
            if ($item->section !== NavSection::Admin) {
                continue;
            }
            if ($item->feature !== null && $features !== [] && !in_array($item->feature, $features, true)) {
                continue;
            }
            $abilities[$item->id] = $item->group !== null && $item->group !== ''
                ? $item->group . ' › ' . $item->label
                : $item->label;
        }

        return $abilities;
    }

    /**
     * The administration abilities granted directly to a user or a role.
     *
     * Only `allow` rows for a known ability, with no record id and no condition — the shape
     * {@see setGrants()} writes. A deny written by hand on the Permissions screen is not a
     * grant and is left for that screen to show.
     *
     * @param 'user'|'role' $subjectType
     * @return list<string>
     */
    public static function grantsFor(string $subjectType, int $subjectId): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->schema()->hasTable('authserver.permissions')) {
            return [];
        }

        $result = $db->queryBuilder()
            ->table('authserver.permissions')
            ->select(['object_type'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('action', self::PRIVILEGE)
            ->where('grant_type', 'allow')
            ->whereNull('object_id')
            ->get();

        $granted = [];
        while ($result && $result->fetch()) {
            $granted[] = (string) $result->fields['object_type'];
        }

        return array_values(array_intersect(array_keys(self::abilities()), $granted));
    }

    /**
     * Make `$abilities` exactly the administration screens granted to a user or a role.
     *
     * Rows for abilities not in the list are removed and missing ones inserted; rows that are
     * not administration grants are never touched. `$grantable` limits what may change, so
     * somebody editing grants cannot hand out a screen they do not hold themselves.
     *
     * @param 'user'|'role'  $subjectType
     * @param list<string>   $abilities  What should be granted afterwards
     * @param list<string>   $grantable  What this editor may add or remove
     * @return array{added: list<string>, removed: list<string>}
     */
    public static function setGrants(
        string $subjectType,
        int $subjectId,
        array $abilities,
        array $grantable,
        ?int $grantedBy = null
    ): array {
        if (!in_array($subjectType, ['user', 'role'], true) || $subjectId <= 0) {
            throw new \InvalidArgumentException('Grants are made to a user or a role.');
        }

        $known   = array_keys(self::abilities());
        $allowed = array_values(array_intersect($known, $grantable));
        $wanted  = array_values(array_intersect($allowed, $abilities));
        $current = self::grantsFor($subjectType, $subjectId);

        $add    = array_values(array_diff($wanted, $current));
        $remove = array_values(array_intersect(array_diff($current, $wanted), $allowed));

        $db = \Pramnos\Framework\Factory::getDatabase();
        foreach ($remove as $ability) {
            $db->queryBuilder()->table('authserver.permissions')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('object_type', $ability)
                ->where('action', self::PRIVILEGE)
                ->where('grant_type', 'allow')
                ->whereNull('object_id')
                ->delete();
        }
        foreach ($add as $ability) {
            $db->queryBuilder()->table('authserver.permissions')->insert([
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'object_type'  => $ability,
                'object_id'    => null,
                'action'       => self::PRIVILEGE,
                'grant_type'   => 'allow',
                'priority'     => 100,
                'is_active'    => true,
                'granted_by'   => $grantedBy,
            ]);
        }

        if ($add !== [] || $remove !== []) {
            Permissions::getInstance()->clearCache();
        }

        return ['added' => $add, 'removed' => $remove];
    }

    /**
     * Where to send somebody refused a screen: the first one they may open, or null.
     *
     * @param object|null  $user
     * @param list<string> $features
     * @param string       $except   The ability that was just refused
     */
    public static function landingFor(?object $user, array $features, string $except = ''): ?string
    {
        $items = [];
        foreach (NavRegistry::all() as $item) {
            if ($item->section === NavSection::Admin && $item->id !== $except) {
                $items[] = $item;
            }
        }
        usort($items, static fn ($a, $b) => $a->position <=> $b->position);

        foreach ($items as $item) {
            if ($item->feature !== null && !in_array($item->feature, $features, true)) {
                continue;
            }
            if (self::allows($user, $item->id, $item->minUserType)) {
                return $item->url;
            }
        }

        return null;
    }
}
