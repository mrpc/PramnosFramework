<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
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

    /**
     * An inspector that answers nothing, unless a test supplied one.
     *
     * The real one reads `pg_stat_activity` and four other catalogue views through a connection
     * these tests do not have. Defaulting to the real one made every test that renders this tab
     * depend on a database.
     */
    protected function databaseInspector(
        \Pramnos\Database\Database $db
    ): \Pramnos\Database\Inspector\DatabaseInspector {
        return $this->inspector ?? new class extends \Pramnos\Database\Inspector\DatabaseInspector {
            public function __construct()
            {
            }

            public function getTableSizes(): array { return []; }

            public function getProcessList(): array { return []; }

            public function getIndexUsage(): array { return ['unused' => [], 'scanned' => []]; }

            public function getSlowStatements(int $limit = 15): array
            {
                return ['available' => false, 'rows' => []];
            }

            public function getReplicationStatus(): array { return []; }

            public function getPublicViews(): array { return []; }
        };
    }

    /** @var array<string, mixed> */
    public $dbStats = ['type' => 'postgresql', 'version' => 'PostgreSQL 16.2'];

    /** @var array<string, mixed> */
    public $timescale = ['ts_version' => null, 'hypertables' => [], 'aggregates' => [],
        'jobs' => [], 'jobHistory' => [], 'chunkCount' => 0];

    protected function databaseStats(\Pramnos\Database\Database $db): array
    {
        return $this->dbStats;
    }

    protected function timescaleData(\Pramnos\Database\Database $db): array
    {
        return $this->timescale;
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
#[CoversClass(DevPanelController::class)]
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

        $this->assertStringContainsString('Active Processes', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('4211', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('>12s<', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('idle 15m 0s', $this->controller->lastRenderedContent,
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
     * The tab answers everything the administration screen does, and more.
     *
     * Asked for by name — «περισσότερα εργαλεία από το database status του διαχειριστικού» — and
     * it had fewer. The listed sections are what `/admin/dashboard/database` renders; the ones
     * after them are what this tab adds. A section quietly dropped from here is a developer
     * being sent back to an admin screen behind an admin session.
     */
    public function testTheTabIsASupersetOfTheAdminScreen()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([
            'tables'      => [['table_name' => 'users', 'total_bytes' => 1024,
                               'data_bytes' => 512, 'index_bytes' => 512, 'row_estimate' => 9]],
            'processes'   => [['pid' => 1, 'usename' => 'app', 'state' => 'active',
                               'active_sec' => 1, 'query' => 'SELECT 1']],
            'replication' => [['client_addr' => '10.0.0.2', 'state' => 'streaming',
                               'sync_state' => 'async', 'lag_sec' => 0]],
            'views'       => [['view_name' => 'v_active_users',
                               'view_definition' => 'SELECT * FROM users']],
            'statements'  => ['available' => true, 'rows' => []],
            'indexes'     => ['unused' => [], 'scanned' => []],
        ]);
        $this->controller->timescale = [
            'ts_version' => '2.5.0', 'chunkCount' => 41,
            'hypertables' => [['hypertable_name' => 'metrics', 'num_chunks' => 41,
                               'compression_enabled' => true]],
            'jobs' => [['proc_name' => 'policy_compression', 'schedule_interval' => '1 day',
                        'last_successful_finish' => '2026-08-29', 'last_run_status' => 'Success']],
            'aggregates' => [], 'jobHistory' => [],
        ];

        // Act
        $this->controller->db();
        $html = $this->controller->lastRenderedContent;

        // Assert — what the administration screen has
        $this->assertStringContainsString('PostgreSQL 16.2', $html, 'the server version');
        $this->assertStringContainsString('users', $html, 'table sizes');
        $this->assertStringContainsString('10.0.0.2', $html, 'replication');
        $this->assertStringContainsString('v_active_users', $html, 'views');
        $this->assertStringContainsString('metrics', $html, 'hypertables');

        // …and what only this tab has
        $this->assertStringContainsString('Active Processes', $html);
        $this->assertStringContainsString('Indexes nothing uses', $html);
        $this->assertStringContainsString('Slowest statements', $html);
        $this->assertStringContainsString('policy_compression', $html,
            'a compression job failing for a week is invisible from the hypertable list');
    }

    /**
     * Every column the administration screen shows, this tab shows too.
     *
     * Asserted as a list of the facts rather than of markup, because it was reported three
     * times — «δεν έφερε τη λειτουργικότητα του admin», then «ακόμα δε βλέπω ΟΛΑ όσα έχει το
     * admin» — and each time the gap was a column somebody could see and I could not.
     */
    public function testEveryFactTheAdminScreenShowsIsHereToo()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->dbStats = [
            'type' => 'postgresql', 'version' => 'PostgreSQL 16.2',
            'db_size_bytes' => 1073741824, 'connections_active' => 3,
            'connections_total' => 100, 'cache_hit_ratio' => 99.4,
            'xact_commit' => 512, 'xact_rollback' => 4,
        ];
        $this->controller->inspector = $this->inspector([
            'tables'      => [['schemaname' => 'public', 'table_name' => 'users',
                               'total_bytes' => 2048, 'data_bytes' => 1024,
                               'index_bytes' => 1024, 'row_estimate' => 515]],
            'processes'   => [['pid' => 4211, 'usename' => 'app',
                               'application_name' => 'myapp', 'client_addr' => '10.0.0.9',
                               'backend_start' => '2026-08-29 10:00:00', 'state' => 'active',
                               'wait_event_type' => 'Lock', 'wait_event' => 'transactionid',
                               'active_sec' => 3, 'query' => 'SELECT 1']],
            'replication' => [['client_addr' => '10.0.0.2', 'state' => 'streaming',
                               'sync_state' => 'async', 'lag_sec' => 2]],
            'views'       => [['view_name' => 'v_active_users',
                               'view_definition' => 'SELECT 1']],
        ]);
        $this->controller->timescale = [
            'ts_version' => '2.5.0', 'chunkCount' => 41,
            'hypertables' => [['hypertable_name' => 'pushlog', 'num_chunks' => 41,
                               'num_dimensions' => 1, 'compression_enabled' => true,
                               'tablespaces' => 'fast_ssd']],
            'aggregates'  => [['view_name' => 'daily_pushes',
                               'materialization_name' => '_materialized_daily',
                               'compression_enabled' => true]],
            'jobs'        => [['proc_name' => 'policy_retention',
                               'schedule_interval' => '1 day',
                               'last_run_started_at' => '2026-08-29 03:00:00',
                               'last_successful_finish' => '2026-08-29 03:00:01',
                               'next_start' => '2026-08-30 03:00:00',
                               'last_run_status' => 'Success']],
            'jobHistory'  => [['proc_name' => 'policy_compression',
                               'start_time' => '2026-08-22 03:00:00',
                               'succeeded' => 'f',
                               'err_message' => 'could not compress chunk']],
        ];

        // Act
        $this->controller->db();
        $html = $this->controller->lastRenderedContent;

        // Assert — every one of these is a column the administration screen has
        foreach ([
            'PostgreSQL 16.2' => 'the server version',
            '1 GB'            => 'the database size',
            '99.4'            => 'the cache-hit ratio',
            'Copy'            => 'the copy button on a query',
            'myapp'           => "the process's application name",
            '10.0.0.9'        => "the process's client address",
            'Started'         => 'when the backend connected',
            '10.0.0.2'        => 'the replication standby',
            'v_active_users'  => 'the public views',
            'fast_ssd'        => "the hypertable's tablespaces",
            'daily_pushes'    => 'the continuous aggregates',
            '2026-08-30 03:00:00' => "a job's next run",
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $html, $why . ' is missing');
        }

        // …and the two things only this tab has about a job
        $this->assertStringContainsString('Lock transactionid', $html,
            'a backend waiting on a lock is a different problem from one that is busy');
        $this->assertStringContainsString('Kill', $html,
            'ending a backend is what this panel has that the administration screen does not');
        $this->assertStringContainsString('could not compress chunk', $html,
            'a green status says the last run worked, not that the three before it did');
    }

    /**
     * A job that succeeded is not listed as a failure.
     *
     * `succeeded` arrives as text and PostgreSQL writes a boolean as `t`/`f`. `(bool) \'f\'` is
     * true, so a naive cast hides every failure — the one thing that section exists to show —
     * and a naive `!` would list every success as one.
     */
    public function testASucceededJobIsNotListedAsAFailure()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([]);
        $this->controller->timescale = [
            'ts_version' => '2.5.0', 'chunkCount' => 0, 'hypertables' => [],
            'aggregates' => [], 'jobs' => [],
            'jobHistory' => [
                ['proc_name' => 'policy_retention', 'start_time' => '2026-08-29',
                 'succeeded' => 't', 'err_message' => ''],
            ],
        ];

        // Act
        $this->controller->db();

        // Assert
        $this->assertStringNotContainsString(
            'Jobs that failed',
            $this->controller->lastRenderedContent
        );
    }

    /**
     * Killing a backend says what happened, above the list it happened to.
     *
     * The one destructive thing on the tab. It is handled before the page is rendered, so the
     * list below reflects what just happened rather than what was true a moment ago — and it
     * says so either way, because a button that appears to do nothing is a button somebody
     * presses again.
     */
    public function testKillingABackendReportsWhatHappened()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector(['kill' => true]);
        $_POST = ['action' => 'kill', 'pid' => '4211'];

        try {
            // Act
            $this->controller->db();

            // Assert
            $this->assertStringContainsString('Backend 4211 was ended', $this->controller->lastRenderedContent);
        } finally {
            $_POST = [];
        }
    }

    /**
     * A backend that could not be ended says so rather than claiming it was.
     *
     * The ordinary case: the list was rendered a minute ago and the backend has finished since,
     * or this role is not allowed to. Reporting a kill that did not happen is how somebody
     * concludes the button is broken *after* trusting it once.
     */
    public function testABackendThatCouldNotBeEndedSaysSo()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector(['kill' => false]);
        $_POST = ['action' => 'kill', 'pid' => '4211'];

        try {
            // Act
            $this->controller->db();

            // Assert
            $this->assertStringContainsString('could not be ended', $this->controller->lastRenderedContent);
        } finally {
            $_POST = [];
        }
    }

    /**
     * A post with no pid does nothing at all.
     *
     * A form submitted without its hidden field, or a crawler following the address. Nothing is
     * asked of the database and nothing is claimed on the page.
     */
    public function testAKillWithNoPidDoesNothing()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector(['kill' => true]);
        $_POST = ['action' => 'kill', 'pid' => '0'];

        try {
            // Act
            $this->controller->db();

            // Assert
            $this->assertStringNotContainsString('was ended', $this->controller->lastRenderedContent);
            $this->assertStringNotContainsString('could not be ended', $this->controller->lastRenderedContent);
        } finally {
            $_POST = [];
        }
    }

    /**
     * A synchronous standby reads "In Sync" rather than a lag of zero.
     *
     * With `sync_state = sync` the primary waits for the standby before it commits, so there is
     * nothing to be behind by. "0s" invites somebody to watch a number that cannot move.
     */
    public function testASynchronousStandbyReadsAsInSync()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([
            'replication' => [['client_addr' => '10.0.0.2', 'state' => 'streaming',
                               'sync_state' => 'sync', 'lag_sec' => 0]],
        ]);

        // Act
        $this->controller->db();

        // Assert
        $this->assertStringContainsString('In Sync', $this->controller->lastRenderedContent);
    }

    /**
     * A hypertable without compression, and a job that has never run, say so.
     *
     * Both are the honest state of a fresh installation, and both used to render as an empty
     * cell — which reads as a rendering fault rather than as "nothing has happened yet".
     */
    public function testUncompressedAndNeverRunAreStated()
    {
        // Arrange
        $this->dbMock->type = 'postgresql';
        $this->controller->inspector = $this->inspector([]);
        $this->controller->timescale = [
            'ts_version' => '2.5.0', 'chunkCount' => 0, 'aggregates' => [], 'jobHistory' => [],
            'hypertables' => [['hypertable_name' => 'pushlog', 'num_chunks' => 0,
                               'num_dimensions' => 1, 'compression_enabled' => false,
                               'tablespaces' => '']],
            'jobs' => [['proc_name' => 'policy_retention', 'schedule_interval' => '1 day',
                        'last_run_started_at' => '', 'last_successful_finish' => '',
                        'next_start' => '', 'last_run_status' => '']],
        ];

        // Act
        $this->controller->db();
        $html = $this->controller->lastRenderedContent;

        // Assert
        $this->assertStringContainsString('>off<', $html);
        $this->assertStringContainsString('never run', $html);
    }

    /**
     * TimescaleDB chunks are not listed as tables.
     *
     * Reported from a real screen: `_hyper_7_15_chunk` and forty like it, crowding out the
     * tables somebody was looking for. They are the extension's own partitioning, named after
     * nothing a person recognises, and they double-count storage the hypertable already reports.
     */
    public function testChunksAreExcludedFromTheTableList()
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Database/Inspector/DatabaseInspector.php'
        );

        // Assert
        $this->assertStringContainsString('_timescaledb', $source,
            'the internal schema must be excluded, or the list is mostly chunks');
        $this->assertStringContainsString('NOT LIKE', $source);
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

            public function getReplicationStatus(): array
            {
                return $this->answers['replication'] ?? [];
            }

            public function getPublicViews(): array
            {
                return $this->answers['views'] ?? [];
            }

            public function killProcess(int $pid): bool
            {
                return (bool) ($this->answers['kill'] ?? false);
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
        $this->controller->timescale = [
            'ts_version'  => '2.5.0',
            'hypertables' => [
                ['hypertable_name' => 'metrics', 'num_chunks' => 10, 'compression_enabled' => true],
            ],
            'aggregates' => [], 'jobs' => [], 'jobHistory' => [], 'chunkCount' => 10,
        ];

        $this->controller->db();

        $this->assertSame('db', $this->controller->lastRenderedTab);
        $this->assertStringContainsString('pg_users', $this->controller->lastRenderedContent);
        $this->assertStringContainsString('TimescaleDB 2.5.0', $this->controller->lastRenderedContent);
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
