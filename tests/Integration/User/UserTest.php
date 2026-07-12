<?php

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pramnos\Framework\Factory;

#[CoversClass(User::class)]
class UserTest extends TestCase
{
    private $db;
    private $testUsername = 'testuser_' . 42;
    private $originalAuth;

    /**
     * Set up the test environment before each test runs.
     *
     * Loads the test application configuration settings, instantiates the database,
     * builds/seeds the user tables schema, and cleans up any existing request data.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // Define CONFIG path if not set, pointing to our test fixtures
        if (!\defined('CONFIG')) {
            \define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        // Explicitly load test settings
        $settingsFile = \ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);
        \Pramnos\Application\Application::getInstance();

        $this->db = \Pramnos\Framework\Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Create the users table first (idempotent), then clear the guest user seed
        // so that setupDb() re-inserts a fresh guest row.
        \Pramnos\User\User::setupDb();

        $userTable = defined('DB_USERSTABLE') ? DB_USERSTABLE : '#PREFIX#users';
        $this->db->query("DELETE FROM " . $userTable . " WHERE userid = 1");

        $this->testUsername = 'testuser_' . \bin2hex(\random_bytes(4));
        $this->originalAuth = Factory::getAuth();
    }

    /**
     * Clean up the test environment after each test runs.
     *
     * Restores the original Factory authentication singleton to prevent leakage
     * between test classes.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $authSingleton = &Factory::getAuth();
        $authSingleton = $this->originalAuth;
    }

    /**
     * Tests the core user CRUD lifecycle including creation, loading, updating,
     * status activation, and deletion using the test database.
     *
     * @return void
     */
    #[Test]
    public function testUserLifecycleOnCurrentDatabase()
    {
        $db = $this->db;
        
        // 1. Create User
        $user = new User();
        $user->username = $this->testUsername;
        $user->email = $this->testUsername . '@example.com';
        $user->setPassword('secret123');
        $user->firstname = 'Test';
        $user->lastname = 'User';
        
        // Set some metadata (otherinfo)
        $user->setinfo_my_custom_field = 'custom_value';
        $user->setinfo_another_field = 123;
        
        $user->save();
        $userId = $user->userid;
        
        $this->assertGreaterThan(1, $userId, "User ID should be greater than 1 after save");
        
        // 2. Load User and verify metadata
        $loadedUser = new User($userId);
        $this->assertEquals($this->testUsername, $loadedUser->username);
        $this->assertEquals('custom_value', $loadedUser->getinfo_my_custom_field);
        $this->assertEquals(123, $loadedUser->getinfo_another_field);
        
        // 3. Update metadata (testing UPSERT)
        $loadedUser->setinfo_my_custom_field = 'updated_value';
        $loadedUser->setinfo_new_field = 'brand_new';
        $loadedUser->save();
        
        $reLoadedUser = new User($userId);
        $this->assertEquals('updated_value', $reLoadedUser->getinfo_my_custom_field);
        $this->assertEquals('brand_new', $reLoadedUser->getinfo_new_field);
        
        // 4. Test Activation/Deactivation
        $reLoadedUser->activate();
        $checkUser = new User($userId);
        $this->assertTrue((bool)$checkUser->active, "User should be active");
        
        $checkUser->deactivate();
        $checkUserAgain = new User($userId);
        $this->assertFalse((bool)$checkUserAgain->active, "User should be inactive");
        
        // 5. Cleanup
        $checkUserAgain->deleteuser();
        $finalCheck = new User($userId);
        $this->assertFalse($finalCheck->load($userId), "User should be deleted");
    }

    /**
     * Tests the database connection and queries against a PostgreSQL container
     * if pgsql settings are configured in the environment.
     *
     * @return void
     */
    #[Test]
    public function testUserOperationsOnPostgreSQL()
    {
        $pgSettings = \Pramnos\Application\Settings::getSetting('postgresql');
        if (!$pgSettings) {
             $this->markTestSkipped('PostgreSQL settings not found');
        }

        $db = new Database();
        $db->type = 'postgresql';
        $db->server = $pgSettings->hostname;
        $db->user = $pgSettings->user;
        $db->password = $pgSettings->password;
        $db->database = $pgSettings->database;
        $db->port = $pgSettings->port;
        $db->schema = $pgSettings->schema ?? 'public';
        
        if (!$db->connect(false)) {
            $this->markTestSkipped('PostgreSQL container not reachable');
        }
        
        // Ensure we can run a simple query
        $result = $db->query("SELECT 1 as connected");
        $this->assertEquals(1, $result->numRows);
    }
    
    /**
     * Placeholder method for running cross-driver SQL database dialect compatibility verification.
     *
     * @param Database $db The database instance under test.
     * @return void
     */
    private function runCrossDriverTest($db)
    {
        $this->assertTrue(true);
    }

    /**
     * Tests user password hashing validation and password verification flows.
     *
     * Creates a user, sets a password, asserts encryption is initialized properly,
     * and performs successful/failed password verification tests.
     *
     * @return void
     */
    #[Test]
    public function testUserPasswordHashingAndVerification()
    {
        $user = new User();
        $user->username = 'pwuser_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        
        // 1. Password verify fails before user is persisted (userid < 2)
        $user->setPassword('mysecret123');
        $this->assertFalse($user->verifyPassword('mysecret123'));

        // 2. Persist user and verify password succeeds
        $user->save();
        $userId = $user->userid;
        $this->assertGreaterThan(1, $userId);

        $loaded = new User($userId);
        $this->assertTrue($loaded->verifyPassword('mysecret123'));
        $this->assertFalse($loaded->verifyPassword('wrongpassword'));

        // Cleanup
        $loaded->deleteuser();
    }

    /**
     * Tests hasaccess() and setaccess() ACL calls on the User instance.
     *
     * Mocks the Pramnos Auth service, registers it inside Factory, and asserts
     * that access check methods delegate to the service correctly.
     *
     * @return void
     */
    #[Test]
    public function testUserPermissionsAndAccess()
    {
        $user = new User();
        $user->username = 'permuser_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->save();

        // Mock Factory Auth singleton
        $authMock = $this->getMockBuilder(\Pramnos\Auth\Auth::class)
            ->disableOriginalConstructor()
            ->getMock();

        $authMock->expects($this->once())
            ->method('useraccess')
            ->with($user->userid, 'document', '12', 'write', 'element', 'user', 'flag', true)
            ->willReturn(true);

        $authMock->expects($this->once())
            ->method('setaccess')
            ->with($user->userid, 'document', '12', 'write', 'element', 'user', 'flag', true)
            ->willReturn(true);

        $authSingleton = &Factory::getAuth();
        $authSingleton = $authMock;

        // Verify hasaccess
        $this->assertTrue($user->hasaccess('document', '12', 'write', 'element', 'flag'));

        // Verify setaccess
        $this->assertTrue($user->setaccess(true, 'document', '12', 'write', 'element', 'flag'));

        // Cleanup
        $user->deleteuser();
    }

    /**
     * Tests the authentication tokens management lifecycle.
     *
     * Creates, lists, deactivates, expires, cleans up, and deletes tokens
     * for a valid user, and validates token-based login lookup.
     *
     * @return void
     */
    #[Test]
    public function testTokensManagementLifecycle()
    {
        $this->db->query("DELETE FROM `usertokens`");

        $user = new User();
        $user->username = 'tokenuser_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->setPassword('tokenpass123');
        $user->save();
        $userId = $user->userid;

        $authToken = 'token_auth_' . bin2hex(random_bytes(8));
        $resetToken = 'token_reset_' . bin2hex(random_bytes(8));

        // 1. Add tokens
        $user->addToken('auth', $authToken, 'Auth token 1');
        $user->addToken('reset', $resetToken, 'Reset token 2');

        // 2. GetAllTokens and Verify
        $tokens = $user->getAllTokens();
        $this->assertCount(2, $tokens);

        // 3. GetToken and Verify (returns the raw token string)
        $tokenStr = $user->getToken();
        $this->assertSame($authToken, $tokenStr);

        // 4. Load user by token and Verify it returns the User object itself
        $loadedUser = new User();
        $retResult = $loadedUser->loadByToken($authToken, 'auth', false);
        $this->assertInstanceOf(User::class, $retResult);
        $this->assertEquals($userId, $loadedUser->userid);

        // 5. Deactivate and Expire Token
        $allTokens = $user->getAllTokens();
        $tokenId1 = $allTokens[0]['tokenid'];
        $tokenId2 = $allTokens[1]['tokenid'];

        $user->deactivateToken($tokenId1);
        $user->expireToken($tokenId2);

        // 6. Cleanup Tokens
        $user->cleanupAuthTokens(0);

        // 7. Delete and Clear
        $user->deleteToken($tokenId1);
        $user->clearTokens();
        $this->assertFalse($user->getToken());

        $remainingTokens = $user->getAllTokens();
        foreach ($remainingTokens as $tok) {
            $this->assertEquals(2, $tok['status']);
        }

        // Cleanup user
        $user->deleteuser();
    }

    /**
     * Tests the web session tokens lifecycle.
     *
     * Creates web session tokens, retrieves active sessions, and invalidates them.
     *
     * @return void
     */
    #[Test]
    public function testWebSessionTokensLifecycle()
    {
        $user = new User();
        $user->username = 'webuser_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->setPassword('webpass123');
        $user->save();

        // 1. Create Web Session Token
        $token = $user->createWebSessionToken('192.168.1.100');
        $this->assertNotNull($token);

        // 2. Get Active Sessions
        $sessions = $user->getActiveSessions();
        $this->assertNotEmpty($sessions);

        // 3. Invalidate Session Token (needs to be the Token object, not a string)
        $_SESSION['usertoken'] = $token;
        $user->invalidateWebSessionToken();
        $this->assertFalse(isset($_SESSION['usertoken']));

        // Cleanup user
        $user->deleteuser();
    }

    /**
     * Tests user status changes and activity feeds.
     *
     * Adds feeds, changes profile status, lists feed items, and verifies database persistence.
     *
     * @return void
     */
    #[Test]
    public function testUserFeedAndStatusOperations()
    {
        $user = new User();
        $user->username = 'feeduser_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->setPassword('feedpass123');
        $user->save();

        // 1. Check getTableNames
        $tables = $user->getTableNames();
        $this->assertArrayHasKey('users', $tables);
        $this->assertArrayHasKey('userdetails', $tables);

        // Setup feed and userfriends tables if not exist
        $this->db->query("DROP TABLE IF EXISTS `feed`");
        $this->db->query("DROP TABLE IF EXISTS `userfriends`");

        $this->db->query("CREATE TABLE `feed` (
            `itemid` int(11) NOT NULL AUTO_INCREMENT,
            `date` int(11) NOT NULL DEFAULT 0,
            `userid` int(11) NOT NULL DEFAULT 0,
            `usertype` tinyint(4) NOT NULL DEFAULT 0,
            `itemprivacy` tinyint(4) NOT NULL DEFAULT 0,
            `itemtext` text NOT NULL,
            PRIMARY KEY (`itemid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->db->query("CREATE TABLE `userfriends` (
            `from_userid` bigint(20) NOT NULL,
            `to_userid` bigint(20) NOT NULL,
            `confirm` tinyint(4) NOT NULL DEFAULT 0,
            PRIMARY KEY (`from_userid`, `to_userid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 2. Create Friend and Setup friendship
        $friend = new User();
        $friend->username = 'friend_' . bin2hex(random_bytes(4));
        $friend->email = $friend->username . '@example.com';
        $friend->save();
        $friendId = $friend->userid;

        $this->db->query("INSERT INTO `userfriends` (`from_userid`, `to_userid`, `confirm`) VALUES ({$user->userid}, {$friendId}, 1)");

        // 3. Add Feed Item for Friend
        $friend->changeStatus('Friend is active!');

        // 4. Get Feed
        $feedItems = $user->getFeed();
        $this->assertNotEmpty($feedItems);
        
        // Clean up feed & friends tables
        $this->db->query("DROP TABLE IF EXISTS `feed`");
        $this->db->query("DROP TABLE IF EXISTS `userfriends`");
        
        // Cleanup users
        $friend->deleteuser();
        $user->deleteuser();
    }

    /**
     * Tests static users lookup retrieval functions.
     *
     * Verifies that getUser() successfully queries and caches users, and static getUsers()
     * handles WHERE clauses to filter output arrays.
     *
     * @return void
     */
    #[Test]
    public function testStaticUserRetrieval()
    {
        $user = new User();
        $user->username = 'staticuser_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->save();
        $userId = $user->userid;

        // 1. getUser static check
        $retrieved = User::getUser($userId);
        $this->assertEquals($userId, $retrieved->userid);

        // 2. getUsers static check
        $usersList = User::getUsers('userid = ' . $userId);
        $this->assertNotEmpty($usersList);
        $this->assertArrayHasKey($userId, $usersList);

        // Cleanup
        $user->deleteuser();
    }
}
