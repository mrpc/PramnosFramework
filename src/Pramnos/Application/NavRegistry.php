<?php

declare(strict_types=1);

namespace Pramnos\Application;

use Pramnos\User\User;

/**
 * Central registry for navigation bar items.
 *
 * Framework controllers and application code register NavItems here at boot
 * time.  The theme header then calls NavRegistry::getForUser() to obtain the
 * filtered, sorted items for the current request — no hardcoded links in any
 * header.php file.
 *
 * ## Registration (typically inside Application::init() or a ServiceProvider)
 *
 * ```php
 * use Pramnos\Application\NavRegistry;
 * use Pramnos\Application\NavItem;
 * use Pramnos\Application\NavSection;
 *
 * NavRegistry::register(new NavItem(
 *     id:          'admin.logs',
 *     label:       'Logs',
 *     url:         sURL . 'logs',
 *     section:     NavSection::Admin,
 *     position:    10,
 *     requireAuth: true,
 *     minUserType: 80,
 * ));
 * ```
 *
 * ## Retrieval (inside header.php)
 *
 * ```php
 * $user = \Pramnos\User\User::getCurrentUser();
 * $features = \Pramnos\Application\Application::currentInstance()?->applicationInfo['features'] ?? [];
 * $nav = NavRegistry::getForUser($user, $features);
 *
 * foreach ($nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $item) {
 *     echo '<a href="' . htmlspecialchars($item->url) . '">' . htmlspecialchars($item->label) . '</a>';
 * }
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class NavRegistry
{
    /** @var array<string, NavItem> All registered items, keyed by id. */
    private static array $items = [];

    // =========================================================================
    // Registration
    // =========================================================================

    /**
     * Registers a nav item.
     *
     * If an item with the same id is already registered it is replaced,
     * allowing applications to override framework defaults by id.
     */
    public static function register(NavItem $item): void
    {
        static::$items[$item->id] = $item;
    }

    /**
     * Removes a previously registered item by id.
     *
     * Silent no-op when the id is not found.
     */
    public static function remove(string $id): void
    {
        unset(static::$items[$id]);
    }

    /**
     * Removes all registered items.
     *
     * Intended for test isolation — call in tearDown().
     */
    public static function reset(): void
    {
        static::$items = [];
    }

    /**
     * Returns all registered item ids.
     *
     * @return string[]
     */
    public static function getIds(): array
    {
        return array_keys(static::$items);
    }

    // =========================================================================
    // Retrieval
    // =========================================================================

    /**
     * Returns filtered and sorted nav items for the given user, grouped by section.
     *
     * Filtering rules (all must pass):
     *   0. guestOnly=true    AND user is logged in          → removed  (e.g. Login link)
     *   1. requireAuth=true  AND no user logged in          → removed
     *   2. minUserType > 0   AND user->usertype < min       → removed
     *   3. permission set    AND explicitly denied          → removed
     *   4. permission set    AND no rule for it              → kept (silence is not a deny)
     *   5. feature set       AND feature not in $features   → removed
     *
     * Within each section items are sorted ascending by position.
     *
     * @param  User|null  $user            Currently logged-in user, or null for guests.
     * @param  string[]   $enabledFeatures List of enabled feature keys from applicationInfo['features'].
     * @return array<string, NavItem[]>    Keyed by NavSection->value, each value sorted by position.
     */
    public static function getForUser(?User $user, array $enabledFeatures = []): array
    {
        $result = [];

        foreach (static::$items as $item) {
            if (!static::isVisible($item, $user, $enabledFeatures)) {
                continue;
            }
            $result[$item->section->value][] = $item;
        }

        // Sort each section by position ascending
        foreach ($result as $section => &$sectionItems) {
            usort($sectionItems, static fn(NavItem $a, NavItem $b) => $a->position <=> $b->position);
        }
        unset($sectionItems);

        return $result;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Determines whether a nav item is visible for the given user and features.
     */
    private static function isVisible(NavItem $item, ?User $user, array $enabledFeatures): bool
    {
        $isLoggedIn = ($user !== null && \Pramnos\Http\Session::staticIsLogged());

        // Rule 0 — guest-only items are hidden when a user is logged in
        if ($item->guestOnly && $isLoggedIn) {
            return false;
        }

        // Rule 1 — authentication required
        if ($item->requireAuth && !$isLoggedIn) {
            return false;
        }

        // Rule 2 — usertype minimum
        if ($item->minUserType > 0) {
            if (!$isLoggedIn || (int) $user->usertype < $item->minUserType) {
                return false;
            }
        }

        // Rule 3 & 4 — permission check
        //
        // This used to ask an optional `Pramnos\Auth\PermissionEngine` addon and,
        // finding it absent, skip the check — as the comment said out loud. The
        // addon exists nowhere, so every declared permission was skipped on every
        // installation and each item was shown to every signed-in user. That was
        // written before the framework had a permission system; it has one now,
        // and it ships with the `auth` feature, so every installation with users
        // has it.
        if ($item->permission !== null && $isLoggedIn) {
            if (!static::userHasPermission($user, $item->permission)) {
                return false;
            }
        }

        // Rule 5 — feature gate
        if ($item->feature !== null && !in_array($item->feature, $enabledFeatures, true)) {
            return false;
        }

        return true;
    }    /**
     * May this user use the thing behind that menu item?
     *
     * Asked of the framework's own permission store, through the small API that
     * knows how to read it. Three answers, and the third is the one that keeps
     * this usable:
     *
     *   - an explicit **deny** hides the item;
     *   - an explicit **allow** shows it;
     *   - **no rule at all** shows it, because an application that has declared
     *     a permission name but granted nothing to anybody would otherwise have
     *     an empty menu. Hiding navigation is not access control — the action
     *     behind the item enforces its own — so silence here means "no opinion",
     *     the same as everywhere else in this framework.
     *
     * A store that cannot be reached is also no opinion. A menu that empties
     * itself because the database hiccuped would be a worse failure than the one
     * this replaced.
     *
     * @param  object|null $user       The signed-in user
     * @param  string      $permission Permission name declared on the item
     * @return bool
     */
    protected static function userHasPermission($user, string $permission): bool
    {
        if (!is_object($user) || (int) ($user->userid ?? 0) < 2) {
            return false;
        }

        // An application with its own scheme is asked first: it is the one that
        // governs the rest of that application.
        if (method_exists($user, 'hasPermission')) {
            try {
                return (bool) $user->hasPermission($permission);
            } catch (\Throwable) {
                return true;
            }
        }

        try {
            $verdict = \Pramnos\Auth\Permissions::getInstance()->isAllowed(
                (int) $user->userid,
                $permission,
                'view',
                '',
                'module',
                'user',
                false
            );
        } catch (\Throwable) {
            return true;
        }

        return $verdict !== false;
    }


}
