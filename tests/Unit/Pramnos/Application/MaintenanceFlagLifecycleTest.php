<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * The maintenance flag must not outlive the process that raised it.
 *
 * WHAT: `runMigration()` puts `var/MAINTENANCE` up before `up()` and takes it
 *       down afterwards. A migration that throws must not leave it behind, a
 *       flag somebody else raised must survive, and the page must say something.
 * WHY:  there was no `try`/`finally`, and `Application::__construct()` refuses to
 *       build an application at all while that file exists. So one migration
 *       throwing put every subsequent request into Maintenance Mode — the web
 *       app, the API front controllers, **and the test bootstrap**, which is how
 *       a whole suite starts answering with a page instead of a test result. The
 *       page then carried nothing to act on: `showError()` was called with no
 *       message, so what rendered was a var-dump of the last database error,
 *       usually `Array ( [message] => '' [code] => 0 )`.
 *
 * These are cheap tests for something expensive: the cost of the bug was not
 * severity, it was the time it took to guess which file to delete.
 */
#[CoversClass(Application::class)]
class MaintenanceFlagLifecycleTest extends TestCase
{
    /** @var string Absolute path of the flag under test */
    private string $flag;

    /** @var bool Whether this test created the var/ directory */
    private bool $madeVarDir = false;

    protected function setUp(): void
    {
        $this->flag = ROOT . DS . 'var' . DS . 'MAINTENANCE';
        if (!is_dir(ROOT . DS . 'var')) {
            mkdir(ROOT . DS . 'var', 0777, true);
            $this->madeVarDir = true;
        }
        // A leftover from anything else would make these tests lie in both
        // directions, so start from no flag.
        if (file_exists($this->flag)) {
            unlink($this->flag);
        }
    }

    protected function tearDown(): void
    {
        // Leaving one behind would put every later test — and the developer's
        // next request — into maintenance mode. This is the failure mode under
        // test, so the cleanup is not optional.
        if (file_exists($this->flag)) {
            unlink($this->flag);
        }
        if ($this->madeVarDir && is_dir(ROOT . DS . 'var')) {
            @rmdir(ROOT . DS . 'var');
        }
    }

    /**
     * A migration that throws does not leave the flag on disk.
     *
     * This is the two-line test, and the one that will regress: it fails the
     * moment somebody removes the `finally` from `runMigration()`. It drives the
     * real method against a fixture migration rather than reproducing its steps,
     * because a test that reproduced them would keep passing after the `finally`
     * was gone.
     */
    public function testAThrowingMigrationDoesNotLeaveTheFlagBehind(): void
    {
        // Arrange
        $app = $this->appWithStubbedDatabase();

        // Act — ThrowingMigration::up() raises, out of the real runMigration()
        try {
            $app->runMigration('ThrowingMigration');
            $this->fail('the exception must propagate; only the flag is cleaned up');
        } catch (\RuntimeException $e) {
            $this->assertSame('this migration always throws', $e->getMessage());
        }

        // Assert
        $this->assertFileDoesNotExist(
            $this->flag,
            'var/MAINTENANCE must not outlive the migration that raised it'
        );
    }

    /**
     * A migration that succeeds also leaves nothing behind.
     */
    public function testASuccessfulMigrationClearsTheFlag(): void
    {
        // Arrange
        $app = $this->appWithStubbedDatabase();

        // Act
        $app->runMigration('TestMigration');

        // Assert
        $this->assertFileDoesNotExist($this->flag);
    }

    /**
     * The flag names the migration that raised it, while it is up.
     *
     * `startMaintenance()` has always accepted a reason and written it into the
     * file; `runMigration()` called it without one, so not even the file said
     * what had stopped the site. Read from inside `up()` — after it returns the
     * flag is gone, which is the previous test.
     */
    public function testTheFlagNamesTheMigrationThatRaisedIt(): void
    {
        // Arrange — FlagReadingMigration copies the file's contents during up()
        $app = $this->appWithStubbedDatabase();

        // Act
        $app->runMigration('FlagReadingMigration');

        // Assert
        $contents = \Pramnos\Migrations\FlagReadingMigration::$seenDuringUp;
        $this->assertStringContainsString('FlagReadingMigration', $contents,
            'the reason must name the migration');
        $this->assertStringContainsString('9.9.7', $contents,
            "and its version, so a ledger row can be matched to it");
    }

    /**
     * A flag raised by somebody else is not lifted by a migration run.
     *
     * `startMaintenance()` returns early when the file already exists, so it
     * never overwrites an operator's flag — and clearing it unconditionally at
     * the end would take the site *out* of a maintenance window that a person
     * put it into on purpose.
     */
    public function testAFlagRaisedElsewhereSurvivesAMigrationRun(): void
    {
        // Arrange — an operator's own flag, with their own reason
        file_put_contents($this->flag, 'Planned maintenance, back at 03:00');
        $app = $this->appWithStubbedDatabase();

        // Act
        $app->runMigration('TestMigration');

        // Assert
        $this->assertFileExists($this->flag, 'somebody else raised it; it is not ours to clear');
        $this->assertSame(
            'Planned maintenance, back at 03:00',
            file_get_contents($this->flag),
            'and it is not ours to rewrite either'
        );
    }

    /**
     * A migration whose statement was refused still records, and says so.
     *
     * The legacy ledger is one column wide and cannot hold the detail, so the
     * detail goes to the log — but the migration is still recorded, because a
     * statement that merely repeats work must not make a migration re-run for
     * ever. That is the constraint the fix had to keep.
     */
    public function testAMigrationWithRefusedStatementsIsStillRecorded(): void
    {
        // Arrange — the ALTER is refused, the ledger INSERT is not
        $app = $this->appWithStubbedDatabase('ADD COLUMN doomed');

        // Act
        $app->runMigration('QueueFailingMigration');

        // Assert — the version was still written, and the flag is down
        $this->assertContains('INSERT QUERY', $app->issuedQueries,
            'the migration must still be recorded, or it re-runs for ever');
        $this->assertFileDoesNotExist($this->flag);
    }

    /**
     * The maintenance page renders on an entry point that has not defined `DS`.
     *
     * WHAT: a bare front controller — `ROOT`, autoload, `new Application()` and
     *       nothing else — with the flag present must answer the 503 page, not a
     *       PHP fatal.
     * WHY:  `Application::__construct()`'s first act is this filesystem check,
     *       and `DS` is defined by `setDefines()` further down the same
     *       constructor. Every method on this path that reached for `DS` was an
     *       uncaught `Error` on any entry point that had not defined it —
     *       including the one the framework's own older scaffolding produces.
     *       The flag also goes up on its own, from `runMigration()`, for the
     *       duration of every migration on a deploy, so a request arriving in
     *       that window got the fatal instead of the 503 with `Retry-After`.
     *
     * **This runs in a subprocess, and it has to.** The suite bootstrap defines
     * `DS`, so a test inside this process cannot reproduce the condition at all —
     * which is exactly why the whole class of bug reached a release with a green
     * suite behind it.
     */
    public function testTheMaintenancePageRendersWithoutTheDSConstant(): void
    {
        // Arrange — the flag, and the entry point from the scaffolding verbatim
        file_put_contents($this->flag, 'Reason: raised by an operator, by hand');
        $script = <<<'PHP'
        <?php
        define('ROOT', getenv('PRAMNOS_TEST_ROOT'));
        define('SP', microtime(true));
        require ROOT . '/vendor/autoload.php';
        $app = new \Pramnos\Application\Application();
        echo "CONSTRUCTED-WITHOUT-MAINTENANCE";
        PHP;
        $file = sys_get_temp_dir() . '/pramnos_bare_entry_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $script);

        try {
            // Act
            $output = (string) shell_exec(
                'PRAMNOS_TEST_ROOT=' . escapeshellarg(ROOT) . ' '
                . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'
            );

            // Assert — no fatal of any kind
            $this->assertStringNotContainsString('Undefined constant', $output,
                'the constructor must not depend on constants setDefines() has not defined yet');
            $this->assertStringNotContainsString('Fatal error', $output);
            $this->assertStringNotContainsString('CONSTRUCTED-WITHOUT-MAINTENANCE', $output,
                'the flag was present, so the page must have been served instead');

            // Assert — and it is the page, carrying the reason
            $this->assertStringContainsString('Maintenance Mode', $output);
            $this->assertStringContainsString('raised by an operator, by hand', $output);
        } finally {
            @unlink($file);
        }
    }

    // =========================================================================
    // Who raised it, and who may take it down
    // =========================================================================

    /**
     * The flag records who raised it.
     *
     * The distinction is the whole basis of `maintenance:off` refusing: a flag a
     * migration raised means a schema is in flux, a flag a person raised means a
     * person decided, and clearing the first because you meant to clear the
     * second is the mistake worth making impossible.
     */
    public function testTheFlagRecordsWhoRaisedIt(): void
    {
        // Arrange
        $app = new MaintenanceProbeApplication();

        // Act
        $app->startMaintenance('by hand', Application::MAINTENANCE_MANUAL);

        // Assert
        $this->assertSame(Application::MAINTENANCE_MANUAL, $app->maintenanceOrigin());
    }

    /**
     * Raising it without saying who defaults to the framework's own.
     *
     * Every existing caller — `runMigration()`, `MigrationRunner` — passes a
     * reason and no origin, and none of them is a person. Defaulting the other
     * way would make `maintenance:off` clear a flag raised by a migration that is
     * still running.
     */
    public function testAnUnattributedFlagIsTreatedAsTheFrameworksOwn(): void
    {
        // Arrange
        $app = new MaintenanceProbeApplication();

        // Act — the signature every existing caller uses
        $app->startMaintenance('Database migrations in progress');

        // Assert
        $this->assertSame(Application::MAINTENANCE_AUTOMATIC, $app->maintenanceOrigin());
    }

    /**
     * A flag written before the origin existed reads `unknown`, not `manual`.
     *
     * Deliberately not `manual`: a command that only clears its own must not
     * clear one whose provenance nobody recorded.
     */
    public function testAFlagWithNoOriginLineReadsUnknown(): void
    {
        // Arrange — what startMaintenance() used to write
        file_put_contents($this->flag, 'Maintenance started at: 05/09/2026 11:00.');
        $app = new MaintenanceProbeApplication();

        // Act + Assert
        $this->assertSame(Application::MAINTENANCE_UNKNOWN, $app->maintenanceOrigin());
        $this->assertNotSame(Application::MAINTENANCE_MANUAL, $app->maintenanceOrigin());
    }

    /**
     * No flag means no origin, so callers can use it as the "is it on" question.
     */
    public function testNoFlagMeansNoOrigin(): void
    {
        // Act + Assert
        $this->assertSame('', (new MaintenanceProbeApplication())->maintenanceOrigin());
    }

    /**
     * The origin line does not reach the page.
     *
     * It is bookkeeping for whoever might take the flag down. A visitor on a 503
     * has no use for it, and the reason — which is operator-authored text — is
     * what the page is for.
     */
    public function testTheOriginLineIsKeptOffThePage(): void
    {
        // Arrange
        $app = new MaintenanceProbeApplication();
        $app->startMaintenance('Adding an index, back in 20 minutes', Application::MAINTENANCE_MANUAL);

        // Act
        $message = (new \ReflectionMethod($app, 'maintenanceMessage'))->invoke($app);

        // Assert
        $this->assertStringContainsString('Adding an index, back in 20 minutes', $message);
        $this->assertStringNotContainsString('Origin:', $message);
        $this->assertStringNotContainsString('manual', $message);
    }

    // =========================================================================
    // Automatic migrations stand down
    // =========================================================================

    /**
     * Auto-migrations do not run while the site is deliberately down.
     *
     * WHAT: `runAutoMigrations()` returns before doing any work when the flag is
     *       up, and proceeds when it is not.
     * WHY:  raising maintenance to run a heavy migration by hand, and having the
     *       next request start migrating underneath you, is the opposite of what
     *       the flag is for. An explicit `migrate` is unaffected: it goes through
     *       MigrationRunner directly.
     */
    public function testAutoMigrationsStandDownWhileMaintenanceIsUp(): void
    {
        // Arrange
        $app = new MaintenanceProbeApplication();
        $app->database = $this->getMockBuilder(\Pramnos\Database\Database::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Act — flag up
        $app->startMaintenance('by hand', Application::MAINTENANCE_MANUAL);
        $app->runAutoMigrationsNow();

        // Assert
        $this->assertFalse(
            $app->reachedTheScopeLookup,
            'it must return before deciding which migrations apply'
        );

        // Act — flag down, same application
        $app->stopMaintenance();
        $app->resetAutoMigrationGuard();
        $app->runAutoMigrationsNow();

        // Assert — the complement, or the test above would pass on a method that
        // never does anything at all
        $this->assertTrue(
            $app->reachedTheScopeLookup,
            'with no flag it must get on with it'
        );
    }

    /**
     * The page shows the reason from the flag rather than an empty array.
     */
    public function testTheMaintenanceMessageCarriesTheReason(): void
    {
        // Arrange — the probe rather than getInstance(), because with the flag on
        // disk getInstance() cannot return an application at all: the constructor
        // answers the maintenance page instead of building one. That is the
        // behaviour under test, so it cannot also be the way the test is set up.
        file_put_contents($this->flag, 'Database migrations in progress');
        $app = new MaintenanceProbeApplication();

        // Act
        $message = (new \ReflectionMethod($app, 'maintenanceMessage'))->invoke($app);

        // Assert
        $this->assertStringContainsString('Database migrations in progress', $message);
    }

    /**
     * The reason is escaped, because it is interpolated into a page.
     *
     * The flag is written by whatever called `startMaintenance()`, and one of
     * those callers passes a migration class name through. Rendering it raw
     * would make the maintenance page an injection point reachable by anything
     * that can influence a reason string.
     */
    public function testTheReasonIsEscapedBeforeItReachesThePage(): void
    {
        // Arrange
        file_put_contents($this->flag, '<script>alert(1)</script>');
        $app = new MaintenanceProbeApplication();

        // Act
        $message = (new \ReflectionMethod($app, 'maintenanceMessage'))->invoke($app);

        // Assert
        $this->assertStringNotContainsString('<script>', $message);
        $this->assertStringContainsString('&lt;script&gt;', $message);
    }

    /**
     * Under DEVELOPMENT the page names the file to delete.
     *
     * Which is the actual remedy, and an operator staring at the page has no
     * other way to learn that `var/MAINTENANCE` exists.
     */
    public function testTheFilePathIsNamedUnderDevelopment(): void
    {
        // Arrange
        file_put_contents($this->flag, 'down');
        $app = new MaintenanceProbeApplication();
        $app->development = true;

        // Act
        $message = (new \ReflectionMethod($app, 'maintenanceMessage'))->invoke($app);

        // Assert
        $this->assertStringContainsString('var/MAINTENANCE', $message);
        $this->assertStringContainsString('down', $message);
    }

    /**
     * In production it shows the reason and no path.
     *
     * A public visitor on a 503 has no use for a filesystem path, and this page
     * is served to whoever asks. Both branches are asserted because the constant
     * is fixed for the life of a process — a test that read it directly could
     * only ever cover one of them, and would quietly cover whichever the suite
     * happened to be configured for.
     */
    public function testTheFilePathIsWithheldInProduction(): void
    {
        // Arrange
        file_put_contents($this->flag, 'Database migrations in progress');
        $app = new MaintenanceProbeApplication();
        $app->development = false;

        // Act
        $message = (new \ReflectionMethod($app, 'maintenanceMessage'))->invoke($app);

        // Assert
        $this->assertStringNotContainsString('var/MAINTENANCE', $message);
        $this->assertSame('Database migrations in progress', $message,
            'the operator-authored reason is appropriate public text; the path is not');
    }
    /**
     * A probe application whose database accepts everything but $failOn.
     *
     * @param string|null $failOn Substring of a statement that must be refused.
     */
    private function appWithStubbedDatabase(?string $failOn = null): MaintenanceProbeApplication
    {
        $app = new MaintenanceProbeApplication();

        $db = $this->getMockBuilder(\Pramnos\Database\Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'prepareQuery'])
            ->getMock();
        $db->type = 'mysql';
        $db->method('prepareQuery')->willReturn('INSERT QUERY');
        $db->method('query')->willReturnCallback(
            function ($sql) use ($app, $failOn) {
                $app->issuedQueries[] = is_string($sql) ? $sql : '(object)';
                if ($failOn !== null && is_string($sql) && str_contains($sql, $failOn)) {
                    throw new \Exception('ERROR:  column "doomed" already exists');
                }
                return new \stdClass();
            }
        );
        $app->database = $db;

        return $app;
    }
}

/**
 * An application that can be built while the flag is on disk.
 *
 * `Application::__construct()` answers the maintenance page instead of building
 * an application whenever `var/MAINTENANCE` exists — which is the behaviour these
 * tests set up on purpose, so it cannot also be how they are constructed. Nothing
 * else is overridden: `runMigration()` under test is the real one.
 */
class MaintenanceProbeApplication extends Application
{
    /** @var array<int, string> Every statement handed to the database stub */
    public array $issuedQueries = [];

    /** @var bool Stands in for the DEVELOPMENT constant */
    public bool $development = false;

    /** @var bool Whether runAutoMigrations() got past its guards */
    public bool $reachedTheScopeLookup = false;

    /** Run the real runAutoMigrations(), which is protected. */
    public function runAutoMigrationsNow(): void
    {
        $this->runAutoMigrations();
    }

    /** The guard is per instance; clear it so one object can be asked twice. */
    public function resetAutoMigrationGuard(): void
    {
        $this->autoMigrationsChecked = false;
    }

    /**
     * The first thing runAutoMigrations() does after its guards.
     *
     * Overridden to record that the guards were passed and to stop there, so the
     * test does not need a real migration tree or a real database behind it.
     */
    public function migrationScope(bool $includeConventionalAppDir = false): array
    {
        $this->reachedTheScopeLookup = true;

        return ['dirs' => [], 'skipped' => [], 'cutoff' => ''];
    }

    protected function inDevelopmentMode(): bool
    {
        return $this->development;
    }

    public function __construct()
    {
        $this->applicationInfo = ['namespace' => 'Pramnos'];
    }
}
