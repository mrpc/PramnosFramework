<?php

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Permissions;

/**
 * Unit tests for Pramnos\Auth\Permissions (no-DB paths).
 *
 * Most Permissions methods interact with the database. This file covers the
 * pure-logic paths that can be exercised without a live connection:
 *
 * - __construct() stores the storage method
 * - setDefaultPermission() stores a boolean in $_defaut and returns $this
 * - allow() with a single privilege delegates to setPermission() with value=true
 * - allow() with an array of privileges loops and delegates each one
 * - deny() with a single privilege delegates to setPermission() with value=false
 * - deny() with an array of privileges loops and delegates each one
 * - isAllowed() returns the cached value when a cache entry already exists,
 *   without touching the database at all
 *
 * The anonymous-subclass pattern is used throughout so that the protected
 * setPermission() method can be overridden (to avoid DB) while the rest of
 * the class under test runs unmodified.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Permissions::class)]
class PermissionsTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return a Permissions subclass that records every setPermission() call
     * instead of touching a database, and exposes internal state for assertions.
     *
     * @return Permissions&object{permissionsSet: array, exposeDefaut(): array, exposeCache(): array, setCache(array): void}
     */
    private function makeTestable(): Permissions
    {
        return new class('test') extends Permissions {
            /** @var array<int, array<string, mixed>> Recorded setPermission calls */
            public array $permissionsSet = [];

            /** Override to capture calls without touching a database. */
            protected function setPermission(
                $subject, $resource, $privilege,
                $resourceElement = '', $resourceType = 'module',
                $subjectType = 'user', $value = false
            ): static {
                $this->permissionsSet[] = [
                    'subject'         => $subject,
                    'resource'        => $resource,
                    'privilege'       => $privilege,
                    'resourceElement' => $resourceElement,
                    'resourceType'    => $resourceType,
                    'subjectType'     => $subjectType,
                    'value'           => $value,
                ];
                return $this;
            }

            /** Expose the protected $_defaut array for assertions. */
            public function exposeDefaut(): array
            {
                return $this->_defaut;
            }

            /** Expose the protected $_cache array for assertions. */
            public function exposeCache(): array
            {
                return $this->_cache;
            }

            /** Allow tests to pre-populate the internal permission cache. */
            public function setCache(array $cache): void
            {
                $this->_cache = $cache;
            }
        };
    }

    // =========================================================================
    // __construct
    // =========================================================================

    /**
     * The constructor must store the storage method in $_storageMethod.
     *
     * A custom value ('memcache', 'custom') must be preserved exactly — the
     * default of 'database' is merely the fallback declared in the property.
     */
    public function testConstructorStoresStorageMethod(): void
    {
        // Arrange + Act
        $default = new Permissions();
        $custom  = new Permissions('custom');

        // Assert — use reflection because $_storageMethod is protected
        $refDefault = new \ReflectionProperty(Permissions::class, '_storageMethod');

        $this->assertSame('database', $refDefault->getValue($default),
            'default storage method must be "database"');
        $this->assertSame('custom', $refDefault->getValue($custom),
            'custom storage method must be stored verbatim');
    }

    // =========================================================================
    // setDefaultPermission
    // =========================================================================

    /**
     * setDefaultPermission() must store the privilege as a boolean and return $this
     * to allow method chaining.
     *
     * The internal $_defaut array is the mechanism by which a default fallback
     * is applied for privileges that have no explicit DB row (lines 296–300 of
     * the production code, covered by integration tests).
     */
    public function testSetDefaultPermissionStoresBooleanValue(): void
    {
        // Arrange
        $p = $this->makeTestable();

        // Act
        $returned = $p->setDefaultPermission('view', true);

        // Assert — returns $this for chaining
        $this->assertSame($p, $returned, 'setDefaultPermission must return $this');

        // Assert — the privilege is stored as a boolean
        $defaut = $p->exposeDefaut();
        $this->assertArrayHasKey('view', $defaut);
        $this->assertTrue($defaut['view'], '"view" must be stored as true');
    }

    /**
     * setDefaultPermission() must coerce truthy and falsy values to strict booleans.
     *
     * The production code casts via (bool) — a string "1" becomes true, 0 becomes
     * false. This invariant matters because _isAllowed() does identity comparisons
     * against the stored value.
     */
    public function testSetDefaultPermissionCastsValueToBool(): void
    {
        // Arrange
        $p = $this->makeTestable();

        // Act — pass truthy / falsy non-boolean values
        $p->setDefaultPermission('edit', 1);
        $p->setDefaultPermission('delete', 0);

        // Assert
        $defaut = $p->exposeDefaut();
        $this->assertIsBool($defaut['edit'],   'stored value must be a bool, not int');
        $this->assertIsBool($defaut['delete'],  'stored value must be a bool, not int');
        $this->assertTrue($defaut['edit'],    '1 must coerce to true');
        $this->assertFalse($defaut['delete'], '0 must coerce to false');
    }

    /**
     * Multiple calls to setDefaultPermission() must accumulate entries — a second
     * call for the same privilege must overwrite the first, while different
     * privileges are stored independently.
     */
    public function testSetDefaultPermissionAccumulatesMultiplePrivileges(): void
    {
        // Arrange
        $p = $this->makeTestable();

        // Act
        $p->setDefaultPermission('view', true);
        $p->setDefaultPermission('edit', false);
        $p->setDefaultPermission('view', false); // overwrite

        // Assert
        $defaut = $p->exposeDefaut();
        $this->assertCount(2, $defaut, 'two distinct privileges must be stored');
        $this->assertFalse($defaut['view'], '"view" must be overwritten to false');
        $this->assertFalse($defaut['edit'], '"edit" must remain false');
    }

    // =========================================================================
    // allow()
    // =========================================================================

    /**
     * allow() with a single-string privilege must call setPermission() exactly
     * once with value=true.
     *
     * This verifies the delegation contract between allow() and setPermission().
     */
    public function testAllowSinglePrivilegeCallsSetPermissionWithTrue(): void
    {
        // Arrange
        $p = $this->makeTestable();

        // Act
        $returned = $p->allow(42, 'blog', 'view', '', 'module', 'user');

        // Assert — returns $this
        $this->assertSame($p, $returned, 'allow must return $this');

        // Assert — exactly one call was recorded with value=true
        $this->assertCount(1, $p->permissionsSet, 'exactly one setPermission call expected');
        $call = $p->permissionsSet[0];
        $this->assertSame(42,       $call['subject'],   'subject must pass through');
        $this->assertSame('blog',   $call['resource'],  'resource must pass through');
        $this->assertSame('view',   $call['privilege'], 'privilege must pass through');
        $this->assertTrue($call['value'], 'allow() must pass value=true to setPermission()');
    }

    /**
     * allow() with an array of privileges must call setPermission() for each
     * element in the array and return $this.
     *
     * This covers the quick-allow mass-privilege branch (lines 171–178).
     */
    public function testAllowArrayOfPrivilegesCallsSetPermissionForEach(): void
    {
        // Arrange
        $p = $this->makeTestable();
        $privileges = ['view', 'edit', 'delete'];

        // Act
        $returned = $p->allow(1, 'posts', $privileges);

        // Assert — returns $this
        $this->assertSame($p, $returned, 'allow must return $this when given an array');

        // Assert — three calls recorded (one per privilege)
        $this->assertCount(3, $p->permissionsSet,
            'one setPermission call must be made per privilege in the array');

        // Extract privilege names that were set
        $calledPrivileges = array_column($p->permissionsSet, 'privilege');
        foreach ($privileges as $priv) {
            $this->assertContains($priv, $calledPrivileges,
                "privilege '{$priv}' must have been passed to setPermission()");
        }
    }

    // =========================================================================
    // deny()
    // =========================================================================

    /**
     * deny() with a single-string privilege must call setPermission() exactly
     * once with value=false.
     *
     * The only difference between allow() and deny() at the delegation level
     * is the value argument — this test pins that contract.
     */
    public function testDenySinglePrivilegeCallsSetPermissionWithFalse(): void
    {
        // Arrange
        $p = $this->makeTestable();

        // Act
        $returned = $p->deny(42, 'blog', 'delete', '', 'module', 'user');

        // Assert — returns $this
        $this->assertSame($p, $returned, 'deny must return $this');

        // Assert — one call recorded with value=false
        $this->assertCount(1, $p->permissionsSet, 'exactly one setPermission call expected');
        $this->assertFalse($p->permissionsSet[0]['value'],
            'deny() must pass value=false to setPermission()');
    }

    /**
     * deny() with an array of privileges must call setPermission() for each
     * element in the array and return $this.
     *
     * This covers the quick-deny mass-privilege branch (lines 202–209).
     */
    public function testDenyArrayOfPrivilegesCallsSetPermissionForEach(): void
    {
        // Arrange
        $p = $this->makeTestable();
        $privileges = ['edit', 'delete'];

        // Act
        $returned = $p->deny(5, 'pages', $privileges);

        // Assert — returns $this
        $this->assertSame($p, $returned);

        // Assert — two calls recorded (one per privilege in the array)
        $this->assertCount(2, $p->permissionsSet,
            'one setPermission call must be made per privilege in the array');

        $calledPrivileges = array_column($p->permissionsSet, 'privilege');
        foreach ($privileges as $priv) {
            $this->assertContains($priv, $calledPrivileges,
                "privilege '{$priv}' must have been passed to setPermission()");
        }
    }

    // =========================================================================
    // isAllowed() — cache-hit path
    // =========================================================================

    /**
     * isAllowed() must return the cached value when an entry already exists for
     * the (subject, resource, privilege, resourceElement, resourceType, subjectType)
     * tuple, without making any database query.
     *
     * This covers the cache-hit branch at lines 244–247 of the production code.
     * The subclass does not override _isAllowed(), so any cache miss would trigger
     * a Factory::getDatabase() call and fail the test — confirming the cache really
     * short-circuits the method.
     */
    public function testIsAllowedReturnsCachedValueWithoutDatabaseQuery(): void
    {
        // Arrange — populate the cache directly
        $p = $this->makeTestable();

        // The six-dimensional cache key mirrors isAllowed()'s internal structure
        $p->setCache([
            42 => [
                'blog' => [
                    'view' => [
                        '' => [
                            'module' => [
                                'user' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Act — default $nonExistEqualsFalse=true goes to the cache branch
        $result = $p->isAllowed(42, 'blog', 'view', '', 'module', 'user');

        // Assert — must return the cached true without touching the database
        $this->assertTrue($result, 'isAllowed must return the cached value (true)');
    }

    /**
     * isAllowed() must return false from cache when the cached entry is false.
     *
     * Complements the previous test: the cache is a trusted source of both
     * allowed (true) and denied (false) decisions.
     */
    public function testIsAllowedReturnsFalseFromCache(): void
    {
        // Arrange
        $p = $this->makeTestable();
        $p->setCache([
            99 => [
                'admin' => [
                    'delete' => [
                        '' => [
                            'module' => [
                                'user' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Act
        $result = $p->isAllowed(99, 'admin', 'delete', '', 'module', 'user');

        // Assert
        $this->assertFalse($result, 'isAllowed must return the cached value (false)');
    }
}
