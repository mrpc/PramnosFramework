<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\TimescaleDrain;
use Pramnos\Database\DeferredWriteQueue;
use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A queue with a scripted backlog and no database.
 */
class ScriptedQueue extends DeferredWriteQueue
{
    /** @var list<string> Tables the queue will claim have work */
    public array $tables = [];

    /** @var array<string, int> Pending rows per table */
    public array $pendingRowCount = [];

    /** @var array<string, int> Failed rows per table */
    public array $failedRowCount = [];

    /** @var array<string, int|null> Cutoffs per table */
    public array $cutoffs2 = [];

    /** @var array<string, array{chunks: int, inserted: int, failed: int}> What a drain returns */
    public array $result = [];

    /** @var int How many rows retryFailed() will claim to have reset */
    public int $reset = 0;

    /** No connection needed: every method a caller reaches is overridden. */
    public function __construct()
    {
    }

    public function tablesWithPendingRows(): array
    {
        return $this->tables;
    }

    public function pending(?string $table = null): int
    {
        return $this->pendingRowCount[$table ?? ''] ?? 0;
    }

    public function failed(?string $table = null): int
    {
        return $this->failedRowCount[$table ?? ''] ?? 0;
    }

    public function writeCutoff(string $table): ?int
    {
        return $this->cutoffs2[$table] ?? null;
    }

    public function retryFailed(?string $table = null): int
    {
        return $this->reset;
    }

    public function process(?string $only = null, ?callable $reporter = null): array
    {
        if ($reporter !== null) {
            $reporter('  chunk _hyper_1_1_chunk (compressed)');
        }

        return $this->result;
    }
}

/**
 * Exposes the command's two halves without going through execute(), which
 * would need a booted application.
 */
class TimescaleDrainProbe extends TimescaleDrain
{
    /**
     * Render the backlog report.
     */
    public function showStatus(BufferedOutput $output, DeferredWriteQueue $queue, ?string $only): int
    {
        return $this->status($output, $queue, $only);
    }

    /**
     * Run the drain and render its result.
     */
    public function runDrain(BufferedOutput $output, DeferredWriteQueue $queue, ?string $only): int
    {
        return $this->drain($output, $queue, $only);
    }
}

/**
 * The command with its queue replaced, so execute() can be driven end to end.
 */
class TimescaleDrainWithScriptedQueue extends TimescaleDrain
{
    /** @var ScriptedQueue The queue execute() will be given */
    public ScriptedQueue $queue;

    public function __construct(ScriptedQueue $queue)
    {
        $this->queue = $queue;
        parent::__construct();
    }

    protected function makeQueue($db): DeferredWriteQueue
    {
        return $this->queue;
    }
}

/**
 * Covers `timescale:drain` — what it tells an operator, and what it returns.
 *
 * The exit code is the part with consequences: this command runs from cron, so
 * a run that leaves rows unwritten must exit non-zero, and one that had nothing
 * to do must not. Getting that backwards means either an alert on every quiet
 * hour or silence while data is being dropped.
 */
class TimescaleDrainTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearAppSingleton();
    }

    protected function tearDown(): void
    {
        $this->clearAppSingleton();
        parent::tearDown();
    }

    /**
     * Forget whatever application the previous test installed.
     */
    private function clearAppSingleton(): void
    {
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /**
     * Make Application::getInstance() return an application with a database.
     */
    private function injectApp(): void
    {
        $app           = $this->createMock(Application::class);
        $app->database = $this->createMock(\Pramnos\Database\Database::class);

        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, ['default' => $app]);
        $ref->getProperty('lastUsedApplication')->setValue(null, 'default');
    }

    /**
     * Wrap a command in a tester.
     */
    private function testerFor(Command $command): CommandTester
    {
        (new SymfonyApp())->add($command);

        return new CommandTester($command);
    }

    /**
     * Without a booted application the command refuses rather than fataling.
     *
     * It runs from cron, where a fatal error is a stack trace in a mail spool
     * and a non-zero exit is an actionable message.
     */
    public function testItRefusesWithoutAnApplication(): void
    {
        // Arrange: no application registered.
        $tester = $this->testerFor(new TimescaleDrain());

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('No application instance', $tester->getDisplay());
    }

    /**
     * A plain run drains and reports.
     */
    public function testExecuteDrains(): void
    {
        // Arrange
        $this->injectApp();
        $queue = new ScriptedQueue();
        $queue->result = ['readings' => ['chunks' => 1, 'inserted' => 3, 'failed' => 0]];
        $tester = $this->testerFor(new TimescaleDrainWithScriptedQueue($queue));

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('3 written', $tester->getDisplay());
    }

    /**
     * --status reports without writing.
     *
     * The distinction matters operationally: an operator inspecting a backlog
     * before a maintenance window must not be the one who drains it.
     */
    public function testExecuteWithStatusDoesNotDrain(): void
    {
        // Arrange
        $this->injectApp();
        $queue = new ScriptedQueue();
        $queue->tables          = ['readings'];
        $queue->pendingRowCount = ['readings' => 5];
        $queue->result          = ['readings' => ['chunks' => 1, 'inserted' => 5, 'failed' => 0]];
        $tester = $this->testerFor(new TimescaleDrainWithScriptedQueue($queue));

        // Act
        $code = $tester->execute(['--status' => true]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('readings', $text);
        $this->assertStringNotContainsString('written', $text, 'Nothing was drained');
    }

    /**
     * --retry-failed re-queues before draining, and says how many.
     */
    public function testExecuteWithRetryFailedRequeuesFirst(): void
    {
        // Arrange
        $this->injectApp();
        $queue = new ScriptedQueue();
        $queue->reset  = 4;
        $queue->result = ['readings' => ['chunks' => 0, 'inserted' => 4, 'failed' => 0]];
        $tester = $this->testerFor(new TimescaleDrainWithScriptedQueue($queue));

        // Act
        $code = $tester->execute(['--retry-failed' => true]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('4 failed row(s) put back', $text);
        $this->assertStringContainsString('4 written', $text);
    }

    /**
     * --table limits the run to one table.
     */
    public function testExecuteWithATableLimitsTheRun(): void
    {
        // Arrange
        $this->injectApp();
        $queue = new ScriptedQueue();
        $queue->cutoffs2 = ['readings' => strtotime('2026-08-02 00:00:00')];
        $tester = $this->testerFor(new TimescaleDrainWithScriptedQueue($queue));

        // Act
        $code = $tester->execute(['--table' => 'readings', '--status' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('2026-08-02', $tester->getDisplay());
    }

    /**
     * The real command builds a real queue.
     *
     * The seam exists for the tests above; this checks it still hands back what
     * production expects, rather than something a test double replaced.
     */
    public function testTheDefaultQueueIsARealOne(): void
    {
        // Arrange
        $command = new TimescaleDrain();
        $method  = new \ReflectionMethod(TimescaleDrain::class, 'makeQueue');

        // Act
        $queue = $method->invoke($command, $this->createMock(\Pramnos\Database\Database::class));

        // Assert
        $this->assertInstanceOf(DeferredWriteQueue::class, $queue);
    }

    /**
     * The command is registered under the name operators type.
     */
    public function testItIsNamedTimescaleDrain(): void
    {
        // Arrange & Act
        $command = new TimescaleDrain();

        // Assert
        $this->assertSame('timescale:drain', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('table'));
        $this->assertTrue($command->getDefinition()->hasOption('status'));
        $this->assertTrue($command->getDefinition()->hasOption('retry-failed'));
    }

    /**
     * A successful drain reports what it wrote and exits zero.
     */
    public function testASuccessfulDrainExitsZero(): void
    {
        // Arrange
        $queue = new ScriptedQueue();
        $queue->result = ['readings' => ['chunks' => 2, 'inserted' => 40, 'failed' => 0]];
        $output = new BufferedOutput();

        // Act
        $code = (new TimescaleDrainProbe())->runDrain($output, $queue, null);
        $text = $output->fetch();

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('40 written', $text);
        $this->assertStringContainsString('2 chunk(s)', $text);
        $this->assertStringContainsString('_hyper_1_1_chunk', $text, 'Progress was passed through');
    }

    /**
     * A drain that could not write everything exits non-zero.
     *
     * This is what makes the failure visible: cron reports a non-zero exit, and
     * the rows are still there with their error message rather than gone.
     */
    public function testADrainWithFailedRowsExitsNonZero(): void
    {
        // Arrange
        $queue = new ScriptedQueue();
        $queue->result = ['readings' => ['chunks' => 1, 'inserted' => 5, 'failed' => 3]];
        $output = new BufferedOutput();

        // Act
        $code = (new TimescaleDrainProbe())->runDrain($output, $queue, null);
        $text = $output->fetch();

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('3 failed', $text);
        $this->assertStringContainsString('--retry-failed', $text, 'It says what to do next');
    }

    /**
     * An empty queue is a success, not a failure.
     *
     * Most runs of an hourly cron job have nothing to do; if that exited
     * non-zero the alert would be permanent and therefore ignored.
     */
    public function testAnEmptyQueueIsASuccess(): void
    {
        // Arrange
        $queue  = new ScriptedQueue();
        $output = new BufferedOutput();

        // Act
        $code = (new TimescaleDrainProbe())->runDrain($output, $queue, null);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Nothing was waiting', $output->fetch());
    }

    /**
     * The status report shows the backlog and the writable-from time per table.
     *
     * The cutoff is the number that explains *why* rows are being queued, so a
     * report without it leaves the operator guessing at the policy.
     */
    public function testTheStatusReportShowsTheBacklogAndTheCutoff(): void
    {
        // Arrange
        $queue = new ScriptedQueue();
        $queue->tables          = ['readings'];
        $queue->pendingRowCount = ['readings' => 1234];
        $queue->failedRowCount  = ['readings' => 2];
        $queue->cutoffs2        = ['readings' => strtotime('2026-08-01 00:00:00')];
        $output = new BufferedOutput();

        // Act
        $code = (new TimescaleDrainProbe())->showStatus($output, $queue, null);
        $text = $output->fetch();

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('1,234', $text);
        $this->assertStringContainsString('2026-08-01', $text);
    }

    /**
     * A table with no compression policy says so rather than showing a date.
     *
     * "any time" is the honest answer on MySQL and on any database whose
     * hypertable is not compressed — printing a date there would suggest a
     * policy that does not exist.
     */
    public function testATableWithNoCutoffSaysSo(): void
    {
        // Arrange
        $queue = new ScriptedQueue();
        $queue->tables          = ['readings'];
        $queue->pendingRowCount = ['readings' => 1];
        $output = new BufferedOutput();

        // Act
        (new TimescaleDrainProbe())->showStatus($output, $queue, null);

        // Assert
        $this->assertStringContainsString('no compression policy', $output->fetch());
    }

    /**
     * An empty queue that still holds failed rows says so.
     *
     * "Nothing is waiting" while rows sit marked failed is the one way this
     * command could mislead an operator into thinking the queue is healthy.
     */
    public function testAnEmptyQueueStillMentionsFailedRows(): void
    {
        // Arrange
        $queue = new ScriptedQueue();
        $queue->failedRowCount = ['' => 7];
        $output = new BufferedOutput();

        // Act
        $code = (new TimescaleDrainProbe())->showStatus($output, $queue, null);
        $text = $output->fetch();

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Nothing is waiting', $text);
        $this->assertStringContainsString('7 row(s) failed earlier', $text);
    }

    /**
     * An empty and clean queue says only that.
     */
    public function testAnEmptyAndCleanQueueSaysNothingElse(): void
    {
        // Arrange
        $queue  = new ScriptedQueue();
        $output = new BufferedOutput();

        // Act
        (new TimescaleDrainProbe())->showStatus($output, $queue, null);
        $text = $output->fetch();

        // Assert
        $this->assertStringContainsString('Nothing is waiting', $text);
        $this->assertStringNotContainsString('failed earlier', $text);
    }

    /**
     * Naming a table reports that table even when nothing is queued for it.
     *
     * `--table=x --status` is how an operator checks one table's cutoff; making
     * it silent when the backlog is empty would remove the answer they came for.
     */
    public function testNamingATableReportsItRegardless(): void
    {
        // Arrange
        $queue = new ScriptedQueue();
        $queue->cutoffs2 = ['readings' => strtotime('2026-08-05 12:00:00')];
        $output = new BufferedOutput();

        // Act
        (new TimescaleDrainProbe())->showStatus($output, $queue, 'readings');
        $text = $output->fetch();

        // Assert
        $this->assertStringContainsString('readings', $text);
        $this->assertStringContainsString('2026-08-05', $text);
    }
}
