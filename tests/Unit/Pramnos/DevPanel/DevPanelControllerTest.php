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

    /** @var int How many times an output path asked to stop */
    public int $terminated = 0;

    protected function terminate(): void
    {
        // Prevent exit during tests, and count: "did this path stop?" is the contract
        // {@see DevPanelControllerTest::testTheJsonEndpointStops()} exists to hold.
        $this->terminated++;
    }
}

/**
 * Runs in separate processes because setUp() does `define('DEVELOPMENT', true)`.
 *
 * A constant cannot be undefined, so without isolation this file decided that
 * the whole test run was "developing" — permanently, for every test that
 * happened to come after it. Two middleware tests had quietly grown to depend
 * on it: they asserted that a JWT exception message reaches the client, which
 * is only true while developing, and they passed in a full-suite run and failed
 * whenever their own class was run alone. The tests for the opposite branch —
 * that the detail is withheld in production — could not run at all.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
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

    // ── The log endpoint's access control ─────────────────────────────────────

    /**
     * A signed debug grant is enough, without an admin user.
     *
     * The rest of the DevPanel requires `usertype >= 90`. This endpoint
     * deliberately does not: the person who opened the toolbar on a live server
     * with `debug:token` is a developer holding a signed, expiring grant, and
     * usually not an admin user of that application at all. Requiring both would
     * make the endpoint useless exactly where it is needed.
     */
    public function testAGrantIsEnoughWithoutAnAdminUser(): void
    {
        // Arrange — no logged-in user at all, but a valid grant. A grant has to
        // be signed with something, and this fixture application has no key, so
        // one is set for the duration of the test: what is under test is the
        // guard, not where the secret comes from.
        $_ENV['APP_KEY'] = 'test-key-for-signing-a-debug-grant';
        $_GET[\Pramnos\Debug\DebugAccess::PARAM] = \Pramnos\Debug\DebugAccess::issue(600);
        \Pramnos\Debug\DebugAccess::reset();
        $_GET['request'] = str_repeat('ab', 8);

        try {
            // Act
            ob_start();
            $this->controller->logs();
            $output = ob_get_clean();

            // Assert — it answered, rather than redirecting to a login
            $decoded = json_decode($output, true);
            $this->assertIsArray($decoded, 'the endpoint replies JSON');
            $this->assertSame(str_repeat('ab', 8), $decoded['request']);
            $this->assertSame([], $decoded['lines'], 'nothing was logged under that id');
        } finally {
            \Pramnos\Debug\DebugAccess::reset();
            unset($_ENV['APP_KEY']);
            $_GET = [];
        }
    }

    /**
     * Without a grant and without an admin user, nothing comes back.
     *
     * This is the one route in the framework that hands over log lines, so the
     * negative case is the one worth pinning: the guard must refuse, not fall
     * through to the reply.
     */
    public function testWithoutAGrantOrAnAdminUserItRefuses(): void
    {
        // Arrange — an ordinary user, no grant
        $this->setMockUser(10);
        \Pramnos\Debug\DebugAccess::reset();
        $_GET['request'] = str_repeat('ab', 8);

        // Act & Assert — guardUserType() redirects, which the test double turns
        // into an exception rather than an exit
        try {
            ob_start();
            $this->controller->logs();
            $output = (string) ob_get_clean();

            $this->assertStringNotContainsString('"lines"', $output, 'no log lines may be written');
        } catch (\RuntimeException $e) {
            ob_end_clean();
            $this->assertSame('redirect_quit', $e->getMessage());
        } finally {
            \Pramnos\Debug\DebugAccess::reset();
            $_GET = [];
        }
    }

    /**
     * An admin user gets through without a grant — the panel's own audience.
     */
    public function testAnAdminUserIsAllowedWithoutAGrant(): void
    {
        // Arrange
        $this->setMockUser(95);
        \Pramnos\Debug\DebugAccess::reset();
        $_GET['request'] = str_repeat('cd', 8);

        try {
            // Act
            ob_start();
            $this->controller->logs();
            $output = ob_get_clean();

            // Assert
            $decoded = json_decode($output, true);
            $this->assertIsArray($decoded);
            $this->assertSame(str_repeat('cd', 8), $decoded['request']);
        } finally {
            $_GET = [];
        }
    }

    /**
     * An id that this framework never issued is refused before anything is read.
     *
     * The value decides which log lines are handed back and reaches file
     * handling, so the shape is checked rather than sanitised — sixteen hex
     * characters cannot carry a path, a glob or a regex.
     */
    public function testAMalformedRequestIdIsRefused(): void
    {
        // Arrange
        $this->setMockUser(95);
        $_GET['request'] = '../../etc/passwd';

        try {
            // Act
            ob_start();
            $this->controller->logs();
            $output = ob_get_clean();

            // Assert — an error, and no lines key at all
            $decoded = json_decode($output, true);
            $this->assertArrayHasKey('error', $decoded);
            $this->assertArrayNotHasKey('lines', $decoded);
        } finally {
            $_GET = [];
        }
    }

    /**
     * A missing id is the same refusal: there is no "everything" to ask for.
     */
    public function testAnAbsentRequestIdIsRefused(): void
    {
        // Arrange
        $this->setMockUser(95);

        // Act
        ob_start();
        $this->controller->logs();
        $output = ob_get_clean();

        // Assert
        $decoded = json_decode($output, true);
        $this->assertArrayHasKey('error', $decoded);
    }

    /**
     * With the DevPanel feature switched off the route does not exist, whatever
     * grant the caller holds.
     */
    public function testTheEndpointIsGoneWhenTheFeatureIsOff(): void
    {
        // Arrange
        \Pramnos\Application\FeatureRegistry::reset();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['cache']);
        $_GET['request'] = str_repeat('ab', 8);

        try {
            // Act
            ob_start();
            $this->controller->logs();
            $output = (string) ob_get_clean();

            // Assert — the 404 page, not a reply
            $this->assertStringContainsString('Error 404', $output);
            $this->assertStringNotContainsString('"lines"', $output);
        } catch (\RuntimeException $e) {
            ob_end_clean();
            $this->assertStringContainsString('Terminated', $e->getMessage());
        } finally {
            $_GET = [];
            \Pramnos\Application\FeatureRegistry::reset();
            \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        }
    }

    /**
     * A JSON endpoint stops after writing, like every other output path here.
     *
     * **The contract, and it was broken.** `renderLayout()` and `renderError()` both echo and
     * then `terminate()`; `sendJson()` echoed and returned `null`, which is the same contract
     * with the ending left off — the only outlier in the file.
     *
     * Reported from a consuming application: `/devpanel/logs?request=…` printed its JSON and
     * then the application rendered a page on top of it, because a `null` return told its
     * dispatcher that nothing had been produced. Its `$output` property is magic —
     * `Base::__get()` answers null for anything unset — and `null !== ''`, so its "did a
     * controller produce output?" guard passed holding a null: two `stripos(): Passing null`
     * deprecations and then a fatal on a `string` parameter, all printed after a perfectly
     * good JSON body.
     *
     * Asserting the JSON alone would not have caught it. The JSON was correct.
     *
     * @return void
     */
    public function testTheJsonEndpointStops(): void
    {
        // Arrange — a valid grant and a well-formed request id
        $this->setMockUser(95);
        $_GET['request'] = str_repeat('ab', 8);

        // Act
        ob_start();
        $this->controller->logs();
        $output = ob_get_clean();

        // Assert — the body is right…
        $this->assertStringContainsString('"request"', $output);
        $this->assertStringContainsString('"lines"', $output);

        // …and the request was declared finished, which is the part that was missing
        $this->assertSame(
            1,
            $this->controller->terminated,
            'A JSON reply is finished when it has been written; anything that runs after it '
            . 'appends to a response that is already complete.'
        );
    }

    /**
     * A rejected request id also stops.
     *
     * The 400 path goes through the same method, so it had the same missing ending — and an
     * error reply followed by a rendered page is the shape that is hardest to read in a
     * browser's network tab.
     *
     * @return void
     */
    public function testTheRejectedJsonEndpointStopsToo(): void
    {
        // Arrange
        $this->setMockUser(95);
        $_GET['request'] = 'not a valid id';

        // Act
        ob_start();
        $this->controller->logs();
        $output = ob_get_clean();

        // Assert
        $this->assertStringContainsString('valid request id', $output);
        $this->assertSame(1, $this->controller->terminated);
    }
}
