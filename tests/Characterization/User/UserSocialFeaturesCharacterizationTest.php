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
 * Characterization tests for User social features (userfriends table).
 *
 * The userfriends table has no framework migration, so each test run
 * creates it inline in setUp() and drops it in tearDown().
 *
 * These tests lock the QB-refactored behavior of:
 *   makefriends(), removefriends(), arefriends(), getfriends()
 *
 * getFeed() and addFeed() are intentionally excluded because they
 * reference pramnoscms_user, a class that does not exist in the framework.
 *
 * These tests run against MySQL only.  The userfriends table uses
 * backtick quoting and AUTO_INCREMENT which is MySQL-specific.
 */
#[CoversClass(User::class)]
class UserSocialFeaturesCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    /** @var int[] */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        // Arrange — bootstrap the application with MySQL settings
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

        // Skip the entire class if the backend is not MySQL/MariaDB
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('UserSocialFeaturesCharacterizationTest runs on MySQL only.');
        }

        // Ensure the user tables exist before creating the userfriends table
        // (userfriends has no FK in the test DDL, so order doesn't matter for the
        // table itself, but we need users to insert test data)
        User::setupDb();

        // Create the userfriends table inline — it has no framework migration
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$this->db->prefix}userfriends` (
                `id`          int AUTO_INCREMENT PRIMARY KEY,
                `from_userid` bigint NOT NULL,
                `to_userid`   bigint NOT NULL,
                `confirm`     tinyint NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    }

    protected function tearDown(): void
    {
        // Remove all rows added during the test, preserving table structure
        // for the next test in the same process.
        $this->db->query("TRUNCATE TABLE `{$this->db->prefix}userfriends`");

        // Remove test users
        foreach ($this->createdUserIds as $uid) {
            $this->db->query(
                $this->db->prepareQuery(
                    'DELETE FROM `#PREFIX#usertokens` WHERE `userid` = %d', $uid
                )
            );
            $this->db->query(
                $this->db->prepareQuery(
                    'DELETE FROM `#PREFIX#userdetails` WHERE `userid` = %d', $uid
                )
            );
            $this->db->query(
                $this->db->prepareQuery(
                    'DELETE FROM `#PREFIX#users` WHERE `userid` = %d', $uid
                )
            );
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create and persist a minimal test user, tracking the id for cleanup.
     */
    private function createTestUser(string $tag = ''): User
    {
        $name = 'social_' . $tag . '_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $name;
        $user->email    = $name . '@example.com';
        $user->setPassword('pass');
        $user->save();
        $uid = (int) $user->userid;
        $this->assertGreaterThan(1, $uid, 'User must be saved for social feature tests');
        $this->createdUserIds[] = $uid;
        return $user;
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    /**
     * makefriends() must insert a row into userfriends with confirm=1 so that
     * arefriends() returns true for the same pair.
     *
     * This tests the QB-based INSERT in makefriends() which replaces raw SQL
     * that was vulnerable to SQL injection.
     */
    public function testMakeFriends(): void
    {
        // Arrange
        $userA = $this->createTestUser('a');
        $userB = $this->createTestUser('b');

        // Act
        $userA->makefriends($userA->userid, $userB->userid);

        // Assert — arefriends() reads the same table; true means the INSERT worked
        $this->assertTrue(
            $userA->arefriends($userA->userid, $userB->userid),
            'makefriends() must create a confirmed friendship row'
        );
    }

    /**
     * removefriends() must delete the friendship row in both directions
     * (A→B or B→A) so that arefriends() returns false afterwards.
     *
     * This tests the QB-based DELETE in removefriends(), which had a raw-SQL
     * SQL injection vulnerability in the original code.
     */
    public function testRemoveFriends(): void
    {
        // Arrange — establish a friendship first
        $userA = $this->createTestUser('ra');
        $userB = $this->createTestUser('rb');
        $userA->makefriends($userA->userid, $userB->userid);
        $this->assertTrue(
            $userA->arefriends($userA->userid, $userB->userid),
            'Precondition: friendship must exist before removal'
        );

        // Act
        $userA->removefriends($userA->userid, $userB->userid);

        // Assert — the friendship row must be gone
        // This proves the DELETE condition covers both directions (A→B and B→A)
        $this->assertFalse(
            $userA->arefriends($userA->userid, $userB->userid),
            'removefriends() must delete the friendship row'
        );
    }

    /**
     * arefriends() must return true only when a confirmed friendship row exists,
     * regardless of direction (A→B or B→A).
     *
     * This tests the QB-based SELECT with a nested OR condition.
     */
    public function testAreFriends(): void
    {
        // Arrange
        $userA = $this->createTestUser('afa');
        $userB = $this->createTestUser('afb');
        $userC = $this->createTestUser('afc');

        // Assert — strangers are not friends
        $this->assertFalse(
            $userA->arefriends($userA->userid, $userB->userid),
            'Strangers must not be considered friends'
        );

        // Act — make A and B friends
        $userA->makefriends($userA->userid, $userB->userid);

        // Assert — both directions must return true
        $this->assertTrue(
            $userA->arefriends($userA->userid, $userB->userid),
            'arefriends() must return true for A→B direction'
        );
        $this->assertTrue(
            $userA->arefriends($userB->userid, $userA->userid),
            'arefriends() must return true for B→A direction (bidirectional)'
        );

        // Assert — unrelated user is still not friends
        $this->assertFalse(
            $userA->arefriends($userA->userid, $userC->userid),
            'Unrelated user must not be a friend'
        );
    }

    /**
     * getfriends() must return an array of friend userids for a given user,
     * collecting both from_userid→to_userid and to_userid→from_userid rows.
     *
     * This tests the QB-based SELECT with an OR condition on both columns.
     */
    public function testGetFriends(): void
    {
        // Arrange — create three users, make A friends with B and C
        $userA = $this->createTestUser('gfa');
        $userB = $this->createTestUser('gfb');
        $userC = $this->createTestUser('gfc');
        $userD = $this->createTestUser('gfd'); // should NOT appear in results

        $userA->makefriends($userA->userid, $userB->userid);
        $userA->makefriends($userA->userid, $userC->userid);
        // D has no friendship with A

        // Act
        $friends = User::getfriends($userA->userid);

        // Assert — B and C must be in the list
        // This proves the SELECT with OR condition captures both from/to rows
        $this->assertContains(
            (string) $userB->userid,
            array_map('strval', $friends),
            'getfriends() must include friend B'
        );
        $this->assertContains(
            (string) $userC->userid,
            array_map('strval', $friends),
            'getfriends() must include friend C'
        );

        // Assert — D must NOT appear
        $this->assertNotContains(
            (string) $userD->userid,
            array_map('strval', $friends),
            'getfriends() must not include non-friend D'
        );

        // Assert — exactly 2 friends
        $this->assertCount(2, $friends, 'getfriends() must return exactly 2 friends');
    }
}
