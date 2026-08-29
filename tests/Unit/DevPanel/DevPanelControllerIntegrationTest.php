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

    /**
     * What the database inspector should answer.
     *
     * `pg_stat_activity`, `pg_stat_user_indexes` and `pg_stat_statements` cannot be faked
     * through a connection, and three of this tab's four sections are made of them.
     *
     * @var \Pramnos\Database\Inspector\DatabaseInspector|null
     */
    public $inspector = null;

    protected function databaseInspector(
        \Pramnos\Database\Database $db
    ): \Pramnos\Database\Inspector\DatabaseInspector {
        return $this->inspector ?? parent::databaseInspector($db);
    }

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

    /**
     * The tables come from the shared inspector, not from a query this tab writes itself.
     *
     * This tab had its own copy of the table-size query — the third in the framework, with its
     * own bugs. It is gone, and what is asserted here is that the shared answer reaches the
     * page.
     */
    public function testDbActionWithMysqlTables()
    {
        $this->dbMock->type = 'mysql';
        $this->controller->inspector = $this->inspector([
            'tables' => [
                ['table_name' => 'users', 'total_bytes' => 2048, 'data_bytes' => 1024,
                 'index_bytes' => 1024, 'row_estimate' => 500],
                ['table_name' => 'posts', 'total_bytes' => 4096, 'data_bytes' => 2048,
                 'index_bytes' => 2048, 'row_estimate' => 1200],
            ],
        ]);

        $this->controller->db();

        $this->assertSame('db', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('users', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('posts', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('1,200', $this->controller->lastRenderedContent);
    }

    /**
     * What the database is doing right now — the tool this tab was asked for by name.
     *
     * A running query's age and an idle connection's age are different numbers, and only the
     * first one means anything: an idle pooled connection reported as running for 194 minutes,
     * in red, is why people stop reading the column.
     */
    public function testDbActionShowsActiveProcesses()
    {
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([
            'processes' => [
                ['pid' => 4211, 'usename' => 'app', 'state' => 'active', 'active_sec' => 12,
                 'idle_sec' => null, 'query' => 'SELECT * FROM users WHERE email = $1'],
                ['pid' => 4212, 'usename' => 'app', 'state' => 'idle', 'active_sec' => null,
                 'idle_sec' => 900, 'query' => 'COMMIT'],
            ],
        ]);

        $this->controller->db();

        $this->assertStringContainsString('Active processes', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('4211', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('<strong>12s</strong>', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('idle 900s', $this->controller->lastRenderedContent,
            'an idle connection is not a stuck query');
    }

    /**
     * Indexes nothing scans, and tables read the hard way.
     *
     * The developer's question about a database, and the one the administration screen does not
     * ask. Neither is visible from a list of table sizes.
     */
    public function testDbActionShowsIndexUsage()
    {
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([
            'indexes' => [
                'unused'  => [['table_name' => 'mails', 'index_name' => 'idx_mails_hash',
                               'size_bytes' => 5242880]],
                'scanned' => [['table_name' => 'usertokens', 'seq_scan' => 8100,
                               'seq_tup_read' => 91000000, 'idx_scan' => 0,
                               'row_estimate' => 7255]],
            ],
        ]);

        $this->controller->db();

        $html = $this->controller->lastRenderedContent;

        $this->assertStringContainsString('idx_mails_hash', $html);
        $this->assertStringContainsString('5 MB', $html);
        $this->assertStringContainsString('usertokens', $html);
        $this->assertStringContainsString('91,000,000', $html);
    }

    /**
     * A missing `pg_stat_statements` says so rather than showing an empty table.
     *
     * "The extension is not installed" and "no slow queries" are different facts, and a screen
     * that renders the same thing for both tells somebody their database is fine when it has
     * never been asked.
     */
    public function testAMissingStatementsExtensionSaysSo()
    {
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([
            'statements' => ['available' => false, 'rows' => []],
        ]);

        $this->controller->db();

        $this->assertStringContainsString(
            'pg_stat_statements</code> is not installed',
            $this->controller->lastRenderedContent
        );
    }

    /**
     * And when it is installed, the slowest by total time.
     *
     * By total, not by mean: a query taking two milliseconds four million times is the one to
     * fix, and it never appears in a list ordered by mean.
     */
    public function testTheSlowestStatementsAreOrderedByTotalTime()
    {
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([
            'statements' => ['available' => true, 'rows' => [
                ['calls' => 4000000, 'total_ms' => '812340.0', 'mean_ms' => '0.20',
                 'query' => 'SELECT 1 FROM usertokens WHERE token = $1'],
            ]],
        ]);

        $this->controller->db();

        $html = $this->controller->lastRenderedContent;

        $this->assertStringContainsString('4,000,000', $html);
        $this->assertStringContainsString('812340.0', $html);
        $this->assertStringContainsString('By total time, not by mean', $html);
    }

    /**
     * An inspector whose four answers are given.
     *
     * @param array<string, mixed> $answers
     */
    private function inspector(array $answers): \Pramnos\Database\Inspector\DatabaseInspector
    {
        return new class ($answers) extends \Pramnos\Database\Inspector\DatabaseInspector {
            public function __construct(private array $answers)
            {
            }

            public function getTableSizes(): array
            {
                return $this->answers['tables'] ?? [];
            }

            public function getProcessList(): array
            {
                return $this->answers['processes'] ?? [];
            }

            public function getIndexUsage(): array
            {
                return $this->answers['indexes'] ?? ['unused' => [], 'scanned' => []];
            }

            public function getSlowStatements(int $limit = 15): array
            {
                return $this->answers['statements'] ?? ['available' => false, 'rows' => []];
            }
        };
    }

    public function testDbActionWithPostgresTablesAndTimescale()
    {
        $this->dbMock->type = 'postgresql';

        $this->controller->inspector = $this->inspector([
            'tables' => [
                ['table_name' => 'pg_users', 'total_bytes' => 2097152, 'data_bytes' => 1048576,
                 'index_bytes' => 1048576, 'row_estimate' => 300],
            ],
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

    /**
     * The sessions and lockouts panels render the rows they are given.
     *
     * The fixture below is the real schema — `usertokens` with `lastused` and
     * `ipaddress`, `authserver.loginlockouts` with `displayvalue` and
     * `lockoutuntil`. It used to describe a table called `tokens` with
     * `last_used` and `ip_address`, which is what the panel queried and what no
     * migration creates: both panels had been rendering empty on every
     * installation, and an empty `catch` made that look like "nothing to show".
     */
    public function testUsersActionRendersSessionsAndLockouts()
    {
        $this->dbMock->mockResults['usertokens'] = new FakeDatabaseResult([], [
            [
                'tokenid'       => 101,
                'userid'        => 1,
                'username'      => 'alice',
                'lastused'      => '2026-06-03 00:00:00',
                'ipaddress'     => '127.0.0.1',
                'applicationid' => 7,
                'tokentype'     => 'auth',
            ],
        ]);
        $this->dbMock->mockResults['loginlockouts'] = new FakeDatabaseResult([], [
            [
                'displayvalue'   => 'bob',
                'lastipaddress'  => '10.0.0.1',
                'lockoutuntil'   => '2026-06-03 12:00:00',
                'failedattempts' => 5,
            ],
        ]);

        $this->controller->users();

        $this->assertSame('users', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('Active Sessions', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('alice', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('bob', $this->controller->lastRenderedContent);

        // ...and the values, not just the names: an empty panel contained the
        // headings too, which is how this stayed green while showing nothing.
        $this->assertStringContainsString('127.0.0.1', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('10.0.0.1', $this->controller->lastRenderedContent);
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
        // `url`, from the join on the urls table, and `servertime` as the unix
        // timestamp the column actually holds: the panel used to print the raw
        // `urlid` under a heading that said URL, and the timestamp unformatted.
        $this->dbMock->mockResults['tokenactions'] = new FakeDatabaseResult([], [
            ['url' => '/api/v1/data', 'method' => 'GET', 'servertime' => 1780531260, 'execution_time_ms' => 12.5, 'return_status' => 200]
        ]);

        $this->controller->users();

        $this->assertStringContainsString('Token #101', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('/api/v1/data', $this->controller->lastRenderedContent);
        $this->assertStringContainsString(
            date('Y-m-d H:i:s', 1780531260),
            $this->controller->lastRenderedContent
        );
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
