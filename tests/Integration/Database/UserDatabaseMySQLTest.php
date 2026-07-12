<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Addon\Auth\UserDatabase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Integration tests for Pramnos\Addon\Auth\UserDatabase::onAuth() against MySQL 8.0.
 *
 * Tests focus on the MD5 legacy password path (Phase 25.3):
 *   - MD5 authentication is disabled by default (legacy_md5 not set in app config)
 *   - MD5 authentication succeeds when 'legacy_md5' => true is configured
 *   - A matched MD5 password is auto-upgraded to bcrypt in the database when
 *     'auto_upgrade' => true (the default when legacy_md5 is on)
 *   - When 'auto_upgrade' => false, the MD5 hash is NOT replaced in the database
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class UserDatabaseMySQLTest extends TestCase
{
    protected Database $db;

    /** Original DB prefix so tests can restore it. */
    private string $originalPrefix = '';

    /** Snapshot of appInstances so tearDown can restore the singleton state. */
    private array $originalAppInstances = [];

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

        // Snapshot the current appInstances so tearDown can restore them
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->originalAppInstances = $ref->getValue() ?? [];

        // Use an isolated table prefix so the test table never conflicts with
        // existing framework tables (e.g. the real 'users' table with FK refs).
        $this->originalPrefix = $this->db->prefix;
        $this->db->prefix     = 'testud_';

        $this->dropTable();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        $this->dropTable();

        // Restore DB prefix
        $this->db->prefix = $this->originalPrefix;

        // Restore the Application singleton state
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $ref->setValue(null, $this->originalAppInstances);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Inject a lightweight Application-compatible stub into the singleton registry
     * so that UserDatabase::onAuth() can read applicationInfo['auth'] from it.
     *
     * @param array<string,mixed> $authConfig
     */
    private function setAuthConfig(array $authConfig): void
    {
        $stub                  = new \stdClass();
        $stub->applicationInfo = ['auth' => $authConfig];

        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $instances              = $ref->getValue() ?? [];
        $instances['default']   = $stub;
        $ref->setValue(null, $instances);
    }

    /**
     * Remove any injected Application stub so onAuth() gets null back
     * and uses the secure-by-default (no legacy_md5) path.
     */
    private function clearAuthConfig(): void
    {
        $ref       = new \ReflectionProperty(Application::class, 'appInstances');
        $instances = $ref->getValue() ?? [];
        unset($instances['default']);
        $ref->setValue(null, $instances);
    }

    protected function dropTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `testud_users`');
    }

    protected function createTable(): void
    {
        $this->db->query(
            'CREATE TABLE `testud_users` (
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
     * Insert a test user with the given plain-text password stored as MD5.
     *
     * @return int The inserted userid
     */
    protected function insertMd5User(string $username, string $plainPassword): int
    {
        $sql = $this->db->prepareQuery(
            "INSERT INTO `testud_users` (`username`, `password`, `email`, `active`, `validated`)
             VALUES (%s, %s, %s, 1, 1)",
            $username,
            md5($plainPassword),
            $username . '@example.com'
        );
        $this->db->query($sql);

        return (int) $this->db->getInsertId();
    }

    /**
     * Read the current password hash stored for a user from the database.
     */
    protected function readStoredPassword(int $userid): string
    {
        $sql    = $this->db->prepareQuery(
            'SELECT `password` FROM `testud_users` WHERE `userid` = %d',
            $userid
        );
        $result = $this->db->query($sql);

        return (string) ($result->fields['password'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When 'legacy_md5' is absent from the app config (the default for new apps),
     * a user whose password is stored as plain MD5 must NOT be authenticated.
     *
     * This is the secure-by-default contract: new apps never inadvertently accept
     * MD5 passwords even if old data ends up in the database.
     */
    public function testLegacyMd5DisabledByDefaultRejectsMd5Password(): void
    {
        // Arrange — no auth config injected; UserDatabase falls back to legacyMd5=false
        $this->clearAuthConfig();

        $userid = $this->insertMd5User('alice', 'secret123');
        $addon  = new UserDatabase();

        // Act
        $response = $addon->onAuth('alice', 'secret123');

        // Assert — MD5 password must be rejected when legacy_md5 is not enabled
        $this->assertFalse($response['status'], 'MD5 passwords must be rejected when legacy_md5 is not configured.');
        $this->assertSame(400, $response['statusCode'], 'Wrong password status code expected.');

        // The stored password must remain unchanged (still MD5)
        $stored = $this->readStoredPassword($userid);
        $this->assertSame(md5('secret123'), $stored, 'Password must not be changed in the database.');
    }

    /**
     * When 'legacy_md5' => true is explicitly set in app config, a user whose
     * password is stored as plain MD5 must be authenticated successfully.
     *
     * This allows legacy apps to opt in to MD5 support without forcing all
     * new apps to carry the security risk.
     */
    public function testLegacyMd5EnabledAuthenticatesMd5Password(): void
    {
        // Arrange — opt in to legacy MD5, disable auto-upgrade for this test
        $this->setAuthConfig(['legacy_md5' => true, 'auto_upgrade' => false]);

        $this->insertMd5User('bob', 'hunter2');
        $addon = new UserDatabase();

        // Act
        $response = $addon->onAuth('bob', 'hunter2');

        // Assert — MD5 password accepted when legacy_md5 is enabled
        $this->assertTrue($response['status'], 'MD5 password must be accepted when legacy_md5 => true.');
        $this->assertSame('bob', $response['username']);
    }

    /**
     * When 'auto_upgrade' => true (the default alongside legacy_md5), a successful
     * MD5 match must trigger an immediate in-place upgrade of the stored hash to
     * bcrypt. Subsequent authentication with the same password must then use the
     * bcrypt path, not the MD5 path.
     *
     * This is the key security improvement: each login opportunity is used to
     * silently migrate the credential to a strong hash.
     */
    public function testAutoUpgradeReplacesStoredMd5HashWithBcrypt(): void
    {
        // Arrange — enable legacy MD5 with auto-upgrade
        $this->setAuthConfig(['legacy_md5' => true, 'auto_upgrade' => true]);

        $userid = $this->insertMd5User('carol', 'mypassword');
        $addon  = new UserDatabase();

        // Act — first login; MD5 matches and triggers auto-upgrade
        $response = $addon->onAuth('carol', 'mypassword');

        // Assert — authentication still succeeds during the upgrade
        $this->assertTrue($response['status'], 'Authentication must succeed even during the upgrade.');

        // Assert — the stored hash is now bcrypt, not MD5
        $stored = $this->readStoredPassword($userid);
        $this->assertNotSame(md5('mypassword'), $stored, 'Password must have been replaced — old MD5 must not remain.');
        $this->assertStringStartsWith('$2', $stored, 'New password must be a bcrypt hash (starts with $2y or $2a).');

        // Assert — subsequent login via the normal bcrypt path succeeds
        $response2 = $addon->onAuth('carol', 'mypassword');
        $this->assertTrue($response2['status'], 'Subsequent login must succeed via the new bcrypt hash.');
    }

    /**
     * When 'auto_upgrade' => false, MD5 authentication succeeds but the stored
     * hash is NOT replaced. Legacy apps can opt in to authentication without
     * triggering schema-level writes if they have constraints on UPDATE traffic.
     */
    public function testAutoUpgradeDisabledLeavesStoredMd5Unchanged(): void
    {
        // Arrange — enable legacy MD5 but disable auto-upgrade
        $this->setAuthConfig(['legacy_md5' => true, 'auto_upgrade' => false]);

        $userid = $this->insertMd5User('dave', 'nochange');
        $addon  = new UserDatabase();

        // Act
        $response = $addon->onAuth('dave', 'nochange');

        // Assert — authentication succeeds
        $this->assertTrue($response['status'], 'MD5 authentication must succeed when legacy_md5 is enabled.');

        // Assert — stored hash must still be the original MD5
        $stored = $this->readStoredPassword($userid);
        $this->assertSame(md5('nochange'), $stored, 'Hash must not change when auto_upgrade is disabled.');
    }

    /**
     * Users with an already-current bcrypt password must continue to authenticate
     * normally regardless of the legacy_md5 configuration flag.
     *
     * This guards against regressions where enabling legacy_md5 accidentally breaks
     * the happy path for modern passwords.
     */
    public function testBcryptPasswordAlwaysWorksRegardlessOfLegacyMd5Flag(): void
    {
        // Arrange — legacy_md5 is on so we test no interference with bcrypt path
        $this->setAuthConfig(['legacy_md5' => true, 'auto_upgrade' => true]);

        $plain = 'strongpassword';
        $salt  = Settings::getSetting('securitySalt');

        // Insert user with placeholder password, then replace with real bcrypt
        $sql = $this->db->prepareQuery(
            "INSERT INTO `testud_users` (`username`, `password`, `email`, `active`, `validated`)
             VALUES (%s, %s, %s, 1, 1)",
            'eve',
            '::placeholder::',
            'eve@example.com'
        );
        $this->db->query($sql);
        $userid = (int) $this->db->getInsertId();

        // Build the same hash the addon would build on a fresh registration
        $bcrypt = password_hash($plain . md5($salt . $userid), PASSWORD_DEFAULT);
        $update = $this->db->prepareQuery(
            'UPDATE `testud_users` SET `password` = %s WHERE `userid` = %d',
            $bcrypt,
            $userid
        );
        $this->db->query($update);

        $addon = new UserDatabase();

        // Act
        $response = $addon->onAuth('eve', $plain);

        // Assert — bcrypt path succeeds without any hash replacement
        $this->assertTrue($response['status'], 'Bcrypt users must authenticate normally.');
        $stored = $this->readStoredPassword($userid);
        $this->assertSame($bcrypt, $stored, 'Bcrypt hash must not be replaced during normal authentication.');
    }
}
