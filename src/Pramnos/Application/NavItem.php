<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * Immutable value object representing a single navigation bar entry.
 *
 * Instances are created by framework controllers and application code and
 * handed to NavRegistry::register().  All properties are readonly — create a
 * new NavItem instead of mutating an existing one.
 *
 * Permission model:
 *   - guestOnly      — item is hidden for authenticated users (e.g. Login link)
 *   - requireAuth    — item is hidden for guests
 *   - minUserType    — minimum usertype integer (0 = all authenticated users)
 *   - permission     — RBAC permission name; null skips the RBAC check
 *   - feature        — item is hidden unless this feature is in $enabledFeatures
 *
 * Both minUserType and permission must pass when set; the stricter always wins.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
readonly class NavItem
{
    /**
     * @param string          $id           Unique identifier used for remove/override (e.g. 'admin.logs').
     * @param string          $label        Display label shown in the nav.
     * @param string          $url          Full URL (typically built with sURL constant).
     * @param NavSection      $section      Which nav section this item belongs to.
     * @param int             $position     Sort order within the section (lower = left/first).
     * @param bool            $requireAuth  If true, hidden when no user is logged in.
     * @param int             $minUserType  Minimum usertype; 0 means any authenticated user.
     * @param string|null     $permission   RBAC permission name, or null to skip RBAC check.
     * @param string|null     $feature      Required feature key from applicationInfo['features'], or null.
     * @param string|null     $icon         Optional CSS icon class (e.g. Bootstrap Icons 'bi-journal').
     * @param bool            $guestOnly    If true, hidden when a user IS logged in (e.g. Login link).
     * @param string|null     $parent       Parent item id for nested dropdown rendering, or null for top-level.
     */
    public function __construct(
        public string      $id,
        public string      $label,
        public string      $url,
        public NavSection  $section,
        public int         $position    = 50,
        public bool        $requireAuth  = false,
        public int         $minUserType  = 0,
        public ?string     $permission   = null,
        public ?string     $feature      = null,
        public ?string     $icon         = null,
        public bool        $guestOnly    = false,
        public ?string     $parent       = null,
    ) {}
}
