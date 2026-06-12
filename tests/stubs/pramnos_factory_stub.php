<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Minimal stub for the legacy pramnos_factory class as seen from the Pramnos\Auth namespace.
 *
 * Auth::setaccess(), Auth::useraccess(), and Auth::groupaccess() call
 * pramnos_factory::getPermissions() without a leading backslash, so PHP resolves
 * the name to Pramnos\Auth\pramnos_factory. The real class is application-level
 * and does not exist inside the framework repo. This stub provides just enough
 * surface for unit tests to exercise those code paths without a real database.
 *
 * Loaded unconditionally from tests/bootstrap.php.
 */

/**
 * Permissions mock class used for stubbing access checks in Unit Tests.
 *
 * Simulates permission persistence and validation logic.
 *
 * @internal test stub only
 */
class PramnosTestPermissionsStub
{
    /**
     * Grants a permission to a user or group.
     *
     * @param int $id The user or group ID.
     * @param string $moduleid The module identifier.
     * @param string $what The action to allow.
     * @param mixed $elementid Optional specific entity identifier.
     * @param string $moduletype The type of access level (user, group, etc).
     * @param string $onwhat Additional target description.
     */
    public function allow(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $onwhat): void {}

    /**
     * Revokes or denies a permission.
     *
     * @param int $id The user or group ID.
     * @param string $moduleid The module identifier.
     * @param string $what The action to deny.
     * @param mixed $elementid Optional specific entity identifier.
     * @param string $moduletype The type of access level (user, group, etc).
     * @param string $onwhat Additional target description.
     */
    public function deny(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $onwhat): void {}

    /**
     * Removes a permission entry entirely.
     *
     * @param int $id The user or group ID.
     * @param string $moduleid The module identifier.
     * @param string $what The action to remove.
     * @param mixed $elementid Optional specific entity identifier.
     * @param string $moduletype The type of access level (user, group, etc).
     * @param string $onwhat Additional target description.
     */
    public function removePermission(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $onwhat): void {}

    /**
     * Checks if a user/group has permission for a specific action.
     *
     * @param int $id The user or group ID.
     * @param string $moduleid The module identifier.
     * @param string $what The action.
     * @param mixed $elementid Optional specific entity identifier.
     * @param string $moduletype The type of access level (user, group, etc).
     * @param string $check The specific verification rule.
     * @return bool True if allowed, false otherwise.
     */
    public function isAllowed(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $check): bool
    {
        return false;
    }
}

/**
 * Mock factory providing permissions service class.
 *
 * @internal test stub only
 */
class pramnos_factory
{
    /** @var PramnosTestPermissionsStub|null Static instance representing permission manager. */
    private static ?PramnosTestPermissionsStub $permissions = null;

    /**
     * Returns the singleton permissions stub instance.
     *
     * @return PramnosTestPermissionsStub
     */
    public static function &getPermissions(): PramnosTestPermissionsStub
    {
        if (self::$permissions === null) {
            self::$permissions = new PramnosTestPermissionsStub();
        }

        return self::$permissions;
    }
}
