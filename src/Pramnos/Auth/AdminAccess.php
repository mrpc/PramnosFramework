<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\Application\Settings;

/**
 * Who may open which administration screen.
 *
 * Three models, chosen by the `admin_access` setting:
 *
 * - **`usertype`** (the default) — each screen has a usertype floor, 80 or 90, and everybody
 *   above it sees the same area. This is how the framework has always worked, and an
 *   installation that sets nothing keeps it.
 * - **`permissions`** — each screen is an ability named after its menu item, `admin.users`,
 *   `admin.roles`, and is open to whoever holds it, granted directly or through a role. Two
 *   administrators can then see different areas. Nothing granted means nothing open: deny by
 *   default, because in this mode the grant *is* the access.
 * - **`mixed`** — the usertype floor, with grants on top: an explicit **allow** opens a screen to
 *   somebody below its floor, an explicit **deny** closes it to somebody above it, and a screen
 *   with neither is decided by the floor. With nothing granted it behaves exactly like
 *   `usertype`, which makes it the safe way to start using grants on a running installation.
 *
 * A deny always wins over an allow, from the user or from any of their roles. The superuser —
 * usertype ≥ `admin_superuser_usertype`, 98 unless set — sees every screen in every model, so an
 * installation cannot lock out the last administrator.
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

    /** `usertype`, `permissions` or `mixed`; anything else reads as `usertype`. */
    public static function mode(): string
    {
        $mode = strtolower(trim((string) Settings::getSetting(self::MODE_SETTING, 'usertype')));

        return in_array($mode, ['permissions', 'mixed'], true) ? $mode : 'usertype';
    }

    /** Whether grants take part in the decision — `permissions` or `mixed`. */
    public static function usesPermissions(): bool
    {
        return self::mode() !== 'usertype';
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

        $mode = self::mode();
        if ($mode === 'usertype') {
            return $usertype >= $minUserType;
        }

        if ($usertype >= self::superuserUsertype()) {
            return true;
        }

        // ponytail: the three-valued ask bypasses Permissions' per-request cache, so a menu of
        // ~17 screens resolves ~17 times per admin page. Memoise per user and request if that
        // shows up in a profile; a memo here would go stale when a caller writes grants through
        // Permissions::allow()/deny() directly.
        try {
            // Three answers: allowed, denied, or no rule at all — a deny beats an allow.
            $verdict = Permissions::getInstance()->isAllowed(
                (int) $user->userid,
                $ability,
                self::PRIVILEGE,
                '',
                'module',
                'user',
                false
            );
        } catch (\Throwable $e) {
            // Closed, not open: a permission may be the only thing standing between an account
            // and the screen, so a store that cannot answer answers no.
            \Pramnos\Logs\Logger::logError('AdminAccess: ' . $e->getMessage(), $e);

            return false;
        }

        if ($verdict !== null) {
            return $verdict === true;
        }

        // No rule: closed under `permissions`, the floor under `mixed`.
        return $mode === 'mixed' && $usertype >= $minUserType;
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
            WebhookService::permissionsChanged($subjectType, $subjectId, ['operation' => 'update']);
        }

        return ['added' => $add, 'removed' => $remove];
    }

    /**
     * The decisions made directly for a user or a role, as `ability => 'allow'|'deny'`.
     *
     * Screens with neither are absent — they are decided by default: closed under
     * `permissions`, by the floor under `mixed`.
     *
     * @param 'user'|'role' $subjectType
     * @return array<string, 'allow'|'deny'>
     */
    public static function decisionsFor(string $subjectType, int $subjectId): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->schema()->hasTable('authserver.permissions')) {
            return [];
        }

        $result = $db->queryBuilder()
            ->table('authserver.permissions')
            ->select(['object_type', 'grant_type'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('action', self::PRIVILEGE)
            ->whereNull('object_id')
            ->get();

        $known = array_keys(self::abilities());
        $decisions = [];
        while ($result && $result->fetch()) {
            $ability = (string) $result->fields['object_type'];
            if (!in_array($ability, $known, true)) {
                continue;
            }
            // A deny recorded beside an allow is what decides, so it is what is shown.
            $decisions[$ability] = ($result->fields['grant_type'] === 'deny' || ($decisions[$ability] ?? '') === 'deny')
                ? 'deny' : 'allow';
        }

        return $decisions;
    }

    /**
     * Record, for each named screen, an allow, a deny, or neither (`default`).
     *
     * Only screens in `$grantable` change — an editor cannot allow, deny or clear a screen they
     * cannot open themselves — and only the rows this writes: allow and deny for `view` with no
     * record id. A deny is stored above an allow in priority, as Permissions stores it.
     *
     * @param 'user'|'role'                          $subjectType
     * @param array<string, 'allow'|'deny'|'default'> $decisions
     * @param list<string>                            $grantable
     * @return list<string> The screens whose decision changed
     */
    public static function setDecisions(
        string $subjectType,
        int $subjectId,
        array $decisions,
        array $grantable,
        ?int $grantedBy = null
    ): array {
        if (!in_array($subjectType, ['user', 'role'], true) || $subjectId <= 0) {
            throw new \InvalidArgumentException('Grants are made to a user or a role.');
        }

        $allowed = array_values(array_intersect(array_keys(self::abilities()), $grantable));
        $current = self::decisionsFor($subjectType, $subjectId);
        $db      = \Pramnos\Framework\Factory::getDatabase();
        $changed = [];

        foreach ($decisions as $ability => $decision) {
            $ability  = (string) $ability;
            $decision = in_array($decision, ['allow', 'deny'], true) ? $decision : 'default';
            if (!in_array($ability, $allowed, true) || ($current[$ability] ?? 'default') === $decision) {
                continue;
            }

            $db->queryBuilder()->table('authserver.permissions')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('object_type', $ability)
                ->where('action', self::PRIVILEGE)
                ->whereIn('grant_type', ['allow', 'deny'])
                ->whereNull('object_id')
                ->delete();

            if ($decision !== 'default') {
                $db->queryBuilder()->table('authserver.permissions')->insert([
                    'subject_type' => $subjectType,
                    'subject_id'   => $subjectId,
                    'object_type'  => $ability,
                    'object_id'    => null,
                    'action'       => self::PRIVILEGE,
                    'grant_type'   => $decision,
                    'priority'     => $decision === 'deny' ? 1100 : 100,
                    'is_active'    => true,
                    'granted_by'   => $grantedBy,
                ]);
            }
            $changed[] = $ability;
        }

        if ($changed !== []) {
            Permissions::getInstance()->clearCache();
            WebhookService::permissionsChanged($subjectType, $subjectId, ['operation' => 'update']);
        }

        return $changed;
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
