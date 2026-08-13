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
    private const TABLES = ['usertokens', 'sessions', 'users'];

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
}
