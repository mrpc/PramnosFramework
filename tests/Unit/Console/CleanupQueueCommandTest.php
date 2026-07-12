<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Pramnos\Console\Commands\CleanupQueue;
use Pramnos\Queue\QueueManager;

/**
 * Unit tests for Pramnos\Console\Commands\CleanupQueue.
 *
 * Uses the Testable Subclass pattern to exercise configure(), execute(), and
 * the hook methods (getControllerName(), createQueueManager()) without touching
 * a live database.
 *
 * TestableCleanupQueue overrides createQueueManager() to return a configurable
 * stub, and exposes a runExecute() wrapper so that execute() can be invoked
 * without bootstrapping the full Symfony Console application.
 *
 * Tested paths:
 *   - configure() option registration (name, hours, include-failed,
 *     include-warning, limit)
 *   - execute() default path: completed + failed tasks purged
 *   - execute() with --include-warning: warning tasks also purged
 *   - execute() with --no-include-failed: failed tasks NOT purged
 *   - getControllerName() default value
 *   - createQueueManager() factory returns QueueManager
 */
#[CoversClass(CleanupQueue::class)]
class CleanupQueueCommandTest extends TestCase
{
    // ── configure() ───────────────────────────────────────────────────────────

    /**
     * configure() must register the command with the name 'queue:cleanup' and
     * include all expected options (hours, include-failed, include-warning, limit).
     *
     * This test exercises lines 28-56 of CleanupQueue.
     */
    public function testConfigureRegistersAllOptions(): void
    {
        // Arrange / Act
        $cmd = new CleanupQueue();

        // Assert
        $def = $cmd->getDefinition();
        $this->assertSame('queue:cleanup', $cmd->getName(),
            'Command name must be queue:cleanup');
        $this->assertTrue($def->hasOption('hours'),
            'Must register --hours option');
        $this->assertTrue($def->hasOption('include-failed'),
            'Must register --include-failed option');
        $this->assertTrue($def->hasOption('include-warning'),
            'Must register --include-warning option');
        $this->assertTrue($def->hasOption('limit'),
            'Must register --limit option');
    }

    // ── getControllerName() ───────────────────────────────────────────────────

    /**
     * getControllerName() must return 'Queueitems' by default.
     *
     * This covers the default hook method (lines 109-111) that subclasses
     * override to use an application-specific controller.
     */
    public function testGetControllerNameReturnsDefault(): void
    {
        // Arrange
        $cmd    = new CleanupQueue();
        $method = new \ReflectionMethod(CleanupQueue::class, 'getControllerName');

        // Act
        $result = $method->invoke($cmd);

        // Assert
        $this->assertSame('Queueitems', $result,
            'getControllerName() must return "Queueitems" by default');
    }

    // ── createQueueManager() ──────────────────────────────────────────────────

    /**
     * createQueueManager() must return a QueueManager instance when given a
     * controller.
     *
     * This covers lines 122-124: the factory hook that subclasses override to
     * inject a custom QueueManager implementation.
     */
    public function testCreateQueueManagerReturnsQueueManagerInstance(): void
    {
        // Arrange
        $cmd    = new CleanupQueue();
        $method = new \ReflectionMethod(CleanupQueue::class, 'createQueueManager');

        // Act — pass null controller; QueueManager constructor stores it
        $mgr = $method->invoke($cmd, null);

        // Assert
        $this->assertInstanceOf(QueueManager::class, $mgr,
            'createQueueManager() must produce a QueueManager instance');
    }

    // ── execute() ─────────────────────────────────────────────────────────────

    /**
     * execute() default run must purge 'completed' and 'failed' tasks older
     * than 24 hours (lines 59-97).
     *
     * The testable subclass provides a stub QueueManager so no real database is
     * needed.  We verify that purgeOldTasks() is called with the expected
     * statuses and that the output contains the expected summary lines.
     */
    public function testExecuteDefaultPurgesCompletedAndFailedTasks(): void
    {
        // Arrange
        $command = $this->createTestableCommand(purgeReturns: [5, 0]);

        $input  = new ArrayInput([], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — command succeeded
        $this->assertSame(0, $result,
            'execute() must return Command::SUCCESS (0) on a normal run');

        // Verify output contains expected messages
        $text = $output->fetch();
        $this->assertStringContainsString('Purging tasks with status: completed, failed', $text,
            'execute() must print the list of statuses being purged');
        $this->assertStringContainsString('24 hours ago', $text,
            'execute() must print the age threshold (24 hours default)');
        $this->assertStringContainsString('Deleted 5 task(s)', $text,
            'execute() must print the count of deleted completed/failed tasks');

        // purgeOldTasks() must be called twice: once for main statuses, once for warning-only
        $this->assertSame(2, $command->purgeCallCount,
            'execute() must call purgeOldTasks() twice (main + warning-only decay)');

        // First call uses the main statuses; second call targets ['warning'] with 10× hours
        [$statusesCall1, $hoursCall1] = $command->purgeCallArgs[0];
        $this->assertContains('completed', $statusesCall1,
            'First purge call must include "completed"');
        $this->assertContains('failed', $statusesCall1,
            'First purge call must include "failed" when --no-include-failed is not set');
        $this->assertSame(24, $hoursCall1,
            'First purge call must use the default 24-hour threshold');

        [$statusesCall2, $hoursCall2] = $command->purgeCallArgs[1];
        $this->assertSame(['warning'], $statusesCall2,
            'Second purge call must target only warning tasks');
        $this->assertSame(240, $hoursCall2,
            'Second purge call must use 10× the hours threshold for warning tasks');
    }

    /**
     * execute() with --include-warning must add 'warning' to the primary
     * purge statuses (line 77-79), causing the first purgeOldTasks() call to
     * include 'warning' in its statuses list.
     */
    public function testExecuteWithIncludeWarningAddWarningToStatuses(): void
    {
        // Arrange
        $command = $this->createTestableCommand(purgeReturns: [3, 1]);

        $input  = new ArrayInput(['--include-warning' => true], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(0, $result);

        $text = $output->fetch();
        $this->assertStringContainsString('completed, failed, warning', $text,
            '--include-warning must add "warning" to the output status list');

        [$statusesCall1] = $command->purgeCallArgs[0];
        $this->assertContains('warning', $statusesCall1,
            '--include-warning must pass "warning" to the first purgeOldTasks() call');
    }

    /**
     * execute() with --no-include-failed must omit 'failed' from the primary
     * purge statuses (lines 74-76: the getOption('include-failed') branch is false),
     * leaving only 'completed' in the first purge call.
     */
    public function testExecuteWithNoIncludeFailedOmitsFailedStatus(): void
    {
        // Arrange
        $command = $this->createTestableCommand(purgeReturns: [2, 0]);

        $input  = new ArrayInput(['--no-include-failed' => true], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(0, $result);

        $text = $output->fetch();
        $this->assertStringContainsString('Purging tasks with status: completed', $text,
            '--no-include-failed must exclude "failed" from the purge status list');
        $this->assertStringNotContainsString('completed, failed', $text,
            '--no-include-failed must not include "failed" in the status list');

        [$statusesCall1] = $command->purgeCallArgs[0];
        $this->assertNotContains('failed', $statusesCall1,
            '--no-include-failed must not pass "failed" to purgeOldTasks()');
    }

    /**
     * execute() with a custom --hours value must pass that threshold to
     * purgeOldTasks(), and the warning decay must use 10× that value.
     */
    public function testExecuteWithCustomHoursPassesCorrectThreshold(): void
    {
        // Arrange
        $command = $this->createTestableCommand(purgeReturns: [0, 0]);

        $input  = new ArrayInput(['--hours' => '48'], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(0, $result);

        $text = $output->fetch();
        $this->assertStringContainsString('48 hours ago', $text,
            'execute() must print the custom hours threshold');

        [$statusesCall1, $hoursCall1] = $command->purgeCallArgs[0];
        $this->assertSame(48, $hoursCall1,
            'execute() must pass the custom --hours value to the first purgeOldTasks()');

        [$statusesCall2, $hoursCall2] = $command->purgeCallArgs[1];
        $this->assertSame(480, $hoursCall2,
            'Warning decay purge must use 10× the custom --hours value');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a TestableCleanupQueue wired to a fake console application.
     *
     * @param array<int, int> $purgeReturns  Values returned by successive purgeOldTasks() calls.
     */
    private function createTestableCommand(array $purgeReturns = [0, 0]): TestableCleanupQueue
    {
        $command = new TestableCleanupQueue($purgeReturns);

        // Build a minimal fake console application so that $this->getApplication()
        // inside execute() returns a valid object with internalApplication.
        $fakeConsoleApp = new TestCQConsoleApplication();

        $command->setApplication($fakeConsoleApp);

        return $command;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Testable subclass — overrides createQueueManager() to return a stub
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Subclass that replaces the QueueManager factory with a recording stub,
 * preventing any real database calls during tests.
 */
class TestableCleanupQueue extends CleanupQueue
{
    /** Number of times purgeOldTasks() was called on the stub QueueManager. */
    public int $purgeCallCount = 0;

    /** Arguments passed to each purgeOldTasks() call: [[statuses, hours], ...] */
    public array $purgeCallArgs = [];

    /** @param array<int, int> $purgeReturns  Values returned by successive purgeOldTasks() calls. */
    public function __construct(private readonly array $purgeReturns = [0, 0])
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
        $purgeReturns = $this->purgeReturns;
        $cmd          = $this;
        $callIndex    = 0;

        return new class($purgeReturns, $cmd, $callIndex) extends QueueManager {
            private int $callIdx = 0;

            public function __construct(
                private readonly array $returns,
                private readonly TestableCleanupQueue $cmd,
                int $startIdx,
            ) {
                // Deliberately skip parent::__construct() — avoids DB calls.
                $this->callIdx = $startIdx;
            }

            public function getStats(): array
            {
                return ['total' => 10, 'completed' => 8, 'failed' => 2];
            }

            public function purgeOldTasks(int $hours = 24, array $statuses = ['completed', 'failed'], int $limit = 0): int
            {
                $this->cmd->purgeCallCount++;
                $this->cmd->purgeCallArgs[] = [$statuses, $hours];
                $result = $this->returns[$this->callIdx] ?? 0;
                $this->callIdx++;
                return $result;
            }
        };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fake console application and internal application stubs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Minimal Symfony Console Application stub that carries an internalApplication.
 */
class TestCQConsoleApplication extends \Symfony\Component\Console\Application
{
    public TestCQInternalApplication $internalApplication;

    public function __construct()
    {
        parent::__construct('test', '1.0');
        $this->internalApplication = new TestCQInternalApplication();
        $this->setAutoExit(false);
    }
}

/**
 * Minimal internal Application stub: init(), database->setTrackingInfo(),
 * and getController() — all no-ops.
 */
class TestCQInternalApplication
{
    public TestCQDatabase $database;

    public function __construct()
    {
        $this->database = new TestCQDatabase();
    }

    public function init(): void
    {
        // No-op — prevents real database bootstrap in tests.
    }

    public function getController(string $name): object
    {
        return new \stdClass();
    }
}

/**
 * Minimal database stub that absorbs setTrackingInfo() without a real DB.
 */
class TestCQDatabase
{
    public function setTrackingInfo(?int $userId, string $appName, array $userData): void
    {
        // No-op.
    }
}
