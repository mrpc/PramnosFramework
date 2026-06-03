<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\TestCase;
use Pramnos\User\User;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * Unit tests for User.php
 *
 * Tests cover:
 * - Construction and new-user flag
 * - setPassword() with userid=0 (pending plain) vs userid>1 (bcrypt)
 * - verifyPassword()
 * - Magic __get / __set via otherinfo
 * - activate() / deactivate() on a new (non-persisted) user
 * - getTableNames() returns expected keys
 * - getCurrentUser() returns null when no session
 */
class UserUnitTest extends TestCase
{
    private $originalDb;
    private $dbMock;
    private $qbMock;

    protected function setUp(): void
    {
        // Save and inject mock DB so that any DB calls don't explode
        $dbRef = &Database::getInstance();
        $this->originalDb = $dbRef;

        $this->qbMock = $this->createMock(QueryBuilder::class);
        $this->qbMock->method('table')->willReturnSelf();
        $this->qbMock->method('select')->willReturnSelf();
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('limit')->willReturnSelf();
        $this->qbMock->method('get')->willReturn(new class {
            public int $numRows = 0;
            public array $fields = [];
            public function fetch(): bool { return false; }
        });
        $this->qbMock->method('first')->willReturn(false);

        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->type = 'mysql';
        $this->dbMock->method('queryBuilder')->willReturn($this->qbMock);
        $this->dbMock->method('cacheflush')->willReturn(null);

        $dbRef = $this->dbMock;
    }

    protected function tearDown(): void
    {
        $dbRef = &Database::getInstance();
        $dbRef = $this->originalDb;
        // Clear static caches
        $ref = new \ReflectionProperty(User::class, '_usercache');
        $ref->setValue(null, null);
        $ref2 = new \ReflectionProperty(User::class, 'usersCache');
        $ref2->setValue(null, []);
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    public function testNewUserHasIsNewFlag(): void
    {
        $user = new User(0);

        $ref = new \ReflectionProperty(User::class, '_isnew');
        $this->assertSame(1, $ref->getValue($user));
    }

    public function testDefaultUsername(): void
    {
        $user = new User(0);
        $this->assertSame('Anonymous', $user->username);
    }

    public function testDefaultUsertype(): void
    {
        $user = new User(0);
        $this->assertSame(0, $user->usertype);
    }

    // ── setPassword / verifyPassword ──────────────────────────────────────────

    public function testSetPasswordWithNoUseridStoresMd5Placeholder(): void
    {
        $user = new User(0); // _isnew=1, userid=1 default

        // userid is 1 by default which is NOT > 1, so it falls into the
        // "pending plain" branch
        $user->userid = 0;
        $user->setPassword('secret123');

        $ref = new \ReflectionProperty(User::class, '_pendingPlainPassword');
        $pending = $ref->getValue($user);
        $this->assertSame('secret123', $pending);
        $this->assertSame(md5('secret123'), $user->password);
    }

    public function testSetPasswordWithRealUseridUsesBcrypt(): void
    {
        $user        = new User(0);
        $user->userid = 42;

        // getSetting returns '' by default (not defined)
        $user->setPassword('mypassword');

        $ref = new \ReflectionProperty(User::class, '_pendingPlainPassword');
        $this->assertNull($ref->getValue($user));
        // Password must be a bcrypt hash
        $this->assertStringStartsWith('$2y$', $user->password);
    }

    public function testVerifyPasswordCorrect(): void
    {
        $user        = new User(0);
        $user->userid = 42;
        $user->setPassword('correctpass');

        $this->assertTrue($user->verifyPassword('correctpass'));
    }

    public function testVerifyPasswordWrong(): void
    {
        $user        = new User(0);
        $user->userid = 42;
        $user->setPassword('correctpass');

        $this->assertFalse($user->verifyPassword('wrongpass'));
    }

    // ── Magic __get / __set ───────────────────────────────────────────────────

    public function testMagicSetStoresInOtherinfo(): void
    {
        $user = new User(0);
        $user->custom_field = 'custom_value';

        $ref = new \ReflectionProperty(User::class, 'otherinfo');
        $info = $ref->getValue($user);
        $this->assertArrayHasKey('custom_field', $info);
        $this->assertSame('custom_value', $info['custom_field']);
    }

    public function testMagicGetReturnsFromOtherinfo(): void
    {
        $user = new User(0);
        $user->mykey = 'myval';

        $this->assertSame('myval', $user->mykey);
    }

    public function testMagicGetReturnsNullForUnknownKey(): void
    {
        $user = new User(0);
        $this->assertNull($user->nonexistent_field);
    }

    public function testMagicGetWithGetinfoPrefix(): void
    {
        // If otherinfo has 'setinfo_foo', then $user->getinfo_foo should return it
        $user = new User(0);
        $ref  = new \ReflectionProperty(User::class, 'otherinfo');
        $ref->setValue($user, ['setinfo_foo' => 'bar_value']);

        $this->assertSame('bar_value', $user->getinfo_foo);
    }

    // ── activate / deactivate ─────────────────────────────────────────────────

    public function testActivateOnNewUserSetsFlag(): void
    {
        $user = new User(0); // _isnew = 1
        $user->active = 0;
        $user->activate();
        $this->assertTrue($user->active);
    }

    public function testDeactivateOnNewUserSetsFlag(): void
    {
        $user = new User(0);
        $user->active = 1;
        $user->deactivate();
        $this->assertSame(0, $user->active);
    }

    // ── getTableNames ─────────────────────────────────────────────────────────

    public function testGetTableNamesReturnsExpectedKeys(): void
    {
        $user  = new User(0);
        $tables = $user->getTableNames();

        $this->assertArrayHasKey('users', $tables);
        $this->assertArrayHasKey('userdetails', $tables);
    }

    // ── getCurrentUser ────────────────────────────────────────────────────────

    public function testGetCurrentUserReturnsNullWithNoSession(): void
    {
        // Make sure there is no uid in session
        unset($_SESSION['uid']);

        $current = User::getCurrentUser();
        $this->assertFalse($current);
    }

    // ── getuserid invalid $by ─────────────────────────────────────────────────

    public function testGetUseridReturnsFalseForInvalidBy(): void
    {
        $result = User::getuserid('testuser', 'invalid_column');
        $this->assertFalse($result);
    }

    // ── Static cache clearance ────────────────────────────────────────────────

    public function testUsersCacheIsSharedAcrossInstances(): void
    {
        // Manually insert into static cache
        $ref = new \ReflectionProperty(User::class, 'usersCache');
        $cachedUser = new User(0);
        $cachedUser->userid   = 99;
        $cachedUser->username = 'CachedUser';
        $ref->setValue(null, [99 => $cachedUser]);

        $fetched = User::getUser(99);
        $this->assertSame('CachedUser', $fetched->username);

        // Clean up
        $ref->setValue(null, []);
    }
}
