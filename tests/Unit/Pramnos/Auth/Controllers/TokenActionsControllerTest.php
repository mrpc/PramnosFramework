<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\TokenActionsController;
use Pramnos\User\User;

class TestableTokenActionsController extends TokenActionsController
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
            public mixed $actions;
            public mixed $action;
            public mixed $total;
            public mixed $page;
            
            public function display($view = '') {
                return 'mock html view for ' . $view;
            }
        };
        return $view;
    }
}

class TokenActionsControllerTest extends TestCase
{
    private TestableTokenActionsController $controller;

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

        // DROP + CREATE to avoid inheriting a schema from a prior test suite
        // (e.g. OauthTest creates usertokens with applicationid NOT NULL, which
        // would break our minimal INSERT that omits applicationid).
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#users`");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#usertokens`");
        $db->query("CREATE TABLE `#PREFIX#users` (
            `userid` bigint NOT NULL AUTO_INCREMENT,
            `username` varchar(255) NOT NULL,
            `email` varchar(255) NOT NULL,
            PRIMARY KEY (`userid`)
        )");
        $db->query("CREATE TABLE `#PREFIX#usertokens` (
            `tokenid` int(11) NOT NULL AUTO_INCREMENT,
            `userid` bigint NOT NULL,
            `tokentype` varchar(50) NOT NULL DEFAULT 'oauth',
            `token` varchar(255) NOT NULL DEFAULT 'testtoken',
            PRIMARY KEY (`tokenid`)
        )");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#tokenactions`");

        $db->query("CREATE TABLE `#PREFIX#tokenactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tokenid` int(11) NOT NULL,
            `urlid` varchar(255) NOT NULL,
            `method` varchar(10) NOT NULL,
            `return_status` int(11) NOT NULL,
            `execution_time_ms` float NOT NULL,
            `servertime` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        )");

        $db->query("INSERT INTO `#PREFIX#users` (`userid`, `username`, `email`) VALUES (1, 'testuser', 'test@test.com')");
        $db->query("INSERT INTO `#PREFIX#usertokens` (`tokenid`, `userid`, `tokentype`, `token`) VALUES (10, 1, 'oauth', 'testtoken')");
        $db->query("INSERT INTO `#PREFIX#tokenactions` (`id`, `tokenid`, `urlid`, `method`, `return_status`, `execution_time_ms`, `servertime`) VALUES (100, 10, '/api/test', 'GET', 200, 15.5, " . time() . ")");

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestableTokenActionsController($app);

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

        // Drop the test tables so subsequent tests get a clean schema via their
        // own setUp (MediaObjectTest and others need columns we don't define here).
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#tokenactions`");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#usertokens`");
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
        $user->usertype = $usertype;
        
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $user->language = $lang ? $lang->currentlang() : 'en';

        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = $user;
        }
    }

    public function testRequireMinUserTypeRedirectsWhenBelowRequired(): void
    {
        $this->setMockUser(79); // Required is 80

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testRequireMinUserTypeRedirectsWhenNullUser(): void
    {
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_SESSION = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testDisplayShowsTokenActions(): void
    {
        $this->setMockUser(80);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('Token Actions', $doc->title);
    }

    public function testShowDisplaysActionDetails(): void
    {
        $this->setMockUser(80);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->show(100);
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('Token Action #100', $doc->title);
    }

    public function testShowRedirectsWhenInvalidId(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->show(0);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=invalid_id', $this->controller->redirectedTo[0]);
        }
    }

    public function testShowRedirectsWhenNotFound(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->show(999);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=not_found', $this->controller->redirectedTo[0]);
        }
    }

    public function testStatsReturnsJson(): void
    {
        $this->setMockUser(80);

        ob_start();
        $this->controller->stats();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('summary', $json);
        $this->assertArrayHasKey('top_slow', $json);
        $this->assertArrayHasKey('top_called', $json);
    }

    public function testExportReturnsCsv(): void
    {
        $this->setMockUser(80);

        ob_start();
        $this->controller->export();
        $output = ob_get_clean();

        $this->assertStringContainsString('id,username,tokenid,urlid,method,return_status,execution_time_ms,servertime', $output);
        $this->assertStringContainsString('100,testuser,10,/api/test,GET,200', $output);
    }
}
