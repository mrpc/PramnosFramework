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

        $db = Factory::getDatabase();
        $db->cacheflush();

        $this->db = Factory::getDatabase();
        $this->db->cacheflush(); // Flush any cached queries from previous tests
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Recreate all three tables fresh so each test gets a known-good schema.
        // `users` needs all columns that User::_save() inserts (dateformat, sex,
        // birthdate, modified, etc.) — a minimal schema would cause silent INSERT
        // failures and load the wrong user on retry.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `users`');
        $this->db->query('DROP TABLE IF EXISTS `sessions`');
        $this->db->query('DROP TABLE IF EXISTS `usertokens`');

        $this->db->query('
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

        // sessions schema for UsersController (userid, date, ip, useragent columns)
        $this->db->query('
            CREATE TABLE `sessions` (
                `sessionid` varchar(255) NOT NULL,
                `userid` bigint NOT NULL,
                `date` bigint NOT NULL,
                `ip` varchar(45) NOT NULL,
                `useragent` text NOT NULL,
                PRIMARY KEY (`sessionid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        // usertokens with full schema including scope and removedate so User::_save()
        // and addToken() do not fail on missing columns.
        $this->db->query('
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
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->cacheflush();

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

        // Drop all three test tables so subsequent tests start with a known-good schema.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `users`');
        $this->db->query('DROP TABLE IF EXISTS `sessions`');
        $this->db->query('DROP TABLE IF EXISTS `usertokens`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        
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
        $this->db->query("INSERT INTO `sessions` (`sessionid`, `userid`, `date`, `ip`, `useragent`) VALUES ('abc', 3, 12345, '127.0.0.1', 'test')");
        
        $_GET['_option'] = 3;
        ob_start();
        $result = $this->controller->sessions();
        $output = ob_get_clean() . $result;
        
        $this->assertStringContainsString('testuser', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
    }

    public function testTokensList(): void
    {
        $this->db->query("INSERT INTO `usertokens` (`userid`, `tokentype`, `token`, `expires`, `created`) VALUES (3, 'api', '123', 0, 0)");
        
        $_GET['_option'] = 3;
        ob_start();
        $result = $this->controller->tokens();
        $output = ob_get_clean() . $result;
        
        $this->assertStringContainsString('testuser', $output);
        $this->assertStringContainsString('api', $output);
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
