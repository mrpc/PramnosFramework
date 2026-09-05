<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\MaintenanceOff;
use Pramnos\Console\Commands\MaintenanceOn;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Raising and clearing maintenance from the console.
 *
 * WHAT: `maintenance:on` raises the flag as a person's, `maintenance:off` takes
 *       down only a flag a person raised.
 * WHY:  the reason for raising it by hand is usually work you intend to do
 *       yourself — a heavy migration — and the two things that must then be true
 *       are that automatic migrations stand down and that the console keeps
 *       working. The second was not true: the flag made every command answer an
 *       HTML maintenance page and exit, `maintenance:off` included, so the only
 *       way back was deleting the file by hand.
 *
 * The refusal is the other half. A flag the framework raised means a schema is in
 * flux and something is either still running or died halfway; clearing it because
 * you meant to clear your own is the mistake worth making impossible.
 */
#[CoversClass(MaintenanceOn::class)]
#[CoversClass(MaintenanceOff::class)]
class MaintenanceCommandsTest extends TestCase
{
    private string $flag;
    private bool $madeVarDir = false;

    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        $this->flag = ROOT . DS . 'var' . DS . 'MAINTENANCE';
        if (!is_dir(ROOT . DS . 'var')) {
            mkdir(ROOT . DS . 'var', 0777, true);
            $this->madeVarDir = true;
        }
        if (file_exists($this->flag)) {
            unlink($this->flag);
        }
    }

    protected function tearDown(): void
    {
        // A leftover flag would put every later test into maintenance mode, which
        // is the failure this whole area is about.
        if (file_exists($this->flag)) {
            unlink($this->flag);
        }
        if ($this->madeVarDir && is_dir(ROOT . DS . 'var')) {
            @rmdir(ROOT . DS . 'var');
        }
    }

    /**
     * @param MaintenanceOn|MaintenanceOff $command
     */
    private function tester($command): CommandTester
    {
        $consoleApp = new class ('Test', '0.0') extends \Pramnos\Console\Application {
            public function __construct(string $name, string $version)
            {
                \Symfony\Component\Console\Application::__construct($name, $version);
                $this->internalApplication = new class extends Application {
                    public function __construct()
                    {
                        $this->applicationInfo = ['namespace' => 'App'];
                    }
                };
            }
        };

        $consoleApp->add($command);

        return new CommandTester($consoleApp->find($command->getName()));
    }

    private function origin(): string
    {
        $app = new class extends Application {
            public function __construct() {}
        };

        return $app->maintenanceOrigin();
    }

    // ── on ───────────────────────────────────────────────────────────────────

    /**
     * It raises the flag, marks it as a person's, and carries the reason.
     */
    public function testItRaisesTheFlagAsAPersons(): void
    {
        // Arrange
        $tester = $this->tester(new MaintenanceOn());

        // Act
        $exit = $tester->execute(['--reason' => 'Adding an index, ~20 minutes']);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->flag);
        $this->assertSame(Application::MAINTENANCE_MANUAL, $this->origin());
        $this->assertStringContainsString(
            'Adding an index, ~20 minutes',
            (string) file_get_contents($this->flag)
        );
        // The two things somebody raising it by hand needs to know.
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Automatic migrations will not run', $display);
        $this->assertStringContainsString('console still works', $display);
    }

    /**
     * Raising it twice reports rather than overwriting.
     *
     * `startMaintenance()` returns early when the file is there, so a second call
     * that claimed success would be saying something untrue about a flag it did
     * not write — including about who raised it.
     */
    public function testRaisingItTwiceReportsTheExistingFlag(): void
    {
        // Arrange — the framework's own flag is already up
        $tester = $this->tester(new MaintenanceOn());
        file_put_contents(
            $this->flag,
            "Maintenance started at: now. Reason: migrations\nOrigin: automatic"
        );

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('already on', $display);
        $this->assertStringContainsString('automatic', $display);
        $this->assertStringContainsString('will not take it down', $display);
        // And it did not rewrite somebody else's flag.
        $this->assertSame(Application::MAINTENANCE_AUTOMATIC, $this->origin());
    }

    // ── off ──────────────────────────────────────────────────────────────────

    /**
     * It clears a flag a person raised.
     */
    public function testItClearsAFlagAPersonRaised(): void
    {
        // Arrange
        file_put_contents(
            $this->flag,
            "Maintenance started at: now. Reason: by hand\nOrigin: manual"
        );

        // Act
        $exit = $this->tester(new MaintenanceOff())->execute([]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileDoesNotExist($this->flag);
    }

    /**
     * It refuses a flag the framework raised, and says what to do.
     *
     * This is the requirement: `maintenance:off` takes down only what
     * `maintenance:on` put up.
     */
    public function testItRefusesAFlagTheFrameworkRaised(): void
    {
        // Arrange
        file_put_contents(
            $this->flag,
            "Maintenance started at: now. Reason: migrations\nOrigin: automatic"
        );
        $tester = $this->tester(new MaintenanceOff());

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertFileExists($this->flag, 'a refusal must not clear it anyway');
        $display = $tester->getDisplay();
        $this->assertStringContainsString('not raised by hand', $display);
        $this->assertStringContainsString('--force', $display);
    }

    /**
     * A flag with no recorded origin is refused too.
     *
     * `unknown` is not `manual`. There is no way to tell who raised it, and the
     * safe reading of "no way to tell" is "not yours".
     */
    public function testItRefusesAFlagWithNoRecordedOrigin(): void
    {
        // Arrange — what startMaintenance() wrote before the origin existed
        file_put_contents($this->flag, 'Maintenance started at: now.');
        $tester = $this->tester(new MaintenanceOff());

        // Act
        $exit = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exit);
        $this->assertFileExists($this->flag);
        $this->assertStringContainsString('unknown', $tester->getDisplay());
    }

    /**
     * `--force` clears it, and says that it did.
     *
     * For the case the refusal cannot decide: a batch that died and left its flag
     * behind. Silently doing it would make the refusal decorative.
     */
    public function testForceClearsItAndSaysSo(): void
    {
        // Arrange
        file_put_contents(
            $this->flag,
            "Maintenance started at: now. Reason: migrations\nOrigin: automatic"
        );
        $tester = $this->tester(new MaintenanceOff());

        // Act
        $exit = $tester->execute(['--force' => true]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileDoesNotExist($this->flag);
        $this->assertStringContainsString('Forced', $tester->getDisplay());
    }

    /**
     * With no flag at all it says so and exits 0.
     *
     * A deploy script running it unconditionally at the end should not fail
     * because the site was already up.
     */
    public function testWithNoFlagItSaysSoAndSucceeds(): void
    {
        // Act
        $tester = $this->tester(new MaintenanceOff());
        $exit   = $tester->execute([]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('already off', $tester->getDisplay());
    }
}
