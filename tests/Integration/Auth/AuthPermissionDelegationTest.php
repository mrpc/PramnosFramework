<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\Permissions;
use Pramnos\Framework\Factory;

/**
 * Covers the three `Auth` methods that delegate to the permission system.
 *
 * `useraccess()`, `groupaccess()` and `setaccess()` reached the permission
 * store through `pramnos_factory::getPermissions()` — a class that exists
 * nowhere in the framework or its dependencies (`Factory::getPermissions()`
 * does exist; the legacy `pramnos_factory` alias does not). Calling any of them
 * raised `Error: Class "pramnos_factory" not found` before a single permission
 * was consulted, so the framework's own documented way of asking about access
 * was unreachable regardless of database or table.
 *
 * These are integration tests rather than unit tests because delegation is only
 * meaningful if it arrives somewhere: each one goes through `Permissions` to a
 * real store.
 */
class AuthPermissionDelegationTest extends TestCase
{
    /** @var Auth The object under test */
    private Auth $auth;

    /**
     * Point the framework at the test database, exactly as the other
     * permission integration tests do, and provision a store to answer from.
     */
    protected function setUp(): void
    {
        if (!\defined('CONFIG')) {
            \define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        \Pramnos\Application\Settings::loadSettings(
            \ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );

        $database = Factory::getDatabase();
        if (!$database->connected) {
            $database->connect();
        }

        \Pramnos\User\User::setupDb();
        Permissions::setupDb(false);

        $this->auth = new Auth();
    }

    /**
     * `useraccess()` reaches the permission system and comes back with a verdict.
     *
     * The assertion is deliberately about the *shape* of the answer, not its
     * value: what this pins is that the call arrives at `Permissions` at all.
     * Before the fix it could not — it named a class that does not exist, so it
     * raised an `Error` before any store was consulted.
     */
    public function testUseraccessReachesThePermissionSystem(): void
    {
        // Act
        $result = $this->auth->useraccess(5, 'module', 'delegationprobe', 'read');

        // Assert
        $this->assertTrue(
            is_bool($result) || $result === null,
            'useraccess() must answer through Permissions'
        );
    }

    /**
     * `groupaccess()` delegates the same way, for the group side of the ACL.
     */
    public function testGroupaccessReachesThePermissionSystem(): void
    {
        // Act
        $result = $this->auth->groupaccess(3, 'module', 'delegationprobe', 'read');

        // Assert
        $this->assertTrue(
            is_bool($result) || $result === null,
            'groupaccess() must answer through Permissions'
        );
    }

    /**
     * `setaccess()` writes a grant that `useraccess()` then reads back.
     *
     * This is the round trip the pair is for, and it is what proves the two
     * methods reach the *same* store rather than each finding its own. The
     * grant is removed again so the test leaves no row behind.
     */
    public function testSetaccessWritesAGrantUseraccessCanRead(): void
    {
        // Arrange — value 1 is this API's "allow"
        $this->auth->setaccess(
            4242, 'module', 'delegationprobe', 'read', '', 'user', '', 1
        );

        // Act
        $granted = $this->auth->useraccess(4242, 'module', 'delegationprobe', 'read');

        // Assert
        $this->assertTrue((bool) $granted, 'the grant just written must be readable');

        // Cleanup — value 2 removes the record rather than denying it
        $this->auth->setaccess(
            4242, 'module', 'delegationprobe', 'read', '', 'user', '', 2
        );

        // ...and the removal must take effect, which also covers the delete path
        $this->assertNotTrue(
            $this->auth->useraccess(4242, 'module', 'delegationprobe', 'read'),
            'the grant must be gone after removal'
        );
    }

    /**
     * No delegation point names the removed factory any more.
     *
     * The three call sites are easy to reintroduce by copying a neighbouring
     * line, and the failure only surfaces at runtime on a path few tests reach.
     * Reading the source settles it statically.
     */
    public function testNoCallSiteNamesTheRemovedFactory(): void
    {
        // Arrange
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Auth/Auth.php'
        );

        // Act + Assert
        $this->assertStringNotContainsString('pramnos_factory', (string) $source);
    }
}
