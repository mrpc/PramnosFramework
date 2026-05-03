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
}
