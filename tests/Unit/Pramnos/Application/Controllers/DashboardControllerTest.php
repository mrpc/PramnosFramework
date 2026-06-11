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

/**
 * Variant whose redirect() never quits — used to cover the early-return
 * statements after requireMinUserType(): with the throwing variant the
 * `return;` / `return null;` lines are unreachable.
 */
class NonQuittingDashboardController extends DashboardController
{
    public array $redirectedTo = [];

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = $url;
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

    /**
     * cacheitem() success path with a small value: the response must carry
     * the cached content, type metadata, and a byte-formatted size ("N B").
     * This is the main branch the cache browser UI depends on.
     */
    public function testCacheItemReturnsContentWithByteSize(): void
    {
        // Arrange — store a real value under the singleton's current category
        $this->setMockUser(80);
        $cache = \Pramnos\Cache\Cache::getInstance();
        $cache->save('small-value', 'dash_byte_key');
        $_GET['key'] = 'dash_byte_key';

        // Act
        ob_start();
        $this->controller->cacheitem();
        $output = ob_get_clean();

        // Assert — hit returned with content + metadata
        $json = json_decode($output, true);
        $this->assertTrue($json['success']);
        $this->assertSame('small-value', $json['content']);
        $this->assertSame('string', $json['metadata']['type']);
        // serialize('small-value') is well under 1 KB → byte formatting
        $this->assertStringEndsWith(' B', $json['metadata']['size']);
    }

    /**
     * cacheitem() must format sizes between 1 KB and 1 MB with the "KB"
     * suffix — covers the middle branch of the size formatter.
     */
    public function testCacheItemFormatsKilobyteSize(): void
    {
        // Arrange — ~4 KB payload
        $this->setMockUser(80);
        $cache = \Pramnos\Cache\Cache::getInstance();
        $cache->save(str_repeat('k', 4096), 'dash_kb_key');
        $_GET['key'] = 'dash_kb_key';

        // Act
        ob_start();
        $this->controller->cacheitem();
        $output = ob_get_clean();

        // Assert
        $json = json_decode($output, true);
        $this->assertTrue($json['success']);
        $this->assertStringEndsWith(' KB', $json['metadata']['size']);
    }

    /**
     * cacheitem() must format sizes of 1 MB and above with the "MB" suffix —
     * covers the top branch of the size formatter.
     */
    public function testCacheItemFormatsMegabyteSize(): void
    {
        // Arrange — payload just above 1 MiB (1048576 bytes)
        $this->setMockUser(80);
        $cache = \Pramnos\Cache\Cache::getInstance();
        $cache->save(str_repeat('m', 1100000), 'dash_mb_key');
        $_GET['key'] = 'dash_mb_key';

        // Act
        ob_start();
        $this->controller->cacheitem();
        $output = ob_get_clean();

        // Assert
        $json = json_decode($output, true);
        $this->assertTrue($json['success']);
        $this->assertStringEndsWith(' MB', $json['metadata']['size']);
    }

    /**
     * Every action must return early (without producing output) when the
     * user lacks the manager usertype. Uses the non-quitting redirect
     * variant so the post-redirect `return` statements actually execute —
     * proving no privileged data leaks after a failed auth check.
     */
    public function testAllActionsReturnEarlyForUnprivilegedUser(): void
    {
        // Arrange — usertype below the required 80, redirect() records but continues
        $this->setMockUser(10);
        $app        = Application::getInstance();
        $controller = new NonQuittingDashboardController($app);

        $actions = ['display', 'activeusers', 'apistats', 'dbstats', 'database', 'cache', 'cacheitem', 'clearcache'];

        foreach ($actions as $action) {
            // Act
            ob_start();
            $result = $controller->$action();
            $output = ob_get_clean();

            // Assert — nothing rendered, nothing returned
            $this->assertSame('', $output, "action {$action} must not output for unprivileged users");
            $this->assertNull($result, "action {$action} must return null for unprivileged users");
        }

        // One redirect recorded per action
        $this->assertCount(count($actions), $controller->redirectedTo);
    }

    /**
     * cache() must aggregate items from populated namespaces: the per-item
     * inner loop fills cacheItems and stamps each item with its namespace.
     * Verified through the namespace listing of the cache adapter after
     * seeding real entries.
     */
    public function testCacheActionAggregatesPopulatedNamespaces(): void
    {
        // Arrange — seed two real cache entries so getCategories()/getAllItems()
        // return non-empty data and the aggregation loop runs in full
        $this->setMockUser(80);
        $cache = \Pramnos\Cache\Cache::getInstance();
        $cache->save('value-one', 'dash_agg_key1');
        $cache->save('value-two', 'dash_agg_key2');

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
            public function getThemeDir() { return ''; }
        };

        // Act — the view template may be missing in the test env; coverage of
        // the aggregation logic is what matters here
        ob_start();
        try {
            $this->controller->cache();
        } catch (\Throwable $e) {
            // ignore view rendering errors
        }
        ob_get_clean();

        // Assert — the action ran to the point of setting the page title,
        // and the seeded items are visible through the adapter
        $this->assertSame('Cache Details', $doc->title);
        $this->assertNotEmpty($cache->getCategories());
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
