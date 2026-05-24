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
 * $features = \Pramnos\Application\Application::getInstance()->applicationInfo['features'] ?? [];
 * $nav = NavRegistry::getForUser($user, $features);
 *
 * foreach ($nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $item) {
 *     echo '<a href="' . htmlspecialchars($item->url) . '">' . htmlspecialchars($item->label) . '</a>';
 * }
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
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
     *   3. permission set    AND RBAC active                → PermissionEngine check
     *   4. permission set    AND RBAC not active            → permission check skipped (fallback to minUserType)
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

        // Rule 3 & 4 — RBAC permission check (only when RBAC is available)
        if ($item->permission !== null && $isLoggedIn) {
            if (class_exists(\Pramnos\Auth\PermissionEngine::class)) {
                if (!\Pramnos\Auth\PermissionEngine::userHas($user, $item->permission)) {
                    return false;
                }
            }
            // If PermissionEngine does not exist, fallback: permission check is skipped
        }

        // Rule 5 — feature gate
        if ($item->feature !== null && !in_array($item->feature, $enabledFeatures, true)) {
            return false;
        }

        return true;
    }
}
