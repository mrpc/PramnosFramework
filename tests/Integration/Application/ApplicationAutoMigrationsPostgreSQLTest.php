<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Integration tests for Application::runAutoMigrations() against PostgreSQL 14.
 *
 * Mirror of ApplicationAutoMigrationsMySQLTest but targeting the PostgreSQL
 * Docker container.  The same fixture migrations are used (they use portable
 * SQL that runs on both engines).
 *
 * Schema isolation:
 *   am_autorun_test      — created by the autoExecute=true fixture
 *   am_manual_only_test  — must NOT be created by auto-run
 *   am_pg_schemaversion  — isolated history table for this suite
 *
 * Requires the Docker PostgreSQL container (host: pg, port: 5432).
 */
class ApplicationAutoMigrationsPostgreSQLTest extends TestCase
{
    /** @var Database Live PostgreSQL connection. */
    private Database $db;

    /** @var string Isolated history table for this test suite. */
    private string $historyTable = 'am_pg_schemaversion';

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
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->connect(true);

        $this->db->query('DROP TABLE IF EXISTS "am_autorun_test"');
        $this->db->query('DROP TABLE IF EXISTS "am_manual_only_test"');
        $this->db->query("DROP TABLE IF EXISTS \"{$this->historyTable}\"");

        $this->fixtureDir = __DIR__ . '/Fixtures/AutoMigrations';
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "am_autorun_test"');
        $this->db->query('DROP TABLE IF EXISTS "am_manual_only_test"');
        $this->db->query("DROP TABLE IF EXISTS \"{$this->historyTable}\"");
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeApp(array $appInfo = []): PgTestableApplication
    {
        return new PgTestableApplication($this->db, [$this->fixtureDir], $this->historyTable, $appInfo);
    }

    private function tableExists(string $table): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt
                   FROM information_schema.tables
                  WHERE table_schema = 'public'
                    AND table_name = %s",
                $table
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    private function ranSlugs(): array
    {
        try {
            $result = $this->db->query(
                "SELECT \"key\" FROM \"{$this->historyTable}\" WHERE \"result\" = 1"
            );
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
     * runAutoMigrations() must run autoExecute=true migrations and record
     * them in the history table on PostgreSQL.
     */
    public function testAutoExecuteTrueMigrationRunsAutomatically(): void
    {
        // Arrange
        $app = $this->makeApp();

        // Act
        $app->triggerAutoMigrations();

        // Assert
        $this->assertTrue(
            $this->tableExists('am_autorun_test'),
            'autoExecute=true migration must create its table on PostgreSQL'
        );
        $this->assertContains(
            'am_create_autorun_table',
            $this->ranSlugs(),
            'Ran migration must be recorded in history on PostgreSQL'
        );
    }

    // -----------------------------------------------------------------------
    // 2. autoExecute=false is never auto-run
    // -----------------------------------------------------------------------

    /**
     * autoExecute=false migrations must be ignored by runAutoMigrations()
     * on PostgreSQL just as on MySQL.
     */
    public function testAutoExecuteFalseMigrationIsNeverAutoRun(): void
    {
        // Arrange
        $app = $this->makeApp();

        // Act
        $app->triggerAutoMigrations();

        // Assert
        $this->assertFalse(
            $this->tableExists('am_manual_only_test'),
            'autoExecute=false migration must NOT run automatically on PostgreSQL'
        );
    }

    // -----------------------------------------------------------------------
    // 3. Already-ran migrations not re-run across requests
    // -----------------------------------------------------------------------

    /**
     * A second Application instance (simulating a new request) must not
     * re-execute migrations that are already recorded in the history table.
     */
    public function testAlreadyRanMigrationsNotReRunOnNextRequest(): void
    {
        // First request
        $app1 = $this->makeApp();
        $app1->triggerAutoMigrations();
        $this->assertTrue($this->tableExists('am_autorun_test'), 'Pre-condition failed');

        $this->db->query('DROP TABLE IF EXISTS "am_autorun_test"');

        // Second request
        $app2 = $this->makeApp();
        $app2->triggerAutoMigrations();

        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'Already-ran migration must not be re-executed on next request (PostgreSQL)'
        );
    }

    // -----------------------------------------------------------------------
    // 4. Per-instance flag prevents double-run within same request
    // -----------------------------------------------------------------------

    /**
     * Two consecutive runAutoMigrations() calls on the same Application
     * instance must only run migrations once.
     */
    public function testPerInstanceFlagPreventsDoubleRunWithinSameRequest(): void
    {
        $app = $this->makeApp();
        $app->triggerAutoMigrations();

        $this->db->query('DROP TABLE IF EXISTS "am_autorun_test"');

        $app->triggerAutoMigrations(); // second call — must be a no-op

        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'Per-instance flag must prevent double-run on PostgreSQL'
        );
    }

    // -----------------------------------------------------------------------
    // 5. migration_cutoff skips pre-cutoff migrations
    // -----------------------------------------------------------------------

    /**
     * A cutoff date after all fixture timestamps must skip all migrations.
     */
    public function testMigrationCutoffSkipsAllPreCutoffMigrations(): void
    {
        $app = $this->makeApp(['migration_cutoff' => '2099-01-01 00:00:00']);
        $app->triggerAutoMigrations();

        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'All pre-cutoff migrations must be skipped on PostgreSQL'
        );
        $this->assertEmpty($this->ranSlugs());
    }

    /**
     * A cutoff before all fixture timestamps must not prevent them from running.
     */
    public function testMigrationsAfterCutoffStillRun(): void
    {
        $app = $this->makeApp(['migration_cutoff' => '2020-01-01 00:00:00']);
        $app->triggerAutoMigrations();

        $this->assertTrue(
            $this->tableExists('am_autorun_test'),
            'Post-cutoff migrations must still run on PostgreSQL'
        );
    }

    // -----------------------------------------------------------------------
    // 5b. Application-declared migration directories (app.php 'migrations')
    // -----------------------------------------------------------------------

    /**
     * Migrations declared by the app (app.php 'migrations' => ['paths' => ...])
     * must auto-run on the same fast-path as framework migrations, even with the
     * framework directories switched off — this is how an app baseline auto-runs.
     */
    public function testAppDeclaredMigrationsRunWithFrameworkDisabled(): void
    {
        $app = $this->makeApp([
            'migrations' => ['paths' => [$this->fixtureDir], 'framework' => false],
        ]);

        $app->triggerAutoMigrations();

        $this->assertTrue(
            $this->tableExists('am_autorun_test'),
            'App-declared migration must auto-run even when framework dirs are disabled'
        );
    }

    /**
     * With 'framework' => false and no app paths, auto-run touches nothing — the
     * escape hatch an app uses when its schema collides with a framework table
     * (e.g. a bespoke sessions layout): the framework directories are skipped
     * entirely even though getFrameworkMigrationDirs() would otherwise yield them.
     */
    public function testFrameworkDisabledSkipsFrameworkDirs(): void
    {
        $app = $this->makeApp(['migrations' => ['framework' => false]]);

        $app->triggerAutoMigrations();

        $this->assertFalse(
            $this->tableExists('am_autorun_test'),
            'Framework migration dirs must be skipped when framework => false'
        );
        $this->assertEmpty($this->ranSlugs());
    }

    /**
     * The public migrate() entry point (used by apps outside the init()/exec()
     * lifecycle) runs the app-declared migrations. Here the DB is already wired,
     * so migrate() proceeds straight to the auto-run.
     */
    public function testPublicMigrateRunsAppDeclaredMigrations(): void
    {
        $app = $this->makeApp([
            'migrations' => ['paths' => [$this->fixtureDir], 'framework' => false],
        ]);

        $app->migrate();

        $this->assertTrue(
            $this->tableExists('am_autorun_test'),
            'Public migrate() must run app-declared migrations'
        );
    }

    // -----------------------------------------------------------------------
    // 6 & 7. hasPendingFromSlugs against real PostgreSQL DB
    // -----------------------------------------------------------------------

    /**
     * hasPendingFromSlugs() must return true on a fresh PostgreSQL install
     * where the history table does not exist yet.
     */
    public function testHasPendingReturnsTrueOnFreshInstall(): void
    {
        $runner  = new MigrationRunner($this->db, $this->historyTable);
        $slugMap = MigrationLoader::slugsFromDirectories([$this->fixtureDir]);
        $this->assertNotEmpty($slugMap, 'Pre-condition: fixture dir must have slugs');

        $result = $runner->hasPendingFromSlugs($slugMap);

        $this->assertTrue($result, 'hasPendingFromSlugs must return true on fresh PostgreSQL install');
    }

    /**
     * hasPendingFromSlugs() must return false after migrations have run on PG.
     */
    public function testHasPendingReturnsFalseAfterMigrationsRan(): void
    {
        $app = $this->makeApp();
        $app->triggerAutoMigrations();

        $slugMap = ['am_create_autorun_table' => '2026_06_01_000001'];
        $runner  = new MigrationRunner($this->db, $this->historyTable);

        $result = $runner->hasPendingFromSlugs($slugMap);

        $this->assertFalse($result, 'hasPendingFromSlugs must return false after migration ran on PG');
    }
}

// =============================================================================
// TestableApplication — PostgreSQL flavour
// =============================================================================

/**
 * Concrete Application subclass for PostgreSQL integration tests.
 * Identical structure to MysqlTestableApplication but kept separate to allow
 * independent class declarations in the same PHP process.
 */
class PgTestableApplication extends Application
{
    private array $testDirs;
    private string $testHistoryTable;

    public function __construct(
        Database $db,
        array $dirs,
        string $historyTable,
        array $appInfo = []
    ) {
        $this->database         = $db;
        $this->testDirs         = $dirs;
        $this->testHistoryTable = $historyTable;
        $this->applicationInfo  = $appInfo;
    }

    protected function getFrameworkMigrationDirs(): array
    {
        return $this->testDirs;
    }

    protected function getMigrationHistoryTable(): string
    {
        return $this->testHistoryTable;
    }

    public function triggerAutoMigrations(): void
    {
        $this->runAutoMigrations();
    }
}
