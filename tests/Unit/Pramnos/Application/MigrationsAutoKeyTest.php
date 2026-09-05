<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * «Do not run by yourself» is not «do not run at all».
 *
 * WHAT: `migrations.auto => false` stops the entry points nobody asked for by
 *       name, and nothing else. The explicit paths ignore it.
 * WHY:  an installation that applies migrations during a watched deploy window
 *       had no way to say so. `migrations.framework => false` was the only
 *       switch, and `migrationScope()` reads it for auto-run *and* for the CLI —
 *       so turning it off to stop the automatic run also emptied the
 *       `pramnos migrate` it was turned off in order to perform. One answer for
 *       two questions.
 *
 * The reason it prompted a change: a batch putting five indexes on a compressed
 * hypertable, where a plain `CREATE INDEX` locks each chunk while it builds. The
 * first visitor's request after a deploy is the wrong place to find that out.
 *
 * **The distinction is the thing under test, not the key.** Gating `upgrade()`
 * itself rather than its automatic call site turns the switch into «never run»,
 * and that has a measured cost: an installation that did it took 738 test errors
 * from a bootstrap that was calling `upgrade()` on purpose.
 */
#[CoversClass(Application::class)]
class MigrationsAutoKeyTest extends TestCase
{
    /**
     * An application with the given `app.php` migrations block.
     *
     * @param array<string, mixed> $migrations
     */
    private function app(array $migrations): AutoKeyProbeApplication
    {
        return new AutoKeyProbeApplication(['migrations' => $migrations]);
    }

    // ── the key itself ───────────────────────────────────────────────────────

    /**
     * Absent means on, so nothing that does not set it changes.
     */
    public function testTheKeyDefaultsToOn(): void
    {
        $this->assertTrue($this->app([])->autoEnabled());
        $this->assertTrue((new AutoKeyProbeApplication([]))->autoEnabled());
    }

    /**
     * `false` turns the automatic run off.
     */
    public function testItCanBeTurnedOff(): void
    {
        $this->assertFalse($this->app(['auto' => false])->autoEnabled());
    }

    /**
     * It is a separate question from which directories are in scope.
     *
     * This is the whole point of adding a key rather than reusing the one that
     * was there: `framework => false` empties the CLI too, and an installation
     * that wants to migrate by hand needs the CLI to be full.
     */
    public function testItIsIndependentOfTheFrameworkDirectoriesKey(): void
    {
        // Arrange — automatic off, framework directories still in scope
        $app = $this->app(['auto' => false]);

        // Assert
        $this->assertFalse($app->autoEnabled(), 'the automatic run is off');
        $this->assertTrue(
            $app->includesFramework(),
            'and the framework directories are still in scope, so the CLI has work to do'
        );

        // And the other way round: scope off, automatic still on
        $other = $this->app(['framework' => false]);
        $this->assertTrue($other->autoEnabled());
        $this->assertFalse($other->includesFramework());
    }

    /**
     * `migrationScope()` does not read it.
     *
     * The scope is what `pramnos migrate` and `migrate:status` resolve through.
     * If the automatic key reached it, turning the automatic run off would empty
     * the command as well — which is the defect this key exists to separate.
     */
    public function testTheScopeIsUnaffectedByTheAutomaticKey(): void
    {
        // Arrange
        $on  = $this->app([]);
        $off = $this->app(['auto' => false]);

        // Act + Assert
        $this->assertSame(
            $on->migrationScope()['dirs'],
            $off->migrationScope()['dirs'],
            'what the CLI can see must not depend on whether the framework runs by itself'
        );
    }

    // ── the automatic path stands down ───────────────────────────────────────

    /**
     * `runAutoMigrations()` returns before doing any work when the key is off.
     */
    public function testTheAutomaticRunStandsDown(): void
    {
        // Arrange
        $app = $this->app(['auto' => false]);
        $app->database = $this->createMock(\Pramnos\Database\Database::class);

        // Act
        $app->runAutoMigrationsNow();

        // Assert
        $this->assertFalse($app->reachedTheScopeLookup);
    }

    /**
     * With the key on it gets on with it.
     *
     * The complement, or the test above would pass against a method that never
     * does anything at all.
     */
    public function testWithTheKeyOnTheAutomaticRunProceeds(): void
    {
        // Arrange
        $app = $this->app([]);
        $app->database = $this->createMock(\Pramnos\Database\Database::class);

        // Act
        $app->runAutoMigrationsNow();

        // Assert
        $this->assertTrue($app->reachedTheScopeLookup);
    }

    // ── the explicit path ignores it ─────────────────────────────────────────

    /**
     * `runPendingMigrations()` runs both systems with the key off.
     *
     * This is the distinction the filing asked to have pinned. An installation
     * that turned the automatic run off has two halves to catch up — the legacy
     * `migrations.php` ledger and the framework's own — and no reason to have to
     * remember that they are two.
     */
    public function testTheExplicitPathIgnoresTheKeyAndRunsBothSystems(): void
    {
        // Arrange
        $app = $this->app(['auto' => false]);
        $app->database = $this->createMock(\Pramnos\Database\Database::class);

        // Act
        $app->runPendingMigrations();

        // Assert — both halves, despite auto => false
        $this->assertTrue($app->ranTheLegacyUpgrade, 'the legacy ledger must be caught up');
        $this->assertTrue($app->reachedTheScopeLookup, 'and the framework migrations too');
    }

    /**
     * `upgrade()` called directly is not gated.
     *
     * The gate is on the automatic *call site* in `exec()`, not inside the
     * method. Putting it inside is what turns "do not run by yourself" into "do
     * not run at all", and it is what cost an installation 738 test errors from a
     * bootstrap that was calling `upgrade()` deliberately.
     */
    public function testUpgradeCalledDirectlyIsNotGated(): void
    {
        // Arrange
        $app = $this->app(['auto' => false]);

        // Act — the framework's own upgrade(), reading the fixture ledger
        $app->upgrade();

        // Assert
        $this->assertTrue(
            $app->ranTheLegacyUpgrade,
            'an explicit upgrade() must run whatever the automatic key says'
        );
        $this->assertContains(
            'AutoKeyLegacyMigration',
            $app->migrationsRun,
            'and must reach the migrations its ledger lists'
        );
    }
}

/**
 * An application that records which migration paths it reached.
 *
 * `runAutoMigrations()` and `upgrade()` are stopped at their first real step, so
 * the test needs neither a migration tree nor a database behind it — what is
 * being asserted is which of them was entered, not what they then did.
 */
class AutoKeyProbeApplication extends Application
{
    public bool $reachedTheScopeLookup = false;
    public bool $ranTheLegacyUpgrade   = false;

    /** @var array<int, string> Legacy migration classes upgrade() asked for */
    public array $migrationsRun = [];

    /** @param array<string, mixed> $info */
    public function __construct(array $info = [])
    {
        $this->applicationInfo = $info + ['namespace' => 'App'];
    }

    public function autoEnabled(): bool
    {
        return $this->autoMigrationsEnabled();
    }

    public function includesFramework(): bool
    {
        return $this->autoMigrationsIncludeFramework();
    }

    public function runAutoMigrationsNow(): void
    {
        $this->runAutoMigrations();
    }

    /** The first real step of runAutoMigrations(), recorded and stopped. */
    public function migrationScope(bool $includeConventionalAppDir = false): array
    {
        $this->reachedTheScopeLookup = true;

        return ['dirs' => [], 'skipped' => [], 'cutoff' => ''];
    }

    /**
     * **Not overridden: `upgrade()` is the method under test.**
     *
     * The first version of this probe did override it, and the test then passed
     * against a deliberately broken framework — because it was asserting the
     * override, not the gate's location. What is stubbed instead is the two
     * things `upgrade()` reaches that need a filesystem and a database.
     */
    public function runMigration($class)
    {
        $this->ranTheLegacyUpgrade = true;
        $this->migrationsRun[]     = $class;
    }

    /** Nothing is recorded as applied, so upgrade() has work to do. */
    public function checkversion($version = null)
    {
        return false;
    }

    protected function isInMaintenance(): bool
    {
        return false;
    }
}
