<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;

/**
 * Covers the three `Auth` methods that delegate to the permission system.
 *
 * `useraccess()`, `groupaccess()` and `setaccess()` reached the permission
 * store through `pramnos_factory::getPermissions()` — a class that exists
 * nowhere in the framework or its dependencies. Calling any of them raised
 * `Error: Class "pramnos_factory" not found` before a single permission was
 * ever consulted, so the framework's own documented way of asking about access
 * was unreachable regardless of database or table.
 *
 * These tests pin the delegation itself. A database-level failure is a working
 * delegation — the call reached the permission system and got as far as the
 * store; a missing-class `Error` is not.
 */
class AuthPermissionDelegationTest extends TestCase
{
    /**
     * Run a delegating call and assert it reached the permission system.
     *
     * What is being excluded is one specific failure: naming a class that does
     * not exist. Anything the store itself does — answering, or failing to
     * reach a database that this unit test deliberately does not provide — means
     * the delegation worked.
     *
     * @param callable $call The Auth method invocation under test
     */
    private function assertReachesPermissions(callable $call): void
    {
        try {
            $call();
        } catch (\Error $e) {
            $this->assertStringNotContainsString(
                'pramnos_factory',
                $e->getMessage(),
                'the call must reach Pramnos\Auth\Permissions, not a class that does not exist'
            );

            return;
        } catch (\Throwable) {
            // A store-level failure: the delegation is what is under test here,
            // not the presence of a database.
        }

        $this->addToAssertionCount(1);
    }

    /**
     * `useraccess()` reaches the permission system and comes back with a verdict.
     */
    public function testUseraccessReachesThePermissionSystem(): void
    {
        // Arrange
        $auth = new Auth();

        // Act
        $result = $auth->useraccess(5, 'module', 'customer', 'read');

        // Assert — a verdict, not a crash. Without a store the answer is the
        // documented default rather than an exception, so this path returns.
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
        // Arrange
        $auth = new Auth();

        // Act
        $result = $auth->groupaccess(3, 'module', 'customer', 'read');

        // Assert
        $this->assertTrue(
            is_bool($result) || $result === null,
            'groupaccess() must answer through Permissions'
        );
    }

    /**
     * `setaccess()` writes through the same instance.
     *
     * Unlike the two read methods, this one reaches the store unguarded — the
     * write side of `Permissions` still targets the legacy table directly — so
     * without a database it raises a database exception. That is the point of
     * the assertion helper: a database error proves the call arrived, where a
     * missing-class error proved it never did.
     */
    public function testSetaccessReachesThePermissionSystem(): void
    {
        // Arrange
        $auth = new Auth();

        // Act + Assert
        $this->assertReachesPermissions(
            static fn() => $auth->setaccess(5, 'module', 'customer', 'read', '', 'user', '', 2)
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
