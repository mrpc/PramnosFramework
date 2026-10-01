<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * `MigrationRunner::run()` with an `exclude` list, against a real database.
 *
 * An exclusion is an application saying a table is its own, so three things have to hold
 * in the database itself rather than in a sorted array: the excluded migration's table is
 * never created, a migration that depends on it still runs, and the dependency pool — which
 * exists to pull a missing framework migration in — does not bring it back. The ledger is
 * read too, because a slug recorded as run would make the exclusion permanent.
 *
 * Run against MySQL and TimescaleDB, the PostgreSQL this environment has.
 */
#[CoversClass(MigrationRunner::class)]
class MigrationExcludeTest extends TestCase
{
    private const HISTORY = 'schemaversion_excludetest';

    private Database $db;

    private Application $app;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'       => ['mysql', 'db', 3306],
            'timescaledb' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /**
     * Connects to one database and starts from no test tables and no ledger.
     */
    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db           = new Database();
        $this->db->type     = $type;
        $this->db->server   = $host;
        $this->db->port     = $port;
        $this->db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $this->app     = $app;

        $this->cleanUp();
    }

    /**
     * Drops what the test created.
     */
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->connected) {
            $this->cleanUp();
        }
    }

    /**
     * Drops the probe tables and the ledger, best effort.
     */
    private function cleanUp(): void
    {
        foreach ([self::HISTORY, 'exclude_owned_probe', 'exclude_dependent_probe'] as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
    }

    /**
     * Whether the ledger records $slug.
     */
    private function ledgerHas(string $slug): bool
    {
        return $this->db->queryBuilder()->table(self::HISTORY)->where('key', $slug)->count() > 0;
    }

    /**
     * An excluded migration is not run, and its dependent is.
     *
     * The dependent declares the excluded one as a dependency and the pool can resolve it,
     * which is exactly the situation in which the runner used to pull a framework migration
     * in on its own. Excluded, it must stay out: the table the application owns is not
     * created by the framework, and nothing is recorded for it.
     */
    #[DataProvider('databases')]
    public function testAnExcludedMigrationIsNotRunAndItsDependentIs(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $owned     = new ExcludeOwnedProbe($this->app);
        $dependent = new ExcludeDependentProbe($this->app);
        $runner    = new MigrationRunner($this->db, self::HISTORY);
        $runner->setDependencyPool(fn (): array => ['exclude_owned_probe' => new ExcludeOwnedProbe($this->app)]);

        // Act — the excluded migration is in the batch and in the pool
        $result = $runner->run([$owned, $dependent], [MigrationRunner::OPTION_EXCLUDE => ['exclude_owned_probe']]);

        // Assert — in the database, not only in the result
        $this->assertSame(['exclude_dependent_probe'], $result['ran']);
        $this->assertSame([], $result['failed']);
        $this->assertFalse($this->db->schema()->hasTable('exclude_owned_probe'), 'the excluded table was created');
        $this->assertTrue($this->db->schema()->hasTable('exclude_dependent_probe'), 'the dependent did not run');
        $this->assertFalse($this->ledgerHas('exclude_owned_probe'), 'an excluded slug must not be recorded as run');
        $this->assertTrue($this->ledgerHas('exclude_dependent_probe'));
    }

    /**
     * Removing a slug from the list makes the migration pending again, and it then runs.
     *
     * An exclusion is configuration, not history: nothing about it is written down, so
     * taking it back needs no repair.
     */
    #[DataProvider('databases')]
    public function testLiftingTheExclusionRunsTheMigration(string $type, string $host, int $port): void
    {
        // Arrange — first run with the exclusion
        $this->connect($type, $host, $port);
        $runner = new MigrationRunner($this->db, self::HISTORY);
        $runner->run(
            [new ExcludeOwnedProbe($this->app), new ExcludeDependentProbe($this->app)],
            [MigrationRunner::OPTION_EXCLUDE => ['exclude_owned_probe']]
        );

        // Act — second run without it
        $result = (new MigrationRunner($this->db, self::HISTORY))->run(
            [new ExcludeOwnedProbe($this->app), new ExcludeDependentProbe($this->app)]
        );

        // Assert
        $this->assertSame(['exclude_owned_probe'], $result['ran']);
        $this->assertTrue($this->db->schema()->hasTable('exclude_owned_probe'));
    }
}

/**
 * Creates the table an application would own: the one it excludes.
 */
class ExcludeOwnedProbe extends Migration
{
    /** @var string */
    public $description = 'Probe: the table an exclusion keeps out';

    /** Its slug, fixed, since the class name is not a timestamped file name. */
    public function getSlug(): string
    {
        return 'exclude_owned_probe';
    }

    /** Creates the probe table. */
    public function up(): void
    {
        $this->schema()->createTable('exclude_owned_probe', function ($table) {
            $table->increments('id');
        });
    }

    /** Drops it. */
    public function down(): void
    {
        $this->schema()->dropTableIfExists('exclude_owned_probe');
    }
}

/**
 * Depends on {@see ExcludeOwnedProbe}, as a later framework migration would.
 */
class ExcludeDependentProbe extends Migration
{
    /** @var string */
    public $description = 'Probe: depends on the excluded migration';

    /** @var string[] */
    public array $dependencies = ['exclude_owned_probe'];

    /** Its slug, fixed, since the class name is not a timestamped file name. */
    public function getSlug(): string
    {
        return 'exclude_dependent_probe';
    }

    /** Creates its own table, with no foreign key to the excluded one. */
    public function up(): void
    {
        $this->schema()->createTable('exclude_dependent_probe', function ($table) {
            $table->increments('id');
        });
    }

    /** Drops it. */
    public function down(): void
    {
        $this->schema()->dropTableIfExists('exclude_dependent_probe');
    }
}
