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
}
