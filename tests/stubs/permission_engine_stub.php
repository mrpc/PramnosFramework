<?php

/**
 * Test-only stub for the optional Pramnos\Auth\PermissionEngine addon.
 *
 * NavRegistry::isVisible() checks class_exists(\Pramnos\Auth\PermissionEngine::class)
 * at runtime. Without this stub the RBAC branch (lines 171-173 of NavRegistry.php)
 * is never executed in the test suite.
 *
 * The $allow flag is static so individual tests can flip it:
 *   \Pramnos\Auth\PermissionEngine::$allow = false;  // deny
 *   \Pramnos\Auth\PermissionEngine::$allow = true;   // allow (default)
 */
namespace Pramnos\Auth {
    if (!class_exists(PermissionEngine::class, false)) {
        class PermissionEngine
        {
            public static bool $allow = true;

            public static function userHas(mixed $user, string $permission): bool
            {
                return self::$allow;
            }
        }
    }
}
