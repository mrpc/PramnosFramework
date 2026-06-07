<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\TwoFactorAuth;
use Pramnos\User\User;

class TestableTwoFactorAuth extends TwoFactorAuth
{
    public array $redirectedTo = [];

    public function redirect($url = null, $quit = true, $code = '302')
    {
        if ($url === null) {
            $url = 'default_redirect';
        }
        $this->redirectedTo[] = $url;
        throw new \RuntimeException('redirect_quit');
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new class {
            public mixed $user;
            public mixed $status;
            public mixed $setupData;
            public mixed $remainingCodes;
            public mixed $newBackupCodes;
            public mixed $setupComplete;
            public mixed $success;
            public mixed $error;
            
            public function display($view = '') {
                return 'mock html view for twofactor ' . $view;
            }
        };
        return $view;
    }
}

class TwoFactorAuthTest extends TestCase
{
    private TestableTwoFactorAuth $controller;

    protected function setUp(): void
    {
        \Pramnos\Application\Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $singleton = null;

        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("CREATE SCHEMA IF NOT EXISTS `authserver`");

        $db->query("DROP TABLE IF EXISTS `#PREFIX#users`");
        $db->query("CREATE TABLE `#PREFIX#users` (
            `userid` bigint NOT NULL AUTO_INCREMENT,
            `username` varchar(255) NOT NULL,
            `email` varchar(255) NOT NULL,
            PRIMARY KEY (`userid`)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS `authserver_user_twofactor` (
            `userid` int(11) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT '0',
            `secret` varchar(255) DEFAULT NULL,
            `backup_codes` text DEFAULT NULL,
            `last_used` int(11) NOT NULL DEFAULT '0',
            `setup_completed_at` int(11) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`userid`)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS `authserver_twofactor_setup` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `userid` int(11) NOT NULL,
            `temp_secret` varchar(255) NOT NULL,
            `used` tinyint(1) NOT NULL DEFAULT '0',
            `expires_at` int(11) NOT NULL,
            `created_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS `authserver_twofactor_attempts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `userid` int(11) NOT NULL,
            `success` tinyint(1) NOT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `code_used` varchar(8) NOT NULL,
            `user_agent` text,
            `attempt_time` datetime NOT NULL,
            PRIMARY KEY (`id`)
        )");

        $db->query("TRUNCATE TABLE `#PREFIX#users`");
        $db->query("TRUNCATE TABLE `authserver_user_twofactor`");
        $db->query("TRUNCATE TABLE `authserver_twofactor_setup`");
        $db->query("TRUNCATE TABLE `authserver_twofactor_attempts`");

        $db->query("INSERT INTO `#PREFIX#users` (`userid`, `username`, `email`) VALUES (2, 'testuser', 'test@test.com')");

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestableTwoFactorAuth($app);

        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        if (isset($doc->themeObject) && $doc->themeObject instanceof \stdClass) {
            unset($doc->themeObject);
        }
        $_SESSION = [];
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_GET = [];
        $_POST = [];
        $_SERVER = [];

        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#users`");
        $db->query("SET FOREIGN_KEY_CHECKS=1");
    }

    private function setMockUser(int $usertype): void
    {
        $_SESSION['logged'] = true;
        $_SESSION['login'] = true;
        $_SESSION['userid'] = 2;
        $_SESSION['uid'] = 2;
        $_SESSION['usertype'] = $usertype;
        $_SESSION['sessionid'] = 'dummy_session_id';

        $user = new User(0);
        $user->userid = 2;
        $user->email = 'test@example.com';
        $user->usertype = $usertype;
        
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $user->language = $lang ? $lang->currentlang() : 'en';

        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = $user;
        }
    }

    public function testDisplayShowsStatus(): void
    {
        $this->setMockUser(80);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        $output = $this->controller->display();
        if (empty($output)) {
            $output = ob_get_clean();
        } else {
            ob_end_clean();
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('Two-Factor Authentication', $doc->title);
    }

    public function testSetupRedirectsWhenAlreadyEnabled(): void
    {
        $this->setMockUser(80);
        
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->query("INSERT INTO `authserver_user_twofactor` (`userid`, `enabled`, `secret`, `created_at`, `updated_at`) VALUES (2, 1, 'secret', 0, 0)");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->setup();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=already_enabled', $this->controller->redirectedTo[0]);
        }
    }

    public function testSetupDisplaysQrCode(): void
    {
        $this->setMockUser(80);
        
        ob_start();
        $output = $this->controller->setup();
        if (empty($output)) {
            $output = ob_get_clean();
        } else {
            ob_end_clean();
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $this->assertSame('2FA Setup', $doc->title);
    }

    public function testSetupVerifiesCodeAndRedirects(): void
    {
        $this->setMockUser(80);
        $_POST['verify_code'] = '000000'; // invalid code
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->setup();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=invalid_code', $this->controller->redirectedTo[0]);
        }
    }

    public function testStatusReturnsJson(): void
    {
        $this->setMockUser(80);
        
        ob_start();
        $this->controller->status();
        $output = ob_get_clean();
        
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('enabled', $json);
        $this->assertArrayHasKey('setup', $json);
    }

    public function testTestReturnsJson(): void
    {
        ob_start();
        $this->controller->test();
        $output = ob_get_clean();
        
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('secret', $json);
        $this->assertArrayHasKey('code', $json);
    }
}
