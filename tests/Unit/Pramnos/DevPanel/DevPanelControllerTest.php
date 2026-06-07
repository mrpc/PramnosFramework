<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\DevPanel;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\User\User;

class TestableDevPanelController extends DevPanelController
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

    protected function terminate(): void
    {
        // Prevent exit during tests
    }
}

class DevPanelControllerTest extends TestCase
{
    private TestableDevPanelController $controller;

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

        if (!defined('DEVELOPMENT')) {
            define('DEVELOPMENT', true);
        }
        
        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        
        $this->controller = new TestableDevPanelController($app);

        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
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

    public function testDisplayShowsOverview(): void
    {
        $this->setMockUser(95);
        ob_start();
        $this->controller->display();
        $output = ob_get_clean();

        // The layout wrapper uses id="devpanel", not a class.
        $this->assertStringContainsString('<div id="devpanel"', $output);
        // "Overview" appears as the active nav tab and in the page title.
        $this->assertStringContainsString('Overview', $output);
    }

    public function testDbShowsDatabasePanel(): void
    {
        $this->setMockUser(95);
        ob_start();
        $this->controller->db();
        $output = ob_get_clean();

        $this->assertStringContainsString('<div id="devpanel"', $output);
        // "Database" appears in the nav tab; the panel content also references it.
        $this->assertStringContainsString('DevPanel', $output);
        $this->assertStringContainsString('Database', $output);
    }

    public function testCacheShowsCachePanel(): void
    {
        $this->setMockUser(95);
        ob_start();
        $this->controller->cache();
        $output = ob_get_clean();

        $this->assertStringContainsString('<div id="devpanel"', $output);
        // The page title always contains the active tab name.
        $this->assertStringContainsString('DevPanel', $output);
        $this->assertStringContainsString('Cache', $output);
    }

    public function testUsersShowsUsersPanel(): void
    {
        $this->setMockUser(95);
        ob_start();
        $this->controller->users();
        $output = ob_get_clean();

        $this->assertStringContainsString('<div id="devpanel"', $output);
        // The users panel renders the active sessions table or an error alert.
        $this->assertStringContainsString('DevPanel', $output);
        $this->assertStringContainsString('Users', $output);
    }

    public function testPerformanceShowsPerformancePanel(): void
    {
        $this->setMockUser(95);
        ob_start();
        $this->controller->performance();
        $output = ob_get_clean();

        $this->assertStringContainsString('<div id="devpanel"', $output);
        $this->assertStringContainsString('DevPanel', $output);
        $this->assertStringContainsString('Performance', $output);
    }

    public function testGitShowsGitPanel(): void
    {
        $this->setMockUser(95);
        ob_start();
        $this->controller->git();
        $output = ob_get_clean();

        $this->assertStringContainsString('<div id="devpanel"', $output);
        // renderGit() always renders the HEAD Commit card.
        $this->assertStringContainsString('DevPanel', $output);
        $this->assertStringContainsString('HEAD Commit', $output);
    }
    
    public function testPhpinfoShowsPhpinfoPanel(): void
    {
        $this->setMockUser(95);
        ob_start();
        $this->controller->phpinfo();
        $output = ob_get_clean();
        
        $this->assertStringContainsString('phpinfo()', $output);
    }
    
    public function testCustomPanelRegistrationAndRouting(): void
    {
        $this->setMockUser(95);
        
        DevPanelController::registerPanel('mycustom', 'Custom Tab', function() {
            return '<div id="my-custom-content">Hello Custom!</div>';
        });
        
        $panels = DevPanelController::getCustomPanels();
        $this->assertArrayHasKey('mycustom', $panels);
        
        ob_start();
        // Magic __call routing
        $this->controller->mycustom();
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Hello Custom!', $output);
        
        DevPanelController::resetCustomPanels();
        $this->assertEmpty(DevPanelController::getCustomPanels());
    }
    
    public function testAccessDeniedForNonAdmin(): void
    {
        $this->setMockUser(10); // Standard user
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');
        
        $this->controller->display();
    }
}
