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

    // =========================================================================
    // Policy callback (custom access policy instead of usertype)
    // =========================================================================

    /** Build a controller whose access policy is the given callback. */
    private function makePolicyController(callable $policy): TestableDevPanelController
    {
        $app  = \Pramnos\Application\Application::getInstance();
        $ctrl = new TestableDevPanelController($app);
        $ref  = new \ReflectionProperty(\Pramnos\DevPanel\DevPanelController::class, 'policyCallback');
        $ref->setValue($ctrl, \Closure::fromCallable($policy));
        return $ctrl;
    }

    /**
     * A policy callback returning false must deny access WITHOUT a redirect:
     * every action returns null early. This covers the guard-denied early
     * return in each action method.
     */
    public function testPolicyCallbackDenyMakesAllActionsReturnNull(): void
    {
        // Arrange — admin user but a policy that always denies
        $this->setMockUser(95);
        $ctrl = $this->makePolicyController(fn($user) => false);

        // Act + Assert — every panel action returns null without output
        foreach (['display', 'db', 'cache', 'users', 'performance', 'git', 'phpinfo'] as $action) {
            ob_start();
            $result = $ctrl->$action();
            $output = ob_get_clean();
            $this->assertNull($result, "{$action}() must return null when the policy denies");
            $this->assertSame('', $output, "{$action}() must produce no output when denied");
        }
    }

    /**
     * A policy callback returning true must grant access even when the
     * usertype check would normally pass anyway — proves the callback takes
     * priority over minUserType.
     */
    public function testPolicyCallbackAllowGrantsAccess(): void
    {
        // Arrange — low-privilege user but a policy that always allows
        $this->setMockUser(10);
        $ctrl = $this->makePolicyController(fn($user) => true);

        // Act
        ob_start();
        $ctrl->git();
        $output = ob_get_clean();

        // Assert — the panel rendered despite usertype 10 < minUserType
        $this->assertStringContainsString('<div id="devpanel"', $output,
            'An allowing policy must override the usertype minimum');
    }

    /**
     * A custom panel invoked through __call() with a denying policy must also
     * return null early (covers the guard branch inside __call()).
     */
    public function testCustomPanelDeniedByPolicyReturnsNull(): void
    {
        // Arrange
        $this->setMockUser(95);
        DevPanelController::registerPanel('polpanel', 'Pol', fn() => 'content');
        $ctrl = $this->makePolicyController(fn($user) => false);

        try {
            // Act
            ob_start();
            $result = $ctrl->polpanel();
            $output = ob_get_clean();

            // Assert
            $this->assertNull($result);
            $this->assertSame('', $output);
        } finally {
            DevPanelController::resetCustomPanels();
        }
    }

    // =========================================================================
    // Feature-disabled guard (renderError 404)
    // =========================================================================

    /**
     * With the devpanel feature disabled, guardAccess() must render a 404
     * error page. The testable terminate() is a no-op, so renderError()
     * reaches its trailing RuntimeException — proving the error page path ran.
     */
    public function testFeatureDisabledRenders404(): void
    {
        // Arrange — loadFromConfig() is additive, so a full reset() is needed
        // to actually disable the devpanel feature.
        $this->setMockUser(95);
        \Pramnos\Application\FeatureRegistry::reset();

        try {
            ob_start();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Terminated: Error 404');

            // Act
            $this->controller->display();
        } finally {
            $output = ob_get_clean();
            // Assert — the 404 error page was emitted before the exception
            $this->assertStringContainsString('Error 404', $output);
            $this->assertStringContainsString('not enabled', $output);
            // Restore the feature for subsequent tests
            \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        }
    }

    // =========================================================================
    // Cache panel — full render + AJAX endpoints
    // =========================================================================

    /** Enable the cache feature and return the shared Cache instance. */
    private function enableCacheFeature(): \Pramnos\Cache\Cache
    {
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel', 'cache']);
        return \Pramnos\Cache\Cache::getInstance();
    }

    /**
     * cache() with the cache feature enabled and at least one stored item
     * must render the full Item Browser (adapter name, item rows, namespace
     * filter) instead of the "not enabled" alert.
     */
    public function testCacheShowsItemBrowserWhenFeatureEnabled(): void
    {
        // Arrange — store one cache item so the browser has a row
        $this->setMockUser(95);
        $cache = $this->enableCacheFeature();
        $cache->category = 'devpaneltest';
        $cache->save('cached-value', 'devpanel_item');

        try {
            // Act
            ob_start();
            $this->controller->cache();
            $output = ob_get_clean();

            // Assert — full browser markup is present
            $this->assertStringContainsString('Item Browser', $output);
            $this->assertStringContainsString('Flush All Cache', $output);
            $this->assertStringContainsString('Cache Status', $output);
        } finally {
            $cache->clear();
            \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        }
    }

    /**
     * cache() with POST action=flush must run the AJAX flush endpoint and
     * emit {"ok":true} before rendering (terminate() is a no-op in tests).
     */
    public function testCacheFlushEndpointReturnsOkJson(): void
    {
        // Arrange
        $this->setMockUser(95);
        $this->enableCacheFeature();
        $_POST['action'] = 'flush';

        try {
            // Act
            ob_start();
            $this->controller->cache();
            $output = ob_get_clean();

            // Assert — the flush JSON envelope was emitted
            $this->assertStringContainsString('{"ok":true}', $output);
        } finally {
            $_POST = [];
            \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        }
    }

    /**
     * cache() with an empty GET key must answer the inspect AJAX call with a
     * "No key specified" error envelope.
     */
    public function testCacheInspectWithEmptyKeyReturnsError(): void
    {
        // Arrange
        $this->setMockUser(95);
        $this->enableCacheFeature();
        $_GET['key'] = '';

        try {
            // Act
            ob_start();
            $this->controller->cache();
            $output = ob_get_clean();

            // Assert
            $this->assertStringContainsString('No key specified', $output);
        } finally {
            $_GET = [];
            \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        }
    }

    /**
     * cache() inspecting a key that does not exist must answer with ok:false
     * and a null content payload (load() returned false).
     */
    public function testCacheInspectUnknownKeyReturnsNotOk(): void
    {
        // Arrange
        $this->setMockUser(95);
        $this->enableCacheFeature();
        $_GET['key'] = urlencode('definitely-missing-key-xyz');

        try {
            // Act
            ob_start();
            $this->controller->cache();
            $output = ob_get_clean();

            // Assert — the inspect envelope reports the miss
            $this->assertStringContainsString('"ok":false', $output);
        } finally {
            $_GET = [];
            \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        }
    }
}
