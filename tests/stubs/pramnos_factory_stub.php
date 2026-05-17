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

/** @internal test stub only */
class PramnosTestPermissionsStub
{
    /** @param mixed $elementid */
    public function allow(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $onwhat): void {}

    /** @param mixed $elementid */
    public function deny(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $onwhat): void {}

    /** @param mixed $elementid */
    public function removePermission(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $onwhat): void {}

    /** @param mixed $elementid */
    public function isAllowed(int $id, string $moduleid, string $what,
        $elementid, string $moduletype, string $check): bool
    {
        return false;
    }
}

/** @internal test stub only */
class pramnos_factory
{
    private static ?PramnosTestPermissionsStub $permissions = null;

    public static function &getPermissions(): PramnosTestPermissionsStub
    {
        if (self::$permissions === null) {
            self::$permissions = new PramnosTestPermissionsStub();
        }

        return self::$permissions;
    }
}
