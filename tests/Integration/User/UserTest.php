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
     * A row in the retired scheme is migrated by the sign-in that reads it.
     *
     * The scheme that was preferred until now appended a 32-character pepper to the
     * plaintext, and bcrypt stops at 72 bytes — so anything a user typed past the 40th
     * character was discarded, and two long passwords sharing a 40-character prefix
     * verified against each other. Nothing reported it: both passwords worked.
     *
     * A successful verification is the only moment the plaintext exists, so it is the only
     * moment the row can be rewritten. Asserted through `verifyPassword()` rather than
     * through the login, because a step-up in the middle of an account screen has to
     * migrate the row too — the two used to disagree about what a stored hash even was.
     */
    #[Test]
    public function testVerifyingARetiredSchemeMigratesTheStoredHash()
    {
        // Arrange — a persisted account, then its hash forced back into the old scheme
        $user = new User();
        $user->username = 'rehash_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->save();
        $userId = (int) $user->userid;
        $this->assertGreaterThan(1, $userId);

        $legacy = password_hash(
            'mysecret123' . \Pramnos\Auth\PasswordHash::pepper($userId),
            PASSWORD_DEFAULT,
            ['cost' => 4]
        );
        $userTable = defined('DB_USERSTABLE') ? DB_USERSTABLE : '#PREFIX#users';
        $this->db->query($this->db->prepareQuery(
            'UPDATE ' . $userTable . ' SET password = %s WHERE userid = %d',
            $legacy,
            $userId
        ));

        // Act
        $loaded = new User($userId);
        $verified = $loaded->verifyPassword('mysecret123');

        // Assert — it verifies, and the stored hash is no longer the old one
        $this->assertTrue($verified, 'the retired scheme must still verify');

        // Read from the row rather than through a `new User($userId)`: the per-process user
        // cache was populated by the load above, so a fresh object hands back the hash as
        // it was *before* the migration — the assertion would fail while the database was
        // correct.
        $row = $this->db->query($this->db->prepareQuery(
            'SELECT password FROM ' . $userTable . ' WHERE userid = %d',
            $userId
        ));
        $storedHash = (string) ($row->fields['password'] ?? '');

        $this->assertNotSame($legacy, $storedHash, 'the row must have been migrated');
        $this->assertSame(
            \Pramnos\Auth\PasswordHash::PREFERRED,
            \Pramnos\Auth\PasswordHash::verify('mysecret123', $storedHash, $userId),
            'and migrated into the preferred scheme'
        );

        // …and the password still works afterwards, which is the only part a user sees
        $this->assertTrue($loaded->verifyPassword('mysecret123'));

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
     * A second sign-in from the same browser retires the token from the first — even after
     * the first token has been used.
     *
     * The bug this covers: `Token::addAction()` overwrote `deviceinfo` on every request with a
     * different shape from the one written at issue, so the fingerprint the retirement matches
     * on was gone after the token's first request. Every old `web_session` token then stayed
     * Active for its full thirty days, and reopening a browser looked like it was minting a new
     * session every time — because it was, and nothing was retiring the previous one.
     *
     * Nothing failed visibly. The tokens are valid bearer credentials, so the only symptom was
     * a growing list of sessions the account holder could not account for.
     *
     * @return void
     */
    #[Test]
    public function testASecondSignInFromTheSameBrowserRetiresTheUsedTokenFromTheFirst()
    {
        // Arrange
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
        unset($_SESSION['usertoken']);

        $user = new User();
        $user->username = 'sessionuser_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->setPassword('sessionpass123');
        $user->save();

        try {
            $first = $user->createWebSessionToken('192.0.2.10');
            $this->assertNotNull($first);

            // The token is used, the way every request after the login uses it. This is what
            // used to rewrite `deviceinfo` into a shape nothing could match again.
            $first->addAction();
            $first->save();

            // Act — the same browser signs in again, in a session of its own
            unset($_SESSION['usertoken']);
            $second = $user->createWebSessionToken('192.0.2.10');

            // Assert
            $live = $this->db->query(
                "SELECT tokenid, status FROM `usertokens` WHERE userid = "
                . (int) $user->userid . " AND tokentype = 'web_session'"
            );
            $status = [];

            foreach ($live->fetchAll() as $row) {
                $status[(int) $row['tokenid']] = (int) $row['status'];
            }

            $this->assertSame(0, $status[(int) $first->tokenid] ?? null,
                'the used token from the previous sign-in is retired');
            $this->assertSame(1, $status[(int) $second->tokenid] ?? null,
                'and the one this sign-in just issued is not');
        } finally {
            $user->deleteuser();
            unset($_SESSION['usertoken']);

            if ($agent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $agent;
            }
        }
    }

    /**
     * A new address on the same browser still retires the old session.
     *
     * `deviceinfo` records the address the token was issued from, and this used to match on the
     * whole stored value — so a router reboot, a move to mobile data or any of the ordinary ways
     * a consumer address changes made the two strings differ, and the older token was left valid
     * for its full thirty days. `currentDeviceInfo()` already documented the address as
     * deliberately not deciding anything; the retirement was deciding on it anyway.
     *
     * @return void
     */
    #[Test]
    public function testANewAddressOnTheSameBrowserStillRetiresTheOldSession()
    {
        // Arrange
        $agent  = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        unset($_SESSION['usertoken']);

        $user = new User();
        $user->username = 'roaming_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->setPassword('roaming123');
        $user->save();

        try {
            $_SERVER['REMOTE_ADDR'] = '192.0.2.30';
            $home = $user->createWebSessionToken('192.0.2.30');

            // Act — same browser, the address the router handed out after a reboot
            unset($_SESSION['usertoken']);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
            $user->createWebSessionToken('198.51.100.77');

            // Assert
            $row = $this->db->query(
                "SELECT status FROM `usertokens` WHERE tokenid = " . (int) $home->tokenid
            );

            $this->assertSame(0, (int) $row->fields['status'],
                'the browser is the same one; the address it arrived from is not the question');
        } finally {
            $user->deleteuser();
            unset($_SESSION['usertoken']);

            if ($agent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $agent;
            }

            if ($remote === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $remote;
            }
        }
    }

    /**
     * A sign-in on another browser leaves this one's session alone.
     *
     * The whole point of having more than one session. A retirement that matched too widely
     * would sign somebody out of their phone every time they opened their laptop, which is a
     * worse failure than the one being fixed.
     *
     * @return void
     */
    #[Test]
    public function testASignInFromAnotherBrowserLeavesThisOneAlone()
    {
        // Arrange
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        unset($_SESSION['usertoken']);

        $user = new User();
        $user->username = 'twodevice_' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.com';
        $user->setPassword('twodevice123');
        $user->save();

        try {
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';
            $phone = $user->createWebSessionToken('192.0.2.20');

            // Act — the same account, a different browser and platform
            unset($_SESSION['usertoken']);
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
            $laptop = $user->createWebSessionToken('192.0.2.21');

            // Assert
            $row = $this->db->query(
                "SELECT status FROM `usertokens` WHERE tokenid = " . (int) $phone->tokenid
            );

            $this->assertSame(1, (int) $row->fields['status'],
                'signing in on a laptop must not sign you out on a phone');
            $this->assertNotSame((int) $phone->tokenid, (int) $laptop->tokenid);
        } finally {
            $user->deleteuser();
            unset($_SESSION['usertoken']);

            if ($agent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $agent;
            }
        }
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
