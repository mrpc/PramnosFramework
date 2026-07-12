<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Drivers\DatabaseAuthDriver;
use Pramnos\Auth\Drivers\AuthResult;
use Pramnos\Database\Database;

/**
 * Integration tests for DatabaseAuthDriver against a live MySQL 8.0 database.
 *
 * These tests verify that DatabaseAuthDriver::verify() correctly authenticates
 * users stored in the `users` table using bcrypt, legacy MD5, and cookie-based
 * (encrypted-password) flows. Tests also cover inactive/deleted/banned users
 * and the MD5 auto-upgrade path.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class DatabaseAuthDriverMySQLTest extends TestCase
{
    protected Database $db;

    /** Original DB prefix restored in tearDown. */
    protected string $originalPrefix = '';

    /** Snapshot of appInstances restored in tearDown. */
    protected array $originalAppInstances = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Snapshot Application singleton state
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->originalAppInstances = $ref->getValue() ?? [];

        // Isolated prefix so tests never conflict with real 'users' table
        $this->originalPrefix = $this->db->prefix;
        $this->db->prefix     = 'testdad_';

        $this->dropTable();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        $this->dropTable();
        $this->db->prefix = $this->originalPrefix;

        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $ref->setValue(null, $this->originalAppInstances);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function dropTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `testdad_users`');
    }

    protected function createTable(): void
    {
        $this->db->query(
            'CREATE TABLE `testdad_users` (
                `userid`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `username`  VARCHAR(100) NOT NULL,
                `password`  VARCHAR(255) NOT NULL,
                `email`     VARCHAR(255) NOT NULL DEFAULT \'\',
                `active`    TINYINT NOT NULL DEFAULT 1,
                `validated` TINYINT NOT NULL DEFAULT 1,
                PRIMARY KEY (`userid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Set applicationInfo['auth'] on the Application singleton so the driver
     * reads the test-specific config (legacy_md5, auto_upgrade).
     */
    protected function setAuthConfig(array $authConfig): void
    {
        $stub                  = new \stdClass();
        $stub->applicationInfo = ['auth' => $authConfig];

        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $instances            = $ref->getValue() ?? [];
        $instances['default'] = $stub;
        $ref->setValue(null, $instances);
    }

    /**
     * Insert a bcrypt-hashed user and return their userid.
     *
     * Uses the same salt formula as UserDatabase::onAuth() so that the driver
     * can verify the password.
     */
    protected function insertBcryptUser(
        string $username,
        string $plainPassword,
        int    $active = 1
    ): int {
        $salt    = Settings::getSetting('securitySalt');
        // userid is unknown until after insert; use a placeholder salt for uid=0
        // then update the hash after insert (mirrors how User::setPassword works)
        $sql = $this->db->prepareQuery(
            "INSERT INTO `testdad_users` (`username`, `password`, `email`, `active`, `validated`)
             VALUES (%s, %s, %s, %d, 1)",
            $username,
            'placeholder',
            $username . '@example.com',
            $active
        );
        $this->db->query($sql);
        $uid = (int) $this->db->getInsertId();

        // Recompute hash now that we know the uid
        $hash = password_hash($plainPassword . md5($salt . $uid), PASSWORD_DEFAULT);
        $this->db->query(
            $this->db->prepareQuery(
                "UPDATE `testdad_users` SET `password` = %s WHERE `userid` = %d",
                $hash,
                $uid
            )
        );

        return $uid;
    }

    protected function insertMd5User(string $username, string $plainPassword): int
    {
        $sql = $this->db->prepareQuery(
            "INSERT INTO `testdad_users` (`username`, `password`, `email`, `active`, `validated`)
             VALUES (%s, %s, %s, 1, 1)",
            $username,
            md5($plainPassword),
            $username . '@example.com'
        );
        $this->db->query($sql);
        return (int) $this->db->getInsertId();
    }

    protected function readStoredPassword(int $userid): string
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT `password` FROM `testdad_users` WHERE `userid` = %d',
                $userid
            )
        );
        return (string) ($result->fields['password'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Tests — bcrypt
    // -------------------------------------------------------------------------

    /**
     * verify() must return AuthResult::success for a correct bcrypt password.
     *
     * This is the primary happy-path contract: new users created with proper
     * bcrypt hashing must be authenticatable via DatabaseAuthDriver.
     */
    public function testVerifyReturnsTrueForCorrectBcryptPassword(): void
    {
        // Arrange
        $uid = $this->insertBcryptUser('alice', 'correct_pass');
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('alice', 'correct_pass');

        // Assert
        $this->assertTrue($result->success, 'AuthResult must report success for correct bcrypt password');
        $this->assertSame('alice',             $result->username);
        $this->assertSame($uid,                $result->uid);
        $this->assertSame('alice@example.com', $result->email);
    }

    /**
     * verify() must return AuthResult::failure for a wrong password.
     *
     * Wrong passwords must never authenticate; the statusCode must be 400.
     */
    public function testVerifyReturnsFailureForWrongPassword(): void
    {
        // Arrange
        $this->insertBcryptUser('bob', 'correct_pass');
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('bob', 'wrong_pass');

        // Assert
        $this->assertFalse($result->success, 'AuthResult must report failure for wrong password');
        $this->assertSame(400, $result->statusCode, 'statusCode must be 400 for wrong password');
    }

    /**
     * verify() must return AuthResult::failure with statusCode=404 for unknown users.
     *
     * The driver must never expose whether a username or email exists via a different
     * error code; 404 is the correct "not found" signal.
     */
    public function testVerifyReturnsFailureForUnknownUser(): void
    {
        // Arrange
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('nobody@example.com', 'any_pass');

        // Assert
        $this->assertFalse($result->success);
        $this->assertSame(404, $result->statusCode, 'Unknown user must yield statusCode 404');
    }

    // -------------------------------------------------------------------------
    // Tests — inactive / deleted / banned
    // -------------------------------------------------------------------------

    /**
     * An inactive user (active=0) must not authenticate even with the correct password.
     *
     * Active=0 means the account has been deactivated by an admin; the driver
     * must reject the login before reaching the password check.
     */
    public function testVerifyRejectsInactiveUser(): void
    {
        // Arrange
        $this->insertBcryptUser('inactive_user', 'pass', active: 0);
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('inactive_user', 'pass');

        // Assert
        $this->assertFalse($result->success);
        $this->assertSame(0, $result->statusCode, 'Inactive user must yield statusCode 0');
    }

    /**
     * A deleted user (active=2) must not authenticate.
     */
    public function testVerifyRejectsDeletedUser(): void
    {
        // Arrange
        $this->insertBcryptUser('deleted_user', 'pass', active: 2);
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('deleted_user', 'pass');

        // Assert
        $this->assertFalse($result->success);
        $this->assertSame(2, $result->statusCode, 'Deleted user must yield statusCode 2');
    }

    /**
     * A banned user (active=5) must not authenticate.
     */
    public function testVerifyRejectsBannedUser(): void
    {
        // Arrange
        $this->insertBcryptUser('banned_user', 'pass', active: 5);
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('banned_user', 'pass');

        // Assert
        $this->assertFalse($result->success);
        $this->assertSame(5, $result->statusCode, 'Banned user must yield statusCode 5');
    }

    // -------------------------------------------------------------------------
    // Tests — legacy MD5
    // -------------------------------------------------------------------------

    /**
     * When legacy_md5 is not configured (new-app default), a user with an MD5
     * password must NOT authenticate.
     *
     * This prevents silent auth from old data accidentally imported into a new app.
     */
    public function testLegacyMd5DisabledByDefaultRejectsMd5Password(): void
    {
        // Arrange — driver uses defaults (no legacy_md5)
        $this->insertMd5User('md5user', 'secret');
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('md5user', 'secret');

        // Assert
        $this->assertFalse($result->success, 'MD5 password must be rejected when legacy_md5 is disabled');
    }

    /**
     * When legacy_md5=true is set, a user with an MD5-stored password must authenticate.
     *
     * Legacy apps that have not yet migrated their password store need this path.
     */
    public function testLegacyMd5EnabledAuthenticatesMd5Password(): void
    {
        // Arrange
        $this->insertMd5User('md5user2', 'secret2');
        $driver = new DatabaseAuthDriver(['legacy_md5' => true, 'auto_upgrade' => false]);

        // Act
        $result = $driver->verify('md5user2', 'secret2');

        // Assert
        $this->assertTrue($result->success, 'MD5 password must authenticate when legacy_md5=true');
    }

    /**
     * Auto-upgrade: after a successful MD5 authentication, the stored hash must
     * be replaced with bcrypt so future logins use the secure path.
     *
     * This is the key migration mechanism — no explicit migration script is needed;
     * users upgrade organically on their first post-upgrade login.
     */
    public function testAutoUpgradeReplacesStoredMd5HashWithBcrypt(): void
    {
        // Arrange
        $uid = $this->insertMd5User('md5user3', 'secret3');
        $driver = new DatabaseAuthDriver(['legacy_md5' => true, 'auto_upgrade' => true]);

        // Act
        $result = $driver->verify('md5user3', 'secret3');

        // Assert — login succeeded
        $this->assertTrue($result->success, 'MD5 login with auto_upgrade must succeed');

        // Assert — stored hash is now bcrypt, not MD5
        $storedHash = $this->readStoredPassword($uid);
        $this->assertNotSame(md5('secret3'), $storedHash, 'Old MD5 hash must no longer be stored');
        $this->assertStringStartsWith('$2', $storedHash, 'Upgraded hash must start with bcrypt prefix $2');
    }

    /**
     * When auto_upgrade=false, a successful MD5 login must NOT modify the stored hash.
     *
     * Some apps may want to defer the upgrade or handle it separately.
     */
    public function testAutoUpgradeDisabledLeavesStoredMd5Unchanged(): void
    {
        // Arrange
        $uid = $this->insertMd5User('md5user4', 'secret4');
        $driver = new DatabaseAuthDriver(['legacy_md5' => true, 'auto_upgrade' => false]);

        // Act
        $result = $driver->verify('md5user4', 'secret4');

        // Assert — login succeeded
        $this->assertTrue($result->success);

        // Assert — stored hash is still the original MD5
        $storedHash = $this->readStoredPassword($uid);
        $this->assertSame(md5('secret4'), $storedHash, 'Hash must remain MD5 when auto_upgrade=false');
    }

    // -------------------------------------------------------------------------
    // Tests — encrypted-password (cookie re-auth)
    // -------------------------------------------------------------------------

    /**
     * When $encryptedPassword=true, the driver compares the supplied password
     * directly to the stored hash — used by UserDatabase::onAuthCheck() to
     * validate the auth cookie without knowing the plain-text password.
     */
    public function testEncryptedPasswordPathAllowsDirectHashComparison(): void
    {
        // Arrange
        $uid = $this->insertBcryptUser('cookieuser', 'my_pass');
        $storedHash = $this->readStoredPassword($uid);
        $driver = new DatabaseAuthDriver();

        // Act — supply the stored hash directly with encryptedPassword=true
        $result = $driver->verify('cookieuser', $storedHash, encryptedPassword: true);

        // Assert
        $this->assertTrue($result->success, 'Correct hash with encryptedPassword=true must succeed');
    }

    /**
     * A wrong hash with encryptedPassword=true must fail — the comparison is
     * strict string equality, so any mismatch is rejected.
     */
    public function testEncryptedPasswordPathRejectsWrongHash(): void
    {
        // Arrange
        $this->insertBcryptUser('cookieuser2', 'my_pass');
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('cookieuser2', 'not_the_real_hash', encryptedPassword: true);

        // Assert
        $this->assertFalse($result->success);
    }

    // -------------------------------------------------------------------------
    // Tests — AuthResult shape
    // -------------------------------------------------------------------------

    /**
     * AuthResult::toArray() must return the legacy array shape expected by
     * Addon\User\User::onLogin() and consumers reading Auth::$lastResponse.
     *
     * The keys 'status', 'uid', 'username', 'email', 'auth', 'remember' must
     * all be present so that legacy onLogin() handlers work without changes.
     */
    public function testVerifySuccessResultConvertsToLegacyArray(): void
    {
        // Arrange
        $uid = $this->insertBcryptUser('arrayuser', 'pass');
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('arrayuser', 'pass');
        $arr    = $result->toArray(remember: false);

        // Assert — all legacy keys must be present
        $this->assertTrue($arr['status']);
        $this->assertSame('arrayuser', $arr['username']);
        $this->assertSame($uid, $arr['uid']);
        $this->assertSame('arrayuser@example.com', $arr['email']);
        $this->assertFalse($arr['remember'], '$remember=false must flow through to the array');
        $this->assertArrayHasKey('auth', $arr, "'auth' key (stored hash) must be in the legacy array");
    }
}
