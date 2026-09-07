<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Pramnos\Console\Commands\QueueReclaim;
use Pramnos\Queue\QueueManager;

/**
 * `queue:reclaim` — the command around {@see QueueManager::reclaimAbandonedTasks()}.
 *
 * What the reclaim itself does is asserted against real MySQL and PostgreSQL in
 * `tests/Integration/Queue/QueueManagerMySQLTest.php`, where the states it acts on can
 * actually exist. These tests are about the command: that the options reach the manager
 * as the operator wrote them, that `--dry-run` writes nothing, and that the report
 * distinguishes the two outcomes — a requeue and a permanent failure are not the same
 * news.
 */
#[CoversClass(QueueReclaim::class)]
class QueueReclaimCommandTest extends TestCase
{
    /**
     * The command with a manager that records instead of writing.
     *
     * @param array{requeued:int,failed:int} $counts What the reclaim reports back
     */
    private function command(array $counts = ['requeued' => 0, 'failed' => 0]): TestableQueueReclaim
    {
        $command = new TestableQueueReclaim($counts);
        $command->setApplication(new TestQRConsoleApplication());

        return $command;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function invoke(TestableQueueReclaim $command, array $options = []): string
    {
        $output = new BufferedOutput();
        $input  = new ArrayInput($options, $command->getDefinition());

        $command->runExecute($input, $output);

        return $output->fetch();
    }

    /**
     * The options are registered, under the names the documentation uses.
     *
     * A command whose flag is spelled differently from its guide is a command nobody runs
     * twice.
     */
    public function testTheOptionsAreRegistered(): void
    {
        // Arrange
        $command = new QueueReclaim();

        // Assert
        $this->assertSame('queue:reclaim', $command->getName());

        foreach (['grace', 'type', 'dry-run'] as $option) {
            $this->assertTrue(
                $command->getDefinition()->hasOption($option),
                'missing option: ' . $option
            );
        }
    }

    /**
     * With nothing to reclaim it says so, rather than printing zeroes.
     *
     * This runs on a schedule beside `queue:cleanup`, so its ordinary output is what
     * somebody reads in a cron mail every hour. "Nothing to reclaim" is a sentence; two
     * zeroes are a puzzle.
     */
    public function testNothingToReclaimIsSaidPlainly(): void
    {
        // Act
        $text = $this->invoke($this->command());

        // Assert
        $this->assertStringContainsString('Nothing to reclaim', $text);
    }

    /**
     * The two outcomes are reported separately, because they mean different things.
     *
     * A requeue is work that will be done. A permanent failure is work that will not, and
     * that nobody would otherwise hear about — the worker died before it could record its
     * own failure, which is the entire reason this command exists.
     */
    public function testTheTwoOutcomesAreReportedSeparately(): void
    {
        // Act
        $text = $this->invoke($this->command(['requeued' => 7, 'failed' => 2]));

        // Assert
        $this->assertStringContainsString('Requeued 7', $text);
        $this->assertStringContainsString('2 as failed', $text);
        $this->assertStringContainsString('no attempts left', $text);
    }

    /**
     * The grace period and the types reach the manager as given.
     *
     * The assertion that the flags are wired rather than merely declared. `--type` is
     * repeatable, so it arrives as an array and must not be flattened into one string.
     */
    public function testTheOptionsReachTheManager(): void
    {
        // Arrange
        $command = $this->command(['requeued' => 1, 'failed' => 0]);

        // Act
        $this->invoke($command, ['--grace' => '90', '--type' => ['imports', 'exports']]);

        // Assert
        $this->assertSame(90, $command->reclaimedWith[0]);
        $this->assertSame(['imports', 'exports'], $command->reclaimedWith[1]);
    }

    /**
     * With no `--type`, the manager is asked for everything rather than for an empty list.
     *
     * The distinction matters: an empty array as a type filter is `type IN ()`, which
     * matches nothing — so a reclaim run without `--type` would silently do nothing at all,
     * which is the shape of bug that gets found months later during an incident.
     */
    public function testWithNoTypeItAsksForEverything(): void
    {
        // Arrange
        $command = $this->command();

        // Act
        $this->invoke($command);

        // Assert
        $this->assertNull($command->reclaimedWith[1], 'an empty type filter matches nothing');
    }

    /**
     * `--dry-run` writes nothing and says what it looked at.
     *
     * This command changes rows in a live queue, so the operator gets to look first.
     */
    public function testADryRunChangesNothing(): void
    {
        // Arrange
        $command = $this->command(['requeued' => 5, 'failed' => 1]);

        // Act
        $text = $this->invoke($command, ['--dry-run' => true]);

        // Assert
        $this->assertSame([], $command->reclaimedWith, 'a dry run reclaimed');
        $this->assertStringContainsString('Dry run', $text);
        $this->assertStringContainsString('processing', $text);
    }

    /**
     * The hooks a subclass overrides answer their defaults.
     *
     * `Queueitems` is the controller name the rest of the queue commands use, and
     * `createQueueManager()` is the documented seam for an application's own subclass.
     */
    public function testTheHooksHaveTheirDocumentedDefaults(): void
    {
        // Arrange
        $command = new class extends QueueReclaim {
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
            $command->exposeManager(new TestQRController())
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Stubs
// ─────────────────────────────────────────────────────────────────────────────

class TestableQueueReclaim extends QueueReclaim
{
    /** @var array{0:int,1:string|array|null}|array{} How reclaimAbandonedTasks() was called */
    public array $reclaimedWith = [];

    /** @param array{requeued:int,failed:int} $counts */
    public function __construct(private readonly array $counts)
    {
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
        $counts  = $this->counts;

        return new class ($command, $counts) extends QueueManager {
            public function __construct(
                private readonly TestableQueueReclaim $command,
                private readonly array $counts
            ) {
                // Deliberately not calling parent::__construct() — it needs a database.
            }

            public function reclaimAbandonedTasks(
                int $graceSeconds = 0,
                string|array|null $taskTypes = null
            ): array {
                $this->command->reclaimedWith = [$graceSeconds, $taskTypes];

                return $this->counts;
            }

            public function throughput(
                int $windowSeconds = 300,
                string|array|null $taskTypes = null
            ): array {
                return [
                    'window' => $windowSeconds, 'arrivals' => 0, 'completions' => 0,
                    'net' => 0, 'pending' => 3, 'processing' => 4, 'losing' => false,
                ];
            }
        };
    }
}

class TestQRConsoleApplication extends \Symfony\Component\Console\Application
{
    public TestQRInternalApplication $internalApplication;

    public function __construct()
    {
        parent::__construct('test', '1.0');
        $this->internalApplication = new TestQRInternalApplication();
        $this->setAutoExit(false);
    }
}

class TestQRInternalApplication
{
    public TestQRDatabase $database;

    public function __construct()
    {
        $this->database = new TestQRDatabase();
    }

    public function init(): void
    {
    }

    public function getController(string $name): object
    {
        return new TestQRController();
    }
}

class TestQRDatabase
{
    public function setTrackingInfo(?int $userId, string $appName, array $userData): void
    {
    }
}

class TestQRController
{
}
