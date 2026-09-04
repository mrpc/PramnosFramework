<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Database\MigrationLoader;

/**
 * Unit tests for Application::migrationScope() and MigrationLoader::scopeFor().
 *
 * WHAT: the single answer to "which migrations apply to this installation" —
 *       the `features` gate, `app.php`'s `migration_cutoff`, and the set that is
 *       deliberately out of scope with the reason for each.
 * WHY:  there were two answers and they disagreed. Auto-run applied both
 *       filters; the CLI, the MCP status tools and the dev panel read
 *       `MigrationLoader::resolveDefaultDirectories()`, which applies neither.
 *       On an installation whose schema predates the migration system that is
 *       the difference between "nothing to migrate" and 44 pending migrations,
 *       42 of them the baseline epoch the cutoff exists to skip — and `migrate`
 *       would have attempted every one.
 *
 * The two filters are asserted separately and from both directions (present and
 * absent), because either one being silently dropped reproduces the bug on its
 * own.
 *
 * FeatureRegistry is static global state, so it is reset around every test.
 */
#[CoversClass(Application::class)]
#[CoversClass(MigrationLoader::class)]
class MigrationScopeTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::reset();
        FeatureRegistry::initDefaults();
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
    }

    /**
     * An Application with a given app.php, and no database.
     *
     * `database` stays null throughout this class on purpose: the console
     * reaches an application without initialising it, so every filter here has
     * to be answerable without a connection.
     *
     * @param array<string, mixed> $info
     */
    private function makeApp(array $info): Application
    {
        return new class ($info) extends Application {
            /** @param array<string, mixed> $info */
            public function __construct(array $info)
            {
                $this->appName         = 'test_app';
                $this->applicationInfo = $info;
                $this->database        = null;
            }
        };
    }

    // =========================================================================
    // The cutoff filter
    // =========================================================================

    /**
     * app.php's `migration_cutoff` is read and normalised to a comparable form.
     *
     * The configuration is written as a datetime; migration filenames carry
     * `YYYY_MM_DD_HHmmss`. The scope returns the latter, because that is what
     * MigrationRunner::filterCutoff() compares against — a scope that handed
     * back the raw string would compare `'2026-01-01 00:00:00'` to
     * `'2020_01_01_000001'` and answer nonsense.
     */
    public function testCutoffIsReadFromTheApplicationConfigurationAndNormalised(): void
    {
        // Arrange
        $app = $this->makeApp(['migration_cutoff' => '2026-01-01 00:00:00']);

        // Act
        $scope = $app->migrationScope();

        // Assert
        $this->assertSame('2026_01_01_000000', $scope['cutoff']);
    }

    /**
     * The baseline epoch convention documented for legacy installations works.
     *
     * `2020_01_02_000000` is the cutoff such installations are told to set, and
     * it must sort strictly after every `2020_01_01_*` baseline migration and
     * strictly before anything later. Asserted as a string comparison because
     * that is exactly how the runner applies it.
     */
    public function testTheDocumentedBaselineCutoffExcludesTheBaselineEpochOnly(): void
    {
        // Arrange
        $app    = $this->makeApp(['migration_cutoff' => '2020-01-02 00:00:00']);
        $cutoff = $app->migrationScope()['cutoff'];

        // Act + Assert — at-or-before the cutoff is out, strictly after is in
        $this->assertLessThanOrEqual(0, strcmp('2020_01_01_000001', $cutoff));
        $this->assertLessThanOrEqual(0, strcmp('2020_01_01_999999', $cutoff));
        $this->assertGreaterThan(0, strcmp('2026_05_21_000046', $cutoff));
    }

    /**
     * No configured cutoff is the empty string, never a guess.
     *
     * Callers branch on `!== ''` to decide whether to filter at all, so a
     * default of "today" or "epoch" would either skip everything or nothing on
     * an application that never asked for either.
     */
    public function testNoConfiguredCutoffMeansNoFiltering(): void
    {
        // Arrange + Act
        $scope = $this->makeApp([])->migrationScope();

        // Assert
        $this->assertSame('', $scope['cutoff']);
    }

    /**
     * An unparseable cutoff is treated as none rather than as a barrier.
     *
     * A typo in app.php must not silently stop every migration from running,
     * which is what a cutoff of "now" or a raw invalid string would do.
     */
    public function testAnUnparseableCutoffIsTreatedAsNone(): void
    {
        // Arrange + Act
        $scope = $this->makeApp(['migration_cutoff' => 'not a date'])->migrationScope();

        // Assert
        $this->assertSame('', $scope['cutoff']);
    }

    // =========================================================================
    // The features filter
    // =========================================================================

    /**
     * A disabled feature's directory is out of `dirs` and named in `skipped`.
     *
     * Both halves matter and for different reasons. Out of `dirs` is what stops
     * `migrate` creating tables the installation declined. Named in `skipped`
     * is what lets `migrate:status` say *why* — a report that simply omitted the
     * rows would be as unhelpful as the one that called them pending, and that
     * silence is what sent somebody looking for this in the first place.
     */
    public function testADisabledFeatureIsExcludedAndTheReasonIsRecorded(): void
    {
        // Arrange — only 'auth' enabled, so e.g. broadcasting is gated off
        FeatureRegistry::loadFromConfig(['auth']);
        $app = $this->makeApp(['features' => ['auth']]);

        // Act
        $scope = $app->migrationScope();

        // Assert — nothing enabled-but-missing, and the reason is the feature name
        $this->assertNotEmpty($scope['skipped'], 'some framework feature must be gated off');
        foreach ($scope['skipped'] as $dir => $reason) {
            $this->assertStringStartsWith('feature: ', $reason);
            $this->assertSame('feature: ' . basename($dir), $reason);
            $this->assertNotContains($dir, $scope['dirs'],
                'a skipped directory must not also be reported as eligible');
        }
    }

    /**
     * Enabling a feature moves its directory from skipped to eligible.
     *
     * The same installation, the same disk, one line of app.php different — this
     * is the assertion that the gate is actually reading the configuration
     * rather than a fixed list.
     */
    public function testEnablingAFeatureMovesItsDirectoryIntoScope(): void
    {
        // Arrange — the same feature, off and then on
        FeatureRegistry::loadFromConfig([]);
        $withoutIt = $this->makeApp(['features' => []])->migrationScope();

        FeatureRegistry::reset();
        FeatureRegistry::initDefaults();
        FeatureRegistry::loadFromConfig(['broadcasting']);
        $withIt = $this->makeApp(['features' => ['broadcasting']])->migrationScope();

        // Act
        $gainedDirs = array_diff($withIt['dirs'], $withoutIt['dirs']);

        // Assert — broadcasting arrived in dirs and left skipped
        $this->assertNotEmpty($gainedDirs, 'enabling a feature must add its directory');
        $this->assertSame(
            ['broadcasting'],
            array_values(array_unique(array_map('basename', $gainedDirs)))
        );
        $this->assertArrayNotHasKey(
            array_values($gainedDirs)[0],
            $withIt['skipped'],
            'an enabled feature must not still be reported as skipped'
        );
    }

    /**
     * `migrations.framework => false` skips every framework directory, with a
     * reason that is not a feature name.
     *
     * An application in this state manages a schema that collides with a
     * framework table. The distinct reason matters to the report: "this feature
     * is off" and "framework migrations are off entirely" are different things
     * for an operator to read, and only one of them is fixed by enabling a
     * feature.
     */
    public function testFrameworkMigrationsCanBeDisabledEntirely(): void
    {
        // Arrange
        FeatureRegistry::loadFromConfig(['auth', 'authserver']);
        $app = $this->makeApp([
            'features'   => ['auth', 'authserver'],
            'migrations' => ['framework' => false],
        ]);

        // Act
        $scope = $app->migrationScope();

        // Assert
        $this->assertSame([], $scope['dirs'], 'no framework directory may be in scope');
        $this->assertNotEmpty($scope['skipped']);
        foreach ($scope['skipped'] as $reason) {
            $this->assertSame('framework migrations disabled', $reason);
        }
    }

    // =========================================================================
    // Application-declared directories
    // =========================================================================

    /**
     * Declared `migrations.paths` are in scope; a non-existent one is dropped.
     */
    public function testDeclaredApplicationPathsAreIncluded(): void
    {
        // Arrange
        FeatureRegistry::loadFromConfig([]);
        $real = sys_get_temp_dir() . '/migscope_' . bin2hex(random_bytes(4));
        mkdir($real);

        try {
            $app = $this->makeApp([
                'migrations' => ['framework' => false, 'paths' => [$real, '/no/such/dir']],
            ]);

            // Act
            $scope = $app->migrationScope();

            // Assert
            $this->assertSame([realpath($real)], $scope['dirs']);
        } finally {
            rmdir($real);
        }
    }

    /**
     * The conventional `app/Migrations` is opt-in, and only when nothing is declared.
     *
     * Auto-run passes false: its directories are exactly what app.php declares,
     * and picking up an undeclared directory would start running migrations on
     * ordinary web requests that never ran there before. The CLI passes true,
     * because it has always listed that directory and must not begin hiding what
     * it finds. The flag exists to keep those two honest rather than to make
     * them identical.
     */
    public function testTheConventionalAppDirectoryIsOptIn(): void
    {
        // Arrange — the directory has to exist for the flag to have anything to
        // find, and it does not exist in this repository, so it is created and
        // removed again. Asserting on an absent directory would pass whatever
        // the flag did.
        FeatureRegistry::loadFromConfig([]);
        $app       = $this->makeApp(['migrations' => ['framework' => false]]);
        $root      = defined('ROOT') ? ROOT : getcwd();
        $created   = $this->createConventionalAppDir($root);

        try {
            // Act
            $withoutFlag = $app->migrationScope(false)['dirs'];
            $withFlag    = $app->migrationScope(true)['dirs'];

            // Assert — false finds nothing, true finds exactly that directory
            $this->assertSame([], $withoutFlag,
                'auto-run must not pick up a directory app.php never declared');
            $this->assertSame(
                [realpath($root . '/app/Migrations')],
                $withFlag,
                'the CLI must keep listing the conventional directory'
            );
        } finally {
            foreach ($created as $dir) {
                rmdir($dir);
            }
        }
    }

    /**
     * Ensure `<root>/app/Migrations` exists; return what had to be created, in
     * the order it must be removed.
     *
     * @return string[]
     */
    private function createConventionalAppDir(string $root): array
    {
        $created = [];
        foreach ([$root . '/app', $root . '/app/Migrations'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir);
                array_unshift($created, $dir);
            }
        }

        return $created;
    }

    /**
     * Declared paths win over the conventional directory even with the flag on.
     *
     * The fallback is for an application that declares nothing. One that does
     * declare its paths has stated where its migrations are, and quietly adding
     * a directory beside them would run migrations it did not list.
     */
    public function testDeclaredPathsSuppressTheConventionalDirectory(): void
    {
        // Arrange — a declared path, plus a conventional directory that exists
        FeatureRegistry::loadFromConfig([]);
        $root     = defined('ROOT') ? ROOT : getcwd();
        $created  = $this->createConventionalAppDir($root);
        $declared = sys_get_temp_dir() . '/migscope_' . bin2hex(random_bytes(4));
        mkdir($declared);

        try {
            $app = $this->makeApp([
                'migrations' => ['framework' => false, 'paths' => [$declared]],
            ]);

            // Act
            $dirs = $app->migrationScope(true)['dirs'];

            // Assert
            $this->assertSame([realpath($declared)], $dirs);
        } finally {
            rmdir($declared);
            foreach ($created as $dir) {
                rmdir($dir);
            }
        }
    }

    // =========================================================================
    // MigrationLoader::scopeFor() — the accessor callers actually use
    // =========================================================================

    /**
     * With no application to ask, every directory on disk is returned uncut.
     *
     * Deliberately permissive: with nothing to read there is no `features` array
     * and no cutoff, and a report that listed too much can at least be read,
     * while one that silently applied a gate it could not actually read cannot.
     */
    public function testScopeForWithoutAnApplicationReportsEverything(): void
    {
        // Act
        $scope = MigrationLoader::scopeFor(null);

        // Assert
        $this->assertSame(MigrationLoader::resolveDefaultDirectories(), $scope['dirs']);
        $this->assertSame([], $scope['skipped']);
        $this->assertSame('', $scope['cutoff']);
    }

    /**
     * An application that answers with nothing usable falls back the same way.
     *
     * This is not hypothetical: a fully-stubbed Application mock returns null
     * from every method, and the callers of this are console commands, MCP tools
     * and a debug panel that each reach an application they did not construct.
     * Before the accessor existed, that produced an "Undefined array key" warning
     * in the middle of the report.
     */
    public function testScopeForToleratesAnApplicationThatAnswersWithNothing(): void
    {
        // Arrange — every method stubbed. Note what such a mock actually returns
        // from migrationScope(): PHPUnit gives the return type's default, so it
        // is `[]` and NOT null. An `is_array()` check therefore passes, and
        // reading `dirs` out of it reports that the installation has no
        // migrations anywhere — which is what the dev panel card and the MCP
        // status tool did until their own tests caught it.
        $mock = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->assertSame([], $mock->migrationScope(true),
            'precondition: an unstubbed array method answers with an empty array');

        // Act
        $scope = MigrationLoader::scopeFor($mock, true);

        // Assert — fell back to the full list rather than trusting the empty one
        $this->assertSame(MigrationLoader::resolveDefaultDirectories(), $scope['dirs']);
        $this->assertNotEmpty($scope['dirs']);
        $this->assertSame([], $scope['skipped']);
        $this->assertSame('', $scope['cutoff']);
    }

    /**
     * An application that really has no directories is believed.
     *
     * The counterpart to the test above, and the reason the fallback cannot key
     * on emptiness: `migrations.framework => false` with no declared paths is a
     * legitimate answer of "nothing", and falling back there would run the whole
     * framework tree on an application that opted out of it — the exact
     * behaviour that option exists to prevent.
     */
    public function testScopeForBelievesAnApplicationWithGenuinelyNoDirectories(): void
    {
        // Arrange
        FeatureRegistry::loadFromConfig([]);
        $app = $this->makeApp(['migrations' => ['framework' => false]]);

        // Act
        $scope = MigrationLoader::scopeFor($app);

        // Assert — empty, not replaced by the default list
        $this->assertSame([], $scope['dirs']);
        $this->assertNotEmpty($scope['skipped'], 'and it says why each one is out');
    }

    /**
     * The accessor passes the real answer straight through.
     */
    public function testScopeForReturnsTheApplicationsAnswer(): void
    {
        // Arrange
        FeatureRegistry::loadFromConfig(['auth']);
        $app = $this->makeApp([
            'features'         => ['auth'],
            'migration_cutoff' => '2026-01-01 00:00:00',
        ]);

        // Act
        $scope = MigrationLoader::scopeFor($app);

        // Assert
        $this->assertSame('2026_01_01_000000', $scope['cutoff']);
        $this->assertSame($app->migrationScope()['dirs'], $scope['dirs']);
        $this->assertNotEmpty($scope['skipped']);
    }
}
