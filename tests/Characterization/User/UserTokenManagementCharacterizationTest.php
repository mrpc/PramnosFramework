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

    /**
     * Builds the user schema once for the whole class.
     *
     * Dropping five tables and running `User::setupDb()` per test was most of this class's
     * 395 ms per test, and these 13 tests assert what token management does with rows.
     * `tearDown()` already cleans up by row, so nothing about the schema needs to be per
     * test.
     *
     * The explicit drop before `setupDb()` is kept for the reason the original comment
     * gives: `setupDb()` uses `CREATE TABLE IF NOT EXISTS`, so a table left by an earlier
     * class with a stale schema would be silently kept and then fail on INSERT.
     * `FOREIGN_KEY_CHECKS` stays 0 across the whole drop-and-create — cycling it between
     * the drop and the create produces InnoDB's "Failed to open the referenced table".
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $db = self::bootDatabase();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['usertokens', 'userstogroups', 'userdetails', 'users', 'usergroups'] as $t) {
            $db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        User::setupDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Loads the fixture settings and returns a connected Factory database.
     *
     * @return \Pramnos\Database\Database A connected handle
     */
    private static function bootDatabase(): \Pramnos\Database\Database
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

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
     * setPassword() for an existing user (userid > 1) writes a hash `verify()` reads.
     *
     * The characterised behaviour is that the stored hash is per-user — two accounts
     * with the same password do not share a hash — and that the framework's own
     * verification accepts it. Both still hold.
     *
     * What changed underneath is the pre-hash. It used to be `$password . md5(salt . uid)`
     * appended to the plaintext, and bcrypt truncates at 72 bytes: with a 32-character
     * suffix, everything a user typed past the 40th character was silently ignored, and
     * two long passwords sharing a 40-character prefix verified against each other.
     * `PasswordHash` pre-hashes with HMAC-SHA-256 instead, so the whole password reaches
     * the KDF as a fixed-length digest.
     *
     * So this test no longer reconstructs the pre-hash by hand. That is deliberate: a
     * test that spells out the scheme pins the scheme, and this one is about the
     * contract. `PasswordHashTest` is where the scheme itself is asserted.
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
        $this->assertNotSame(md5('securepass'), $user->password);

        // …the framework reads it back, and says which scheme matched
        $this->assertSame(
            \Pramnos\Auth\PasswordHash::PREFERRED,
            \Pramnos\Auth\PasswordHash::verify('securepass', $user->password, 99999)
        );
        $this->assertNull(
            \Pramnos\Auth\PasswordHash::verify('wrong', $user->password, 99999)
        );

        // …and it is bound to the account: the same password on another userid is a
        // different hash, so one leaked hash does not test against every account.
        $other = new User();
        $other->userid = 88888;
        $other->setPassword('securepass');
        $this->assertNotSame($user->password, $other->password);
        $this->assertNull(
            \Pramnos\Auth\PasswordHash::verify('securepass', $other->password, 99999)
        );
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
