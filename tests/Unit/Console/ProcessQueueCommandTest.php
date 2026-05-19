<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Pramnos\Console\Commands\ProcessQueue;
use Pramnos\Queue\Worker;
use Pramnos\Queue\QueueManager;

/**
 * Unit tests for Pramnos\Console\Commands\ProcessQueue.
 *
 * Uses the Testable Subclass pattern to test the daemon execute() loop and
 * pure helper methods without starting a real queue, touching a live database,
 * or blocking on system-level sleeps.
 *
 * Two harnesses are provided:
 *   TestableProcessQueue          – exposes protected helper methods via run*()
 *                                   wrappers; used for isDatabaseFailure(),
 *                                   attemptDatabaseReconnect(), processBatch().
 *   TestableExecutableProcessQueue – overrides all I/O side-effects so that
 *                                   execute() can run deterministically in a
 *                                   test; used for execute() scenario tests.
 *
 * Daemon loop termination in execute() tests is achieved by writing a stop
 * file before the command starts, by supplying enough controlled now() values
 * to trigger runtime/task-limit exit conditions, or by overriding specific
 * hook methods.
 *
 * Lock files (QUEUE_PROCESSOR_<workerId>) are written to ROOT/var/ and cleaned
 * up in tearDown() so tests remain isolated.
 */
#[CoversClass(ProcessQueue::class)]
class ProcessQueueCommandTest extends TestCase
{
    /** @var string[] Paths of lock/stop files created during a test. */
    private array $filesToCleanup = [];
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->filesToCleanup as $path) {
            @unlink($path);
        }
        $this->filesToCleanup = [];

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns ROOT/var/QUEUE_PROCESSOR_<workerId> and tracks it for cleanup.
     */
    private function trackLockFile(string $workerId): string
    {
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $path = $base . '/var/QUEUE_PROCESSOR_' . $workerId;
        $this->filesToCleanup[] = $path;
        $this->filesToCleanup[] = $path . '.stop';
        return $path;
    }

    /**
     * Write a stop file so the daemon loop exits on its first check.
     */
    private function writeStopFile(string $workerId): void
    {
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        file_put_contents($base . '/var/QUEUE_PROCESSOR_' . $workerId . '.stop', '1');
    }

    /**
     * Build a TestableExecutableProcessQueue wired to a fake console application.
     */
    private function createExecutable(): TestableExecutableProcessQueue
    {
        $command = new TestableExecutableProcessQueue();
        $consoleApp = new TestPQConsoleApplication();
        $consoleApp->internalApplication = new TestPQInternalApplication();
        $command->setApplication($consoleApp);
        return $command;
    }

    /**
     * Build a TestableProcessQueue used for pure-helper-method tests.
     * No application wiring needed since these methods don't call execute().
     */
    private function createPure(): TestableProcessQueue
    {
        return new TestableProcessQueue();
    }

    // ── configure() ───────────────────────────────────────────────────────────

    /**
     * configure() must register the command as 'queue:process' with every
     * expected option so application code can add the command without errors.
     */
    public function testConfigureRegistersAllOptions(): void
    {
        // Arrange / Act
        $cmd = new ProcessQueue();

        // Assert
        $def = $cmd->getDefinition();
        $this->assertSame('queue:process', $cmd->getName());
        $this->assertTrue($def->hasOption('daemon'));
        $this->assertTrue($def->hasOption('runtime'));
        $this->assertTrue($def->hasOption('sleep'));
        $this->assertTrue($def->hasOption('limit'));
        $this->assertTrue($def->hasOption('batch'));
        $this->assertTrue($def->hasOption('type'));
        $this->assertTrue($def->hasOption('force'));
        $this->assertTrue($def->hasOption('worker-id'));
        $this->assertTrue($def->hasOption('start-from'));
        $this->assertTrue($def->hasOption('reverse-order'));
    }

    // ── execute() guard paths ─────────────────────────────────────────────────

    /**
     * execute() must return 1 immediately when another instance is detected
     * via checkIfRunning(), and must not attempt to process any tasks.
     *
     * We write a real lock file so that the hard-coded filemtime() call in
     * the "already running" error path does not emit a PHP warning.
     */
    public function testExecuteReturnsOneWhenAlreadyRunning(): void
    {
        // Arrange
        $workerId = 'already-running-' . bin2hex(random_bytes(4));
        $lockPath = $this->trackLockFile($workerId);
        // Write a lock file so filemtime() succeeds inside execute().
        file_put_contents($lockPath, getmypid() . "\n");

        $command = $this->createExecutable();
        $command->runningOverride = true;

        $input  = new ArrayInput(['--worker-id' => $workerId], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — must bail out without touching the queue
        $this->assertSame(1, $result);
        $this->assertSame(0, $command->processBatchCallCount);
        $this->assertStringContainsString('already running', $output->fetch());
    }

    /**
     * execute() must return 1 and output an error when --start-from carries a
     * string that strtotime() cannot parse, without touching the queue.
     */
    public function testExecuteReturnsOneForInvalidStartFromDate(): void
    {
        // Arrange
        $workerId = 'invalid-date-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $input   = new ArrayInput([
            '--worker-id' => $workerId,
            '--start-from' => 'definitely-not-a-date',
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(1, $result);
        $this->assertStringContainsString('Invalid date format', $output->fetch());
        $this->assertSame(0, $command->processBatchCallCount);
    }

    // ── execute() one-shot mode ───────────────────────────────────────────────

    /**
     * In one-shot mode (no --daemon), execute() must call processBatch() once,
     * render a dashboard, and return 0.
     */
    public function testExecuteOneShotCallsProcessBatchAndReturnsZero(): void
    {
        // Arrange
        $workerId = 'oneshot-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchResults = [3]; // 3 tasks processed

        $input  = new ArrayInput(['--worker-id' => $workerId], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — one batch, completed state, success exit code
        $this->assertSame(0, $result);
        $this->assertSame(1, $command->processBatchCallCount);
        $this->assertSame('completed', $command->lastDashboardState);
        $this->assertSame('oneshot', $command->lastDashboardMode);
        $this->assertStringContainsString('Processed 3 tasks', $output->fetch());
    }

    /**
     * execute() must pass the parsed task types list to processBatch().
     * The --type option accepts a comma-separated list.
     */
    public function testExecuteOneShotPassesTaskTypesToProcessBatch(): void
    {
        // Arrange
        $workerId = 'type-test-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchResults = [0];

        $input = new ArrayInput([
            '--worker-id' => $workerId,
            '--type' => 'sync,stats',
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $command->runExecute($input, $output);

        // Assert — task types appear in the printed configuration block
        $this->assertStringContainsString('Task types: sync, stats', $output->fetch());
    }

    /**
     * execute() one-shot must return 1 when processBatch() throws an unexpected
     * (non-database) exception, and must write the error message to output.
     */
    public function testExecuteOneShotReturnOneOnUnexpectedException(): void
    {
        // Arrange
        $workerId = 'oneshot-ex-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchThrowable = new \RuntimeException('unexpected crash');

        $input  = new ArrayInput(['--worker-id' => $workerId], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(1, $result);
        $this->assertStringContainsString('unexpected crash', $output->fetch());
    }

    // ── execute() daemon mode ─────────────────────────────────────────────────

    /**
     * In daemon mode, the loop must exit cleanly when a stop file is detected,
     * printing the stop-signal message without processing any tasks.
     */
    public function testExecuteDaemonExitsOnStopFile(): void
    {
        // Arrange
        $workerId = 'stop-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);
        $this->writeStopFile($workerId);   // stop before first iteration

        $command = $this->createExecutable();
        $command->nowValues = array_fill(0, 20, 1000);

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — graceful exit, no tasks processed
        $this->assertSame(0, $result);
        $this->assertSame(0, $command->processBatchCallCount);
        $this->assertStringContainsString('Stop signal detected', $output->fetch());
    }

    /**
     * In daemon mode with --runtime=N, the loop must exit when
     * now() - startTime >= N, printing the max-runtime message.
     */
    public function testExecuteDaemonExitsOnMaxRuntime(): void
    {
        // Arrange
        $workerId = 'runtime-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        // First several now() calls initialise start time, lastRefresh, etc.
        // Then return a time far ahead so (now - start) >= 1.
        $command->nowValues = [
            1000, 1000, 1000, 1000, 1000, 1000, // init & pre-loop
            1002,                                 // first loop: runtime check triggers
            1002, 1002, 1002, 1002, 1002,
        ];

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
            '--runtime'   => 1,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(0, $result);
        $this->assertStringContainsString('Maximum runtime reached', $output->fetch());
    }

    /**
     * In daemon mode with --limit=1, the loop must exit after processing
     * exactly one task and print the task-count summary.
     */
    public function testExecuteDaemonExitsOnTaskLimit(): void
    {
        // Arrange
        $workerId = 'limit-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchResults = [1];    // one task in the first batch
        $command->nowValues = array_fill(0, 30, 1000);

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
            '--limit'     => 1,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(0, $result);
        $this->assertSame(1, $command->processBatchCallCount);
        $this->assertStringContainsString('Processed 1 tasks', $output->fetch());
    }

    /**
     * In daemon mode, heartbeat() must be called when the elapsed time since
     * the last heartbeat reaches 30 seconds.
     */
    public function testExecuteDaemonCallsHeartbeatAfter30Seconds(): void
    {
        // Arrange
        $workerId = 'hb-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchResults = [1];
        // now() sequence: init values, then jump 31s for heartbeat trigger,
        // then limit reached.
        $command->nowValues = [
            1000, 1000, 1000, 1000, 1000, 1000, // init
            1031,                                 // loop: heartbeat triggered (1031 - 1000 >= 30)
            1031, 1031, 1031, 1031, 1031, 1031, 1031, 1031,
        ];

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
            '--limit'     => 1,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $command->runExecute($input, $output);

        // Assert
        $this->assertTrue($command->heartbeatCalled);
    }

    /**
     * In daemon mode, queue stats must be refreshed when 10 seconds have
     * elapsed since the last stats update.
     */
    public function testExecuteDaemonRefreshesQueueStatsAfter10Seconds(): void
    {
        // Arrange
        $workerId = 'stats-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchResults = [1];
        $command->nowValues = [
            1000, 1000, 1000, 1000, 1000, 1000, // init
            1000, 1000, 1000, 1011,              // loop: stats check triggered (1011 - 1000 >= 10)
            1011, 1011, 1011, 1011, 1011, 1011,
        ];

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
            '--limit'     => 1,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $command->runExecute($input, $output);

        // Assert
        $this->assertGreaterThanOrEqual(1, $command->getStatsCallCount());
    }

    /**
     * In daemon mode, when a database failure exception is thrown from
     * processBatch(), recoverDatabaseConnection() must be called.
     */
    public function testExecuteDaemonCallsRecoverOnDatabaseException(): void
    {
        // Arrange
        $workerId = 'dbfail-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchThrowable = new \RuntimeException('Database connection unavailable');
        $command->recoverDatabaseConnectionResult = false; // don't retry
        $command->nowValues = array_fill(0, 30, 1000);

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(0, $result);
        $this->assertTrue($command->recoverDatabaseConnectionCalled);
    }

    /**
     * In daemon mode, when processBatch() throws a non-database exception,
     * execute() must propagate it to the outer catch and return 1.
     */
    public function testExecuteDaemonReturnsOneForUnexpectedBatchException(): void
    {
        // Arrange
        $workerId = 'daemon-ex-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        $command->processBatchThrowable = new \RuntimeException('totally unexpected crash');
        $command->nowValues = array_fill(0, 30, 1000);

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert
        $this->assertSame(1, $result);
        $this->assertStringContainsString('totally unexpected crash', $output->fetch());
    }

    // ── processBatch() ────────────────────────────────────────────────────────

    /**
     * processBatch() must call processNextTask() in a loop and return the
     * count of tasks processed before the worker returns false (empty queue).
     */
    public function testProcessBatchCountsTasksUntilWorkerReturnsFalse(): void
    {
        // Arrange
        $command = $this->createPure();
        $worker  = $this->buildWorkerMock([
            ['id' => 1, 'type' => 'alpha', 'status' => 'completed'],
            ['id' => 2, 'type' => 'beta',  'status' => 'completed'],
            false,
        ]);
        $output  = new BufferedOutput();

        // Act
        $processed = $command->runProcessBatch($worker, $output, 10);

        // Assert
        $this->assertSame(2, $processed);
        $this->assertStringContainsString('Processed 2 tasks', $output->fetch());
    }

    /**
     * processBatch() with limit = 0 must coerce to a single attempt.
     * This prevents infinite loops when the caller passes 0 as "no limit."
     */
    public function testProcessBatchCoercesZeroLimitToSingleAttempt(): void
    {
        // Arrange
        $command = $this->createPure();
        $worker  = $this->buildWorkerMock([
            ['id' => 7, 'type' => 'single', 'status' => 'completed'],
        ]);
        $output  = new BufferedOutput();

        // Act
        $processed = $command->runProcessBatch($worker, $output, 0);

        // Assert — exactly one task processed despite limit=0
        $this->assertSame(1, $processed);
    }

    // ── isDatabaseFailure() ───────────────────────────────────────────────────

    /**
     * isDatabaseFailure() must return true for exceptions whose message
     * contains a known database error keyword (case-insensitive).
     */
    public function testIsDatabaseFailureRecognizesKnownKeywords(): void
    {
        // Arrange
        $command = $this->createPure();

        $cases = [
            'Database connection unavailable',
            'server has gone away',
            'Lost connection to MySQL',
            'broken pipe',
            'pg_query(): Query failed',
        ];

        // Act / Assert — each keyword should match
        foreach ($cases as $message) {
            $this->assertTrue(
                $command->runIsDatabaseFailure(new \RuntimeException($message)),
                "Expected isDatabaseFailure() = true for: $message"
            );
        }
    }

    /**
     * isDatabaseFailure() must return false for unrelated exceptions.
     */
    public function testIsDatabaseFailureReturnsFalseForUnrelatedExceptions(): void
    {
        // Arrange
        $command = $this->createPure();

        // Act / Assert
        $this->assertFalse(
            $command->runIsDatabaseFailure(new \RuntimeException('completely unrelated error'))
        );
    }

    /**
     * isDatabaseFailure() must recurse into the previous exception chain,
     * so a wrapped database error is still correctly classified.
     */
    public function testIsDatabaseFailureDetectsNestedDatabaseException(): void
    {
        // Arrange
        $command = $this->createPure();
        $nested  = new \RuntimeException('Database connection unavailable');
        $wrapped = new \RuntimeException('wrapper error', 0, $nested);

        // Act / Assert — the outer message doesn't match, the inner one does
        $this->assertTrue($command->runIsDatabaseFailure($wrapped));
    }

    // ── attemptDatabaseReconnect() ────────────────────────────────────────────

    /**
     * attemptDatabaseReconnect() must call tryReconnect() and return its result
     * when the database object exposes that method.
     */
    public function testAttemptDatabaseReconnectUsesTryReconnect(): void
    {
        // Arrange
        $command  = $this->createPure();
        $database = new class {
            public bool $result = true;
            public function tryReconnect(): bool { return $this->result; }
        };

        // Act / Assert
        $this->assertTrue($command->runAttemptDatabaseReconnect($database));

        $database->result = false;
        $this->assertFalse($command->runAttemptDatabaseReconnect($database));
    }

    /**
     * attemptDatabaseReconnect() must fall back to refresh() when tryReconnect()
     * is not available, treating a null return as success.
     */
    public function testAttemptDatabaseReconnectUsesRefreshFallback(): void
    {
        // Arrange
        $command = $this->createPure();

        $nullRefresh  = new class { public function refresh(): void {} };
        $trueRefresh  = new class { public function refresh(): bool { return true; } };
        $falseRefresh = new class { public function refresh(): bool { return false; } };

        // Act / Assert
        $this->assertTrue($command->runAttemptDatabaseReconnect($nullRefresh),  'null return = success');
        $this->assertTrue($command->runAttemptDatabaseReconnect($trueRefresh),  'true return = success');
        $this->assertFalse($command->runAttemptDatabaseReconnect($falseRefresh), 'false return = failure');
    }

    /**
     * attemptDatabaseReconnect() must return false when the database exposes
     * neither tryReconnect() nor refresh().
     */
    public function testAttemptDatabaseReconnectReturnsFalseWithNoMethod(): void
    {
        // Arrange
        $command = $this->createPure();
        $db      = new \stdClass();

        // Act / Assert
        $this->assertFalse($command->runAttemptDatabaseReconnect($db));
    }

    /**
     * attemptDatabaseReconnect() must return false when refresh() throws,
     * treating any throwable from the driver as a failed reconnect.
     */
    public function testAttemptDatabaseReconnectReturnsFalseWhenRefreshThrows(): void
    {
        // Arrange
        $command = $this->createPure();
        $db      = new class {
            public function refresh(): void { throw new \RuntimeException('refresh failed'); }
        };

        // Act / Assert
        $this->assertFalse($command->runAttemptDatabaseReconnect($db));
    }

    // ── recoverDatabaseConnection() ───────────────────────────────────────────

    /**
     * recoverDatabaseConnection() must return false immediately when a stop
     * file exists — the daemon is shutting down and should not try to reconnect.
     */
    public function testRecoverDatabaseConnectionReturnsFalseOnStopFile(): void
    {
        // Arrange
        $command  = $this->createPure();
        $jobName  = 'QUEUE_PROCESSOR_RECOVER_' . bin2hex(random_bytes(4));
        $command->setPublicJobName($jobName);

        $base     = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $lockPath = $base . '/var/' . $jobName;

        file_put_contents($lockPath, 'test-lock');
        file_put_contents($lockPath . '.stop', '1');
        $this->filesToCleanup[] = $lockPath;
        $this->filesToCleanup[] = $lockPath . '.stop';

        $app = new class { public object $database; };
        $app->database = new TestPQRecoveringDatabase([]);

        // Act
        $result = $command->runRecoverDatabaseConnection(
            $app, new BufferedOutput(), 'TestApp'
        );

        // Assert
        $this->assertFalse($result);
    }

    /**
     * recoverDatabaseConnection() must return false when $shouldContinue has
     * been set to false (e.g. by a signal handler).
     */
    public function testRecoverDatabaseConnectionReturnsFalseWhenShouldContinueFalse(): void
    {
        // Arrange
        $command = $this->createPure();
        $command->setShouldContinue(false);

        $app = new class { public object $database; };
        $app->database = new TestPQRecoveringDatabase([]);

        // Act
        $result = $command->runRecoverDatabaseConnection(
            $app, new BufferedOutput(), 'TestApp'
        );

        // Assert
        $this->assertFalse($result);
    }

    /**
     * recoverDatabaseConnection() must return false when the configured
     * max-runtime has elapsed during the reconnect wait.
     */
    public function testRecoverDatabaseConnectionReturnsFalseOnRuntimeExpired(): void
    {
        // Arrange
        $command     = $this->createPure();
        $jobName     = 'QUEUE_PROCESSOR_RTIME_' . bin2hex(random_bytes(4));
        $command->setPublicJobName($jobName);
        $command->nowValues = [1001]; // startedAt=1000, runtime=1, now=1001 → expired

        $base     = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $lockPath = $base . '/var/' . $jobName;
        file_put_contents($lockPath, 'test-lock');
        $this->filesToCleanup[] = $lockPath;
        $this->filesToCleanup[] = $lockPath . '.stop';

        $app = new class { public object $database; };
        $app->database = new TestPQRecoveringDatabase([]);

        // Act
        $result = $command->runRecoverDatabaseConnection(
            $app, new BufferedOutput(), 'TestApp',
            ['maxRuntime' => 1, 'startedAt' => 1000]
        );

        // Assert
        $this->assertFalse($result);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Build a Worker mock that returns a preset series of processNextTask() results.
     *
     * @param array<int, array<string,mixed>|false> $returns
     */
    private function buildWorkerMock(array $returns): Worker
    {
        $worker = $this->getMockBuilder(Worker::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['processNextTask'])
            ->getMock();

        $worker->method('processNextTask')
            ->willReturnOnConsecutiveCalls(...$returns);

        return $worker;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Test harness: pure helper methods (no execute())
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Exposes protected ProcessQueue helper methods as public run*() wrappers
 * and makes now() injectable for time-sensitive branches.
 */
class TestableProcessQueue extends ProcessQueue
{
    /** @var int[] Deterministic now() return values consumed in order. */
    public array $nowValues = [];

    /** Fallback when $nowValues is exhausted. */
    public int $nowFallback = 1000;

    private string $publicJobName = 'TEST_QUEUE_PURE';

    protected function getJobName(): string
    {
        return $this->publicJobName;
    }

    public function setPublicJobName(string $name): void
    {
        $this->publicJobName = $name;
    }

    /** Set the shouldContinue flag to simulate a signal-handler shutdown. */
    public function setShouldContinue(bool $value): void
    {
        $ref = new \ReflectionClass(ProcessQueue::class);
        // shouldContinue is private in ProcessQueue
        $prop = $ref->getProperty('shouldContinue');
        $prop->setAccessible(true);
        $prop->setValue($this, $value);
    }

    protected function now(): int
    {
        if ($this->nowValues !== []) {
            return (int) array_shift($this->nowValues);
        }
        return $this->nowFallback;
    }

    // ── run*() wrappers ───────────────────────────────────────────────────────

    public function runProcessBatch(
        Worker $worker,
        \Symfony\Component\Console\Output\OutputInterface $output,
        int $limit,
        ?array $taskTypes = null,
        ?int $startFromTimestamp = null,
        bool $reverseOrder = false
    ): int {
        return $this->processBatch($worker, $output, $limit, $taskTypes, $startFromTimestamp, $reverseOrder);
    }

    public function runIsDatabaseFailure(\Throwable $exception): bool
    {
        return $this->isDatabaseFailure($exception);
    }

    public function runAttemptDatabaseReconnect(object $database): bool
    {
        return $this->attemptDatabaseReconnect($database);
    }

    public function runRecoverDatabaseConnection(
        object $application,
        \Symfony\Component\Console\Output\OutputInterface $output,
        string $appName,
        array $dashboardData = []
    ): bool {
        return $this->recoverDatabaseConnection($application, $output, $appName, $dashboardData);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Test harness: execute() scenario tests
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Overrides all I/O side-effects so that execute() runs deterministically:
 *   – checkIfRunning()             → $runningOverride (default false)
 *   – now()                        → consumed from $nowValues, then $nowFallback
 *   – sleepSeconds()               → no-op
 *   – processBatch()               → returns from $processBatchResults[]
 *   – renderDashboard()            → records state/mode
 *   – updateSystemMetrics()        → no-op
 *   – configureInterruptHandling() → no-op
 *   – initializeInteractiveTerminal() → no-op
 *   – createWorker()               → returns TestPQWorker (no DB calls)
 *   – createQueueManager()         → returns TestPQQueueManager (stub stats)
 */
class TestableExecutableProcessQueue extends ProcessQueue
{
    public ?bool $runningOverride          = false;
    public array $processBatchResults      = [];
    public ?\Throwable $processBatchThrowable = null;
    public array $nowValues                = [];
    public int $nowFallback                = 1000;
    public int $processBatchCallCount      = 0;
    public bool $heartbeatCalled           = false;
    public bool $attemptReconnectCalled    = false;
    public ?bool $attemptReconnectResult   = null;
    public bool $recoverDatabaseConnectionCalled = false;
    public ?bool $recoverDatabaseConnectionResult = null;
    public ?string $lastDashboardState     = null;
    public ?string $lastDashboardMode      = null;

    private int $statsCallCount = 0;

    public function runExecute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        return $this->execute($input, $output);
    }

    public function getStatsCallCount(): int
    {
        return $this->statsCallCount;
    }

    // ── Overrides ─────────────────────────────────────────────────────────────

    protected function checkIfRunning(): bool
    {
        return $this->runningOverride ?? false;
    }

    protected function now(): int
    {
        if ($this->nowValues !== []) {
            return (int) array_shift($this->nowValues);
        }
        return $this->nowFallback;
    }

    protected function sleepSeconds(int $seconds): void
    {
        // No-op — suppress sleep() calls in tests.
    }

    protected function configureInterruptHandling(
        \Symfony\Component\Console\Output\OutputInterface $output,
        string $manualHandlerMethod = 'handleInterruptSignal'
    ): void {
        // No-op — pcntl_signal not needed in tests.
    }

    protected function initializeInteractiveTerminal(
        \Symfony\Component\Console\Output\OutputInterface $output,
        bool $registerShutdown = true
    ): void {
        // No-op — suppress cursor/screen manipulation in tests.
    }

    protected function updateSystemMetrics(): void
    {
        // No-op — no CPU/memory reads needed.
    }

    protected function createWorker($controller, ?string $workerId): Worker
    {
        return new TestPQWorker($controller, $workerId);
    }

    protected function createQueueManager($controller, ?string $workerId): QueueManager
    {
        $mgr = $this;
        return new class($controller, $workerId, $mgr) extends QueueManager {
            private TestableExecutableProcessQueue $cmd;

            public function __construct($ctrl, $wid, TestableExecutableProcessQueue $cmd)
            {
                // Deliberately skip parent::__construct() — avoids DB calls.
                $this->cmd = $cmd;
            }

            public function getStats(): array
            {
                // Expose call count to the test via the owning command.
                $ref = new \ReflectionClass(TestableExecutableProcessQueue::class);
                $prop = $ref->getProperty('statsCallCount');
                $prop->setAccessible(true);
                $prop->setValue($this->cmd, $prop->getValue($this->cmd) + 1);

                return ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'warning' => 0];
            }
        };
    }

    protected function processBatch(
        Worker $worker,
        \Symfony\Component\Console\Output\OutputInterface $output,
        int $limit,
        ?array $taskTypes = null,
        ?int $startFromTimestamp = null,
        bool $reverseOrder = false
    ): int {
        $this->processBatchCallCount++;
        if ($this->processBatchThrowable !== null) {
            throw $this->processBatchThrowable;
        }
        return (int) array_shift($this->processBatchResults);
    }

    protected function renderDashboard(
        \Symfony\Component\Console\Output\OutputInterface $output,
        array $data
    ): void {
        $this->lastDashboardState = (string) ($data['state'] ?? 'unknown');
        $this->lastDashboardMode  = (string) ($data['mode'] ?? 'unknown');
    }

    protected function heartbeat(): void
    {
        $this->heartbeatCalled = true;
        parent::heartbeat();
    }

    protected function attemptDatabaseReconnect(object $database): bool
    {
        $this->attemptReconnectCalled = true;
        if ($this->attemptReconnectResult !== null) {
            return $this->attemptReconnectResult;
        }
        return parent::attemptDatabaseReconnect($database);
    }

    protected function recoverDatabaseConnection(
        object $application,
        \Symfony\Component\Console\Output\OutputInterface $output,
        string $appName,
        array $dashboardData = []
    ): bool {
        $this->recoverDatabaseConnectionCalled = true;
        if ($this->recoverDatabaseConnectionResult !== null) {
            return $this->recoverDatabaseConnectionResult;
        }
        return parent::recoverDatabaseConnection($application, $output, $appName, $dashboardData);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Doubles / fakes used by the harnesses
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Minimal fake console application that satisfies getApplication() calls.
 * Extends the Symfony Application directly so that setApplication() on
 * the command accepts it. The $internalApplication is set by tests.
 */
class TestPQConsoleApplication extends \Symfony\Component\Console\Application
{
    public ?object $internalApplication = null;
}

/**
 * Minimal fake internalApplication that satisfies the calls inside execute().
 */
class TestPQInternalApplication
{
    public TestPQDatabase $database;

    public function __construct()
    {
        $this->database = new TestPQDatabase();
    }

    public function init(): void {}

    public function getController(string $name): TestPQController
    {
        return new TestPQController($this);
    }
}

class TestPQDatabase
{
    /** @var array<int, array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $trackingCalls = [];

    public function setTrackingInfo(mixed $userId = null, string $appName = '', array $userData = []): void
    {
        $this->trackingCalls[] = [$userId, $appName, $userData];
    }
}

class TestPQController
{
    public TestPQInternalApplication $application;

    public function __construct(TestPQInternalApplication $app)
    {
        $this->application = $app;
    }
}

/**
 * Worker that always returns false from processNextTask() (empty queue).
 * Used only so createWorker() in execute() doesn't throw — actual processing
 * is handled by the overridden processBatch().
 */
class TestPQWorker extends Worker
{
    public function __construct($controller = null, ?string $workerId = null)
    {
        // Skip parent constructor to avoid QueueManager instantiation.
    }
}

/**
 * Database double for recoverDatabaseConnection() tests that need a
 * controlled sequence of tryReconnect() return values.
 */
class TestPQRecoveringDatabase
{
    /** @var bool[] */
    private array $results;

    public int $reconnectAttempts = 0;

    /** @param bool[] $results Sequence of return values for tryReconnect(). */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function tryReconnect(): bool
    {
        $this->reconnectAttempts++;
        if (empty($this->results)) {
            return false;
        }
        return (bool) array_shift($this->results);
    }

    public function setTrackingInfo(mixed $userId = null, string $appName = '', array $userData = []): void {}
}
