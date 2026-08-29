<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\UsersController;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Database\Database;
use Pramnos\Http\Request;

#[CoversClass(UsersController::class)]
class UsersControllerTest extends TestCase
{
    private Database $db;
    private UsersController $controller;
    private $redirectUrl = null;

    /**
     * The tables this class owns.
     *
     * @var string[]
     */
    private const TABLES = ['usertokens', 'sessions', 'users', 'mails'];

    /**
     * The `authserver.*` tables, under the names MySQL actually sees.
     *
     * `authserver_user_activity_log`, not `authserver`.`user_activity_log` — see
     * {@see createAuthServerTables()} for why that distinction cost this class its
     * assertions.
     */
    private const AUTHSERVER_TABLES = [
        'authserver_user_activity_log' => '
            `id` bigint NOT NULL AUTO_INCREMENT,
            `userid` bigint NOT NULL,
            `action` varchar(100) NOT NULL,
            `details` text,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `created_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)',
        'authserver_loginlockouts' => '
            `id` bigint NOT NULL AUTO_INCREMENT,
            `userid` bigint NOT NULL,
            `attempts` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)',
        'authserver_passkey_credentials' => '
            `id` bigint NOT NULL AUTO_INCREMENT,
            `userid` bigint NOT NULL,
            `name` varchar(190) DEFAULT NULL,
            PRIMARY KEY (`id`)',
        'authserver_permissions' => '
            `permissionid` bigint NOT NULL AUTO_INCREMENT,
            `subject_type` varchar(50) NOT NULL,
            `subject_id` bigint NOT NULL,
            `object_type` varchar(100) NOT NULL,
            `object_id` varchar(190) DEFAULT NULL,
            `action` varchar(100) NOT NULL,
            `grant_type` varchar(10) NOT NULL DEFAULT \'allow\',
            `priority` int NOT NULL DEFAULT 100,
            `granted_by` bigint DEFAULT NULL,
            PRIMARY KEY (`permissionid`)',
    ];

    /**
     * Builds the schema once for the whole class.
     *
     * `users` needs every column `User::_save()` inserts — a minimal schema causes silent
     * INSERT failures and loads the wrong user on retry — which is why the DDL is verbatim
     * what this class used to run per test.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $db = self::bootDatabase();
        self::createAuthServerTables($db);

        // Once per class: clears whatever an earlier class left in the SQL cache. It costs
        // 85 ms, which is why it is not per test.
        $db->cacheflush();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $db->query('
            CREATE TABLE `users` (
                `userid` bigint NOT NULL AUTO_INCREMENT,
                `username` varchar(255) NOT NULL DEFAULT \'\',
                `password` varchar(255) NOT NULL DEFAULT \'\',
                `email` varchar(255) NOT NULL DEFAULT \'\',
                `lastname` varchar(128) NOT NULL DEFAULT \'\',
                `firstname` varchar(128) NOT NULL DEFAULT \'\',
                `regdate` int NOT NULL DEFAULT 0,
                `regcompletion` int DEFAULT NULL,
                `lasttermsagreed` int DEFAULT NULL,
                `lastlogin` int NOT NULL DEFAULT 0,
                `active` tinyint NOT NULL DEFAULT 1,
                `validated` tinyint NOT NULL DEFAULT 1,
                `language` varchar(50) NOT NULL DEFAULT \'\',
                `timezone` varchar(50) NOT NULL DEFAULT \'\',
                `dateformat` varchar(15) NOT NULL DEFAULT \'d/m/Y H:i\',
                `usertype` tinyint NOT NULL DEFAULT 0,
                `sex` tinyint NOT NULL DEFAULT 0,
                `birthdate` bigint NOT NULL DEFAULT 0,
                `photo` int DEFAULT NULL,
                `phone` varchar(50) NOT NULL DEFAULT \'\',
                `fax` varchar(50) NOT NULL DEFAULT \'\',
                `mobile` varchar(50) NOT NULL DEFAULT \'\',
                `vat` varchar(15) NOT NULL DEFAULT \'\',
                `website` varchar(255) NOT NULL DEFAULT \'\',
                `modified` int NOT NULL DEFAULT 0,
                `fbauth` bigint DEFAULT NULL,
                `avatarurl` varchar(255) DEFAULT NULL,
                `login_attempts` int NOT NULL DEFAULT 0,
                `last_login_attempt` bigint NOT NULL DEFAULT 0,
                PRIMARY KEY (`userid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $db->query('
            CREATE TABLE `sessions` (
                `visitorid` varchar(255) NOT NULL,
                `uname` varchar(128) NOT NULL DEFAULT \'\',
                `time` int unsigned NOT NULL,
                `host_addr` varchar(39) NOT NULL DEFAULT \'\',
                `guest` tinyint NOT NULL DEFAULT 0,
                `agent` varchar(255) NOT NULL,
                `userid` bigint DEFAULT NULL,
                `url` varchar(255) NOT NULL,
                `history` text NOT NULL,
                `logout` tinyint NOT NULL DEFAULT 0,
                `sid` varchar(32) NOT NULL,
                PRIMARY KEY (`visitorid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $db->query('
            CREATE TABLE `usertokens` (
                `tokenid` int(11) NOT NULL AUTO_INCREMENT,
                `userid` bigint NOT NULL,
                `applicationid` int(11) NOT NULL DEFAULT 0,
                `tokentype` varchar(50) NOT NULL DEFAULT \'\',
                `token` text NOT NULL,
                `expires` bigint(20) NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created` bigint(20) NOT NULL DEFAULT 0,
                `lastused` bigint(20) NOT NULL DEFAULT 0,
                `code_challenge` varchar(128) DEFAULT NULL,
                `code_challenge_method` varchar(10) DEFAULT NULL,
                `deviceinfo` text DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `ipaddress` varchar(45) DEFAULT NULL,
                `parentToken` int(11) DEFAULT NULL,
                `actions` int(11) DEFAULT 0,
                `scope` text DEFAULT NULL,
                `removedate` bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (`tokenid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        // The mail log, which the user screen reads to show what this address was sent.
        $db->query('
            CREATE TABLE `mails` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `status` smallint NOT NULL DEFAULT 0,
                `frommail` varchar(128) NOT NULL DEFAULT \'\',
                `fromname` varchar(255) NOT NULL DEFAULT \'\',
                `tomail` varchar(128) NOT NULL DEFAULT \'\',
                `toname` varchar(255) NOT NULL DEFAULT \'\',
                `subject` varchar(255) NOT NULL DEFAULT \'\',
                `content` text NOT NULL,
                `date` int(11) NOT NULL DEFAULT 0,
                `module` varchar(128) NOT NULL DEFAULT \'\',
                `moduleinfo` varchar(255) NOT NULL DEFAULT \'\',
                `extrainfo` text NOT NULL,
                `path` varchar(255) NOT NULL DEFAULT \'\',
                `hash` char(32) NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Drops the class's tables so the next class builds its own.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        $db = self::bootDatabase();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        self::dropAuthServerTables($db);
    }

    /**
     * The `authserver.*` tables these actions write to.
     *
     * They ship with the `authserver` feature's migrations, and without them the operator
     * actions below can only be tested through the branch that reports "the feature is off"
     * — which leaves the half that actually changes something untested.
     *
     * **On MySQL `authserver.foo` is not a database.** `QueryBuilder::from()` resolves a
     * qualified name to `{prefix}authserver_foo` in the *current* database, because on
     * MySQL `schema.table` means a cross-database reference and the framework wants a
     * namespace. This fixture used to create a database called `authserver` and put the
     * tables in it, so nothing the controller read ever existed: every one of those reads is
     * guarded and returns an empty result on failure, so the panels came back empty, the
     * assertions were about which *keys* the array had, and they passed. A fixture in the
     * wrong place is worse than no fixture — no fixture fails.
     *
     * Dropped again in `tearDownAfterClass()`, and `ActivityLog`'s memoized table probe is
     * reset on both sides: it is a per-process cache, so a later test class in the same
     * process would otherwise inherit this class's answer to "does that table exist".
     */
    private static function createAuthServerTables(\Pramnos\Database\Database $db): void
    {
        foreach (self::AUTHSERVER_TABLES as $table => $columns) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
            $db->query(
                'CREATE TABLE `' . $table . '` (' . $columns
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }

        \Pramnos\Auth\ActivityLog::resetTableCache();
    }

    private static function dropAuthServerTables(\Pramnos\Database\Database $db): void
    {
        foreach (array_keys(self::AUTHSERVER_TABLES) as $table) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }

        \Pramnos\Auth\ActivityLog::resetTableCache();
    }

    /**
     * Loads the fixture settings and returns a connected Factory database.
     *
     * The controller under test reaches the database through the Factory, so the fixtures
     * are built through the same singleton rather than a handle of our own.
     *
     * @return \Pramnos\Database\Database A connected handle
     */
    private static function bootDatabase(): \Pramnos\Database\Database
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }

        Settings::clearSettings();
        $settingsFile = realpath(__DIR__ . '/../../../../fixtures/app/settings.php');
        if (!$settingsFile) {
            throw new \RuntimeException('Test settings not found');
        }
        Settings::loadSettings($settingsFile);

        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }

        if (!defined('APP_PATH')) {
            define('APP_PATH', realpath(__DIR__ . '/../../../../fixtures/app'));
        }

        if (!defined('Pramnos\Application\INCLUDES')) {
            define('Pramnos\Application\INCLUDES', realpath(__DIR__ . '/../../../../../../src') . DIRECTORY_SEPARATOR);
        }

        Settings::clearSettings();
        $settingsFile = realpath(__DIR__ . '/../../../../fixtures/app/settings.php');
        if ($settingsFile) {
            Settings::loadSettings($settingsFile);
        } else {
            throw new \RuntimeException('Test settings not found');
        }

        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // The schema belongs to the class (see setUpBeforeClass); this only clears the rows.
        // Recreating three tables per test cost about 250 ms, and three cacheflush() calls
        // cost another 255 ms — a file-cache directory scan each — in a class whose queries
        // never opt into the SQL cache. Together that was most of this class's 10.65 s.
        //
        // DELETE rather than TRUNCATE, which is implicit DDL and measured slower than
        // DROP + CREATE. The fixtures below give explicit userids, so nothing here depends
        // on auto-increment restarting.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $this->db->query('DELETE FROM `' . $table . '`');
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Tables are freshly created above — insert test fixtures.
        // Insert Anonymous
        $this->db->query('INSERT INTO `users` (`userid`, `username`, `email`, `usertype`, `active`) VALUES (1, "Anonymous", "", 0, 1)');
        // Insert admin user
        $this->db->query('INSERT INTO `users` (`userid`, `username`, `email`, `usertype`, `active`) VALUES (2, "admin", "admin@example.com", 100, 1)');
        // Insert standard user
        $this->db->query('INSERT INTO `users` (`userid`, `username`, `email`, `usertype`, `active`) VALUES (3, "testuser", "test@example.com", 1, 1)');

        $_SESSION = [];
        $_SERVER = [];
        $_POST = [];
        $_GET = [];

        // Mock Application to intercept redirects
        $appMock = $this->createMock(Application::class);
        $appMock->method('redirect')->willReturnCallback(function($url) {
            $this->redirectUrl = $url;
        });
        $appMock->method('getExtraPaths')->willReturn([]);

        \Pramnos\Framework\Factory::getDocument('html');

        $this->controller = clone new UsersController($appMock);
        
        $ref = new \ReflectionClass(Application::class);
        $prop = $ref->getProperty('appInstances');
        $prop->setValue(null, ['default' => $appMock]);

        \Pramnos\Http\Session::getInstance()->start();

        // Setup admin session
        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true);
        }
        global $unittesting_logged;
        $unittesting_logged = true;
        
        $_SESSION['logged'] = true;
        $_SESSION['uid'] = 2;
        $_SESSION['user'] = [
            'userid' => 2,
            'username' => 'admin',
            'usertype' => 100,
            'active' => 1
        ];
        
        $user = new \Pramnos\User\User();
        $user->userid = 2;
        $user->username = 'admin';
        $user->email = 'admin@example.com';
        $user->usertype = 100;
        $user->active = 1;
        
        $appMock->currentUser = $user;
        $_SESSION['last_activity'] = time();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > 1) {
            ob_end_clean();
        }

        // Drop all three test tables, then immediately recreate users+usertokens
        // using the framework's full schema so subsequent tests (OauthCoverageTest,
        // MediaObjectTest, SessionTest, etc.) find the tables in the expected state.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        // The tables belong to the class; tearDownAfterClass() drops them.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Restore the framework schema for tables that other test suites depend on.
        \Pramnos\User\User::setupDb();
        
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::clearSettings();

        $ref = new \ReflectionClass(Application::class);
        $prop = $ref->getProperty('appInstances');
        $prop->setValue(null, []);

        $prop2 = $ref->getProperty('lastUsedApplication');
        $prop2->setValue(null, null);
        
        $refUser = new \ReflectionClass(\Pramnos\User\User::class);
        if ($refUser->hasProperty('usersCache')) {
            $propUser = $refUser->getProperty('usersCache');
            $propUser->setValue(null, []);
        }
        if ($refUser->hasProperty('_usercache')) {
            $propUser2 = $refUser->getProperty('_usercache');
            $propUser2->setValue(null, null);
        }
        $_SESSION = [];
        $_SERVER = [];
        $_POST = [];
        $_GET = [];
    }

    public function testDisplayShowsDataTable(): void
    {
        ob_start();
        $result = $this->controller->display();
        $output = ob_get_clean() . $result;
        
        $this->assertStringContainsString('dt-users', $output);
    }

    public function testViewValidUserReturnsView(): void
    {
        $_GET['_option'] = 3;
        
        ob_start();
        $result = $this->controller->view();
        $output = ob_get_clean() . $result;
        
        $this->assertStringContainsString('testuser', $output);
        $this->assertStringContainsString('test@example.com', $output);
    }

    public function testViewInvalidUserRedirects(): void
    {
        $_GET['_option'] = 999;
        
        ob_start();
        $this->controller->view();
        ob_get_clean();
        
        $this->assertNotNull($this->redirectUrl);
        $this->assertStringContainsString('users', $this->redirectUrl);
    }

    public function testDataReturnsJsonList(): void
    {
        ob_start();
        $response = $this->controller->data();
        $output = ob_get_clean() . ($response ? $response->getBody() : '');
        
        $this->assertStringContainsString('admin', $output);
        $this->assertStringContainsString('testuser', $output);
    }

    public function testEditExistingUserReturnsForm(): void
    {
        $_GET['_option'] = 3; // Edit user 3

        ob_start();
        $result = $this->controller->edit();
        $output = ob_get_clean() . $result;
        
        $this->assertStringContainsString('testuser', $output);
        $this->assertStringContainsString('name="email"', $output);
    }

    public function testEditNewUserReturnsForm(): void
    {
        $_GET['_option'] = 0;
        
        ob_start();
        $result = $this->controller->edit();
        $output = ob_get_clean() . $result;
        
        $this->assertStringContainsString('New User', $output);
    }

    public function testSaveNewUser(): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $token = $session->getCsrfToken();
        
        $_POST = [
            '_csrf_token' => $token,
            'userid' => 0,
            'username' => 'newuser',
            'email' => 'new@example.com',
            'usertype' => 10,
            'active' => '1',
            'password' => 'secret123'
        ];
        
        $this->controller->save();
        
        $this->assertNotNull($this->redirectUrl);
        
        // Verify user was inserted
        $row = $this->db->query("SELECT * FROM `users` WHERE `username` = 'newuser'")->fetch();
        $this->assertNotEmpty($row);
        $this->assertEquals('new@example.com', $row['email']);
        $this->assertEquals(10, $row['usertype']);
    }

    public function testSaveExistingUser(): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $token = $session->getCsrfToken();
        
        $_POST = [
            '_csrf_token' => $token,
            'userid' => 3,
            'username' => 'testuser_updated',
            'email' => 'updated@example.com',
            'usertype' => 20
        ];
        
        $this->controller->save();
        
        $row = $this->db->query("SELECT * FROM `users` WHERE `userid` = 3")->fetch();
        $this->assertEquals('testuser_updated', $row['username']);
        $this->assertEquals(20, $row['usertype']);
    }

    public function testSaveWithInvalidCsrfToken(): void
    {
        $_POST = [
            '_csrf_token' => 'invalid_token',
            'userid' => 0,
            'username' => 'hacker',
            'email' => 'hack@example.com'
        ];
        
        $this->controller->save();
        
        $this->assertStringContainsString('users/edit/', $this->redirectUrl);
        $this->assertEquals('Invalid security token. Please try again.', $_SESSION['users_error']);
        
        $row = $this->db->query("SELECT * FROM `users` WHERE `username` = 'hacker'")->fetch();
        $this->assertEmpty($row);
    }

    public function testLockAndUnlockUser(): void
    {
        // Test lock
        $_GET['_option'] = 3;
        $this->controller->lock();
        
        $row = $this->db->query("SELECT * FROM `users` WHERE `userid` = 3")->fetch();
        $this->assertEquals(0, $row['active']);
        
        // Test unlock
        $_GET['_option'] = 3;
        $this->controller->unlock();
        
        $row = $this->db->query("SELECT * FROM `users` WHERE `userid` = 3")->fetch();
        $this->assertEquals(1, $row['active']);
    }

    public function testDeleteDeactivatesUser(): void
    {
        $_GET['_option'] = 3;
        $this->controller->delete();
        
        $row = $this->db->query("SELECT * FROM `users` WHERE `userid` = 3")->fetch();
        $this->assertEquals(0, $row['active']);
    }

    public function testDeleteProtectsAdmin(): void
    {
        $_GET['_option'] = 1;
        $this->controller->delete();
        
        $row = $this->db->query("SELECT * FROM `users` WHERE `userid` = 1")->fetch();
        $this->assertEquals(1, $row['active']); // Should not be deactivated
    }

    public function testResetPasswordCreatesTokenAndSetsMessage(): void
    {
        $_GET['_option'] = 3;
        $this->controller->resetpassword();
        
        $this->assertNotNull($this->redirectUrl);
        $message = $_SESSION['users_success'] ?? $_SESSION['users_error'] ?? '';
        $this->assertTrue(str_contains($message, 'test@example.com') || str_contains($message, 'Failed to send'), 'Expected success or failure message');
        
        // Verify token was created
        $row = $this->db->query("SELECT * FROM `usertokens` WHERE `userid` = 3 AND `tokentype` = 'password_reset'")->fetch();
        $this->assertNotEmpty($row);
    }

    public function testSessionsList(): void
    {
        // Insert an active session using the real column names (visitorid/time/
        // host_addr/agent) so the query's ORDER BY `time` resolves.
        $this->db->query("INSERT INTO `sessions` (`visitorid`, `userid`, `time`, `host_addr`, `agent`, `url`, `history`, `sid`) VALUES ('abc', 3, 12345, '127.0.0.1', 'test', '', '', '')");

        $_GET['_option'] = 3;
        ob_start();
        $result = $this->controller->sessions();
        $output = ob_get_clean() . $result;

        $this->assertStringContainsString('testuser', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
    }

    public function testTokensList(): void
    {
        // Token management was unified under the Tokens controller; the legacy
        // users/tokens route now redirects to Tokens/userid/{id}.
        $_GET['_option'] = 3;
        $this->controller->tokens();

        $this->assertNotNull($this->redirectUrl);
        $this->assertStringContainsString('Tokens/userid/3', (string) $this->redirectUrl);
    }

    public function testDeactivateToken(): void
    {
        $this->db->query("INSERT INTO `usertokens` (`tokenid`, `userid`, `tokentype`, `token`, `expires`, `created`, `status`) VALUES (9, 3, 'api', '123', 0, 0, 1)");
        
        $_POST = ['userid' => 3, 'tokenid' => 9];
        $this->controller->deactivateToken();
        
        $row = $this->db->query("SELECT * FROM `usertokens` WHERE `tokenid` = 9")->fetch();
        $this->assertEquals(0, $row['status']);
    }

    public function testDeleteToken(): void
    {
        $this->db->query("INSERT INTO `usertokens` (`tokenid`, `userid`, `tokentype`, `token`, `expires`, `created`, `status`) VALUES (9, 3, 'api', '123', 0, 0, 1)");
        
        $_POST = ['userid' => 3, 'tokenid' => 9];
        $this->controller->deleteToken();
        
        $row = $this->db->query("SELECT * FROM `usertokens` WHERE `tokenid` = 9")->fetch();
        $this->assertEquals(2, $row['status']); // Status 2 means deleted
    }

    /**
     * Every store the framework writes about a user is collected for the screen.
     *
     * Nine of them, and no screen joined any: sign-in history, GDPR requests, lockouts,
     * second factors, passkeys, privacy choices, token actions and organizations. Some
     * were visible in the DevPanel — a development tool — and the rest nowhere.
     *
     * Asserted on the keys rather than on rows: the fixture user has no history, and what
     * this pins is that the screen asks for all of it. A panel that is silently absent is
     * indistinguishable from a panel that is empty.
     */
    public function testEveryPerUserStoreIsCollected(): void
    {
        // Arrange
        $controller = new UsersProbe();

        // Act
        $records = $controller->exposeUserRecords(1);

        // Assert
        foreach ([
            'activity', 'activityCount', 'gdpr', 'gdprCount', 'lockouts', 'twofactor',
            'passkeys', 'privacy', 'tokenActions', 'tokenActionCount', 'organizations',
            'emails', 'emailCount',
        ] as $key) {
            $this->assertArrayHasKey($key, $records, $key . ' must be on the user screen');
        }
    }

    /**
     * The token-action panel finds a real action, which is the assertion that was missing.
     *
     * The key-presence test above pins that the screen *asks*. It cannot tell an empty panel
     * from a broken one — and this one was broken from the day it was written: `tokenactions`
     * has no `userid` (the account is on the token) and no `actiondate` (it is `servertime`),
     * so both queries could only fail. The helper catches, the panel renders empty, and an
     * empty panel is exactly what an account with no API tokens is supposed to look like.
     *
     * Found by a diagnostic tool reading the error log, months later.
     */
    public function testTheTokenActionPanelFindsARealAction(): void
    {
        // Arrange
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `tokenactions` ('
            . '`actionid` bigint NOT NULL AUTO_INCREMENT, `tokenid` int NOT NULL, '
            . '`urlid` int NOT NULL DEFAULT 0, `method` varchar(6) NOT NULL DEFAULT \'\', '
            . '`servertime` int NOT NULL DEFAULT 0, `return_status` int NULL, '
            . 'PRIMARY KEY (`actionid`))'
        );

        $this->db->query(
            "INSERT INTO `usertokens` (`userid`, `tokentype`, `token`, `created`, `notes`) "
            . "VALUES (3, 'api', 'tok_" . bin2hex(random_bytes(6)) . "', " . time() . ", 'A token')"
        );
        $tokenId = (int) $this->db->getInsertId();

        $this->db->query(
            'INSERT INTO `tokenactions` (`tokenid`, `urlid`, `method`, `servertime`, `return_status`) '
            . 'VALUES (' . $tokenId . ', 7, \'GET\', ' . (time() - 60) . ', 200), '
            . '(' . $tokenId . ', 8, \'POST\', ' . time() . ', 201)'
        );

        try {
            // Act
            $records = (new UsersProbe())->exposeUserRecords(3);

            // Assert
            $this->assertSame(2, $records['tokenActionCount'],
                'the count is the whole panel when the list is collapsed');
            $this->assertCount(2, $records['tokenActions']);
            $this->assertSame('POST', $records['tokenActions'][0]['method'],
                'newest first, ordered by the column that exists');

            // …and another account's tokens are not this account's actions
            $this->assertSame(0, (new UsersProbe())->exposeUserRecords(999999)['tokenActionCount']);
        } finally {
            $this->db->query('DELETE FROM `tokenactions` WHERE tokenid = ' . $tokenId);
            $this->db->query('DELETE FROM `usertokens` WHERE tokenid = ' . $tokenId);
        }
    }

    /**
     * A missing table is an empty panel, not a broken page.
     *
     * These tables arrive with features: an application without `authserver` has none of
     * the `authserver.*` ones, and one mid-migration has some. Every read is guarded on
     * its own, so the page renders whatever exists.
     */
    public function testAMissingStoreLeavesTheRestOfThePageAlone(): void
    {
        // Arrange — a userid nothing has ever written about
        $controller = new UsersProbe();

        // Act
        $records = $controller->exposeUserRecords(999999);

        // Assert
        $this->assertSame([], $records['activity']);
        $this->assertSame(0, $records['activityCount']);
        $this->assertNull($records['twofactor']);
    }

    /**
     * The mail this account was sent is on its screen, newest first.
     *
     * The mail log is indexed by address, and the user screen is indexed by account, so
     * "did this person ever get the code" was a question an operator could only answer by
     * searching another screen for an address they had to copy by hand. Which is why it
     * was answered with "it must have been sent" instead.
     *
     * The address is passed in rather than read here, because it is the only link there
     * is: `mails` has no `userid`. Which also fixes the shape of the limit — mail sent to
     * an address the account used before it was changed is not this account's mail as far
     * as this table is concerned, and nothing pretends otherwise.
     */
    public function testTheMailSentToAnAccountIsOnItsScreen(): void
    {
        // Arrange
        $address = 'records_' . bin2hex(random_bytes(4)) . '@example.com';
        foreach ([['Older notice', 1000], ['Newest notice', 2000]] as [$subject, $sent]) {
            $this->db->query(
                "INSERT INTO `mails` (`status`, `frommail`, `fromname`, `tomail`, `toname`, "
                . "`subject`, `content`, `date`, `module`, `moduleinfo`, `extrainfo`, "
                . "`path`, `hash`) VALUES (1, 'from@example.com', 'From', "
                . "'" . $address . "', 'To', '" . $subject . "', 'Body', " . $sent
                . ", 'auth', '', '', '', '" . md5($subject) . "')"
            );
        }

        // Act
        $records = (new UsersProbe())->exposeUserRecords(1, $address);

        // Assert
        $this->assertSame(2, $records['emailCount']);
        $this->assertCount(2, $records['emails']);
        $this->assertSame('Newest notice', $records['emails'][0]['subject'],
            'newest first: an operator is looking for the last one, not the first');

        // …and an account whose address nothing was sent to gets an empty panel rather
        // than every mail in the table.
        $none = (new UsersProbe())->exposeUserRecords(1, 'nobody@example.com');
        $this->assertSame([], $none['emails']);
        $this->assertSame(0, $none['emailCount']);
    }

    /**
     * The address is matched whatever its capitalisation.
     *
     * `=` is case-sensitive on PostgreSQL, so a mail addressed to `Name@example.com` while
     * the account says `name@example.com` was simply not on the page — and an empty panel is
     * indistinguishable from an account nothing was ever sent to. MySQL's default collation
     * folds case, which is why this could only ever be seen on one engine and why the test
     * has to assert the *behaviour* rather than the SQL.
     *
     * The address is published with the rows for the same reason: a zero an operator cannot
     * check is the shape of every "the screen is broken" report.
     */
    public function testTheAddressIsMatchedWhateverItsCapitalisation(): void
    {
        // Arrange — stored one way, asked for another
        $address = 'Mixed_' . bin2hex(random_bytes(4)) . '@Example.COM';
        $this->db->query(
            "INSERT INTO `mails` (`status`, `frommail`, `fromname`, `tomail`, `toname`, "
            . "`subject`, `content`, `date`, `module`, `moduleinfo`, `extrainfo`, "
            . "`path`, `hash`) VALUES (1, 'from@example.com', 'From', "
            . "'" . $address . "', 'To', 'Mixed case', 'Body', 3000, 'auth', '', '', '', "
            . "'" . md5($address) . "')"
        );

        // Act
        $records = (new UsersProbe())->exposeUserRecords(1, strtolower($address));

        // Assert
        $this->assertSame(1, $records['emailCount']);
        $this->assertSame('Mixed case', $records['emails'][0]['subject']);
        $this->assertSame(strtolower($address), $records['emailAddress'],
            'the panel has to be able to say which address it looked for');
    }

    // ── The per-user operator actions ───────────────────────────────────────────
    //
    // Thirteen actions were added to this controller so that everything the framework
    // records about a user can be read and changed where the user is, instead of in a
    // development panel or nowhere. Each one is small and each one ends in a redirect,
    // which is exactly the shape that goes untested and then 500s in somebody's hands.
    //
    // Two things are asserted per action: the guard (an id that cannot name a user goes
    // back to the list without touching anything) and the outcome (the operator is
    // returned to the screen they came from, and told what happened).
    //
    // The `authserver.*` tables are not in this fixture, so several of these take their
    // documented "the feature is off" branch. That is deliberate and is the branch most
    // likely to be wrong: it is the one that must not become a stack trace on screen.

    /**
     * Clearing a login lockout returns to the user and says something either way.
     */
    public function testUnlockLoginReportsBackToTheUsersScreen(): void
    {
        // Arrange
        $_GET['_option'] = 3;

        // Act
        $this->controller->unlocklogin();

        // Assert
        $this->assertStringContainsString('users/view/3', (string) $this->redirectUrl);
        $this->assertTrue(
            isset($_SESSION['users_success']) || isset($_SESSION['users_error']),
            'the operator must be told what happened'
        );
    }

    /**
     * An id that cannot name a user goes back to the list.
     *
     * `1` is the guest/system row and `0` is nothing at all; both used to reach the query.
     * Checked on one action per shape rather than on all thirteen, since it is the same
     * guard — the point is that the guard exists and returns without a write.
     */
    public function testAnImpossibleIdIsRefusedBeforeAnythingIsWritten(): void
    {
        foreach (['unlocklogin', 'disabletwofactor', 'signinalerts'] as $action) {
            // Arrange
            $_GET['_option'] = 1;
            $this->redirectUrl = null;

            // Act
            $this->controller->$action();

            // Assert
            $this->assertStringEndsWith('users', rtrim((string) $this->redirectUrl, '/'),
                $action . ' must send an impossible id back to the list');
        }
    }

    /**
     * Turning off a user's second factor is an operator action, and is recorded as one.
     */
    public function testDisableTwoFactorReturnsToTheUser(): void
    {
        // Arrange
        $_GET['_option'] = 3;

        // Act
        $this->controller->disabletwofactor();

        // Assert
        $this->assertStringContainsString('users/view/3', (string) $this->redirectUrl);
    }

    /**
     * Revoking a passkey needs both the user and the credential.
     *
     * Without the credential id the action would delete by user alone — every key on the
     * account — from a link that says "revoke this one".
     */
    public function testRevokingAPasskeyNeedsTheCredentialToo(): void
    {
        // Arrange — no credential
        $_GET['_option'] = 3;

        // Act
        $this->controller->revokepasskey();

        // Assert
        $this->assertStringEndsWith('users', rtrim((string) $this->redirectUrl, '/'));

        // …and with one, it acts and reports
        $_GET['credential'] = 7;
        $this->controller->revokepasskey();
        $this->assertStringContainsString('users/view/3', (string) $this->redirectUrl);
    }

    /**
     * A per-user setting is stored, and JSON text is stored as the structure.
     *
     * A form field cannot say which it meant, so the value is decoded when it parses.
     * Guessing in this direction is harmless — `"1"` and `1` read the same for a flag —
     * and it is what lets an operator repair a structured setting from the screen.
     */
    public function testSavingAndDeletingAPerUserSetting(): void
    {
        // Arrange — `usersettings` is a legacy table no migration creates, so the fixture
        // makes it: without it `setSetting()` returns false and the assertion below would
        // be testing the failure path instead of the JSON-decoding this is about.
        $this->db->query('DROP TABLE IF EXISTS `usersettings`');
        $this->db->query('
            CREATE TABLE `usersettings` (
                `userid` bigint NOT NULL,
                `setting` varchar(190) NOT NULL,
                `value` text,
                `updated_at` int DEFAULT NULL,
                `updated_by` bigint DEFAULT NULL,
                PRIMARY KEY (`userid`, `setting`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $_GET['_option']  = 3;
        $_POST['setting'] = 'dashboard_layout';
        $_POST['value']   = '{"columns":2}';

        // Act
        $this->controller->savesetting();

        // Assert
        $this->assertStringContainsString('users/edit/3', (string) $this->redirectUrl);
        $user = new \Pramnos\User\User();
        $user->load(3);
        $this->assertSame(['columns' => 2], $user->getSetting('dashboard_layout'));

        // …and removing it
        $_GET['setting'] = 'dashboard_layout';
        $this->controller->deletesetting();
        $this->assertNull((new \Pramnos\User\User(3))->getSetting('dashboard_layout'));

        $this->db->query('DROP TABLE IF EXISTS `usersettings`');
    }

    /**
     * A setting with no name is refused rather than stored under an empty key.
     */
    public function testASettingWithNoNameIsRefused(): void
    {
        // Arrange
        $_GET['_option']  = 3;
        $_POST['setting'] = '   ';

        // Act
        $this->controller->savesetting();

        // Assert
        $this->assertSame('A setting needs a name.', $_SESSION['users_error'] ?? null);
    }

    /**
     * Deleting a setting needs a name as well.
     */
    public function testDeletingASettingNeedsAName(): void
    {
        // Arrange
        $_GET['_option'] = 3;

        // Act
        $this->controller->deletesetting();

        // Assert
        $this->assertStringEndsWith('users', rtrim((string) $this->redirectUrl, '/'));
    }

    /**
     * The per-account new-sign-in alert toggle reads the state from the link.
     *
     * Both directions from one action, because a screen that can only turn something on
     * is a screen an operator cannot undo.
     */
    public function testSignInAlertsCanBeTurnedOnAndOff(): void
    {
        foreach (['1', '0'] as $state) {
            // Arrange
            $_GET['_option'] = 3;
            $_GET['enabled'] = $state;
            unset($_SESSION['users_success'], $_SESSION['users_error']);

            // Act
            $this->controller->signinalerts();

            // Assert
            $this->assertStringContainsString('users/view/3', (string) $this->redirectUrl);
            $this->assertTrue(
                isset($_SESSION['users_success']) || isset($_SESSION['users_error'])
            );
        }
    }

    /**
     * The activity screen renders its own table rather than the users one.
     */
    public function testTheActivityScreenRendersItsTable(): void
    {
        // Arrange
        $_GET['_option'] = 3;

        // Act
        ob_start();
        $output = ob_get_clean() . (string) $this->controller->activity();

        // Assert
        $this->assertStringContainsString('dt-useractivity', $output);
    }

    /**
     * The activity endpoint lists the account's own history, escaped.
     *
     * `details` is JSON as the action recorded it, and is shown as text rather than parsed
     * into columns: what is in there differs per action, and a screen that assumes a shape
     * hides the actions that do not have it. Escaped because it is written by whatever
     * recorded the entry.
     */
    public function testTheActivityEndpointListsTheAccountsHistory(): void
    {
        // Arrange — one entry, with something that must not reach the page as markup
        $this->db->query(
            "INSERT INTO `authserver_user_activity_log`"
            . " (`userid`, `action`, `details`, `ip_address`, `created_at`)"
            . " VALUES (3, '<b>login</b>', '{\"by\":2}', '10.0.0.1', NOW())"
        );
        $_GET['_option'] = 3;

        // Act
        ob_start();
        $response = $this->controller->activitydata();
        $body = ob_get_clean() . ($response ? $response->getBody() : '');

        // Assert
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $rows = $decoded['data'] ?? $decoded['aaData'] ?? null;
        $this->assertIsArray($rows, 'the endpoint must answer in a shape the table reads');
        $this->assertCount(1, $rows);
        // Asserted on the decoded row rather than on the body: JSON escapes the slash in
        // a closing tag, so a substring check on the body passes or fails for the wrong
        // reason.
        $this->assertSame('&lt;b&gt;login&lt;/b&gt;', $rows[0][1]);
        $this->assertStringContainsString('&quot;by&quot;:2', $rows[0][3]);
    }

    /**
     * With no table it answers an empty list rather than a 500.
     *
     * `authserver.user_activity_log` arrives with the `authserver` feature. A datatable
     * handles an empty list; it cannot handle an error, and shows a permanent "Loading…"
     * instead — which reads as a hung page rather than as a feature that is off.
     */
    public function testTheActivityEndpointAnswersAnEmptyListWithNoTable(): void
    {
        // Arrange — and flush the SQL cache, or the listing from the test above is
        // served from it and the missing table is never reached
        $this->db->query('DROP TABLE IF EXISTS `authserver_user_activity_log`');
        $this->db->cacheflush();
        $_GET['_option'] = 3;

        try {
            // Act
            ob_start();
            $response = $this->controller->activitydata();
            $body = ob_get_clean() . ($response ? $response->getBody() : '');

            // Assert — whichever shape it answers in, it answers, and it is empty
            $decoded = json_decode($body, true);
            $this->assertIsArray($decoded);
            $this->assertSame([], $decoded['data'] ?? $decoded['aaData'] ?? null);
        } finally {
            self::createAuthServerTables($this->db);
        }
    }

    /**
     * And it refuses an id that cannot name a user, with an empty list rather than an error.
     */
    public function testTheActivityEndpointRefusesAnImpossibleIdAsAnEmptyList(): void
    {
        // Arrange
        $_GET['_option'] = 0;

        // Act
        ob_start();
        $response = $this->controller->activitydata();
        $body = ob_get_clean() . ($response ? $response->getBody() : '');

        // Assert
        $this->assertSame(0, json_decode($body, true)['recordsTotal'] ?? null);
    }

    /**
     * The message form renders for a real account and refuses a placeholder one.
     */
    public function testTheMessageFormRendersForARealAccount(): void
    {
        // Arrange
        $_GET['_option'] = 3;

        // Act
        ob_start();
        $output = ob_get_clean() . (string) $this->controller->notify();

        // Assert — the account's own address is on the form
        $this->assertStringContainsString('@', $output);

        // …and an id that names nobody goes back to the list
        $_GET['_option'] = 1;
        $this->controller->notify();
        $this->assertStringEndsWith('users', rtrim((string) $this->redirectUrl, '/'));
    }

    /**
     * A message with no subject or no body is refused before the mailer is asked.
     *
     * An empty message is worse than no message: the account gets mail with nothing in
     * it, and the operator is told it was sent.
     */
    public function testAnEmptyMessageIsRefusedBeforeTheMailer(): void
    {
        // Arrange
        $_GET['_option'] = 3;
        $_POST['subject'] = 'Something';
        $_POST['message'] = '   ';

        // Act
        $this->controller->sendnotification();

        // Assert
        $this->assertSame('A message needs a subject and a body.', $_SESSION['users_error'] ?? null);
        $this->assertStringContainsString('users/notify/3', (string) $this->redirectUrl);
    }

    /**
     * An account with no usable address is not handed to the mailer either.
     */
    public function testAnAccountWithNoUsableAddressIsNotMailed(): void
    {
        // Arrange — a row of its own rather than an edit to a cached one: the per-process
        // user cache would hand the controller the address the edit replaced, and the test
        // would be asserting the mailer path while looking like it asserts this one.
        $this->db->query(
            "INSERT INTO `users` (`username`, `email`, `usertype`, `active`, `validated`)"
            . " VALUES ('noaddress', 'not-an-address', 0, 1, 1)"
        );
        $id = (int) $this->db->getInsertId();

        $_GET['_option']  = $id;
        $_POST['subject'] = 'Subject';
        $_POST['message'] = 'Body';

        // Act
        $this->controller->sendnotification();

        // Assert
        $this->assertSame(
            'This account has no usable email address.',
            $_SESSION['users_error'] ?? null
        );
        $this->assertStringContainsString('users/view/' . $id, (string) $this->redirectUrl);
    }

    /**
     * A well-formed message reaches the mailer, and the operator is told the outcome.
     *
     * Both outcomes are the same code path and both are reported: whether the mailer
     * accepts the message depends on the installation's mail settings, and an operator who
     * is told "sent" when nothing was sent has no way to find out otherwise. The activity
     * log records the attempt either way, with `sent` in it.
     */
    public function testAWellFormedMessageIsHandedToTheMailerAndReported(): void
    {
        // Arrange
        $_GET['_option']  = 3;
        $_POST['subject'] = 'About your account';
        $_POST['message'] = "Something an operator typed.\nWith two lines.";

        // Act
        $this->controller->sendnotification();

        // Assert — one of the two outcomes, and the operator is returned to the user
        $this->assertTrue(
            isset($_SESSION['users_success']) || isset($_SESSION['users_error']),
            'the operator must be told whether the message went out'
        );
        $this->assertStringContainsString('users/view/3', (string) $this->redirectUrl);
    }

    /**
     * A permission needs an object type and an action, and returns to the edit screen.
     */
    public function testGrantingAPermissionNeedsAnObjectTypeAndAnAction(): void
    {
        // Arrange
        $_GET['_option'] = 3;

        // Act
        $this->controller->grantpermission();

        // Assert
        $this->assertSame(
            'A permission needs an object type and an action.',
            $_SESSION['users_error'] ?? null
        );
        $this->assertStringContainsString('users/edit/3', (string) $this->redirectUrl);

        // …and with both, it reports back rather than throwing
        $_POST['object_type'] = 'invoice';
        $_POST['action']      = 'read';
        $_POST['grant_type']  = 'deny';
        $this->controller->grantpermission();
        $this->assertStringContainsString('users/edit/3', (string) $this->redirectUrl);
    }

    /**
     * Revoking one needs the permission's own id.
     *
     * Without it the delete would match on the subject alone — every permission the
     * account has — from a link that says "revoke this one".
     */
    public function testRevokingAPermissionNeedsItsId(): void
    {
        // Arrange
        $_GET['_option'] = 3;

        // Act
        $this->controller->revokepermission();

        // Assert
        $this->assertStringEndsWith('users', rtrim((string) $this->redirectUrl, '/'));

        // …and with one it acts and returns to the edit screen
        $_GET['permission'] = 5;
        $this->controller->revokepermission();
        $this->assertStringContainsString('users/edit/3', (string) $this->redirectUrl);
    }

    /**
     * The usertype reference screen renders every band the registry declares.
     *
     * The screen exists so that "what is 85 here" has one answer, so the test is that the
     * bands on it come from `UserTypes` — not that any particular band exists.
     */
    public function testTheUsertypeReferenceScreenRendersTheRegistry(): void
    {
        // Act
        ob_start();
        $output = ob_get_clean() . (string) $this->controller->types();

        // Assert
        foreach (\Pramnos\User\UserTypes::labels() as $label) {
            $this->assertStringContainsString($label, $output);
        }

        // …and the capabilities each band resolves to are on it. Asserted because the
        // first version of this screen built that column with
        // `$view->resolved[$floor] = ...`, an indirect write to an overloaded property
        // that PHP discards: every band rendered with an empty column, and the only
        // complaint was a notice nobody sees on a rendered page.
        foreach (\Pramnos\User\UserTypes::capabilities(90) as $capability) {
            $this->assertStringContainsString($capability, $output);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The Send screen
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The screen only offers channels this account can actually receive.
     *
     * An operator who presses Send and is told "sent" is entitled to believe it. A channel
     * that silently delivers nothing — no address, no key pair, no subscribed browser — is the
     * exact failure this screen exists to prevent, and it is invisible from the outside:
     * nothing errors, the message simply never arrives.
     */
    public function testTheSendScreenOnlyOffersChannelsThatCanReachTheAccount(): void
    {
        // Arrange — an ordinary account with an address, and no push subscriptions anywhere
        $user           = new \Pramnos\User\User();
        $user->userid   = 2;
        $user->email    = 'someone@example.com';

        // Act
        $channels = (new UsersProbe())->exposeSendChannels($user);

        // Assert
        $this->assertTrue($channels['mail']['available']);
        $this->assertTrue($channels['database']['available'],
            'the in-app record is the one channel that always works');
        $this->assertFalse($channels['push']['available'],
            'nothing has subscribed, so push would deliver nowhere');
        $this->assertNotSame('', $channels['push']['reason'],
            'and a disabled option without a reason is a support ticket');
    }

    /**
     * An account with no usable address cannot be emailed, and the screen says so.
     */
    public function testAnAccountWithoutAnAddressCannotBeEmailed(): void
    {
        // Arrange
        $user         = new \Pramnos\User\User();
        $user->userid = 2;
        $user->email  = 'not an address';

        // Act
        $channels = (new UsersProbe())->exposeSendChannels($user);

        // Assert
        $this->assertFalse($channels['mail']['available']);
        $this->assertStringContainsString('email address', $channels['mail']['reason']);
    }

    /**
     * The wrapper list is read from the directories, so the bundled default is always there.
     *
     * A configured list would be wrong the first time somebody dropped a file into
     * `app/emails`, which is the only way wrappers are ever added.
     */
    public function testTheWrapperListComesFromTheDirectories(): void
    {
        // Act
        $templates = (new UsersProbe())->exposeMailTemplates();

        // Assert
        $this->assertContains('default', $templates,
            'the bundled wrapper resolves on an installation that has published nothing');
        $this->assertSame(array_values(array_unique($templates)), $templates,
            'the same name in two directories is one wrapper, not two');
    }

    /**
     * `all` is always a list, whatever the opt-out records say.
     */
    public function testAllIsAlwaysAList(): void
    {
        // Act
        $lists = (new UsersProbe())->exposeMailLists();

        // Assert
        $this->assertContains(\Pramnos\Email\Unsubscribe::LIST_ALL, $lists);
    }

    /**
     * The composed message carries the channels, the body and nothing that was not asked for.
     *
     * The transactional default is the important half: a form submitted with the options
     * untouched must not produce a message with a wrapper choice, a list, tracking or an
     * action. Otherwise "we locked your account" acquires an unsubscribe link.
     */
    public function testAnUntouchedFormComposesATransactionalMessage(): void
    {
        // Arrange
        $this->arrangePost([]);

        // Act
        $message = (new UsersProbe())->exposeCompose('Locked', "First line\nSecond line", ['mail']);

        // Assert
        $this->assertSame(['mail'], $message->via(null));
        $this->assertSame('Locked', $message->toMail(null)['subject']);
        $this->assertStringContainsString('<br', $message->toMail(null)['body'],
            'line breaks are kept');
        $this->assertSame('', $message->unsubscribeList());
        $this->assertFalse($message->trackingRequested());
        $this->assertSame([], $message->mailStructuredData());
        $this->assertSame('', $message->mailPreheader());
        $this->assertNull($message->mailTemplate());
    }

    /**
     * The body is escaped before the line breaks are added.
     *
     * The other order turns `<b>` typed by an operator into working markup in somebody's mail
     * client — which is the whole reason this field is text rather than HTML.
     */
    public function testTheBodyIsEscapedNotRendered(): void
    {
        // Arrange
        $this->arrangePost([]);

        // Act
        $body = (new UsersProbe())
            ->exposeCompose('S', '<script>alert(1)</script>', ['mail'])
            ->toMail(null)['body'];

        // Assert
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /**
     * Every option the form offers reaches the message.
     */
    public function testTheFormOptionsReachTheMessage(): void
    {
        // Arrange
        $this->arrangePost([
            'link'        => 'https://example.com/account',
            'template'    => 'receipt',
            'list'        => 'digest',
            'tracking'    => '1',
            'preheader'   => 'Your code is 481920',
            'action_type' => 'confirm',
            'action_name' => 'Confirm it',
            'action_url'  => 'https://example.com/confirm/abc',
        ]);

        // Act
        $message = (new UsersProbe())->exposeCompose('S', 'B', ['mail', 'push']);

        // Assert
        $this->assertSame('https://example.com/account', $message->toPush(null)['url']);
        $this->assertSame('receipt', $message->mailTemplate());
        $this->assertSame('digest', $message->unsubscribeList());
        $this->assertSame('Your code is 481920', $message->mailPreheader());
        $this->assertTrue($message->trackingRequested());

        $blocks = $message->mailStructuredData();
        $this->assertCount(1, $blocks);
        $this->assertSame('ConfirmAction', $blocks[0]['potentialAction']['@type'] ?? null);
    }

    /**
     * "No wrapper" is a real answer and survives as one.
     *
     * The default is a sentinel rather than an empty string precisely so that these two can be
     * told apart: an installation that wraps everything otherwise has no way to send one bare
     * body.
     */
    public function testNoWrapperIsDistinguishableFromTheDefault(): void
    {
        // Arrange
        $this->arrangePost(['template' => '']);

        // Act & Assert
        $this->assertSame('', (new UsersProbe())->exposeCompose('S', 'B', ['mail'])->mailTemplate());

        $this->arrangePost(['template' => '__default__']);
        $this->assertNull((new UsersProbe())->exposeCompose('S', 'B', ['mail'])->mailTemplate());
    }

    /**
     * A link or action URL that is not a URL is dropped rather than sent.
     *
     * A push notification whose `url` is `javascript:…` or a stray word opens nothing, and a
     * Gmail action pointing at rubbish is a button that fails for every reader at once.
     */
    public function testRubbishUrlsAreDropped(): void
    {
        // Arrange
        $this->arrangePost([
            'link'        => 'not a url',
            'action_type' => 'view',
            'action_name' => 'Open',
            'action_url'  => 'javascript:alert(1)',
        ]);

        // Act
        $message = (new UsersProbe())->exposeCompose('S', 'B', ['mail', 'push']);

        // Assert
        $this->assertArrayNotHasKey('url', $message->toPush(null));
        $this->assertSame([], $message->mailStructuredData());
    }

    /**
     * An action needs all three of its parts, or it is not an action.
     *
     * A type with no button text renders as an unlabelled control; a name with no URL is a
     * button that goes nowhere. Half an action is worse than none.
     */
    public function testAnIncompleteActionIsNotAnAction(): void
    {
        // Arrange
        $this->arrangePost(['action_type' => 'view', 'action_url' => 'https://example.com']);

        // Act & Assert
        $this->assertSame([], (new UsersProbe())->exposeCompose('S', 'B', ['mail'])->mailStructuredData());
    }

    /**
     * A form that posts no channels at all still sends the mail.
     *
     * The previous version of this screen had one channel and no `channels` field, and an
     * application that published that view still posts nothing. Read as "chose nothing", every
     * such form would stop working the day the framework was updated — with an error about
     * channels nobody had ever seen a field for.
     */
    public function testAFormWithNoChannelFieldStillSendsTheMail(): void
    {
        // Arrange — exactly what the old view posts
        $_GET['_option']  = 3;
        $_POST['subject'] = 'About your account';
        $_POST['message'] = 'Something an operator typed.';
        unset($_POST['channels']);

        // Act
        $this->controller->sendnotification();

        // Assert
        $this->assertArrayNotHasKey('users_error', $_SESSION,
            'the old form must not start failing');
        $this->assertStringContainsString('mail', (string) ($_SESSION['users_success'] ?? ''));
    }

    /**
     * Ticking a channel this account cannot receive reports that channel's own reason.
     *
     * «Could not send» leaves an operator with nothing to do. "No browser has subscribed" is
     * the user's problem and "this installation has no key pair" is the installation's — and
     * they need different people.
     */
    public function testAnUnreachableChannelReportsItsOwnReason(): void
    {
        // Arrange
        $_GET['_option']   = 3;
        $_POST['subject']  = 'Subject';
        $_POST['message']  = 'Body';
        $_POST['channels'] = ['push'];

        // Act
        $this->controller->sendnotification();

        // Assert
        $error = (string) ($_SESSION['users_error'] ?? '');
        $this->assertNotSame('', $error);
        $this->assertMatchesRegularExpression('~subscrib|key pair~i', $error,
            'the message has to say which of the two problems it is');
    }

    /**
     * Arrange a POST body for the composer.
     *
     * @param array<string, mixed> $fields
     */
    private function arrangePost(array $fields): void
    {
        $_POST    = $fields;
        $_REQUEST = $fields;
        Request::resetInstance();
    }
}

/**
 * Exposes the protected collector so it can be asserted without a rendered page.
 */
class UsersProbe extends \Pramnos\Application\Controllers\UsersController
{
    public function __construct()
    {
        // Deliberately not parent::__construct(): that registers actions against an
        // application this test does not have.
    }

    /** @return array<string, mixed> */
    public function exposeUserRecords(int $userId, string $email = ''): array
    {
        return $this->userRecords($userId, $email);
    }

    /** @return array<string, array{available: bool, reason: string}> */
    public function exposeSendChannels(\Pramnos\User\User $user): array
    {
        return $this->sendChannels($user);
    }

    /** @return list<string> */
    public function exposeMailTemplates(): array
    {
        return $this->mailTemplates();
    }

    /** @return list<string> */
    public function exposeMailLists(): array
    {
        return $this->mailLists();
    }

    /** @param list<string> $channels */
    public function exposeCompose(string $subject, string $message, array $channels): \Pramnos\Notification\Message
    {
        return $this->composeMessage($subject, $message, $channels);
    }
}