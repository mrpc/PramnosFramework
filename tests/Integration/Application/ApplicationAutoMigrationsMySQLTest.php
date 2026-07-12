<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Integration tests for Application::runAutoMigrations() against MySQL 8.0.
 *
 * These tests verify the full auto-migration pipeline that runs on every
 * Application::exec() call:
 *
 *   1. autoExecute=true migrations run automatically.
 *   2. autoExecute=false migrations are NEVER run automatically.
 *   3. Already-ran migrations are not re-run (idempotent across requests).
 *   4. The per-instance flag prevents a second run within the same request.
 *   5. migration_cutoff in app.php correctly skips pre-cutoff migrations.
 *   6. When nothing is pending, the fast check exits without loading PHP files.
 *   7. MigrationRunner::hasPendingFromSlugs() works against a real MySQL DB.
 *
 * Schema isolation — all tables are isolated to this suite via unique names:
 *   am_autorun_test      — created by the autoExecute=true fixture
 *   am_manual_only_test  — must NOT be created by auto-run
 *   am_my_schemaversion  — isolated history table (not the real schemaversion)
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class ApplicationAutoMigrationsMySQLTest extends TestCase
{
    /** @var Database Live MySQL connection. */
    private Database $db;

    /** @var string Isolated history table for this test suite. */
    private string $historyTable = 'am_my_schemaversion';

    /** @var string Absolute path to the fixture migration directory. */
    private string $fixtureDir;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        $this->db->query("DROP TABLE IF EXISTS `am_autorun_test`");
        $this->db->query("DROP TABLE IF EXISTS `am_manual_only_test`");
        $this->db->query("DROP TABLE IF EXISTS `{$this->historyTable}`");

        $this->fixtureDir = __DIR__ . '/Fixtures/AutoMigrations';
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `am_autorun_test`");
        $this->db->query("DROP TABLE IF EXISTS `am_manual_only_test`");
        $this->db->query("DROP TABLE IF EXISTS `{$this->historyTable}`");
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeApp(array $appInfo = []): MysqlTestableApplication
    {
        return new MysqlTestableApplication($this->db, [$this->fixtureDir], $this->historyTable, $appInfo);
    }

    private function tableExists(string $table): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                $table
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    private function ranSlugs(): array
    {
        try {
            $result = $this->db->query("SELECT `key` FROM `{$this->historyTable}` WHERE `result` = 1");
        } catch (\Throwable) {
            return [];
        }
        $slugs = [];
        while ($result->fetch()) {
            $slugs[] = $result->fields['key'];
        }
        return $slugs;
    }

    // -----------------------------------------------------------------------
    // 1. autoExecute=true runs automatically
    // -----------------------------------------------------------------------

    /**
     * runAutoMigrations() must execute migrations whose autoExecute=true and
     * record them in the history table.
     *
     * The fixture 2026_06_01_000001_am_create_autorun_table.php has
     * autoExecute=true and creates am_autorun_test.
     */
    public function testAutoExecuteTrueMigrationRunsAutomatically(): void
    {
        // Arrange
        $app = $this->makeApp();

        // Act
        $app->triggerAutoMigrations();

        // Assert — table created
        $this->assertTrue(
            $this->tableExists('am_autorun_test'),
            'autoExecute=true migration must create its table automatically'
        );
        // Assert — recorded in history with result=1
        $this->assertContains(
            'am_create_autorun_table',
            $this->ranSlugs(),
            'Successful migration must appear in the history table'
        );
    }

    // -----------------------------------------------------------------------
    // 2. autoExecute=false is never auto-run
    // -----------------------------------------------------------------------

    /**
     * autoExecute=false migrations must be completely ignored by auto-run.
     * They require an explicit `pramnos migrate` or DevPanel trigger.
     *
     * The fixture 2026_06_01_000002_am_manual_only.php has autoExecute=false.
     */
    public function testAutoExecuteFalseMigrationIsNeverAutoRun(): void
    {
        // Arrange
        $app = $this->makeApp();

        // Act
        $app->triggerAutoMigrations();

        // Assert — table was NOT created
        $this->assertFalse(
            $this->tableExists('am_manual_only_test'),
            'autoExecute=false migration must NOT run via auto-run'
        );
        $this->assertNotContains(
            'am_manual_only',
            $this->ranSlugs(),
            'autoExecute=false migration must not appear in history'
        );
    }

    // -----------------------------------------------------------------------
    // 3. Idempotency — already-ran migrations not re-run across requests
    // -----------------------------------------------------------------------

    /**
     * On the second request (new Application instance, same history table),
     * migrations that already ran must not be re-executed.
     *
     * We verify by running migrations, dropping the created table, then
     * confirming a second Application instance does NOT recreate it.
     */
    public function testAlreadyRanMigrationsNotReRunOnNextRequest(): void
    {
        // Arrange — first request runs migrations
        $app1 = $this->makeApp();
        $app1->triggerAutoMigrations();
        $this->assertTrue($this->tableExists('am_autorun_test'), 'Pre-condition: migration must have run');

        // Simulate next request: drop the table to detect a re-run
        $this->db->query("DROP TABLE IF EXISTS `am_autorun_test`");

        // Act — second request (new Application instance)
        $app2 = $this->makeApp();
        $app2->triggerAutoMigrations();

        // Assert — migration was NOT re-run
        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'Already-ran migration must not be re-executed on next request'
        );
    }

    // -----------------------------------------------------------------------
    // 4. Per-instance flag prevents double-run within same request
    // -----------------------------------------------------------------------

    /**
     * Calling runAutoMigrations() twice on the SAME Application instance must
     * only run migrations once.  The second call must be a no-op.
     */
    public function testPerInstanceFlagPreventsDoubleRunWithinSameRequest(): void
    {
        // Arrange
        $app = $this->makeApp();
        $app->triggerAutoMigrations(); // first call

        $this->db->query("DROP TABLE IF EXISTS `am_autorun_test`");

        // Act — second call on the same instance
        $app->triggerAutoMigrations();

        // Assert — flag blocked the second run
        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'Per-instance flag must block a second auto-migration run on the same Application'
        );
    }

    // -----------------------------------------------------------------------
    // 5. migration_cutoff skips pre-cutoff migrations
    // -----------------------------------------------------------------------

    /**
     * When migration_cutoff is a date that is after all fixture migration
     * timestamps, runAutoMigrations() must skip all migrations.
     *
     * Fixture timestamps are 2026_06_01_000001 and 2026_06_01_000002.
     * A cutoff of '2099-01-01 00:00:00' is strictly after both.
     */
    public function testMigrationCutoffSkipsAllPreCutoffMigrations(): void
    {
        // Arrange — cutoff after all fixture timestamps
        $app = $this->makeApp(['migration_cutoff' => '2099-01-01 00:00:00']);

        // Act
        $app->triggerAutoMigrations();

        // Assert — nothing ran
        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'All fixture migrations are pre-cutoff and must be skipped'
        );
        $this->assertEmpty($this->ranSlugs(), 'History must remain empty when cutoff excludes everything');
    }

    /**
     * A cutoff that is before all fixture migrations must not exclude them.
     */
    public function testMigrationsAfterCutoffStillRun(): void
    {
        // Arrange — cutoff well before the 2026-06-01 fixture timestamps
        $app = $this->makeApp(['migration_cutoff' => '2020-01-01 00:00:00']);

        // Act
        $app->triggerAutoMigrations();

        // Assert — autorun migration still ran
        $this->assertTrue(
            $this->tableExists('am_autorun_test'),
            'Post-cutoff migrations must run even when a cutoff is set'
        );
    }

    // -----------------------------------------------------------------------
    // 6. Fast path exits early when nothing is pending
    // -----------------------------------------------------------------------

    /**
     * After all migrations have run, the fast slug check must detect no pending
     * migrations and return without loading any PHP migration files.
     *
     * Verified indirectly: second Application instance on the same DB must
     * not recreate a table that was dropped post-first-run.
     */
    public function testFastPathExitsEarlyWhenNothingPending(): void
    {
        // First run populates history
        $app1 = $this->makeApp();
        $app1->triggerAutoMigrations();
        $this->db->query("DROP TABLE IF EXISTS `am_autorun_test`");

        // Second run: fast path (history has all slugs) must exit immediately
        $app2 = $this->makeApp();
        $app2->triggerAutoMigrations();

        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'Fast path must exit before loading migration PHP files when nothing is pending'
        );
    }

    // -----------------------------------------------------------------------
    // 7. hasPendingFromSlugs against real MySQL DB
    // -----------------------------------------------------------------------

    /**
     * hasPendingFromSlugs() must return true when the history table does not
     * yet exist (fresh install — table missing triggers the "all pending" path).
     */
    public function testHasPendingReturnsTrueOnFreshInstall(): void
    {
        // Arrange — history table does not exist
        $runner  = new MigrationRunner($this->db, $this->historyTable);
        $slugMap = MigrationLoader::slugsFromDirectories([$this->fixtureDir]);
        $this->assertNotEmpty($slugMap, 'Pre-condition: fixture dir must have slugs');

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap);

        // Assert
        $this->assertTrue($result, 'hasPendingFromSlugs must return true when history table is missing');
    }

    /**
     * hasPendingFromSlugs() must return false after the autorun migration ran
     * (its slug is in the history table with result=1).
     */
    public function testHasPendingReturnsFalseAfterMigrationsRan(): void
    {
        // Arrange — run migrations to populate history
        $app = $this->makeApp();
        $app->triggerAutoMigrations();

        // Only check the autorun slug (manual-only is never recorded)
        $slugMap = ['am_create_autorun_table' => '2026_06_01_000001'];
        $runner  = new MigrationRunner($this->db, $this->historyTable);

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap);

        // Assert
        $this->assertFalse($result, 'hasPendingFromSlugs must return false after migration ran');
    }
}

// =============================================================================
// TestableApplication — MySQL flavour
// =============================================================================

/**
 * Concrete Application subclass that bypasses the constructor and injects
 * test-controlled migration directories and history table.
 *
 * triggerAutoMigrations() calls the protected runAutoMigrations() directly so
 * tests do not need to exercise the full exec() controller flow.
 */
class MysqlTestableApplication extends Application
{
    private array $testDirs;
    private string $testHistoryTable;

    public function __construct(
        Database $db,
        array $dirs,
        string $historyTable,
        array $appInfo = []
    ) {
        // Bypass complex parent constructor
        $this->database            = $db;
        $this->testDirs            = $dirs;
        $this->testHistoryTable    = $historyTable;
        $this->applicationInfo     = $appInfo;
    }

    protected function getFrameworkMigrationDirs(): array
    {
        return $this->testDirs;
    }

    protected function getMigrationHistoryTable(): string
    {
        return $this->testHistoryTable;
    }

    /** Exposes the protected runAutoMigrations() for direct test invocation. */
    public function triggerAutoMigrations(): void
    {
        $this->runAutoMigrations();
    }
}
