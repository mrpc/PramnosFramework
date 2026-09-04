<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Console\Commands\MigrateStatus;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * migrate:status must report the scope of this installation, not the disk.
 *
 * WHAT: with a `migration_cutoff` and a `features` array that omits features
 *       having migrations, the excluded migrations appear as `Skipped (...)`
 *       with a reason — and never as `Pending`.
 * WHY:  this is the command the upgrade guide tells an operator to run before
 *       migrating. It read `MigrationLoader::resolveDefaultDirectories()`, which
 *       applies neither filter, so on an installation whose schema predates the
 *       migration system it reported 44 pending migrations of which 42 were the
 *       baseline epoch the cutoff exists to skip and 2 belonged to features the
 *       application had declined. Every one of them was reported as work waiting
 *       to be done, and `migrate` would have done it.
 *
 * Both halves are asserted, because only fixing one produces a different bug.
 * Omitting the rows would leave an operator with no way to find out why a
 * migration they can see on disk is never going to run — a CLI that hides
 * pending migrations is as bad as one that invents them.
 *
 * No database is used here. The console reaches an application without
 * initialising it, so "no connection" is an ordinary state for this command and
 * the filters must be answerable without one — which is also what makes this
 * test a cheap reproduction of the reported case.
 */
#[CoversClass(MigrateStatus::class)]
class MigrateStatusScopeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        FeatureRegistry::reset();
        FeatureRegistry::initDefaults();
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
    }

    /**
     * A console application whose app.php is $applicationInfo and whose database
     * was never opened.
     *
     * @param array<string, mixed> $applicationInfo
     */
    private function makeTester(array $applicationInfo): CommandTester
    {
        $consoleApp = new class ('Test', '0.0') extends \Pramnos\Console\Application {
            public function __construct(string $name, string $version)
            {
                // Straight to Symfony's constructor: no command auto-registration,
                // no getInstance(), no define() side effects.
                \Symfony\Component\Console\Application::__construct($name, $version);
                $this->internalApplication = new class extends \Pramnos\Application\Application {
                    /** @var \Pramnos\Database\Database|null */
                    public $database = null;
                    public function __construct() {}
                };
            }
        };
        $consoleApp->internalApplication->applicationInfo = $applicationInfo;

        $command = new MigrateStatus();
        $consoleApp->add($command);

        return new CommandTester($consoleApp->find('migrate:status'));
    }

    /**
     * The baseline epoch is reported as skipped by the cutoff, not as pending.
     *
     * `2020_01_01_*` is the framework's baseline epoch — the migrations written
     * before the migration system existed — and `migration_cutoff` is the
     * setting that exists to skip it. `create_sessions_table` is asserted by
     * name because it is one of those baseline migrations and it ships in the
     * framework tree this test reads.
     */
    public function testTheBaselineEpochIsSkippedByTheCutoffAndNotPending(): void
    {
        // Arrange — the cutoff a legacy installation is documented to set
        FeatureRegistry::loadFromConfig(['auth', 'authserver']);
        $tester = $this->makeTester([
            'features'         => ['auth', 'authserver'],
            'migration_cutoff' => '2020-01-02 00:00:00',
        ]);

        // Act
        $exit    = $tester->execute([]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Skipped', $display);
        $this->assertStringContainsString('cutoff', $display);
        $this->assertStringContainsString('do not apply to this installation', $display);
        // The cutoff is named, so an operator can see which setting decided it.
        $this->assertStringContainsString('2020_01_02_000000', $display);

        // The row exists (nothing is hidden) and is not counted as pending.
        $this->assertStringContainsString('create_sessions_table', $display);
        $this->assertSame(
            'Skipped',
            $this->statusOf($display, 'create_sessions_table'),
            'a pre-cutoff migration must be reported as skipped, never as pending'
        );
    }

    /**
     * A declined feature's migrations are skipped, and the feature is named.
     *
     * `broadcasting` is used because it is a registered framework feature with
     * its own migration directory, and one an application can perfectly
     * reasonably not want.
     */
    public function testADeclinedFeatureIsSkippedWithItsNameAsTheReason(): void
    {
        // Arrange — broadcasting deliberately absent from features
        FeatureRegistry::loadFromConfig(['auth']);
        $tester = $this->makeTester(['features' => ['auth']]);

        // Act
        $exit    = $tester->execute([]);
        $display = $tester->getDisplay();

        // Assert — the reason names the feature, which is the actionable part:
        // enabling it in app.php is what changes the answer.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('feature: broadcasting', $display);
        $this->assertSame(
            'Skipped',
            $this->statusOf($display, 'create_broadcast_events_table'),
            "a declined feature's migration must not be reported as pending"
        );
    }

    /**
     * Enabling the feature turns the same row into pending work.
     *
     * The complement of the test above: without this, a command that marked
     * every framework migration `Skipped` would pass it. Same disk, one line of
     * app.php different.
     */
    public function testEnablingTheFeatureMakesItsMigrationPendingAgain(): void
    {
        // Arrange — this time broadcasting is on, and no cutoff excludes it
        FeatureRegistry::loadFromConfig(['auth', 'broadcasting']);
        $tester = $this->makeTester(['features' => ['auth', 'broadcasting']]);

        // Act
        $tester->execute([]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertSame(
            'Pending',
            $this->statusOf($display, 'create_broadcast_events_table'),
            'an enabled feature with no history must show as pending'
        );
        $this->assertStringNotContainsString('feature: broadcasting', $display);
    }

    /**
     * Without a connection the command still reports, and says why it cannot
     * tell Ran from Pending.
     */
    public function testWithoutAConnectionItSaysSoAndStillReports(): void
    {
        // Arrange
        FeatureRegistry::loadFromConfig(['auth']);
        $tester = $this->makeTester(['features' => ['auth']]);

        // Act
        $exit    = $tester->execute([]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $exit, 'a missing connection is not a failure here');
        $this->assertStringContainsString('No database connection', $display);
        $this->assertStringContainsString('without run history', $display);
    }

    /**
     * --path reports exactly the named directory, with no feature gate.
     *
     * The operator named the directory, so there is no feature to infer and
     * nothing to mark skipped for that reason. The cutoff is a separate
     * question, covered in MigrateTest.
     */
    public function testExplicitPathAppliesNoFeatureGate(): void
    {
        // Arrange — point at the broadcasting directory that features excludes
        FeatureRegistry::loadFromConfig(['auth']);
        $tester = $this->makeTester(['features' => ['auth']]);
        $dir    = dirname(__DIR__, 3) . '/database/migrations/framework/broadcasting';
        $this->assertDirectoryExists($dir, 'fixture: the broadcasting migrations ship here');

        // Act
        $exit    = $tester->execute(['--path' => $dir]);
        $display = $tester->getDisplay();

        // Assert — reported, and not gated off by a feature the operator bypassed
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('create_broadcast_events_table', $display);
        $this->assertStringNotContainsString('feature: broadcasting', $display);
    }

    /**
     * The Status column for one migration row of the rendered table.
     *
     * The table is Symfony's box drawing, so the row is found by slug and the
     * fourth pipe-delimited cell read out of it. Returns '' when the slug is
     * absent, so a caller asserting on a status also proves the row is there.
     */
    private function statusOf(string $display, string $slug): string
    {
        foreach (explode("\n", $display) as $line) {
            if (!str_contains($line, $slug)) {
                continue;
            }
            $cells = array_map('trim', explode('|', $line));
            // [0] is the empty string before the leading pipe, so Status is [4].
            return preg_replace('/\s*\(.*$/', '', $cells[4] ?? '') ?? '';
        }

        return '';
    }
}
