<?php

declare(strict_types=1);

namespace Pramnos\User;

/**
 * The usertype each role starts at — one place, instead of a number in every screen.
 *
 * Every screen and tool the framework ships asks for a role, not a number. An installation
 * whose administrators are 97 says so once:
 *
 * ```php
 * // app/app.php
 * 'roles' => ['admin' => 97, 'super' => 98, 'root' => 99],
 * ```
 *
 * | role | default | what asks for it |
 * |---|---|---|
 * | `manager` | 80 | the day-to-day screens: dashboard, queue, emails, organisations, services, a user's own tokens |
 * | `admin` | 90 | what decides for everyone: permissions, roles, applications, every token, phpinfo, GDPR requests |
 * | `super` | 98 | the administration area's floor and its superuser, invitations |
 * | `root` | 99 | the developer tools: the DevPanel, Adminer, the debug bar's grants |
 *
 * A key a tool already had — `devpanel.min_usertype`, `devpanel.adminer_min_usertype`,
 * `debug.grant_min_usertype`, the `admin_*_usertype` settings — still wins over its role.
 */
final class Roles
{
    public const MANAGER = 'manager';

    public const ADMIN = 'admin';

    public const SUPER = 'super';

    public const ROOT = 'root';

    /** Each role's usertype unless `app.php` says otherwise. */
    public const DEFAULTS = [
        self::MANAGER => 80,
        self::ADMIN   => 90,
        self::SUPER   => 98,
        self::ROOT    => 99,
    ];

    /**
     * The usertype a role starts at: `app.php`'s `roles`, or the default.
     *
     * @throws \InvalidArgumentException For a role nobody defined — a typo must not open a screen at 0
     */
    public static function level(string $role): int
    {
        $configured = \Pramnos\Application\Application::currentInstance()?->applicationInfo['roles'][$role] ?? null;

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }
        if (!isset(self::DEFAULTS[$role])) {
            throw new \InvalidArgumentException('No role "' . $role . '": ' . implode(', ', array_keys(self::DEFAULTS)) . '.');
        }

        return self::DEFAULTS[$role];
    }

    /** Whether a person holds a role: somebody, of its usertype or more. */
    public static function atLeast(?object $user, string $role): bool
    {
        return $user !== null && (int) ($user->usertype ?? 0) >= self::level($role);
    }
}
