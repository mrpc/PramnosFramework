<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Pramnos\Console\Commands\QueueHealth;
use Pramnos\Queue\QueueManager;

/**
 * `queue:health` — arrivals against completions, and an exit status a monitor can page on.
 *
 * The number itself is asserted against real databases in
 * `tests/Integration/Queue/QueueManagerMySQLTest.php`. What is tested here is the part an
 * alert depends on: **the exit status**, and specifically that one losing type is not
 * hidden by healthy totals.
 *
 * That is not a hypothetical. On the installation that asked for this, every other queue
 * was draining and one was not, so the sum looked fine for four hours while the backlog
 * on that one type reached 175,000.
 */
#[CoversClass(QueueHealth::class)]
class QueueHealthCommandTest extends TestCase
{
    /**
     * @param array<string, mixed>                $overall
     * @param array<string, array<string, mixed>> $perType
     */
    private function command(array $overall, array $perType = []): TestableQueueHealth
    {
        $command = new TestableQueueHealth($overall, $perType);
        $command->setApplication(new TestQHConsoleApplication());

        return $command;
    }

    /**
     * @param array<string, mixed> $numbers
     * @return array<string, mixed>
     */
    private function numbers(int $arrivals, int $completions, array $numbers = []): array
    {
        return $numbers + [
            'window'      => 300,
            'arrivals'    => $arrivals,
            'completions' => $completions,
            'net'         => $completions - $arrivals,
            'pending'     => 0,
            'processing'  => 0,
            'losing'      => $completions < $arrivals,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0:int,1:string}
     */
    private function invoke(TestableQueueHealth $command, array $options = []): array
    {
        $output = new BufferedOutput();
        $input  = new ArrayInput($options, $command->getDefinition());

        $status = $command->runExecute($input, $output);

        return [$status, $output->fetch()];
    }

    /** The options are registered under the names the guide uses. */
    public function testTheOptionsAreRegistered(): void
    {
        // Arrange
        $command = new QueueHealth();

        // Assert
        $this->assertSame('queue:health', $command->getName());

        foreach (['window', 'type', 'per-type', 'json', 'history'] as $option) {
            $this->assertTrue($command->getDefinition()->hasOption($option), $option);
        }
    }

    /**
     * A queue that keeps up exits zero and says so.
     *
     * The control for every assertion below: a command that always reported trouble would
     * be switched off within a week.
     */
    public function testKeepingUpExitsZero(): void
    {
        // Act
        [$status, $text] = $this->invoke($this->command($this->numbers(10, 10)));

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Keeping up', $text);
    }

    /**
     * A losing queue exits 1, which is the whole point of the command.
     *
     * `cron` mails a non-zero exit and a monitor pages on it, with nothing having to parse
     * the output. A tool that only prints is a tool somebody has to remember to read.
     */
    public function testLosingExitsOne(): void
    {
        // Act
        [$status, $text] = $this->invoke($this->command($this->numbers(120, 40)));

        // Assert
        $this->assertSame(QueueHealth::LOSING, $status);
        $this->assertStringContainsString('losing', $text);
    }

    /**
     * One losing type fails the run even when the totals look healthy.
     *
     * The reported incident, exactly: nine queues draining, one not, and a sum that says
     * everything is fine. An alert watching only the sum fires when the backlog is large
     * enough to move the sum, which is hours late.
     */
    public function testOneLosingTypeFailsTheRunDespiteHealthyTotals(): void
    {
        // Arrange
        $command = $this->command(
            $this->numbers(100, 140),
            [
                'exports' => $this->numbers(10, 50),
                'imports' => $this->numbers(90, 90 - 30),
            ]
        );

        // Act
        [$status, $text] = $this->invoke($command, ['--per-type' => true]);

        // Assert
        $this->assertSame(QueueHealth::LOSING, $status, 'a losing type passed');
        $this->assertStringContainsString('imports', $text);
        $this->assertStringContainsString('exports', $text);
    }

    /**
     * Without `--per-type` nothing is broken down, and the totals decide alone.
     *
     * So the cheap check stays cheap: one query rather than one per type, which is what
     * makes it reasonable to run every minute.
     */
    public function testWithoutPerTypeOnlyTheTotalsAreConsulted(): void
    {
        // Arrange
        $command = $this->command(
            $this->numbers(100, 140),
            ['imports' => $this->numbers(90, 10)]
        );

        // Act
        [$status, $text] = $this->invoke($command);

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringNotContainsString('imports', $text);
        $this->assertSame([], $command->typesAsked, 'the per-type queries ran anyway');
    }

    /**
     * `--json` emits the report and nothing else.
     *
     * For a monitor that reads stdout. The prose version has colour tags in it, which is
     * not something to make anybody's collector parse.
     */
    public function testJsonIsMachineReadable(): void
    {
        // Act
        [, $text] = $this->invoke(
            $this->command($this->numbers(5, 2)),
            ['--json' => true]
        );

        // Assert
        $decoded = json_decode(trim($text), true);

        $this->assertIsArray($decoded);
        $this->assertSame(-3, $decoded['overall']['net']);
        $this->assertTrue($decoded['overall']['losing']);
    }

    /**
     * The window and the types reach the manager as given.
     */
    public function testTheOptionsReachTheManager(): void
    {
        // Arrange
        $command = $this->command($this->numbers(1, 1));

        // Act
        $this->invoke($command, ['--window' => '900', '--type' => ['imports']]);

        // Assert
        $this->assertSame(900, $command->askedWith[0]);
        $this->assertSame(['imports'], $command->askedWith[1]);
    }

    /**
     * `--per-type` with explicit types uses those, and asks the queue otherwise.
     *
     * The types come from the table rather than from a directory of handler classes,
     * because a type with traffic and no handler is exactly what somebody needs to see.
     */
    public function testTheTypesToBreakDownComeFromTheQueue(): void
    {
        // Arrange
        $command = $this->command($this->numbers(1, 1), ['seen' => $this->numbers(1, 1)]);

        // Act
        $this->invoke($command, ['--per-type' => true]);

        // Assert
        $this->assertSame(['seen'], $command->typesAsked);

        // and a named type overrides the lookup
        $named = $this->command($this->numbers(1, 1), ['named' => $this->numbers(1, 1)]);
        $this->invoke($named, ['--per-type' => true, '--type' => ['named']]);

        $this->assertSame(['named'], $named->typesAsked);
    }

    /** The overridable hooks answer their documented defaults. */
    public function testTheHooksHaveTheirDocumentedDefaults(): void
    {
        // Arrange
        $command = new class extends QueueHealth {
            public function exposeControllerName(): string
            {
                return $this->getControllerName();
            }

            public function exposeManager($controller): QueueManager
            {
                return $this->createQueueManager($controller);
            }
        };

        // Assert
        $this->assertSame('Queueitems', $command->exposeControllerName());
        $this->assertInstanceOf(
            QueueManager::class,
            $command->exposeManager(new TestQHController())
        );
    }

    /**
     * A window that reaches past the oldest surviving row says so.
     *
     * Consequence 1 of the report, and the one that cost nothing to hit: `throughput()`
     * counts arrivals and completions among rows that are still there, so
     * `--window=86400` on an installation purging hourly counted the ninety minutes that
     * survived, reported the rate as if it were the day, and **exited 0**. Not an error,
     * not a warning — a number that looks like the answer to the question that was asked.
     *
     * Detected rather than configured: the retention is an argument on somebody else's
     * crontab, and the oldest surviving completion is a fact this connection has.
     */
    public function testAWindowLongerThanTheSurvivingRowsIsFlagged(): void
    {
        // Arrange — the oldest completion is an hour old, and a day is asked for
        $command = $this->command($this->numbers(10, 10));
        $command->horizon = date('Y-m-d H:i:s', time() - 3600);

        // Act
        [, $text] = $this->invoke($command, ['--window' => '86400']);

        // Assert
        $this->assertStringContainsString('oldest completed task', $text);
        $this->assertStringContainsString('--history', $text, 'the caveat must name the way out');
    }

    /**
     * A window the rows can cover is not flagged.
     *
     * The control. A caveat printed on every run is a caveat nobody reads, and the default
     * window is five minutes on a table that keeps at least an hour.
     */
    public function testAWindowTheRowsCoverIsNotFlagged(): void
    {
        // Arrange — a day of history, five minutes asked for
        $command = $this->command($this->numbers(10, 10));
        $command->horizon = date('Y-m-d H:i:s', time() - 86400);

        // Act
        [, $text] = $this->invoke($command, ['--window' => '300']);

        // Assert
        $this->assertStringNotContainsString('oldest completed task', $text);
    }

    /**
     * An empty table is not flagged either.
     *
     * `retentionHorizon()` answers null when nothing terminal is left, and a fresh
     * installation would otherwise be told its numbers are truncated on the first run.
     */
    public function testAnEmptyTableIsNotFlagged(): void
    {
        // Arrange
        $command = $this->command($this->numbers(0, 0));
        $command->horizon = null;

        // Act
        [, $text] = $this->invoke($command, ['--window' => '86400']);

        // Assert
        $this->assertStringNotContainsString('oldest completed task', $text);
    }

    /**
     * `--history` reads the roll-up instead of the live rows.
     *
     * The other half of consequence 1: the buckets outlive the rows, so a window longer
     * than the retention is answerable from them and only from them.
     */
    public function testHistoryReadsTheRollUp(): void
    {
        // Arrange
        $command = $this->command($this->numbers(1, 1));
        $command->historyRows = [[
            'type' => 'imports', 'bucket' => '2026-09-08 01:00:00', 'tasks' => 16130,
            'failed' => 0, 'warning' => 0, 'retried' => 0,
            'exec_mean' => 0.2, 'exec_max' => 1.0, 'cpu_mean' => 0.1,
            'wait_mean' => 9.48, 'wait_max' => 40.0,
            'fail_rate' => 0.0, 'retry_rate' => 0.0,
        ]];

        // Act
        [$status, $text] = $this->invoke($command, ['--history' => '48']);

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(48, $command->historyAskedFor, 'the hours must reach history()');
        $this->assertSame([], $command->askedWith, 'the live query ran as well');
        $this->assertStringContainsString('imports', $text);
        $this->assertStringContainsString('9.48', $text, 'the wait is the number worth showing');
    }

    /**
     * `--history` with nothing recorded says why rather than printing an empty table.
     *
     * The roll-up is written by `queue:cleanup`; until that has run once there is nothing
     * to read, and on an installation that has not applied the framework migrations there
     * is nowhere to write. An empty table would read as "the queue did nothing".
     */
    public function testHistoryWithNothingRecordedExplainsItself(): void
    {
        // Arrange
        $command = $this->command($this->numbers(1, 1));
        $command->historyRows = [];

        // Act
        [$status, $text] = $this->invoke($command, ['--history' => '48']);

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Nothing recorded', $text);
        $this->assertStringContainsString('queue:cleanup', $text);
    }

    /**
     * `--history --json` is the roll-up and nothing else.
     */
    public function testHistoryJsonIsMachineReadable(): void
    {
        // Arrange
        $command = $this->command($this->numbers(1, 1));
        $command->historyRows = [['type' => 'a', 'bucket' => 'b', 'tasks' => 1,
            'failed' => 0, 'warning' => 0, 'retried' => 0, 'exec_mean' => 0.0,
            'exec_max' => 0.0, 'cpu_mean' => null, 'wait_mean' => 0.0, 'wait_max' => 0.0,
            'fail_rate' => 0.0, 'retry_rate' => 0.0]];

        // Act
        [, $text] = $this->invoke($command, ['--history' => '12', '--json' => true]);

        // Assert
        $decoded = json_decode(trim($text), true);
        $this->assertIsArray($decoded);
        $this->assertSame(12, $decoded['hours']);
        $this->assertCount(1, $decoded['history']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Stubs
// ─────────────────────────────────────────────────────────────────────────────

class TestableQueueHealth extends QueueHealth
{
    /** @var array{0:int,1:array|null}|array{} How throughput() was called for the totals */
    public array $askedWith = [];

    /** @var list<string> Types the command broke down */
    public array $typesAsked = [];

    /** The oldest surviving completion the double should report, or null for none. */
    public ?string $horizon = null;

    /** @var array<int, array<string, mixed>> What history() should hand back */
    public array $historyRows = [];

    /** How many hours the command asked history() for, or null when it did not ask. */
    public ?int $historyAskedFor = null;

    /**
     * @param array<string, mixed>                $overall
     * @param array<string, array<string, mixed>> $perType
     */
    public function __construct(
        private readonly array $overall,
        private readonly array $perType
    ) {
        parent::__construct();
    }

    public function runExecute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        return $this->execute($input, $output);
    }

    protected function createQueueManager($controller): QueueManager
    {
        $command = $this;
        $overall = $this->overall;
        $perType = $this->perType;

        return new class ($command, $overall, $perType) extends QueueManager {
            public function __construct(
                private readonly TestableQueueHealth $command,
                private readonly array $overall,
                private readonly array $perType
            ) {
                // No parent::__construct() — it needs a database.
            }

            /**
             * The command asks how far back the live rows reach, so the double answers.
             *
             * Null by default — "nothing terminal is left" — which is the honest answer for
             * a manager with no database and keeps the truncation caveat from firing on the
             * tests that are about the throughput lines. The caveat has its own.
             */
            public function retentionHorizon(): ?string
            {
                return $this->command->horizon;
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            public function history(int $hours = 168, string|array|null $taskTypes = null): array
            {
                $this->command->historyAskedFor = $hours;

                return $this->command->historyRows;
            }

            public function throughput(
                int $windowSeconds = 300,
                string|array|null $taskTypes = null
            ): array {
                if (is_string($taskTypes) && isset($this->perType[$taskTypes])) {
                    $this->command->typesAsked[] = $taskTypes;

                    return $this->perType[$taskTypes];
                }

                $this->command->askedWith = [$windowSeconds, $taskTypes];

                return $this->overall;
            }

            public function activeTaskTypes(int $windowSeconds = 86400): array
            {
                return array_keys($this->perType);
            }
        };
    }
}

class TestQHConsoleApplication extends \Symfony\Component\Console\Application
{
    public TestQHInternalApplication $internalApplication;

    public function __construct()
    {
        parent::__construct('test', '1.0');
        $this->internalApplication = new TestQHInternalApplication();
        $this->setAutoExit(false);
    }
}

class TestQHInternalApplication
{
    public TestQHDatabase $database;

    public function __construct()
    {
        $this->database = new TestQHDatabase();
    }

    public function init(): void
    {
    }

    public function getController(string $name): object
    {
        return new TestQHController();
    }
}

class TestQHDatabase
{
    public function setTrackingInfo(?int $userId, string $appName, array $userData): void
    {
    }
}

class TestQHController
{
}
