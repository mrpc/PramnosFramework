<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Auth\AccountErasure;
use Pramnos\Event\Event;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * A user a test creates is gone when the test ends, with what belongs to it.
 *
 * Every application built on the framework wrote this itself — three projects, three
 * versions, each after its suite had slowed to a crawl under the users nobody removed. The
 * erase is the account deletion's own, so the application's `account.data_erase` listeners
 * clean up their rows too, and there is one definition of what belongs to a person.
 */
#[CoversClass(BaseTestCase::class)]
class TestUsersAreErasedTest extends BaseTestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        $this->runMigrations([\Pramnos\Framework\Migrations\Auth\CreateUsersTable::class], $this->db);
    }

    protected function tearDown(): void
    {
        Event::forget(AccountErasure::EVENT);
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** Whether the user's row is there. */
    private function exists(int $userId): bool
    {
        return $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)->count() > 0;
    }

    /**
     * A created user exists during the test, can sign in with its password, and is erased at
     * the end — the application's listener running as part of it.
     */
    public function testACreatedUserIsErasedWithTheApplicationsRows(): void
    {
        // Arrange
        $erased = [];
        Event::listen(AccountErasure::EVENT, static function (int $userId) use (&$erased): bool {
            $erased[] = $userId;

            return true;
        });

        // Act
        $userId = $this->createTestUser(['password' => 'correct horse', 'usertype' => 99]);

        // Assert — there, an administrator, and its password is the framework's own hash
        $this->assertTrue($this->exists($userId));
        $row = $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)->first()->fields;
        $this->assertSame(99, (int) $row['usertype']);
        $this->assertStringStartsWith('test_', (string) $row['username']);
        // verify() names the scheme that matched, or null
        $this->assertNotNull(\Pramnos\Auth\PasswordHash::verify('correct horse', (string) $row['password'], $userId));

        // Act — the end of the test
        parent::tearDown();

        // Assert — gone, through the erase the application's listener hears
        $this->assertFalse($this->exists($userId), 'the test user outlived its test');
        $this->assertSame([$userId], $erased);
    }

    /**
     * A user the code under test created is erased too, once handed over.
     */
    public function testATrackedUserIsErased(): void
    {
        // Arrange — a user made some other way
        $this->db->queryBuilder()->table('#PREFIX#users')->insert([
            'username' => 'tracked_' . bin2hex(random_bytes(4)), 'email' => 'tracked@example.test',
        ]);
        $userId = (int) $this->db->queryBuilder()->table('#PREFIX#users')
            ->orderBy('userid', 'desc')->first()->fields['userid'];

        // Act
        $this->assertSame($userId, $this->trackTestUser($userId), 'the id is handed back for wrapping');
        parent::tearDown();

        // Assert
        $this->assertFalse($this->exists($userId));
    }

    /**
     * Two users in one test are two unique accounts, and a test with none erases nothing.
     */
    public function testUsersAreUniqueAndATestWithNoneErasesNothing(): void
    {
        // Act
        $first  = $this->createTestUser();
        $second = $this->createTestUser();

        // Assert
        $this->assertNotSame($first, $second);
        parent::tearDown();
        $this->assertFalse($this->exists($first));
        $this->assertFalse($this->exists($second));
        // A second end erases nothing more and raises nothing
        parent::tearDown();
    }
}
