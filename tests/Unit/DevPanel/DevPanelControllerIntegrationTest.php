<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\Application\Application;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Framework\Factory;
use Pramnos\Application\Settings;
use Pramnos\Cache\Cache;

class TestableDevPanelController extends DevPanelController
{
    public $lastRenderedContent = '';
    public $lastRenderedTab = '';
    public $lastRedirectUrl = '';
    public $lastErrorCode = null;
    public $lastErrorMessage = '';
    public $bypassGuard = true;

    protected function renderLayout(string $activeTab, string $content): void
    {
        $this->lastRenderedTab = $activeTab;
        $this->lastRenderedContent = $content;
    }

    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        $this->lastRedirectUrl = (string)$url;
    }

    public function terminate(): void
    {
        throw new \RuntimeException("Terminated");
    }

    protected function renderError(int $code, string $message): never
    {
        $this->lastErrorCode = $code;
        $this->lastErrorMessage = $message;
        // Since it is declared to return 'never', throw an exception to bypass exit()
        throw new \RuntimeException("HTTP Error {$code}: {$message}");
    }

    protected function guardAccess(): bool
    {
        if ($this->bypassGuard) {
            return false;
        }
        return parent::guardAccess();
    }

    // Expose protected methods for testing
    public function exposeHumanBytes(int $bytes): string
    {
        $ref = new \ReflectionMethod(DevPanelController::class, 'humanBytes');
        return $ref->invoke($this, $bytes);
    }

    public function exposeReadProcUptime(): string
    {
        $ref = new \ReflectionMethod(DevPanelController::class, 'readProcUptime');
        return $ref->invoke($this);
    }

    public function exposeReadProcLoadAvg(): string
    {
        $ref = new \ReflectionMethod(DevPanelController::class, 'readProcLoadAvg');
        return $ref->invoke($this);
    }

    public function exposeReadProcMemInfo(): array
    {
        $ref = new \ReflectionMethod(DevPanelController::class, 'readProcMemInfo');
        return $ref->invoke($this);
    }

    public function exposeDetectRepoRoot(): string
    {
        $ref = new \ReflectionMethod(DevPanelController::class, 'detectRepoRoot');
        return $ref->invoke($this);
    }

    public function exposeIsDevMode(): bool
    {
        $ref = new \ReflectionMethod(DevPanelController::class, 'isDevMode');
        return $ref->invoke($this);
    }
}

class FakeDatabaseResult
{
    public $numRows = 0;
    public $fields = [];
    public $rowsData = [];

    public function __construct(array $fields = [], array $rowsData = [], int $numRows = 0)
    {
        $this->fields = $fields;
        $this->rowsData = $rowsData;
        $this->numRows = $numRows ?: count($rowsData);
    }

    public function fetchAll(): array
    {
        return $this->rowsData;
    }

    public function fetch(): ?array
    {
        return reset($this->rowsData) ?: null;
    }
}

class FakeDatabase extends \Pramnos\Database\Database
{
    public $type = 'mysql';
    public $connected = true;
    public $executedSql = [];
    public $mockResults = [];

    public function __construct() {}

    public function execute($sql, &...$arguments)
    {
        $this->executedSql[] = $sql;
        foreach ($this->mockResults as $pattern => $result) {
            if (strpos($sql, $pattern) !== false) {
                return $result;
            }
        }
        return new FakeDatabaseResult();
    }
}

class DevPanelControllerIntegrationTest extends TestCase
{
    protected ?TestableDevPanelController $controller = null;
    protected $dbMock;
    protected $origDb;
    protected $origCacheAdapter;

    protected function setUp(): void
    {
        parent::setUp();
        
        $app = $this->createMock(Application::class);
        $user = new \stdClass();
        $user->usertype = 99;
        $app->user = $user;
        
        // Mock database
        $this->dbMock = new FakeDatabase();
        
        $singleton = &Factory::getDatabase();
        $this->origDb = $singleton;
        $singleton = $this->dbMock;

        FeatureRegistry::reset();
        FeatureRegistry::loadFromConfig(['devpanel', 'cache', 'queue']);

        if (!defined('DEVELOPMENT')) {
            define('DEVELOPMENT', true);
        }

        $this->controller = new TestableDevPanelController($app);

        // Store original cache adapter
        $cacheInstance = Cache::getInstance();
        $ref = new \ReflectionProperty(Cache::class, 'adapter');
        $this->origCacheAdapter = $ref->getValue($cacheInstance);
    }

    protected function tearDown(): void
    {
        $singleton = &Factory::getDatabase();
        $singleton = $this->origDb;

        // Restore original cache adapter
        $cacheInstance = Cache::getInstance();
        $ref = new \ReflectionProperty(Cache::class, 'adapter');
        $ref->setValue($cacheInstance, $this->origCacheAdapter);

        FeatureRegistry::reset();
        Settings::clearSettings();
        DevPanelController::resetCustomPanels();
        $_GET = [];
        $_POST = [];
        parent::tearDown();
    }

    public function testDisplayOverviewRendersCorrectly()
    {
        $this->dbMock->mockResults['VERSION()'] = new FakeDatabaseResult(['v' => '8.0.25-mysql']);

        $this->controller->display();

        $this->assertSame('overview', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('System Info', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('8.0.25-mysql', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('Peak memory', $this->controller->lastRenderedContent);
    }

    public function testDbActionWithMysqlTables()
    {
        $this->dbMock->type = 'mysql';

        $this->dbMock->mockResults['DATABASE()'] = new FakeDatabaseResult([], ['d' => 'testdb']);
        $this->dbMock->mockResults['information_schema.tables'] = new FakeDatabaseResult([], [
            ['tbl' => 'users', 'total' => '2048', 'data' => '1024', 'rows' => 500],
            ['tbl' => 'posts', 'total' => '4096', 'data' => '2048', 'rows' => 1200],
        ]);

        $this->controller->db();

        $this->assertSame('db', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('users', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('posts', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('1,200', $this->controller->lastRenderedContent);
    }

    public function testDbActionWithPostgresTablesAndTimescale()
    {
        $this->dbMock->type = 'postgresql';

        $this->dbMock->mockResults['pg_class'] = new FakeDatabaseResult([], [
            ['tbl' => 'pg_users', 'total' => '2 MB', 'data' => '1 MB', 'rows' => 300],
        ]);
        $this->dbMock->mockResults['pg_extension'] = new FakeDatabaseResult([], ['extversion' => '2.5.0']);
        $this->dbMock->mockResults['timescaledb_information'] = new FakeDatabaseResult([], [
            ['hypertable_name' => 'metrics', 'num_chunks' => 10, 'compression_enabled' => true],
        ]);

        $this->controller->db();

        $this->assertSame('db', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('pg_users', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('TimescaleDB Hypertables', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('metrics', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('ok', $this->controller->lastRenderedContent);
    }

    public function testCacheFlushAction()
    {
        $_POST['action'] = 'flush';
        
        $adapterMock = $this->createMock(\Pramnos\Cache\Adapter\RedisAdapter::class);
        $adapterMock->method('clear')->willReturn(true);
        
        $cacheInstance = Cache::getInstance();
        $ref = new \ReflectionProperty(Cache::class, 'adapter');
        $ref->setValue($cacheInstance, $adapterMock);

        ob_start();
        try {
            $this->controller->cache();
        } catch (\Exception $e) {
            // Handled or bypassed redirect/exits
        }
        $output = ob_get_clean();

        $this->assertJson($output);
        $this->assertStringContainsString('ok', $output);
    }

    public function testCacheItemInspectAction()
    {
        $_GET['key'] = urlencode('my-test-key');

        $adapterMock = $this->createMock(\Pramnos\Cache\Adapter\RedisAdapter::class);
        $adapterMock->method('getPrefix')->willReturn('pramnos:');
        $adapterMock->method('load')->with('pramnos:my-test-key', 0)->willReturn(['hello' => 'world']);

        $cacheInstance = Cache::getInstance();
        $ref = new \ReflectionProperty(Cache::class, 'adapter');
        $ref->setValue($cacheInstance, $adapterMock);

        ob_start();
        try {
            $this->controller->cache();
        } catch (\Exception $e) {
            // expected bypass
        }
        $output = ob_get_clean();

        $this->assertJson($output);
        $this->assertStringContainsString('hello', $output);
        $this->assertStringContainsString('world', $output);
    }

    public function testUsersActionRendersSessionsAndLockouts()
    {
        $this->dbMock->mockResults['tokens'] = new FakeDatabaseResult([], [
            ['tokenid' => 101, 'userid' => 1, 'username' => 'alice', 'last_used' => '2026-06-03 00:00:00', 'ip_address' => '127.0.0.1', 'application' => 'web', 'tokentype' => 1]
        ]);
        $this->dbMock->mockResults['loginlockouts'] = new FakeDatabaseResult([], [
            ['identifier' => 'bob', 'ip_address' => '10.0.0.1', 'lockout_until' => '2026-06-03 12:00:00', 'failed_attempts' => 5]
        ]);

        $this->controller->users();

        $this->assertSame('users', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('Active Sessions', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('alice', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('bob', $this->controller->lastRenderedContent);
    }

    public function testTokenDetailView()
    {
        $_GET['token'] = '101';

        $this->dbMock->mockResults['LIMIT 1'] = new FakeDatabaseResult(['tokenid' => 101, 'userid' => 1, 'username' => 'alice', 'application' => 'web'], [
            ['tokenid' => 101, 'userid' => 1, 'username' => 'alice', 'application' => 'web']
        ]);
        $this->dbMock->mockResults['COUNT(*)'] = new FakeDatabaseResult(['cnt' => 120], [
            ['cnt' => 120]
        ]);
        $this->dbMock->mockResults['tokenactions'] = new FakeDatabaseResult([], [
            ['urlid' => '/api/v1/data', 'method' => 'GET', 'servertime' => '2026-06-03 00:01:00', 'execution_time_ms' => 12.5, 'return_status' => 200]
        ]);

        $this->controller->users();

        $this->assertStringContainsString('Token #101', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('/api/v1/data', $this->controller->lastRenderedContent);
    }

    public function testUserLogView()
    {
        $_GET['user'] = '1';

        $this->dbMock->mockResults['LIMIT 1'] = new FakeDatabaseResult(['userid' => 1, 'username' => 'alice'], [
            ['userid' => 1, 'username' => 'alice']
        ]);
        $this->dbMock->mockResults['COUNT(*)'] = new FakeDatabaseResult(['cnt' => 2], [
            ['cnt' => 2]
        ]);
        $this->dbMock->mockResults['userlog'] = new FakeDatabaseResult([], [
            ['logid' => 10, 'date' => time(), 'logtype' => 1, 'log' => 'User logged in', 'details' => 'Success']
        ]);

        $this->controller->users();

        $this->assertStringContainsString('User Log — #1 alice', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('User logged in', $this->controller->lastRenderedContent);
    }

    public function testPerformanceReport()
    {
        $_GET['range'] = '6';

        $this->dbMock->mockResults['ta.tokenid'] = new FakeDatabaseResult([], [
            ['userid' => 1, 'username' => 'alice', 'app_name' => 'Web App', 'calls' => 300, 'avg_ms' => 55.4, 'max_ms' => 150.0]
        ]);
        $this->dbMock->mockResults['tokenactions'] = new FakeDatabaseResult([], [
            ['endpoint' => '/home', 'method' => 'GET', 'calls' => 500, 'avg_ms' => 45.2, 'max_ms' => 120.0]
        ]);

        $this->controller->performance();

        $this->assertSame('performance', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('Slowest Endpoints', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('/home', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('alice', $this->controller->lastRenderedContent);
    }

    public function testHumanBytesHelper()
    {
        $this->assertSame('150 B', $this->controller->exposeHumanBytes(150));
        $this->assertSame('1.5 KB', $this->controller->exposeHumanBytes(1536));
        $this->assertSame('2.5 MB', $this->controller->exposeHumanBytes(2621440));
        $this->assertSame('5.25 GB', $this->controller->exposeHumanBytes(5637144576));
    }

    public function testReadProcUptimeHelper()
    {
        $uptime = $this->controller->exposeReadProcUptime();
        $this->assertNotEmpty($uptime);
    }

    public function testReadProcLoadAvgHelper()
    {
        $load = $this->controller->exposeReadProcLoadAvg();
        $this->assertNotEmpty($load);
    }

    public function testReadProcMemInfoHelper()
    {
        $mem = $this->controller->exposeReadProcMemInfo();
        $this->assertCount(3, $mem);
    }

    public function testDetectRepoRootHelper()
    {
        $root = $this->controller->exposeDetectRepoRoot();
        $this->assertNotEmpty($root);
    }

    public function testIsDevModeHelper()
    {
        $this->assertTrue($this->controller->exposeIsDevMode());
    }

    public function testGuardAccessDeniedWhenDisabled()
    {
        FeatureRegistry::reset();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DevPanel feature is not enabled.');
        
        $ctrl = new TestableDevPanelController($this->createMock(Application::class));
        $ctrl->bypassGuard = false;
        $ctrl->display();
    }
}
