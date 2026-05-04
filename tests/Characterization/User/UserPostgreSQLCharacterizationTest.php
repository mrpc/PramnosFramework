<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Characterization tests for User behavior on PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors UserCharacterizationTest (MySQL) exactly — same contracts, same
 * assertions. The goal is to lock User behavior across both supported backends
 * before any internal refactoring work touches the class.
 *
 * User has explicit PostgreSQL branching in setupDb(), save(), addToken(), and
 * deleteToken(), so all lifecycle operations should work identically on PG.
 *
 * #[RunTestsInSeparateProcesses] is required because Factory::getDatabase()
 * returns a static singleton. Running in separate processes gives each test a
 * clean PHP state so that the PG settings take effect before any MySQL
 * singleton is created by a sibling test class in the same suite.
 *
 * TimescaleDB coverage: the Docker "timescaledb" container is a PostgreSQL 14
 * server with the TimescaleDB extension. These tests therefore cover both the
 * plain PostgreSQL and TimescaleDB backends.
 */
#[CoversClass(User::class)]
#[RunTestsInSeparateProcesses]
class UserPostgreSQLCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        // Arrange — bootstrap constants (defined in tests/bootstrap.php but
        // re-checked here because separate processes re-run the bootstrap)
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        if (!is_dir(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs', 0777, true);
        }

        // Load PG-only settings so Factory::getDatabase() returns a PG connection.
        $pgSettingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Create (or ensure existence of) the user tables on PostgreSQL.
        User::setupDb();
    }

    /**
     * Full user lifecycle must work on PostgreSQL: create, load, update
     * dynamic otherinfo fields, activate, deactivate, delete.
     *
     * Mirrors UserCharacterizationTest::testUserLifecycleAndOtherInfoContract.
     */
    public function testUserLifecycleAndOtherInfoContractOnPostgreSQL(): void
    {
        // Arrange
        $username = 'pg_char_user_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username  = $username;
        $user->email     = $username . '@example.com';
        $user->firstname = 'PgChar';
        $user->lastname  = 'User';
        $user->setPassword('secret123');
        $user->setinfo_department = 'QA';

        // Act
        $user->save();
        $userId = (int) $user->userid;

        // Assert
        $this->assertGreaterThan(1, $userId, 'save() must assign a userid > 1');

        // Arrange — update a dynamic otherinfo field
        $loaded = new User($userId);
        $loaded->setinfo_department = 'Platform';
        $loaded->save();

        // Assert — reload and verify persisted otherinfo
        $reloaded = new User($userId);
        $this->assertSame($username, $reloaded->username);
        $this->assertSame('Platform', $reloaded->getinfo_department,
            'getinfo_* must resolve persisted userdetails metadata on PG');

        // Arrange — activate
        $reloaded->activate();
        $afterActivate = new User($userId);
        $this->assertTrue((bool) $afterActivate->active, 'activate() must set active=true');

        // Arrange — deactivate
        $afterActivate->deactivate();
        $afterDeactivate = new User($userId);
        $this->assertFalse((bool) $afterDeactivate->active, 'deactivate() must set active=false');

        // Arrange — delete
        $afterDeactivate->deleteuser();
        $checkDeleted = new User($userId);

        // Assert — load must return false for deleted user
        $this->assertFalse($checkDeleted->load($userId),
            'load() must return false after deleteuser() on PG');
    }

    /**
     * Password hashing behavior must be identical on PostgreSQL — the branching
     * is determined by userid value, not by the database backend.
     *
     * Mirrors UserCharacterizationTest::testSetPasswordMaintainsLegacyBranchingBehavior.
     */
    public function testSetPasswordBranchingIsBackendAgnostic(): void
    {
        // Arrange — guest-like user (userid <= 1): legacy md5() branch
        $guestLike = new User();
        $guestLike->userid = 1;

        // Act
        $guestLike->setPassword('plain');

        // Assert
        $this->assertSame(md5('plain'), $guestLike->password,
            'userid <= 1 must use legacy md5() hash');

        // Arrange — persisted user: modern password_hash() branch
        $persistedLike = new User();
        $persistedLike->userid = 55;
        $salt = (string) Settings::getSetting('securitySalt');

        // Act
        $persistedLike->setPassword('plain');

        // Assert
        $this->assertNotSame(md5('plain'), $persistedLike->password);
        $expectedInput = 'plain' . md5($salt . '55');
        $this->assertTrue(
            password_verify($expectedInput, $persistedLike->password),
            'userid > 1 must use password_hash() with salted-userid payload'
        );
    }

    /**
     * getUser() must cache and return the same object instance for a given id,
     * regardless of the database backend.
     *
     * Mirrors UserCharacterizationTest::testGetUserReturnsCachedInstanceForSameUserId.
     */
    public function testGetUserCacheWorksOnPostgreSQL(): void
    {
        // Arrange
        $username = 'pg_cache_user_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $username;
        $user->email    = $username . '@example.com';
        $user->setPassword('secret123');
        $user->save();
        $userId = (int) $user->userid;

        // Act
        $first  = User::getUser($userId);
        $second = User::getUser($userId);

        // Assert — same object identity proves the cache is used
        $this->assertSame($first, $second,
            'getUser() must return the cached object instance on PG');
        $this->assertSame($username, $second->username);

        // Cleanup
        $second->deleteuser();

        // Assert
        $deleted = new User($userId);
        $this->assertFalse($deleted->load($userId));
    }
}
