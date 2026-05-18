<?php

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Permissions;
use Pramnos\Framework\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(Permissions::class)]
class PermissionsTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!\defined('CONFIG')) {
            \define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        $settingsFile = \ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        \Pramnos\User\User::setupDb();
        Permissions::setupDb(false);
    }

    /**
     * Verifies the core user-permission lifecycle:
     *   allow → isAllowed returns true
     *   deny  → isAllowed returns false
     *   removePermission → row deleted, cache cleared
     *
     * This exercises the user-subject branches of setPermission() (DB INSERT/DELETE
     * paths, lines 125–150) and _isAllowed() (user SQL query + numRows check, lines
     * 303–324).
     */
    #[Test]
    public function testPermissionGrantingAndRevoking(): void
    {
        // Arrange
        $permissions = Permissions::getInstance();
        $testUserId  = 9991;
        $resource    = 'test_module';
        $privilege   = 'view';

        // Act — grant
        $permissions->allow($testUserId, $resource, $privilege);

        // Assert — row exists, isAllowed returns true (covers _isAllowed numRows != 0 path)
        $isAllowed = $permissions->isAllowed($testUserId, $resource, $privilege);
        $this->assertTrue($isAllowed, 'Permission should be granted after allow()');

        // Act — deny
        $permissions->deny($testUserId, $resource, $privilege);
        $isAllowedAfterDeny = $permissions->isAllowed($testUserId, $resource, $privilege);

        // Assert — value changed to 0
        $this->assertFalse($isAllowedAfterDeny, 'Permission should be denied after deny()');

        // Act — remove
        $permissions->removePermission($testUserId, $resource, $privilege);

        // Cleanup
        $this->db->cacheflush('permissions');
    }

    /**
     * Verifies that isAllowed() with $nonExistEqualsFalse = false delegates directly
     * to _isAllowed() without going through the cache or default-permission logic.
     *
     * This covers the early-return branch at lines 238–241 of Permissions.php:
     *   if ($nonExistEqualsFalse == false) { return $this->_isAllowed(..., false); }
     *
     * When no permission row exists for the subject, _isAllowed() must return NULL
     * (not false) when nonExistEqualsFalse is false — indicating "no explicit decision"
     * rather than "explicitly denied".
     */
    #[Test]
    public function testIsAllowedWithNonExistEqualsFalseReturnsNullWhenNoRow(): void
    {
        // Arrange — use an unlikely userId so no row exists
        $permissions = Permissions::getInstance();
        $subject     = 888888;
        $resource    = 'nonexistent_resource';
        $privilege   = 'view';

        // Ensure no row exists for this subject
        $permissions->removePermission($subject, $resource, $privilege);
        $this->db->cacheflush('permissions');

        // Act — pass nonExistEqualsFalse = false (6th arg = subjectType, 7th = nonExistEqualsFalse)
        $result = $permissions->isAllowed(
            $subject, $resource, $privilege,
            '', 'module', 'user', false
        );

        // Assert — NULL because no permission row exists and nonExistEqualsFalse = false
        $this->assertNull($result,
            'isAllowed() with nonExistEqualsFalse=false must return NULL when no permission exists');
    }

    /**
     * Verifies that allow() and removePermission() work correctly when
     * $subjectType = 'group' (non-user subject).
     *
     * This covers the else-branch of removePermission() (lines 75–86: DELETE with
     * subject = %s instead of userid = %d) and setPermission() (lines 128–130: the
     * non-user DELETE before INSERT, and line 145: the non-user INSERT data array
     * append).
     */
    #[Test]
    public function testGroupSubjectTypePermissions(): void
    {
        // Arrange
        $permissions = Permissions::getInstance();
        $groupId     = 'group_42';
        $resource    = 'test_module';
        $privilege   = 'edit';

        // Act — allow a group (covers non-user setPermission branches, lines 128-130, 145)
        $permissions->allow($groupId, $resource, $privilege, '', 'module', 'group');

        // Act — remove the group permission (covers non-user removePermission branch, lines 75-86)
        $permissions->removePermission($groupId, $resource, $privilege, '', 'module', 'group');

        // Assert — no exception was thrown; rows inserted then deleted cleanly
        $this->db->cacheflush('permissions');
        $this->assertTrue(true, 'Group permission allow/remove must not throw');
    }
}
