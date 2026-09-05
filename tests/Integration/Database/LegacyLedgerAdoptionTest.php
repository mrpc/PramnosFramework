<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * An application on the legacy version ledger can say «these already ran».
 *
 * WHAT: `adoptLegacyVersions()` records the slug of every migration whose
 *       `$version` the ledger already holds, and runs none of them.
 * WHY:  both systems write to `schemaversion` and key it differently — the
 *       legacy path stores `$version` (`0.010`), the runner stores the slug
 *       (`migration0010`). An application that migrated for years through the
 *       old path has a full ledger the runner cannot read a row of, so
 *       `migrate:status` calls every one of those pending and `migrate` would
 *       run them again against a schema that already has their changes. Every
 *       such application was writing its own one-off migration to fix this,
 *       after finding out that `migrate:status` had been lying to it.
 *
 * The `migration_cutoff` does not help here and that is worth knowing: it filters
 * on the filename timestamp, and a legacy `MigrationNNNN` class has none —
 * `filterCutoff()` lets a timestampless migration through by design.
 */
class LegacyLedgerAdoptionTest extends TestCase
{
    private Database $db;
    private Application $app;

    private const HISTORY = 'schemaversion_adopttest';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        if (!$this->db->connect(false)) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB container not reachable');
        }

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app     = $app;

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        foreach ([self::HISTORY, 'adopt_probe_table'] as $table) {
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
            } catch (\Throwable) {
                // Best-effort teardown.
            }
        }
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->db, self::HISTORY);
    }

    /** Put a legacy row in the ledger, the way Application::runMigration() does. */
    private function recordLegacyVersion(string $version): void
    {
        $this->runner()->ensureHistoryTable();
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO "' . self::HISTORY . '" ("key") VALUES (%s)',
                $version
            )
        );
    }

    private function historyHas(string $key): bool
    {
        $row = $this->db->query(
            $this->db->prepareQuery(
                'SELECT COUNT(*) AS c FROM "' . self::HISTORY . '" WHERE "key" = %s',
                $key
            )
        );

        return (int) ($row->fields['c'] ?? 0) > 0;
    }

    // ── Adoption ─────────────────────────────────────────────────────────────

    /**
     * A migration whose version the ledger holds is recorded under its slug.
     */
    public function testAMigrationRecordedByVersionIsAdoptedBySlug(): void
    {
        // Arrange — the legacy path recorded '0.010' years ago
        $this->recordLegacyVersion('0.010');
        $migration = $this->legacyMigration('0.010', 'migration0010');

        // Act
        $adopted = $this->runner()->adoptLegacyVersions([$migration]);

        // Assert
        $this->assertSame(['migration0010' => '0.010'], $adopted);
        $this->assertTrue($this->historyHas('migration0010'));
    }

    /**
     * It records; it does not run.
     *
     * The work was done by the other path years ago. Executing it again is the
     * outcome this exists to prevent, so the probe migration fails loudly if its
     * `up()` is ever reached.
     */
    public function testAdoptionDoesNotExecuteTheMigration(): void
    {
        // Arrange
        $this->recordLegacyVersion('0.011');
        $migration = new class ($this->app) extends Migration {
            public $version = '0.011';
            public function getSlug(): string { return 'migration0011'; }
            public function up(): void
            {
                $this->schema()->createTable('adopt_probe_table', fn($t) => $t->increments('id'));
            }
        };

        // Act
        $this->runner()->adoptLegacyVersions([$migration]);

        // Assert — recorded, and its up() never touched the schema
        $this->assertTrue($this->historyHas('migration0011'));
        $this->assertFalse(
            $this->db->schema()->hasTable('adopt_probe_table'),
            'adoption must record, never execute'
        );
    }

    /**
     * A migration the ledger has never heard of stays pending.
     *
     * The only evidence that it ran would be this method inventing it, and a
     * migration wrongly marked applied is a schema change that silently never
     * happens.
     */
    public function testAMigrationTheLedgerDoesNotKnowIsLeftAlone(): void
    {
        // Arrange — the ledger knows 0.010; the migration claims 0.099
        $this->recordLegacyVersion('0.010');
        $migration = $this->legacyMigration('0.099', 'migration0099');

        // Act
        $adopted = $this->runner()->adoptLegacyVersions([$migration]);

        // Assert
        $this->assertSame([], $adopted);
        $this->assertFalse($this->historyHas('migration0099'));
    }

    /**
     * A slug the runner already knows is not written twice.
     */
    public function testAnAlreadyKnownSlugIsNotAdoptedAgain(): void
    {
        // Arrange — both conventions already present
        $this->recordLegacyVersion('0.012');
        $this->recordLegacyVersion('migration0012');
        $migration = $this->legacyMigration('0.012', 'migration0012');

        // Act
        $adopted = $this->runner()->adoptLegacyVersions([$migration]);

        // Assert
        $this->assertSame([], $adopted);
    }

    /**
     * `--dry-run` reports and writes nothing.
     *
     * Marking migrations as applied is not a thing to discover the effect of
     * afterwards, so the command offers a way to look first.
     */
    public function testADryRunReportsWithoutWriting(): void
    {
        // Arrange
        $this->recordLegacyVersion('0.013');
        $migration = $this->legacyMigration('0.013', 'migration0013');

        // Act
        $adopted = $this->runner()->adoptLegacyVersions([$migration], true);

        // Assert
        $this->assertSame(['migration0013' => '0.013'], $adopted);
        $this->assertFalse($this->historyHas('migration0013'), 'a dry run writes nothing');
    }

    /**
     * A migration with no version is not adoptable, and does not throw.
     *
     * Every modern migration is this shape — a timestamped file with no
     * `$version` — so it is the common case passing through, not an edge.
     */
    public function testAMigrationWithoutAVersionIsSkipped(): void
    {
        // Arrange
        $this->recordLegacyVersion('0.014');
        $modern = new class ($this->app) extends Migration {
            public function getSlug(): string { return 'create_something_table'; }
        };

        // Act
        $adopted = $this->runner()->adoptLegacyVersions([$modern]);

        // Assert
        $this->assertSame([], $adopted);
    }

    // ── The empty-adopter pattern ────────────────────────────────────────────

    /**
     * A migration with nothing but `$dependencies` pulls in a framework one.
     *
     * WHAT: an application migration with no `up()` at all, declaring one
     *       dependency, causes that dependency to run and both to be recorded.
     * WHY:  it is the supported way for an application to adopt a single
     *       framework migration it does not otherwise run — because the feature
     *       is off, or because `migrations.framework` is false. The mechanism was
     *       documented with a worked example that *did* work in its `up()`, so
     *       whether an empty one was legitimate or an accident was not stated
     *       anywhere. It is legitimate, and this pins it.
     */
    public function testAnEmptyMigrationCanAdoptAFrameworkMigrationByDependency(): void
    {
        // Arrange — the framework migration exists only in the on-demand pool
        $dependency = new class ($this->app) extends Migration {
            public function getSlug(): string { return 'adopt_dep_creates_table'; }
            public function up(): void
            {
                $this->schema()->createTable('adopt_probe_table', fn($t) => $t->increments('id'));
            }
        };

        // …and the application's migration is nothing but a dependency
        $adopter = new class ($this->app) extends Migration {
            public array $dependencies = ['adopt_dep_creates_table'];
            public function getSlug(): string { return 'adopt_wants_the_table'; }
        };

        $runner = $this->runner();
        $runner->setDependencyPool(fn(): array => ['adopt_dep_creates_table' => $dependency]);

        // Act
        $result = $runner->run([$adopter]);

        // Assert — the dependency ran, and ran first
        $this->assertSame(
            ['adopt_dep_creates_table', 'adopt_wants_the_table'],
            $result['ran'],
            'the dependency has to be ordered before the migration that needs it'
        );
        $this->assertSame([], $result['failed']);
        $this->assertTrue(
            $this->db->schema()->hasTable('adopt_probe_table'),
            'an empty adopter is only useful if the dependency actually does its work'
        );

        // Assert — and both are recorded, so neither runs again
        $this->assertTrue($this->historyHas('adopt_dep_creates_table'));
        $this->assertTrue($this->historyHas('adopt_wants_the_table'));
    }

    /**
     * A migration whose only content is a `$version` and a slug.
     */
    private function legacyMigration(string $version, string $slug): Migration
    {
        return new class ($this->app, $version, $slug) extends Migration {
            public $version = '';
            private string $slug;

            public function __construct($app, string $version, string $slug)
            {
                parent::__construct($app);
                $this->version = $version;
                $this->slug    = $slug;
            }

            public function getSlug(): string
            {
                return $this->slug;
            }
        };
    }
}
