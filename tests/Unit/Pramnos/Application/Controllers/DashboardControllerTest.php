<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\DashboardController;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

class TestableDashboardController extends DashboardController
{
    public array $redirectedTo = [];
    
    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = $url;
        if ($quit) {
            throw new \RuntimeException('redirect_quit');
        }
    }
}

#[CoversClass(DashboardController::class)]
class DashboardControllerTest extends TestCase
{
    private TestableDashboardController $controller;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('ROOT')) {
            define('ROOT', dirname(__DIR__, 5));
        }

        Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $app = Application::getInstance();

        $singleton = &Factory::getDatabase();
        $singleton = null;

        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Create sessions table for ActiveUsersService tests
        $db->query("CREATE TABLE IF NOT EXISTS `#PREFIX#sessions` (
            `sessionid` varchar(255) NOT NULL,
            `time` int(11) NOT NULL,
            `guest` tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (`sessionid`)
        )");

        // Initialize the Application instance if missing
        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            // Force it to skip further initialization that might redirect or fail
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestableDashboardController($app);

        // Reset globals
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
        \Pramnos\Cache\Cache::getInstance()->clear();
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
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

    public function testDisplayShowsDashboardForAdmin(): void
    {
        $this->setMockUser(80);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        // Setup a dummy theme object that has the needed method
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        $user = \Pramnos\User\User::getCurrentUser();
        
        // Some views return the HTML, some echo it. Handle both.
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
        $this->assertSame('Admin Dashboard', $doc->title);
    }

    public function testActiveUsersReturnsJson(): void
    {
        $this->setMockUser(100);

        ob_start();
        $this->controller->activeusers();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('now', $json);
        $this->assertArrayHasKey('last_1h', $json);
    }

    public function testApiStatsReturnsJson(): void
    {
        $this->setMockUser(80);

        $_GET['window'] = \Pramnos\Application\Statistics\ApiPerformanceService::WINDOW_1H;

        ob_start();
        $this->controller->apistats();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('total_requests', $json);
        $this->assertArrayHasKey('avg_execution_ms', $json);
    }

    public function testDbStatsReturnsJson(): void
    {
        $this->setMockUser(80);

        ob_start();
        $this->controller->dbstats();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
    }

    public function testDatabaseDisplaysDatabaseDetails(): void
    {
        $this->setMockUser(80);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
            public function getThemeDir() { return ''; }
        };

        ob_start();
        try {
            $this->controller->database();
        } catch (\Throwable $e) {
            // View might fail without templates but we just want to hit the controller logic
        }
        $output = ob_get_clean();

        $this->assertSame('Database Details', $doc->title);
    }

    public function testCacheDisplaysCacheDetails(): void
    {
        $this->setMockUser(80);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
            public function getThemeDir() { return ''; }
        };

        ob_start();
        try {
            $this->controller->cache();
        } catch (\Throwable $e) {
            // ignore view error
        }
        $output = ob_get_clean();

        $this->assertSame('Cache Details', $doc->title);
    }

    public function testCacheItemReturnsJson(): void
    {
        $this->setMockUser(80);

        $cache = \Pramnos\Cache\Cache::getInstance();
        $cache->save('test_key', 'test_data', 'test_namespace');

        $_GET['key'] = 'test_namespace::test_key';

        ob_start();
        $this->controller->cacheitem();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertArrayHasKey('success', $json);
        $this->assertFalse($json['success']);
    }

    public function testCacheItemFailsWhenNoKey(): void
    {
        $this->setMockUser(80);

        ob_start();
        $this->controller->cacheitem();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertFalse($json['success']);
        $this->assertSame('No key provided', $json['error']);
    }

    public function testCacheItemFailsWhenNotFound(): void
    {
        $this->setMockUser(80);

        $_GET['key'] = 'non_existent_key';

        ob_start();
        $this->controller->cacheitem();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertFalse($json['success']);
        $this->assertSame('Item not found or expired', $json['error']);
    }

    public function testClearCacheReturnsJsonOnPost(): void
    {
        $this->setMockUser(80);

        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        $this->controller->clearcache();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertTrue($json['success']);
    }

    public function testClearCacheFailsOnGet(): void
    {
        $this->setMockUser(80);

        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $this->controller->clearcache();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertFalse($json['success']);
        $this->assertSame('Method not allowed', $json['error']);
    }
}
