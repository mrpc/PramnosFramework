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
 * Characterization tests for User pure-logic methods and
 * token management integration.
 *
 * Locks: getTableNames(), setPassword() hashing strategy,
 * addToken/getToken/getAllTokens/deactivateToken/expireToken/cleanupAuthTokens.
 */
#[CoversClass(User::class)]
class UserTokenManagementCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;
    /** @var int[] */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
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

        // Drop tables before (re)creating them with User::setupDb().
        // User::setupDb() uses CREATE TABLE IF NOT EXISTS, so it silently skips
        // table creation when the table already exists. If a previous test run left
        // a table with a stale schema (e.g. missing a column that was added later),
        // subsequent INSERT statements would fail with "Field ... doesn't have a
        // default value" or "Unknown column" errors.
        // Explicitly dropping ensures every setUp starts with the canonical schema.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['usertokens', 'userstogroups', 'userdetails', 'users', 'usergroups'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        User::setupDb();
    }

    protected function tearDown(): void
    {
        // Remove test users and their tokens
        foreach ($this->createdUserIds as $uid) {
            $this->db->query($this->db->prepareQuery(
                'DELETE FROM `#PREFIX#usertokens` WHERE `userid` = %d', $uid
            ));
            $this->db->query($this->db->prepareQuery(
                'DELETE FROM `#PREFIX#userdetails` WHERE `userid` = %d', $uid
            ));
            $this->db->query($this->db->prepareQuery(
                'DELETE FROM `#PREFIX#users` WHERE `userid` = %d', $uid
            ));
        }
    }

    // -----------------------------------------------------------------------
    // Pure-logic (no DB)
    // -----------------------------------------------------------------------

    /**
     * getTableNames() returns the expected keys 'users' and 'userdetails'.
     */
    public function testGetTableNamesReturnsExpectedKeys(): void
    {
        // Arrange
        $user = new User();

        // Act
        $tables = $user->getTableNames();

        // Assert
        $this->assertArrayHasKey('users', $tables);
        $this->assertArrayHasKey('userdetails', $tables);
    }

    /**
     * setPassword() for a new user (userid == 0) stores an md5 hash.
     * This is the legacy single-factor path used before the user is saved.
     */
    public function testSetPasswordForNewUserUsesMd5(): void
    {
        // Arrange
        $user = new User(); // userid = 0, _isnew = 1

        // Act
        $user->setPassword('mypassword');

        // Assert – md5 produces a 32-character hex string
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $user->password);
        $this->assertSame(md5('mypassword'), $user->password);
    }

    /**
     * setPassword() for userid == 1 (admin sentinel) also uses md5.
     */
    public function testSetPasswordForUserid1UsesMd5(): void
    {
        // Arrange
        $user = new User();
        $user->userid = 1;

        // Act
        $user->setPassword('adminpass');

        // Assert
        $this->assertSame(md5('adminpass'), $user->password);
    }

    /**
     * setPassword() for an existing user (userid > 1) uses password_hash.
     * The resulting hash verifies correctly against the salted input.
     */
    public function testSetPasswordForExistingUserUsesPasswordHash(): void
    {
        // Arrange
        $user = new User();
        $user->userid = 99999; // any value > 1

        // Act
        $user->setPassword('securepass');

        // Assert – password_hash produces a bcrypt/argon string, not plain md5
        $this->assertStringStartsWith('$', $user->password);
        // And it verifies with the salted input
        $salt = md5(Settings::getSetting('securitySalt', '') . $user->userid);
        $this->assertTrue(password_verify('securepass' . $salt, $user->password));
    }

    // -----------------------------------------------------------------------
    // Token management (integration, requires DB)
    // -----------------------------------------------------------------------

    /**
     * Helper: create and persist a test user, returning the populated User.
     */
    private function createTestUser(): User
    {
        $name = 'char_tokenmgmt_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $name;
        $user->email = $name . '@example.com';
        $user->firstname = 'Char';
        $user->lastname = 'TokenMgmt';
        $user->setPassword('pass');
        $user->save();
        $uid = (int) $user->userid;
        $this->assertGreaterThan(1, $uid, 'User must be persisted to test tokens');
        $this->createdUserIds[] = $uid;
        return $user;
    }

    /**
     * addToken() persists a token and getToken() retrieves its value.
     */
    public function testAddTokenAndGetToken(): void
    {
        // Arrange
        $user = $this->createTestUser();
        $tokenValue = 'tok_' . bin2hex(random_bytes(8));

        // Act
        $user->addToken('auth', $tokenValue, 'characterization');

        // Assert
        $retrieved = $user->getToken();
        $this->assertSame($tokenValue, $retrieved);
    }

    /**
     * getAllTokens() returns a structured array with expected keys for each token.
     */
    public function testGetAllTokensReturnsStructuredArray(): void
    {
        // Arrange
        $user = $this->createTestUser();
        $t1 = 'tok_' . bin2hex(random_bytes(6));
        $t2 = 'tok_' . bin2hex(random_bytes(6));
        $user->addToken('auth', $t1, 'first');
        $user->addToken('auth', $t2, 'second');

        // Act
        $tokens = $user->getAllTokens();

        // Assert
        $this->assertIsArray($tokens);
        $this->assertCount(2, $tokens);
        foreach ($tokens as $token) {
            $this->assertArrayHasKey('tokenid', $token);
            $this->assertArrayHasKey('token', $token);
            $this->assertArrayHasKey('tokentype', $token);
            $this->assertArrayHasKey('status', $token);
        }
    }

    /**
     * deactivateToken() sets token status to 0, so getToken() no longer returns it.
     */
    public function testDeactivateTokenRemovesTokenFromGetToken(): void
    {
        // Arrange
        $user = $this->createTestUser();
        $tokenValue = 'tok_' . bin2hex(random_bytes(8));
        $user->addToken('auth', $tokenValue, 'deactivate test');

        // Get the tokenid
        $tokens = $user->getAllTokens();
        $this->assertCount(1, $tokens);
        $tokenId = $tokens[0]['tokenid'];

        // Act
        $result = $user->deactivateToken($tokenId);

        // Assert
        $this->assertTrue($result);
        // Token is no longer returned as active
        $this->assertFalse($user->getToken());
    }

    /**
     * expireToken() marks the token with an expiry timestamp and status=0.
     */
    public function testExpireTokenSetsTokenStatusToInactive(): void
    {
        // Arrange
        $user = $this->createTestUser();
        $tokenValue = 'tok_' . bin2hex(random_bytes(8));
        $user->addToken('auth', $tokenValue, 'expire test');

        $tokens = $user->getAllTokens();
        $tokenId = $tokens[0]['tokenid'];

        // Act
        $result = $user->expireToken($tokenId);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($user->getToken()); // expired token no longer active
    }

    /**
     * clearTokens() deactivates ALL tokens for a user (sets status=2).
     */
    public function testClearTokensDeactivatesAllTokens(): void
    {
        // Arrange
        $user = $this->createTestUser();
        $user->addToken('auth', 'tok_' . bin2hex(random_bytes(6)), 'clear1');
        $user->addToken('auth', 'tok_' . bin2hex(random_bytes(6)), 'clear2');

        // Act
        $user->clearTokens();

        // Assert – no active token remains
        $this->assertFalse($user->getToken());
    }

    /**
     * cleanupAuthTokens() marks old auth tokens as expired (status=2).
     * When called with $days=0, all tokens older than "now" are cleaned.
     */
    public function testCleanupAuthTokensReturnsTrue(): void
    {
        // Arrange
        $user = $this->createTestUser();
        $user->addToken('auth', 'tok_' . bin2hex(random_bytes(6)), 'cleanup');

        // Act
        $result = $user->cleanupAuthTokens(0); // 0 days = all tokens are "old"

        // Assert
        $this->assertTrue($result);
    }

    /**
     * deleteToken() must set the token's status to 2 (removed) in the database.
     * This tests the QB-based UPDATE path that was previously raw SQL.
     */
    public function testDeleteTokenSetsStatusToRemoved(): void
    {
        // Arrange — create a user and an auth token
        $user = $this->createTestUser();
        $tokenValue = 'tok_' . bin2hex(random_bytes(8));
        $user->addToken('auth', $tokenValue, 'delete test');

        $tokens = $user->getAllTokens();
        $this->assertCount(1, $tokens, 'Precondition: exactly one token exists');
        $tokenId = (int) $tokens[0]['tokenid'];

        // Act
        $user->deleteToken($tokenId);

        // Assert — getToken() must return false because status is now 2, not 1
        // This proves the UPDATE reached the database and changed the status column
        $this->assertFalse($user->getToken());

        // Verify status=2 directly in DB to distinguish from status=0 (deactivated)
        $db = \Pramnos\Framework\Factory::getDatabase();
        $row = $db->queryBuilder()
            ->table('usertokens')
            ->where('tokenid', $tokenId)
            ->first();
        $this->assertSame('2', (string) $row->fields['status'],
            'deleteToken() must set status=2, not just deactivate (status=0)');
    }

    /**
     * cleanupAllAuthTokens() (static) must mark all old auth tokens across all
     * users as status=2.  We back-date the token by 10 seconds in the DB, then
     * call cleanupAllAuthTokens() with a cutoff of "now - 5 seconds", which makes
     * the token eligible and verifies the UPDATE reached the database.
     */
    public function testCleanupAllAuthTokensMarksOldTokens(): void
    {
        // Arrange — create a user and persist one auth token
        $user  = $this->createTestUser();
        $token = 'tok_' . bin2hex(random_bytes(8));
        $user->addToken('auth', $token, 'all cleanup test');

        $tokens = $user->getAllTokens();
        $this->assertCount(1, $tokens, 'Precondition: exactly one token exists');
        $tokenId = (int) $tokens[0]['tokenid'];

        // Back-date created and lastused by 10 seconds so they fall before any
        // reasonable cutoff we pass in the next step.
        $tenSecondsAgo = time() - 10;
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()
            ->table('usertokens')
            ->where('tokenid', $tokenId)
            ->update(['created' => $tenSecondsAgo, 'lastused' => $tenSecondsAgo]);

        // Act — cutoff = time() - (0 days) = time(); since created is 10s ago,
        // the token satisfies created < cutoff and lastused < cutoff.
        $result = User::cleanupAllAuthTokens(0);

        // Assert — method must report success
        $this->assertTrue($result);

        // Assert — the token's status must have been updated to 2 in the DB
        $row = $db->queryBuilder()
            ->table('usertokens')
            ->where('tokenid', $tokenId)
            ->first();
        $this->assertSame('2', (string) $row->fields['status'],
            'cleanupAllAuthTokens() must set status=2 on old auth tokens');
    }

    /**
     * loadByToken() must load the User that owns a given active token.
     * This tests the QB-based SELECT with the expires/status conditions.
     */
    public function testLoadByToken(): void
    {
        // Arrange — create a user and add a token
        $user       = $this->createTestUser();
        $uid        = (int) $user->userid;
        $tokenValue = 'tok_' . bin2hex(random_bytes(8));
        $user->addToken('auth', $tokenValue, 'loadbytoken test');

        // Act — load a fresh User object by the token value
        $loaded = new \Pramnos\User\User();
        $loaded->loadByToken($tokenValue, 'auth', false);

        // Assert — the loaded user must have the same userid
        // This proves the SELECT with tokentype/status/expires conditions works
        $this->assertSame($uid, (int) $loaded->userid,
            'loadByToken() must resolve the userid from the usertokens table');
    }
}
