<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Drivers\AuthResult;
use Pramnos\Auth\Drivers\DatabaseAuthDriver;
use Pramnos\Auth\RememberTokens;
use Pramnos\Database\Database;

/**
 * Three paths {@see DatabaseAuthDriverMySQLTest} does not reach:
 *
 *   - `resolveConfig()`'s own-application branch — `setAuthConfig()` exists in that file but
 *     nothing ever calls it, so `legacy_md5`/`rehash_on_login` are only ever tested via the
 *     driver's constructor array, never via `app.php`;
 *   - Path 4 (a `RememberTokens` token presented where the hash is expected) — the cookie
 *     re-auth flow for a client that re-authenticates with the token it was given, rather
 *     than storing the password;
 *   - a password-hash upgrade whose write fails — must not fail the login that already
 *     succeeded on the strength of the password just checked.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class DatabaseAuthDriverCoverageGapsTest extends TestCase
{
    protected Database $db;

    protected string $originalPrefix = '';

    protected array $originalAppInstances = [];

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

        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->originalAppInstances = $ref->getValue() ?? [];

        $this->originalPrefix = $this->db->prefix;
        $this->db->prefix     = 'testdadgaps_';

        $this->db->query('DROP TABLE IF EXISTS `testdadgaps_users`');
        $this->db->query(
            'DROP TABLE IF EXISTS '
            . $this->db->schema()->quoteTable('authserver.remember_tokens')
        );
        $this->db->query(
            'CREATE TABLE `testdadgaps_users` (
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

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `testdadgaps_users`');
        $this->db->query(
            'DROP TABLE IF EXISTS '
            . $this->db->schema()->quoteTable('authserver.remember_tokens')
        );
        $this->db->prefix = $this->originalPrefix;

        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $ref->setValue(null, $this->originalAppInstances);
    }

    /** A real `Application`, so `resolveConfig()`'s `Application::currentInstance()` finds one. */
    protected function setAuthConfig(array $authConfig): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => $authConfig];

        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $instances             = $ref->getValue() ?? [];
        $instances['default']  = $stub;
        $ref->setValue(null, $instances);
    }

    protected function insertMd5User(string $username, string $plainPassword): int
    {
        $this->db->query($this->db->prepareQuery(
            "INSERT INTO `testdadgaps_users` (`username`, `password`, `email`, `active`, `validated`)
             VALUES (%s, %s, %s, 1, 1)",
            $username,
            md5($plainPassword),
            $username . '@example.com'
        ));

        return (int) $this->db->getInsertId();
    }

    protected function insertBcryptUser(string $username, string $plainPassword): int
    {
        $salt = Settings::getSetting('securitySalt');
        $this->db->query($this->db->prepareQuery(
            "INSERT INTO `testdadgaps_users` (`username`, `password`, `email`, `active`, `validated`)
             VALUES (%s, %s, %s, 1, 1)",
            $username,
            'placeholder',
            $username . '@example.com'
        ));
        $uid = (int) $this->db->getInsertId();
        $hash = password_hash($plainPassword . md5($salt . $uid), PASSWORD_DEFAULT);
        $this->db->query($this->db->prepareQuery(
            'UPDATE `testdadgaps_users` SET `password` = %s WHERE `userid` = %d',
            $hash,
            $uid
        ));

        return $uid;
    }

    /** A real `remember_tokens` row for `$uid`, through the migration this feature ships. */
    protected function createRememberTokensTable(): void
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        require_once ROOT . '/database/migrations/framework/auth/2026_10_16_000001_create_remember_tokens_table.php';
        (new \Pramnos\Framework\Migrations\Auth\CreateRememberTokensTable($app))->up();
    }

    /**
     * `rehash_on_login`/`legacy_md5` read from `app.php`, not only from the driver's own
     * constructor array — the configuration the guide documents and most installations use.
     */
    public function testConfigurationIsReadFromTheApplicationWhenNoneIsGivenToTheConstructor(): void
    {
        // Arrange — legacy MD5 allowed only through app.php, no constructor config at all
        $this->setAuthConfig(['legacy_md5' => true, 'rehash_on_login' => 'off']);
        $uid = $this->insertMd5User('fromapp', 'secret');
        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('fromapp', 'secret');

        // Assert — authenticated via the app-level legacy_md5 switch...
        $this->assertTrue($result->success);
        // ...and left as MD5, because rehash_on_login is off at the app level too
        $stored = (string) $this->db->query(
            $this->db->prepareQuery('SELECT `password` FROM `testdadgaps_users` WHERE `userid` = %d', $uid)
        )->fields['password'];
        $this->assertSame(md5('secret'), $stored);
    }

    /**
     * A sign-in token presented where the password hash is expected authenticates — the
     * cookie re-auth path, used when a client re-authenticates with what it was given
     * instead of storing the password.
     */
    public function testARememberTokenAuthenticatesInThePasswordSlot(): void
    {
        // Arrange
        $this->createRememberTokensTable();
        $uid   = $this->insertBcryptUser('viaToken', 'whatever-the-password-is');
        $token = RememberTokens::issue($uid);
        $this->assertNotNull($token, 'the fixture could not issue a token');

        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('viaToken', $token, encryptedPassword: true);

        // Assert
        $this->assertTrue($result->success);
        $this->assertSame($uid, $result->uid);
    }

    /** A token for a *different* account does not authenticate this one. */
    public function testARememberTokenForAnotherAccountIsRefused(): void
    {
        // Arrange
        $this->createRememberTokensTable();
        $uid        = $this->insertBcryptUser('owner', 'whatever');
        $otherUid   = $this->insertBcryptUser('someone_else', 'whatever-else');
        $theirToken = RememberTokens::issue($otherUid);
        $this->assertNotNull($theirToken, 'the fixture could not issue a token');

        $driver = new DatabaseAuthDriver();

        // Act
        $result = $driver->verify('owner', $theirToken, encryptedPassword: true);

        // Assert
        $this->assertFalse($result->success);
    }

    /**
     * A matched MD5 password that cannot be upgraded (the write fails) still succeeds —
     * the login must not fail because the upgrade could not be written.
     *
     * The initial read must still succeed (it finds the existing, short MD5 hash and matches
     * it in PHP), only the *write* of the new bcrypt hash has to fail — done by narrowing the
     * `password` column to a width the existing MD5 hash fits but a bcrypt hash does not, so
     * MySQL's strict mode refuses the `UPDATE` with a truncation error.
     */
    public function testAFailedUpgradeWriteStillSucceedsTheLogin(): void
    {
        // Arrange
        $this->setAuthConfig([]);
        $uid = $this->insertMd5User('upgrademe', 'secret');
        $this->db->query('ALTER TABLE `testdadgaps_users` MODIFY `password` VARCHAR(32) NOT NULL');
        $driver = new DatabaseAuthDriver(['legacy_md5' => true, 'auto_upgrade' => true]);

        // Act
        $result = $driver->verify('upgrademe', 'secret');

        // Assert — the login succeeded despite the write failing
        $this->assertTrue($result->success, 'a login must not fail because its own password upgrade could not be written');
        $stored = (string) $this->db->query(
            $this->db->prepareQuery('SELECT `password` FROM `testdadgaps_users` WHERE `userid` = %d', $uid)
        )->fields['password'];
        $this->assertSame(md5('secret'), $stored, 'the failed write must not have left a truncated hash behind');
    }
}
