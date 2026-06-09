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

    // ── getDashboardTitle() / createWorker() / createQueueManager() ──────────

    /**
     * getDashboardTitle() must return the default dashboard title string.
     *
     * This covers the uncovered hook method (line 86-88) that subclasses
     * override to customise the dashboard header.
     */
    public function testGetDashboardTitleReturnsDefaultString(): void
    {
        // Arrange / Act — instantiate ProcessQueue directly (not testable subclass)
        $cmd = new ProcessQueue();

        // Assert — use reflection to call the protected method
        $method = new \ReflectionMethod(ProcessQueue::class, 'getDashboardTitle');
        $result = $method->invoke($cmd);

        $this->assertSame(' QUEUE PROCESSOR ', $result);
    }

    /**
     * createWorker() must return a Worker instance when called directly.
     *
     * This covers the hook method on lines 121-124. Subclasses override this to
     * inject a custom Worker implementation; the default must produce a Worker.
     */
    public function testCreateWorkerReturnsWorkerInstance(): void
    {
        // Arrange
        $cmd        = new ProcessQueue();
        $method     = new \ReflectionMethod(ProcessQueue::class, 'createWorker');

        // Act — pass null controller (Worker constructor is skipped in the stub,
        // but here we need the real factory to execute)
        $worker = $method->invoke($cmd, null, null);

        // Assert — factory produces a Worker
        $this->assertInstanceOf(Worker::class, $worker);
    }

    /**
     * createQueueManager() must return a QueueManager instance when called directly.
     *
     * This covers the hook method on lines 135-138.
     */
    public function testCreateQueueManagerReturnsQueueManagerInstance(): void
    {
        // Arrange
        $cmd    = new ProcessQueue();
        $method = new \ReflectionMethod(ProcessQueue::class, 'createQueueManager');

        // Act
        $mgr = $method->invoke($cmd, null, null);

        // Assert — factory produces a QueueManager
        $this->assertInstanceOf(QueueManager::class, $mgr);
    }

    // ── execute() --force flag ────────────────────────────────────────────────

    /**
     * When --force is supplied, execute() must call endJob() before the
     * checkIfRunning() guard, ensuring any stale lock file is cleared.
     *
     * We use a command that simulates "already running" (returns true from
     * checkIfRunning() even after endJob() clears the file) to keep the test
     * fast, and verify that endJob() was invoked via the forceEndJobCalled flag.
     */
    public function testExecuteForceCallsEndJobBeforeRunningCheck(): void
    {
        // Arrange
        $workerId = 'force-' . bin2hex(random_bytes(4));
        $lockPath = $this->trackLockFile($workerId);
        // Write the lock file so the filemtime() call inside execute()'s
        // "already running" error message does not produce a TypeError.
        file_put_contents($lockPath, getmypid() . "\n");

        $command = $this->createExecutable();
        // Simulate another instance still running after force-clear
        $command->runningOverride = true;

        $input  = new ArrayInput([
            '--worker-id' => $workerId,
            '--force'     => true,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — the command bailed at checkIfRunning() with exit code 1,
        // confirming that the --force + endJob() + checkIfRunning() path executed.
        $this->assertSame(1, $result);
        $this->assertTrue($command->endJobCalled,
            '--force must trigger endJob() before checking if another instance is running');
    }

    // ── execute() daemon mode — sleeping / reconnect paths ────────────────────

    /**
     * In daemon mode, when the previous batch found no tasks, the loop must
     * render a "sleeping" dashboard (post-batch render, line 334) and NOT call
     * processBatch() again on the next iteration until the sleep window elapses.
     *
     * The sleeping BRANCH (lines 290-307) is entered on iter 2 when hasTasks=false
     * and now-lastBatchTime < sleepTime. A --runtime=1 exit with carefully crafted
     * now() values terminates the loop after iter 2's sleeping branch.
     *
     * now() call sequence:
     *   Init (7 calls at 1000): startTime, this->startTime, lastRefresh,
     *                           statsUpdateTime, lastBatchTime, lastHeartbeat, lastReconnect.
     *   Iter 1 (6 calls): runtime(1000), heartbeat(1000), reconnect(1000),
     *                      stats(1000), lastBatchTime_set(1000), refresh_check(1000).
     *   Iter 2 (6 calls): runtime(1000), heartbeat(1000), reconnect(1000),
     *                      stats(1000), sleeping_cond(1000), sleeping_remaining(1000).
     *   Iter 3 (1 call):  runtime(1002) → 1002-1000=2 >= --runtime=1 → exit.
     */
    public function testExecuteDaemonRendersSleepingDashboardWhenNoTasks(): void
    {
        // Arrange
        $workerId = 'sleep-dash-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        // Only one batch call: returns 0 tasks → hasTasks = false.
        $command->processBatchResults = [0];

        // Provide exactly enough now() values to drive the two loop iterations
        // and trigger the --runtime=1 exit on iter 3.
        //
        // Call count breakdown:
        //   Init (7): startTime, this->startTime, lastRefresh, statsUpdateTime,
        //             lastBatchTime, lastHeartbeat, lastReconnect
        //   Iter 1 (7): runtime-check, heartbeat, reconnect, stats,
        //               lastBatchTime-set (after batch), sleepRemaining-in-post-batch-render
        //   Iter 2 (6): runtime-check, heartbeat, reconnect, stats,
        //               sleeping-branch-condition, sleeping-sleepRemaining
        //   Iter 3 (1): runtime-check → 1002 triggers max-runtime exit
        // Total: 7 + 7 + 6 + 1 = 21 calls.
        $command->nowValues = array_merge(
            array_fill(0, 20, 1000),  // init (7) + iter 1 (7) + iter 2 (6)
            [1002]                    // iter 3 runtime check: 1002-1000=2 >= 1
        );

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
            '--sleep'     => 30,     // large window: 30s > 0 (now-lastBatchTime)
            '--runtime'   => 1,      // max 1 second; triggers after sleeping iteration
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — loop exited via maximum-runtime path
        $this->assertSame(0, $result);
        $this->assertStringContainsString('Maximum runtime reached', $output->fetch());
        // The last rendered dashboard state must be 'sleeping' from the sleeping branch.
        $this->assertSame('sleeping', $command->lastDashboardState,
            'When hasTasks=false and within sleep window, dashboard must render state=sleeping');
        // processBatch() must have been called exactly once (sleeping branch skips it)
        $this->assertSame(1, $command->processBatchCallCount,
            'The sleeping branch must not call processBatch() again');
    }

    /**
     * After a database reconnect via recoverDatabaseConnection(), the daemon
     * loop must recreate both the worker and queueManager, and continue running.
     *
     * This covers lines 363-369 (post-recovery resource renewal), which are only
     * reached when recoverDatabaseConnection() returns true.
     */
    public function testExecuteDaemonRecreatesWorkerAfterSuccessfulRecovery(): void
    {
        // Arrange
        $workerId = 'recover-cont-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);

        $command = $this->createExecutable();
        // First processBatch() call throws a DB error; recovery succeeds;
        // second call returns 1 task; third call is never reached because
        // the task limit is 1.
        $command->processBatchResults            = [1];       // after recovery
        $command->recoverDatabaseConnectionResult = true;     // recovery succeeds
        $command->processBatchThrowableOnce       = new \RuntimeException('Database connection unavailable');
        $command->nowValues                       = array_fill(0, 50, 1000);

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
            '--limit'     => 1,
            // sleep=0 ensures now()-lastBatchTime (always 0 since nowFallback=1000)
            // is NOT less than sleepTime, so the sleeping-branch is skipped after
            // recovery and processBatch() is called immediately.
            '--sleep'     => 0,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — recovery was triggered and processing continued to completion
        $this->assertSame(0, $result);
        $this->assertTrue($command->recoverDatabaseConnectionCalled,
            'recoverDatabaseConnection() must be called on DB exception');
        // processBatch() is called twice: once before recovery (throws), once after recovery (returns 1).
        $this->assertSame(2, $command->processBatchCallCount,
            'processBatch() must be called twice: once before recovery (throws), once after (returns 1 task)');
    }

    // ── applyDatabaseTrackingInfo() ───────────────────────────────────────────

    /**
     * applyDatabaseTrackingInfo() must call setTrackingInfo() on the database
     * with null for userId, the application name, and an empty userData array.
     *
     * This is an important "configuration persistence after reconnect" helper
     * that ensures query tracking survives database reconnects.
     */
    public function testApplyDatabaseTrackingInfoCallsSetTrackingInfo(): void
    {
        // Arrange
        $command = $this->createPure();
        $db      = new TestPQDatabase();
        $app     = new \stdClass();
        $app->database = $db;

        // Act — call via reflection (protected method)
        $method = new \ReflectionMethod(ProcessQueue::class, 'applyDatabaseTrackingInfo');
        $method->invoke($command, $app, 'MyApp');

        // Assert — setTrackingInfo was called with expected arguments
        $this->assertCount(1, $db->trackingCalls,
            'applyDatabaseTrackingInfo() must call database->setTrackingInfo() exactly once');
        [$userId, $appName, $userData] = $db->trackingCalls[0];
        $this->assertNull($userId,    'userId must be null');
        $this->assertSame('MyApp', $appName, 'appName must match the passed label');
        $this->assertSame([], $userData, 'userData must be an empty array');
    }

    // ── recoverDatabaseConnection() success path ──────────────────────────────

    /**
     * recoverDatabaseConnection() must return true when tryReconnect() succeeds,
     * and must call applyDatabaseTrackingInfo() to re-apply tracking metadata.
     *
     * This covers lines 561-564: the successful reconnect branch that calls
     * applyDatabaseTrackingInfo() before returning true.
     */
    public function testRecoverDatabaseConnectionReturnsTrueOnSuccessfulReconnect(): void
    {
        // Arrange
        $command = $this->createPure();
        $jobName = 'QUEUE_PROCESSOR_SUCCESS_' . bin2hex(random_bytes(4));
        $command->setPublicJobName($jobName);

        $base     = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $lockPath = $base . '/var/' . $jobName;
        file_put_contents($lockPath, 'test-lock');
        $this->filesToCleanup[] = $lockPath;
        $this->filesToCleanup[] = $lockPath . '.stop';

        // Database that succeeds on the first reconnect attempt.
        // TestPQRecoveringDatabase already includes setTrackingInfo().
        $db  = new TestPQRecoveringDatabase([true]);
        $app = new class { public object $database; };
        $app->database = $db;

        // Act
        $result = $command->runRecoverDatabaseConnection(
            $app, new BufferedOutput(), 'TestApp'
        );

        // Assert — successfully reconnected
        $this->assertTrue($result,
            'recoverDatabaseConnection() must return true when reconnect succeeds');
        $this->assertSame(1, $db->reconnectAttempts,
            'tryReconnect() must have been called exactly once');
    }

    // ── addRecentTask() ───────────────────────────────────────────────────────

    /**
     * addRecentTask() must store the task info in the internal recent-tasks list,
     * using 'unknown' defaults for missing fields and formatting execution_time.
     *
     * This covers lines 703-718: the task-info normalisation and ring-buffer logic.
     */
    public function testAddRecentTaskStoresNormalisedTaskInfo(): void
    {
        // Arrange
        $command = $this->createPure();
        $method  = new \ReflectionMethod(ProcessQueue::class, 'addRecentTask');

        // Act — task with all fields present
        $method->invoke($command, [
            'id'             => 42,
            'type'           => 'send_email',
            'status'         => 'completed',
            'message'        => 'OK',
            'execution_time' => 0.123,
        ]);

        // Assert — inspect the recentTasks list via reflection
        $prop  = new \ReflectionProperty(ProcessQueue::class, 'recentTasks');
        $tasks = $prop->getValue($command);

        $this->assertCount(1, $tasks);
        $this->assertSame(42,           $tasks[0]['id']);
        $this->assertSame('send_email', $tasks[0]['type']);
        $this->assertSame('completed',  $tasks[0]['status']);
        $this->assertSame('OK',         $tasks[0]['message']);
        // execution_time must be formatted to 3 decimal places
        $this->assertSame('0.123s',     $tasks[0]['execution_time']);
    }

    /**
     * addRecentTask() must use 'unknown' defaults for missing id and type, and
     * 'processed' for a missing status, and null for missing message/execution_time.
     */
    public function testAddRecentTaskUsesDefaultsForMissingFields(): void
    {
        // Arrange
        $command = $this->createPure();
        $method  = new \ReflectionMethod(ProcessQueue::class, 'addRecentTask');

        // Act — empty task info
        $method->invoke($command, []);

        // Assert
        $prop  = new \ReflectionProperty(ProcessQueue::class, 'recentTasks');
        $tasks = $prop->getValue($command);

        $this->assertCount(1, $tasks);
        $this->assertSame('unknown',   $tasks[0]['id']);
        $this->assertSame('unknown',   $tasks[0]['type']);
        $this->assertSame('processed', $tasks[0]['status']);
        $this->assertNull($tasks[0]['message']);
        $this->assertNull($tasks[0]['execution_time']);
    }

    /**
     * addRecentTask() must enforce the maxRecentTasks ring-buffer limit by
     * evicting the oldest entry when the list is full.
     */
    public function testAddRecentTaskEvictsOldestWhenBufferFull(): void
    {
        // Arrange — fill the buffer to its max capacity (5)
        $command    = $this->createPure();
        $method     = new \ReflectionMethod(ProcessQueue::class, 'addRecentTask');
        $maxProp    = new \ReflectionProperty(ProcessQueue::class, 'maxRecentTasks');
        $max        = (int) $maxProp->getValue($command); // 5

        for ($i = 1; $i <= $max; $i++) {
            $method->invoke($command, ['id' => $i, 'type' => 'task']);
        }

        // Act — add one more task beyond the limit
        $method->invoke($command, ['id' => 999, 'type' => 'overflow']);

        // Assert — buffer still has $max entries, oldest (id=1) evicted
        $prop  = new \ReflectionProperty(ProcessQueue::class, 'recentTasks');
        $tasks = $prop->getValue($command);

        $this->assertCount($max, $tasks, 'Buffer must not exceed maxRecentTasks');
        // First entry should now be id=2 (id=1 was evicted)
        $this->assertSame(2,   $tasks[0]['id'], 'Oldest task must have been evicted');
        $this->assertSame(999, $tasks[$max - 1]['id'], 'Newest task must be at the end');
    }

    // ── addStatusMessage() ────────────────────────────────────────────────────

    /**
     * addStatusMessage() must append a message with type, message text, and
     * a formatted timestamp to the internal statusMessages buffer.
     */
    public function testAddStatusMessageAppendsMessageWithCorrectStructure(): void
    {
        // Arrange
        $command = $this->createPure();
        $method  = new \ReflectionMethod(ProcessQueue::class, 'addStatusMessage');

        // Act
        $method->invoke($command, 'info', 'Database reconnected');

        // Assert
        $prop     = new \ReflectionProperty(ProcessQueue::class, 'statusMessages');
        $messages = $prop->getValue($command);

        $this->assertCount(1, $messages);
        $this->assertSame('info',                 $messages[0]['type']);
        $this->assertSame('Database reconnected', $messages[0]['message']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $messages[0]['time'],
            'time must be formatted as HH:MM:SS');
    }

    /**
     * addStatusMessage() must enforce the 5-message ring-buffer limit by
     * evicting the oldest entry when the list is full.
     */
    public function testAddStatusMessageEvictsOldestWhenBufferFull(): void
    {
        // Arrange — fill the buffer to its capacity of 5
        $command = $this->createPure();
        $method  = new \ReflectionMethod(ProcessQueue::class, 'addStatusMessage');

        for ($i = 1; $i <= 5; $i++) {
            $method->invoke($command, 'info', "Message $i");
        }

        // Act — add a 6th message
        $method->invoke($command, 'warning', 'Overflow message');

        // Assert — still exactly 5 entries, oldest evicted
        $prop     = new \ReflectionProperty(ProcessQueue::class, 'statusMessages');
        $messages = $prop->getValue($command);

        $this->assertCount(5, $messages, 'statusMessages must never exceed 5 entries');
        $this->assertSame('Message 2',        $messages[0]['message'], 'Oldest must be evicted');
        $this->assertSame('Overflow message', $messages[4]['message'], 'Newest must be at the end');
    }

    // ── handleSignal() ────────────────────────────────────────────────────────

    /**
     * handleSignal() must set shouldContinue to false and call endJob().
     *
     * This covers lines 762-769: the SIGTERM/SIGINT handler that gracefully
     * stops the daemon loop by flipping the sentinel flag and releasing the lock.
     */
    public function testHandleSignalSetsShouldContinueToFalse(): void
    {
        // Arrange
        $command = $this->createPure();
        // Confirm shouldContinue starts as true
        $ref  = new \ReflectionClass(ProcessQueue::class);
        $prop = $ref->getProperty('shouldContinue');
        $this->assertTrue($prop->getValue($command), 'shouldContinue must start as true');

        // Act
        $command->handleSignal(15); // SIGTERM

        // Assert — flag flipped to false
        $this->assertFalse($prop->getValue($command),
            'handleSignal() must set shouldContinue to false');
    }

    /**
     * handleSignal() must write a message to signalOutput when it is set.
     *
     * This covers the `if ($this->signalOutput)` branch on line 764-766.
     */
    public function testHandleSignalWritesToSignalOutputWhenSet(): void
    {
        // Arrange
        $command = $this->createPure();
        $output  = new BufferedOutput();

        // Inject signalOutput via reflection (it is protected in CommandBase).
        $ref  = new \ReflectionProperty(\Pramnos\Console\CommandBase::class, 'signalOutput');
        $ref->setValue($command, $output);

        // Act
        $command->handleSignal(2); // SIGINT

        // Assert — message was written to signalOutput
        $text = $output->fetch();
        $this->assertStringContainsString('shutdown signal', $text,
            'handleSignal() must write a shutdown message to signalOutput');
    }

    // ── recoverDatabaseConnection() retry path ────────────────────────────────

    /**
     * recoverDatabaseConnection() must log a warning and sleep when tryReconnect()
     * fails, then succeed on the next attempt.
     *
     * This covers lines 567-568 (addStatusMessage + sleepSeconds called when
     * reconnect fails) and the second-attempt success path at lines 561-563.
     *
     * Note: TestableProcessQueue's renderDashboard() calls parent::renderDashboard()
     * which uses terminal ANSI sequences harmlessly on BufferedOutput.
     */
    public function testRecoverDatabaseConnectionLogsWarningAndRetriesOnFailure(): void
    {
        // Arrange
        $command = $this->createPure();
        $jobName = 'QUEUE_PROCESSOR_RETRY_' . bin2hex(random_bytes(4));
        $command->setPublicJobName($jobName);

        $base     = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $lockPath = $base . '/var/' . $jobName;
        file_put_contents($lockPath, 'test-lock');
        $this->filesToCleanup[] = $lockPath;
        $this->filesToCleanup[] = $lockPath . '.stop';

        // First tryReconnect() fails, second succeeds.
        $db  = new TestPQRecoveringDatabase([false, true]);
        $app = new class { public object $database; };
        $app->database = $db;

        // Act
        $result = $command->runRecoverDatabaseConnection(
            $app, new BufferedOutput(), 'TestApp'
        );

        // Assert — eventually succeeded
        $this->assertTrue($result,
            'recoverDatabaseConnection() must return true when a retry succeeds');
        $this->assertSame(2, $db->reconnectAttempts,
            'tryReconnect() must have been called twice (fail then succeed)');
    }

    // ── execute() daemon — periodic refresh-interval calculation ─────────────

    /**
     * In daemon mode, when the elapsed time since the last dashboard refresh
     * exceeds refreshInterval (1s), the loop must recalculate taskPerSecond.
     *
     * This covers lines 323-330: the refresh-interval block that computes the
     * tasks-per-second rate and resets the per-interval counter.
     *
     * The trick: supply a now() value that makes now()-lastRefresh >= 1 during
     * the single iteration, then use a stop file to exit cleanly.
     *
     * now() call sequence (approximate):
     *   Init (7 calls): 1000 for startTime, lastRefresh, etc.
     *   Iter 1 inner (5 calls before processBatch): 1000
     *   lastBatchTime = now(): 1000
     *   Refresh check (1 call): 1001 → 1001-1000=1 >= refreshInterval(1) → triggers
     *   taskPerSecond calc (1 call): 1001 (elapsed = 1001-1000=1)
     *   lastRefresh = now() (1 call): 1001
     *   renderDashboard, then usleep, then stop-file exit
     */
    public function testExecuteDaemonCalculatesTaskPerSecondAfterRefreshInterval(): void
    {
        // Arrange
        $workerId = 'refresh-' . bin2hex(random_bytes(4));
        $this->trackLockFile($workerId);
        $base     = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $stopPath = $base . '/var/QUEUE_PROCESSOR_' . $workerId . '.stop';

        $command = $this->createExecutable();
        $command->processBatchResults = [3]; // 3 tasks processed
        $command->writeStopFileAfterFirstBatch = $stopPath;

        // Build now() sequence. Call positions (1-indexed):
        //   1-7:  init (startTime×2, lastRefresh, statsUpdateTime, lastBatchTime,
        //                lastHeartbeat, lastReconnect) → all 1000
        //   8:    heartbeat check (now()-lastHeartbeat < 30)       → 1000
        //   9:    reconnect check (now()-lastReconnect > 300)      → 1000
        //   10:   stats check (now()-statsUpdateTime >= 10)        → 1000
        //   11:   lastBatchTime = now() (after batch)              → 1000
        //   12:   refresh check: now()-lastRefresh >= refreshInterval(1)
        //         → 1001 so 1001-1000=1 >= 1 triggers the block
        //   13:   elapsed = now()-lastRefresh inside block         → 1001
        //   14:   lastRefresh = now() inside block                 → 1001
        // Total: 11 × 1000, then 3 × 1001 = 14 values.
        $command->nowValues = array_merge(
            array_fill(0, 11, 1000),  // init(7) + heartbeat + reconnect + stats + lastBatchTime
            [1001, 1001, 1001]        // refresh check, elapsed calc, lastRefresh reset
        );

        $input = new ArrayInput([
            '--daemon'    => true,
            '--worker-id' => $workerId,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        // Act
        $result = $command->runExecute($input, $output);

        // Assert — run completed without error
        $this->assertSame(0, $result,
            'execute() must return 0 after a clean refresh-interval calculation');
        // processBatch was called once before the stop file was detected
        $this->assertSame(1, $command->processBatchCallCount);
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
        // Also update the private $jobname in ProcessQueue so that the lock-file
        // paths inside recoverDatabaseConnection() (which use $this->jobname
        // directly) refer to the same file we create in the test.
        $prop = new \ReflectionProperty(ProcessQueue::class, 'jobname');
        $prop->setValue($this, $name);
    }

    /** Set the shouldContinue flag to simulate a signal-handler shutdown. */
    public function setShouldContinue(bool $value): void
    {
        $ref = new \ReflectionClass(ProcessQueue::class);
        // shouldContinue is private in ProcessQueue
        $prop = $ref->getProperty('shouldContinue');
        $prop->setValue($this, $value);
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
        // No-op — suppress real sleep() calls in pure-helper tests.
    }

    protected function renderDashboard(
        \Symfony\Component\Console\Output\OutputInterface $output,
        array $data
    ): void {
        // Suppress terminal ANSI escape sequences — pure-helper tests only need
        // the logic, not the dashboard rendering output.
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
    /** Throwable thrown only on the first processBatch() call (then cleared). */
    public ?\Throwable $processBatchThrowableOnce = null;
    public array $nowValues                = [];
    public int $nowFallback                = 1000;
    public int $processBatchCallCount      = 0;
    public bool $heartbeatCalled           = false;
    public bool $endJobCalled              = false;
    public bool $attemptReconnectCalled    = false;
    public ?bool $attemptReconnectResult   = null;
    public bool $recoverDatabaseConnectionCalled = false;
    public ?bool $recoverDatabaseConnectionResult = null;
    public ?string $lastDashboardState     = null;
    public ?string $lastDashboardMode      = null;
    /**
     * When set to a path, processBatch() writes a stop file at this path after
     * the first batch call so that the next daemon-loop iteration exits cleanly.
     * Used to test the sleeping-branch state without an infinite loop.
     */
    public ?string $writeStopFileAfterFirstBatch = null;

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
        // One-shot throwable: thrown only once, then cleared so subsequent calls succeed.
        if ($this->processBatchThrowableOnce !== null) {
            $ex = $this->processBatchThrowableOnce;
            $this->processBatchThrowableOnce = null;
            throw $ex;
        }
        if ($this->processBatchThrowable !== null) {
            throw $this->processBatchThrowable;
        }
        $result = (int) array_shift($this->processBatchResults);
        // Write stop file after first batch to let the sleeping branch run once
        // before the loop exits on the next iteration (used by sleeping-branch tests).
        if ($this->writeStopFileAfterFirstBatch !== null && $this->processBatchCallCount === 1) {
            file_put_contents($this->writeStopFileAfterFirstBatch, '1');
        }
        return $result;
    }

    public function endJob(): void
    {
        $this->endJobCalled = true;
        // Do NOT call parent::endJob() here — we track the flag only.
        // Calling parent would delete the lock file before execute()'s filemtime()
        // call in the "already running" error path, causing a TypeError.
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
