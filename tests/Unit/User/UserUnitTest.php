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

    /**
     * `isset()` on a non-standard field answers from the store `__get()` reads.
     *
     * `__get()`/`__set()` were overridden onto `$otherinfo`; `__isset()`/`__unset()` were
     * inherited and read `$_data`, a store this class never writes. So `isset()` was
     * `false` for every field `__get()` would have returned.
     */
    public function testIssetAnswersFromTheSameStoreAsGet(): void
    {
        // Arrange
        $user = new User();
        $user->favouriteColour = 'petrol';

        // Act & Assert
        $this->assertTrue(isset($user->favouriteColour));
        $this->assertFalse(isset($user->neverSetAnything));
    }

    /**
     * `??` returns the value, not the fallback.
     *
     * This is the consequence that mattered, and it is not obvious from the `isset()`
     * bug: `??` asks `__isset()` **first** and calls `__get()` only when the class
     * declares no `__isset()`. With a broken inherited one, `$user->pref ?? ''` returned
     * `''` for a value that was in the object and in the database all along — silently.
     * A consuming project read every notification preference that way, and the whole set
     * became "no preference" with no error anywhere.
     */
    public function testNullCoalescingReturnsTheStoredValue(): void
    {
        // Arrange
        $user = new User();
        $user->notifyByEmail = '1';

        // Act
        $value = $user->notifyByEmail ?? '';

        // Assert
        $this->assertSame('1', $value);
    }

    /**
     * `unset()` removes it from the same store, so the four agree.
     */
    public function testUnsetRemovesFromTheSameStore(): void
    {
        // Arrange
        $user = new User();
        $user->temporary = 'x';

        // Act
        unset($user->temporary);

        // Assert
        $this->assertFalse(isset($user->temporary));
        $this->assertNull($user->temporary);
    }

    /**
     * The `getinfo_` alias `__get()` resolves is visible to `isset()` too.
     *
     * `__get('getinfo_foo')` falls back to `setinfo_foo`. If `isset()` did not follow
     * that, `$user->getinfo_foo ?? 'default'` would return the default for a value
     * `__get()` can produce — the same class of bug one level down.
     */
    public function testTheGetinfoAliasIsVisibleToIsset(): void
    {
        // Arrange
        $user = new User();
        $user->setinfo_country = 'GR';

        // Act & Assert
        $this->assertTrue(isset($user->getinfo_country));
        $this->assertSame('GR', $user->getinfo_country ?? 'default');
    }

    /**
     * `load(null)` refuses instead of using null as an array key.
     *
     * `null` is a normal argument, not a caller's mistake: `new User($record->userid)`
     * on a record that did not load passes it, and the next line is usually a
     * `userid < 2` check. It reached the user cache as an array offset — *Using null as
     * an array offset is deprecated* on PHP 8.1+, twice per call.
     *
     * Refused rather than coerced to `0`: `0` already means "load whoever is in the
     * session", which is a different question.
     */
    public function testLoadRefusesANullId(): void
    {
        // Arrange
        $user = new User();
        $previous = $_SESSION['uid'] ?? null;
        unset($_SESSION['uid']);

        try {
            // Act
            $result = $user->load(null);

            // Assert
            $this->assertFalse($result);
        } finally {
            if ($previous !== null) {
                $_SESSION['uid'] = $previous;
            }
        }
    }

    /**
     * The friend methods address the prefixed table.
     *
     * `QueryBuilder::table()` substitutes `#PREFIX#` and leaves a bare name as written,
     * so the four friend methods addressed `userfriends` — a table that does not exist
     * on any installation with a prefix, which is every installation the scaffolder
     * produces.
     */
    public function testTheFriendMethodsUseThePrefixedTable(): void
    {
        // Arrange
        $method = new \ReflectionMethod(User::class, 'userFriendsTable');

        // Act
        $table = $method->invoke(null);

        // Assert
        $this->assertStringContainsString('userfriends', $table);
        $this->assertStringStartsWith('#PREFIX#', $table);
    }

    /**
     * A per-user setting round-trips through JSON.
     *
     * The framework had two places to keep something about a user and neither fits an
     * operator-visible switch: `users` columns are the shared schema, and `$otherinfo` is
     * a blob with no list, no per-key delete and nothing an administrator can read.
     *
     * Asserted on the accessors' contract with no database behind them: without a table
     * every read is the default and every write reports failure, which is the behaviour a
     * project that has not migrated must get — not an exception on a page.
     */
    public function testSettingsAnswerTheDefaultWithoutATable(): void
    {
        // Arrange — the mock DB in setUp() has no usersettings table
        $user = new User();
        $user->userid = 5;

        // Act & Assert
        $this->assertSame('fallback', $user->getSetting('anything', 'fallback'));
        $this->assertSame([], $user->listSettings());
    }

    /**
     * The anonymous account has no settings, and cannot be given any.
     *
     * Ids 0 and 1 are the guest and the built-in system account. A setting on either is a
     * setting on everybody, which is a global setting wearing a per-user name.
     */
    public function testTheAnonymousAccountHasNoSettings(): void
    {
        // Arrange
        $user = new User();
        $user->userid = 1;

        // Act & Assert
        $this->assertFalse($user->setSetting('flag', true));
        $this->assertFalse($user->deleteSetting('flag'));
        $this->assertSame([], $user->listSettings());
        $this->assertNull($user->getSetting('flag'));
    }

    /**
     * A setting with no name is refused.
     *
     * The form posts a free-text name, and an empty one would write a row nothing can
     * ever look up again — findable only by listing every setting the user has.
     */
    public function testASettingNeedsAName(): void
    {
        // Arrange
        $user = new User();
        $user->userid = 5;

        // Act & Assert
        $this->assertFalse($user->setSetting('', 'x'));
        $this->assertFalse($user->deleteSetting(''));
    }

    /**
     * The settings table is reported among the tables this class uses.
     *
     * `getTableNames()` is what a GDPR export and a schema audit read; a store the class
     * writes and does not declare is a store neither of them sees.
     */
    public function testTheSettingsTableIsDeclared(): void
    {
        // Act
        $tables = (new User())->getTableNames();

        // Assert
        $this->assertArrayHasKey('usersettings', $tables);
        $this->assertStringContainsString('usersettings', $tables['usersettings']);
    }
}
