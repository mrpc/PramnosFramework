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
     * @param string|null     $group        Heading this item sits under in a sidebar, e.g. `System`.
     *
     *                                      Distinct from `$parent`, and the difference is what a
     *                                      reader sees: a parent is an item you can click, with its
     *                                      children folded under it; a group is a **label** over a
     *                                      block of items, always visible. A list of fifteen
     *                                      administration screens with neither is a list nobody
     *                                      reads, and folding operational screens under an
     *                                      unrelated item — Logs under "Dashboard" — hides them
     *                                      behind a name that does not describe them.
     *
     *                                      A theme that does not render groups is unaffected: the
     *                                      items are still returned in position order.
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
        public ?string     $group        = null,
        /**
         * A number to show beside the label, as a callable that produces it.
         *
         * A callable rather than an `int`, because navigation is registered once at boot and
         * rendered on every request: a number resolved at registration would be the count as it
         * was when the application started, which for an unread badge is always wrong and
         * usually zero.
         *
         * It is called with the signed-in user's id and must be cheap — an indexed `COUNT`, not
         * a join. {@see badgeCount()} memoises it for the request and answers zero if it throws.
         *
         * @var (callable(int): int)|null
         */
        public ?\Closure $badge = null,
    ) {}

    /**
     * The number to show, or zero.
     *
     * Memoised in a function static, because the class is `readonly` — which forbids both an
     * instance property to cache in and a static one to hold a map. Per request rather than per
     * instance for the reason a badge is memoised at all: a theme reads the navigation more
     * than once per page (a header and a mobile menu are two renders of the same list), and a
     * badge is not worth two queries.
     *
     * Zero on any failure. A count is decoration on a screen that is about something else, and
     * a navigation item that throws takes every page with it.
     */
    public function badgeCount(int $userId): int
    {
        static $resolved = [];

        if ($this->badge === null || $userId < 1) {
            return 0;
        }

        $key = $this->id . '|' . $userId;

        if (isset($resolved[$key])) {
            return $resolved[$key];
        }

        try {
            return $resolved[$key] = max(0, (int) ($this->badge)($userId));
        } catch (\Throwable) {
            return $resolved[$key] = 0;
        }
    }

    /**
     * How a badge over ninety-nine is written.
     *
     * `99+`, because the difference between a hundred and four hundred unread is not a
     * difference anybody acts on, and a four-digit badge is wider than the label it sits beside.
     */
    public function badgeLabel(int $userId): string
    {
        $count = $this->badgeCount($userId);

        return $count > 99 ? '99+' : (string) $count;
    }
}
