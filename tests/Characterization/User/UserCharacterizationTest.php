<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Characterization tests for legacy User observable behavior.
 *
 * These tests lock creation/update/deletion flow, password contracts,
 * and getUser cache behavior before deeper internal migration work.
 */
#[CoversClass(User::class)]
class UserCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        // Arrange
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        User::setupDb();
    }

    /**
     * Ensures full user lifecycle behavior remains stable (create/load/update/
     * activate/deactivate/delete) including dynamic otherinfo fields.
     */
    public function testUserLifecycleAndOtherInfoContract(): void
    {
        // Arrange
        $username = 'char_user_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $username;
        $user->email = $username . '@example.com';
        $user->firstname = 'Char';
        $user->lastname = 'User';
        $user->setPassword('secret123');
        $user->setinfo_department = 'QA';

        // Act
        $user->save();
        $userId = (int) $user->userid;

        // Assert
        $this->assertGreaterThan(1, $userId);

        // Arrange
        $loaded = new User($userId);

        // Act
        $loaded->setinfo_department = 'Platform';
        $loaded->save();

        // Assert
        $reloaded = new User($userId);
        $this->assertSame($username, $reloaded->username);
        // This proves getinfo_* indirection still resolves persisted metadata.
        $this->assertSame('Platform', $reloaded->getinfo_department);

        // Arrange
        $reloaded->activate();
        $afterActivate = new User($userId);

        // Assert
        $this->assertTrue((bool) $afterActivate->active);

        // Arrange
        $afterActivate->deactivate();
        $afterDeactivate = new User($userId);

        // Assert
        $this->assertFalse((bool) $afterDeactivate->active);

        // Arrange
        $afterDeactivate->deleteuser();

        // Act
        $checkDeleted = new User($userId);

        // Assert
        $this->assertFalse($checkDeleted->load($userId));
    }

    /**
     * Ensures password generation behavior is preserved for guest-like users
     * (userid <= 1) and persisted users (userid > 1).
     */
    public function testSetPasswordMaintainsLegacyBranchingBehavior(): void
    {
        // Arrange
        $guestLike = new User();
        $guestLike->userid = 1;

        // Act
        $guestLike->setPassword('plain');

        // Assert
        $this->assertSame(md5('plain'), $guestLike->password);

        // Arrange
        $persistedLike = new User();
        $persistedLike->userid = 55;
        $salt = (string) Settings::getSetting('securitySalt');

        // Act
        $persistedLike->setPassword('plain');

        // Assert
        $this->assertNotSame(md5('plain'), $persistedLike->password);
        $expectedInput = 'plain' . md5($salt . '55');
        // This proves the modern branch still hashes password+salted-userid payload.
        $this->assertTrue(password_verify($expectedInput, $persistedLike->password));
    }

    /**
     * Ensures getUser caches and returns the same object instance for an id
     * loaded from persistence.
     */
    public function testGetUserReturnsCachedInstanceForSameUserId(): void
    {
        // Arrange
        $username = 'cache_user_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $username;
        $user->email = $username . '@example.com';
        $user->setPassword('secret123');
        $user->save();
        $userId = (int) $user->userid;

        // Act
        $first = User::getUser($userId);
        $second = User::getUser($userId);

        // Assert
        $this->assertSame($first, $second);
        $this->assertSame($username, $second->username);

        // Arrange
        $second->deleteuser();

        // Assert
        $deleted = new User($userId);
        $this->assertFalse($deleted->load($userId));
    }

    /**
     * deleteuser() must physically remove the row from the database.
     * After calling deleteuser() the user can no longer be loaded by ID.
     * This proves the QB-based DELETE in deleteuser() correctly targets the row.
     */
    public function testDeleteUserRemovesFromDatabase(): void
    {
        // Arrange — create a user and persist it
        $username = 'del_user_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $username;
        $user->email    = $username . '@example.com';
        $user->setPassword('pass');
        $user->save();
        $userId = (int) $user->userid;
        $this->assertGreaterThan(1, $userId, 'User must be saved before deletion test');

        // Act
        $user->deleteuser();

        // Assert — loading the same id must fail after deletion
        $reloaded = new User($userId);
        // This proves the DELETE reached the database (load returns false for missing users)
        $this->assertFalse($reloaded->load($userId));
    }

    /**
     * activate() and deactivate() must toggle the active flag in the database.
     * This tests the QB-based UPDATE paths in both methods against the real schema.
     */
    public function testActivateDeactivateTogglesActiveFlag(): void
    {
        // Arrange — create an active user
        $username = 'toggle_user_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $username;
        $user->email    = $username . '@example.com';
        $user->setPassword('pass');
        $user->save();
        $userId = (int) $user->userid;

        // Act — deactivate
        $user->deactivate();

        // Assert — reload and verify the flag was written to the DB
        $afterDeactivate = new User($userId);
        // This proves deactivate() issued a real UPDATE (not just an in-memory assignment)
        $this->assertFalse((bool) $afterDeactivate->active);

        // Act — re-activate
        $afterDeactivate->activate();

        // Assert
        $afterActivate = new User($userId);
        $this->assertTrue((bool) $afterActivate->active);

        // Cleanup
        $afterActivate->deleteuser();
    }

    /**
     * getUsers() must return all saved users as User objects.
     * This tests the QB-based SELECT in getUsers() with no $where filter.
     */
    public function testGetUsersReturnsAll(): void
    {
        // Arrange — create two distinct users
        $suffix = bin2hex(random_bytes(3));
        $userA  = new User();
        $userA->username = 'get_users_a_' . $suffix;
        $userA->email    = 'get_users_a_' . $suffix . '@example.com';
        $userA->setPassword('pass');
        $userA->save();
        $idA = (int) $userA->userid;

        $userB  = new User();
        $userB->username = 'get_users_b_' . $suffix;
        $userB->email    = 'get_users_b_' . $suffix . '@example.com';
        $userB->setPassword('pass');
        $userB->save();
        $idB = (int) $userB->userid;

        // Act
        $all = User::getUsers();

        // Assert — both created users must appear in the returned map
        // This proves the SELECT returned rows from the real table (not just an empty result)
        $this->assertArrayHasKey($idA, $all);
        $this->assertArrayHasKey($idB, $all);
        $this->assertInstanceOf(User::class, $all[$idA]);
        $this->assertInstanceOf(User::class, $all[$idB]);

        // Cleanup
        $userA->deleteuser();
        $userB->deleteuser();
    }

    /**
     * getuserid() must resolve the numeric userid by username and by email.
     * This tests the QB-based SELECT in getuserid() against both column branches.
     */
    public function testGetUseridByUsernameAndEmail(): void
    {
        // Arrange
        $username = 'getuserid_' . bin2hex(random_bytes(4));
        $email    = $username . '@example.com';
        $user = new User();
        $user->username = $username;
        $user->email    = $email;
        $user->setPassword('pass');
        $user->save();
        $userId = (int) $user->userid;

        // Act — look up by username
        $foundById = User::getuserid($username, 'username');

        // Assert — proves the WHERE username = ? path works
        $this->assertSame($userId, (int) $foundById);

        // Act — look up by email
        $foundByEmail = User::getuserid($email, 'email');

        // Assert — proves the WHERE email = ? path works
        $this->assertSame($userId, (int) $foundByEmail);

        // Assert — unknown column guard still returns false
        $this->assertFalse(User::getuserid($username, 'badcolumn'));

        // Cleanup
        $user->deleteuser();
    }

    /**
     * getbyparam() must return userids for rows in userdetails matching
     * a given fieldname+value pair.
     * This tests the QB-based SELECT in getbyparam().
     */
    public function testGetbyparam(): void
    {
        // Arrange — create a user with a custom otherinfo field
        $username  = 'getbyparam_' . bin2hex(random_bytes(4));
        $fieldName = 'custom_field_' . bin2hex(random_bytes(2));
        $fieldVal  = 'value_' . bin2hex(random_bytes(2));

        $user = new User();
        $user->username       = $username;
        $user->email          = $username . '@example.com';
        $user->setPassword('pass');
        $user->otherinfo[$fieldName] = $fieldVal;
        $user->save();
        $userId = (int) $user->userid;

        // Act
        $result = User::getbyparam($fieldName, $fieldVal);

        // Assert — the returned array must contain the userid of our test user
        // This proves the JOIN-free SELECT against userdetails works via QB
        $this->assertContains((string) $userId, array_map('strval', $result));

        // Cleanup
        $user->deleteuser();
    }

    /**
     * getDataUsageStats() must return correct token and unique-app counts
     * from the real usertokens table via QB.
     * Also proves the missing-prefix bug from the old raw SQL is fixed (QB
     * automatically prepends the database prefix).
     */
    public function testGetDataUsageStats(): void
    {
        // Arrange — create a user and add two tokens with distinct applicationids
        $username = 'stats_user_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $username;
        $user->email    = $username . '@example.com';
        $user->setPassword('pass');
        $user->save();
        $userId = (int) $user->userid;

        $tok1 = 'tok_' . bin2hex(random_bytes(6));
        $tok2 = 'tok_' . bin2hex(random_bytes(6));
        $user->addToken('auth', $tok1, 'stats test 1');
        $user->addToken('auth', $tok2, 'stats test 2');

        // Set distinct applicationids directly in the DB so we can test unique-app count
        $this->db->queryBuilder()
            ->table('usertokens')
            ->where('userid', $userId)
            ->where('token', $tok1)
            ->update(['applicationid' => 101]);
        $this->db->queryBuilder()
            ->table('usertokens')
            ->where('userid', $userId)
            ->where('token', $tok2)
            ->update(['applicationid' => 102]);

        // Act
        $stats = $user->getDataUsageStats();

        // Assert
        $this->assertIsArray($stats);
        // This proves the QB count() returns the right total (old code missed prefix)
        $this->assertSame(2, (int) $stats['total_tokens']);
        // This proves the unique-app pluck+dedupe logic works
        $this->assertSame(2, (int) $stats['unique_apps']);

        // Cleanup
        $user->deleteuser();
    }

    /**
     * Ensures that when setPassword() is called before save() (userid <= 1),
     * _save() automatically rehashes the password with the real userid after
     * INSERT so the user can immediately authenticate via Auth::auth().
     *
     * This was broken in v1.2 because the MD5 fallback in UserDatabase::onAuth
     * was gated behind legacyMd5=true, but User::setPassword() stores MD5 for
     * unsaved users. The fix stores a pending password and rehashes post-INSERT.
     */
    public function testPasswordRehashesAfterInsertWithRealUserId(): void
    {
        // Arrange
        $username = 'rehash_user_' . bin2hex(random_bytes(4));
        $plainPassword = 'TestPass!42';

        $user = new User();
        $user->username = $username;
        $user->email    = $username . '@example.com';
        // setPassword is called BEFORE save() — userid is still 1 here,
        // so a plain MD5 placeholder is stored temporarily.
        $user->setPassword($plainPassword);

        // Act
        $user->save();
        $userId = (int) $user->userid;

        // Assert — real userid must have been assigned
        $this->assertGreaterThan(1, $userId, 'INSERT must yield a real userid > 1');

        // Reload from DB to get the stored password hash
        $salt = (string) Settings::getSetting('securitySalt');
        $result = $this->db->queryBuilder()
            ->table('users')
            ->select('password')
            ->where('userid', $userId)
            ->get();
        $storedHash = $result->fields['password'];

        // The stored hash must NOT be a raw MD5 (32-char hex) — it must be bcrypt.
        $this->assertNotSame(md5($plainPassword), $storedHash,
            'Password must be rehashed to bcrypt after INSERT, not left as MD5 placeholder');

        // Verifying with the salted+userid format proves bcrypt rehash worked.
        $expectedPayload = $plainPassword . md5($salt . $userId);
        $this->assertTrue(
            password_verify($expectedPayload, $storedHash),
            'Stored bcrypt hash must verify against password+salt+userid payload'
        );

        // Cleanup
        $user->deleteuser();
    }
}
