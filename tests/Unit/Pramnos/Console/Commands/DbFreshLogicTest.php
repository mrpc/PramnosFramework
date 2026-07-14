<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DbFresh;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for DbFresh's orchestration, WITHOUT a real database.
 *
 * db:fresh delegates the destructive/migration work to the db:wipe, migrate and
 * db:seed commands. We build a real Pramnos console application, replace those
 * three with recording fakes, give it a non-null (stub) database, then drive
 * db:fresh with --force so the full wipe → migrate → (seed) sequence and its
 * abort-on-failure branches are covered — nothing real is dropped or migrated.
 *
 * The safety-guard branches are covered by DbFreshTest.
 */
#[CoversClass(DbFresh::class)]
class DbFreshLogicTest extends TestCase
{
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        }
    }

    /**
     * --force runs wipe then migrate (no seed) and reports completion.
     */
    public function testForceRunsWipeThenMigrate(): void
    {
        // Arrange
        [$tester, $calls] = $this->harness();

        // Act
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame(['db:wipe', 'migrate'], $calls->ran, 'wipe then migrate, no seed');
        $this->assertStringContainsString('Fresh complete', $tester->getDisplay());
    }

    /**
     * --seed additionally runs db:seed after migrating.
     */
    public function testSeedAlsoRunsSeeder(): void
    {
        // Arrange
        [$tester, $calls] = $this->harness();

        // Act
        $tester->execute(['--force' => true, '--seed' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(['db:wipe', 'migrate', 'db:seed'], $calls->ran);
    }

    /**
     * A failing wipe aborts before migrating and returns the child exit code.
     */
    public function testWipeFailureAborts(): void
    {
        // Arrange — db:wipe fake returns FAILURE.
        [$tester, $calls] = $this->harness(wipeCode: Command::FAILURE);

        // Act
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['db:wipe'], $calls->ran, 'migrate must not run after a failed wipe');
        $this->assertStringContainsString('Wipe failed', $tester->getDisplay());
    }

    /**
     * A failing migration is reported and its exit code propagated.
     */
    public function testMigrateFailureReported(): void
    {
        // Arrange
        [$tester, $calls] = $this->harness(migrateCode: Command::FAILURE);

        // Act
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['db:wipe', 'migrate'], $calls->ran);
        $this->assertStringContainsString('Migrations failed', $tester->getDisplay());
    }

    /**
     * Run outside the Pramnos console application → refuses (no framework DB).
     */
    public function testExecuteFailsOutsideConsoleApp(): void
    {
        // Arrange
        $app = new \Symfony\Component\Console\Application('t', '1');
        $app->add(new DbFresh());
        $app->setAutoExit(false);
        $tester = new CommandTester($app->find('db:fresh'));

        // Act
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('must run within the Pramnos console application', $tester->getDisplay());
    }

    /**
     * Inside the console app but with no database → clean failure.
     */
    public function testExecuteFailsWhenNoDatabase(): void
    {
        // Arrange — console app with a null database.
        $app = new \Pramnos\Console\Application();
        $internal = new class { public $database; };
        $internal->database = null;
        $app->internalApplication = $internal;
        $app->setAutoExit(false);
        $app->add(new DbFresh());
        $tester = new CommandTester($app->find('db:fresh'));

        // Act
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('No database connection available', $tester->getDisplay());
    }

    /**
     * Interactive "no" at the confirmation is a clean abort (exit 0), before any
     * wipe/migrate.
     */
    public function testInteractiveDeclineAborts(): void
    {
        // Arrange
        [$tester, $calls] = $this->harness();
        $tester->setInputs(['no']);

        // Act
        $code = $tester->execute([], ['interactive' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame([], $calls->ran, 'nothing runs when declined');
        $this->assertStringContainsString('Aborted.', $tester->getDisplay());
    }

    /**
     * Build a console application with a stub DB and recording fakes for
     * db:wipe / migrate / db:seed, and return a CommandTester for db:fresh.
     *
     * @return array{0: CommandTester, 1: object}
     */
    private function harness(int $wipeCode = Command::SUCCESS, int $migrateCode = Command::SUCCESS): array
    {
        $calls = new class { public array $ran = []; };

        $app = new \Pramnos\Console\Application();
        // Give db:fresh a non-null database so it proceeds past the null guard.
        $internal = new class { public $database; };
        $internal->database = new \stdClass();
        $app->internalApplication = $internal;
        $app->setAutoExit(false);

        // Replace the real destructive/migration commands with recording fakes.
        $app->add($this->fakeCommand('db:wipe', $calls, $wipeCode));
        $app->add($this->fakeCommand('migrate', $calls, $migrateCode));
        $app->add($this->fakeCommand('db:seed', $calls, Command::SUCCESS));
        $app->add(new DbFresh());

        return [new CommandTester($app->find('db:fresh')), $calls];
    }

    private function fakeCommand(string $name, object $calls, int $code): Command
    {
        return new class($name, $calls, $code) extends Command {
            public function __construct(private string $cmdName, private object $calls, private int $code)
            {
                parent::__construct($cmdName);
            }
            protected function configure(): void
            {
                $this->setName($this->cmdName);
                // db:fresh forwards --force to db:wipe; accept (and ignore) it.
                $this->addOption('force', 'f', \Symfony\Component\Console\Input\InputOption::VALUE_NONE);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->calls->ran[] = $this->cmdName;
                return $this->code;
            }
        };
    }
}
