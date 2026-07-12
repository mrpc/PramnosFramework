<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\CronExpression;
use Pramnos\Scheduling\ScheduledTask;

/**
 * Unit tests for Pramnos\Scheduling\ScheduledTask.
 *
 * ScheduledTask couples a handler (callable, command string, or job object)
 * with a timing policy expressed as a CronExpression.  Fluent builder methods
 * (everyMinute, daily, at, …) modify the internal CronExpression and return
 * $this for chaining.
 *
 * Tests verify:
 *   - Fluent timing methods produce the correct cron expression.
 *   - isDue() correctly delegates to the underlying CronExpression.
 *   - getSummary() reflects the current state (expression, description, …).
 *   - getCronExpression() exposes the underlying CronExpression for inspection.
 *   - run() with a callable handler actually invokes the callable.
 */
#[CoversClass(ScheduledTask::class)]
class ScheduledTaskTest extends TestCase
{
    // =========================================================================
    // Helper — build a callable task
    // =========================================================================

    /** Build a ScheduledTask backed by a callable (the simplest handler type). */
    private static function callableTask(callable $fn): ScheduledTask
    {
        return new ScheduledTask($fn, 'callable');
    }

    // =========================================================================
    // Default state after construction
    // =========================================================================

    /**
     * A freshly constructed ScheduledTask defaults to '* * * * *' — run every
     * minute — so a new task is always due until explicitly configured.
     */
    public function testDefaultExpressionIsEveryMinute(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null);

        // Assert
        $this->assertSame('* * * * *', $task->getCronExpression()->getExpression());
    }

    // =========================================================================
    // Fluent timing methods
    // =========================================================================

    /**
     * everyMinute() sets the cron expression to '* * * * *'.
     */
    public function testEveryMinuteSetsCorrectExpression(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->everyMinute();

        // Assert
        $this->assertSame('* * * * *', $task->getCronExpression()->getExpression());
    }

    /**
     * everyNMinutes(10) sets a step-of-10 cron expression.
     * The expression uses the '*' + '/10' (step) syntax in the minute field.
     */
    public function testEveryNMinutesSetsStepExpression(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->everyNMinutes(10);

        // Assert – minute field should be '*/10'
        $expr = $task->getCronExpression()->getExpression();
        $this->assertStringStartsWith('*/10', $expr);
    }

    /**
     * everyFiveMinutes() is a convenience alias for everyNMinutes(5).
     */
    public function testEveryFiveMinutesIsAliasForEveryNMinutes(): void
    {
        // Arrange
        $alias = self::callableTask(fn() => null)->everyFiveMinutes();
        $full  = self::callableTask(fn() => null)->everyNMinutes(5);

        // Assert – both produce identical expressions
        $this->assertSame(
            $alias->getCronExpression()->getExpression(),
            $full->getCronExpression()->getExpression()
        );
    }

    /**
     * hourly() sets the expression to '0 * * * *' — fires at the start of
     * every hour (minute = 0).
     */
    public function testHourlySetsCorrectExpression(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->hourly();

        // Assert
        $this->assertSame('0 * * * *', $task->getCronExpression()->getExpression());
    }

    /**
     * daily() sets the expression to '0 0 * * *' — fires at midnight.
     */
    public function testDailySetsCorrectExpression(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->daily();

        // Assert
        $this->assertSame('0 0 * * *', $task->getCronExpression()->getExpression());
    }

    /**
     * weekly() sets the expression to '0 0 * * 0' — midnight every Sunday.
     */
    public function testWeeklySetsCorrectExpression(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->weekly();

        // Assert
        $this->assertSame('0 0 * * 0', $task->getCronExpression()->getExpression());
    }

    /**
     * monthly() sets the expression to '0 0 1 * *' — midnight on the 1st.
     */
    public function testMonthlySetsCorrectExpression(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->monthly();

        // Assert
        $this->assertSame('0 0 1 * *', $task->getCronExpression()->getExpression());
    }

    /**
     * yearly() sets the expression to '0 0 1 1 *' — midnight on 1 January.
     */
    public function testYearlySetsCorrectExpression(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->yearly();

        // Assert
        $this->assertSame('0 0 1 1 *', $task->getCronExpression()->getExpression());
    }

    /**
     * at('14:30') replaces the minute (30) and hour (14) of the current cron
     * expression while keeping the day, month, and day-of-week fields.
     *
     * Typical use: daily()->at('14:30') → task runs at 14:30 each day.
     */
    public function testAtAdjustsMinuteAndHourFields(): void
    {
        // Arrange / Act — daily at 14:30
        $task = self::callableTask(fn() => null)->daily()->at('14:30');

        // Assert – expression should have minute=30, hour=14
        $parts = preg_split('/\s+/', $task->getCronExpression()->getExpression());
        $this->assertSame('30', $parts[0]);  // minute
        $this->assertSame('14', $parts[1]);  // hour
        // Rest of the fields unchanged from daily()
        $this->assertSame('*', $parts[2]);   // day-of-month
        $this->assertSame('*', $parts[3]);   // month
        $this->assertSame('*', $parts[4]);   // day-of-week
    }

    /**
     * Fluent timing methods return the same ScheduledTask instance ($this) so
     * calls can be chained: ->daily()->at('02:00')->withoutOverlapping().
     */
    public function testFluentTimingMethodsReturnSelf(): void
    {
        // Arrange
        $task = self::callableTask(fn() => null);

        // Act
        $returned = $task->daily();

        // Assert – same instance
        $this->assertSame($task, $returned);
    }

    /**
     * cron() sets an arbitrary 5-field expression, overriding any previously
     * configured schedule.
     */
    public function testCronSetsArbitraryExpression(): void
    {
        // Arrange / Act — once-a-year custom expression
        $task = self::callableTask(fn() => null)->cron('0 6 15 3 *');

        // Assert
        $this->assertSame('0 6 15 3 *', $task->getCronExpression()->getExpression());
    }

    // =========================================================================
    // isDue
    // =========================================================================

    /**
     * isDue() delegates to CronExpression::isDue() — a daily task is due only
     * at midnight.
     */
    public function testIsDueDelegatesToCronExpression(): void
    {
        // Arrange
        $task     = self::callableTask(fn() => null)->daily();
        $midnight = new \DateTimeImmutable('2024-06-15 00:00:00');
        $noon     = new \DateTimeImmutable('2024-06-15 12:00:00');

        // Assert
        $this->assertTrue($task->isDue($midnight));
        $this->assertFalse($task->isDue($noon));
    }

    // =========================================================================
    // description / getSummary
    // =========================================================================

    /**
     * description() stores a human-readable label that appears in getSummary().
     */
    public function testDescriptionIsReflectedInSummary(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)
            ->daily()
            ->description('Nightly cleanup job');

        $summary = $task->getSummary();

        // Assert
        $this->assertSame('Nightly cleanup job', $summary['description']);
    }

    /**
     * getSummary() returns the expected keys: type, handler, expression,
     * description, no_overlap.
     */
    public function testGetSummaryContainsExpectedKeys(): void
    {
        // Arrange / Act
        $task    = self::callableTask(fn() => null)->hourly();
        $summary = $task->getSummary();

        // Assert – all documented keys are present
        $this->assertArrayHasKey('type',        $summary);
        $this->assertArrayHasKey('handler',     $summary);
        $this->assertArrayHasKey('expression',  $summary);
        $this->assertArrayHasKey('description', $summary);
        $this->assertArrayHasKey('no_overlap',  $summary);
    }

    /**
     * getSummary()['expression'] always matches the cron expression currently
     * configured on the task.
     */
    public function testGetSummaryExpressionMatchesCronExpression(): void
    {
        // Arrange
        $task = self::callableTask(fn() => null)->weekly();

        // Act
        $summary = $task->getSummary();

        // Assert
        $this->assertSame(
            $task->getCronExpression()->getExpression(),
            $summary['expression']
        );
    }

    /**
     * getSummary()['no_overlap'] defaults to false and becomes true after
     * withoutOverlapping() is called.
     */
    public function testGetSummaryNoOverlapReflectsWithoutOverlapping(): void
    {
        // Arrange
        $task = self::callableTask(fn() => null)->daily();

        // Assert – default is false
        $this->assertFalse($task->getSummary()['no_overlap']);

        // Act
        $task->withoutOverlapping();

        // Assert – now true
        $this->assertTrue($task->getSummary()['no_overlap']);
    }

    // =========================================================================
    // run() — callable handler
    // =========================================================================

    /**
     * run() with a callable handler invokes the callable exactly once.
     */
    public function testRunInvokesCallableHandler(): void
    {
        // Arrange
        $called = 0;
        $task   = self::callableTask(function () use (&$called) {
            $called++;
        });

        // Act
        $task->run();

        // Assert
        $this->assertSame(1, $called);
    }

    /**
     * run() can be called multiple times and invokes the callable each time.
     */
    public function testRunCanBeCalledMultipleTimes(): void
    {
        // Arrange
        $called = 0;
        $task   = self::callableTask(function () use (&$called) {
            $called++;
        });

        // Act
        $task->run();
        $task->run();

        // Assert
        $this->assertSame(2, $called);
    }

    // =========================================================================
    // getCronExpression
    // =========================================================================

    /**
     * getCronExpression() returns a CronExpression instance that reflects the
     * currently configured schedule.
     */
    public function testGetCronExpressionReturnsCorrectType(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->monthly();

        // Assert
        $this->assertInstanceOf(CronExpression::class, $task->getCronExpression());
    }

    // =========================================================================
    // run() — job handler (object with handle() method)
    // =========================================================================

    /**
     * run() with a 'job' handler that has a handle() method must invoke it.
     */
    public function testRunInvokesJobHandleMethod(): void
    {
        // Arrange — a minimal job object with a handle() method
        $called = 0;
        $job = new class($called) {
            public function __construct(private int &$count) {}
            public function handle(): void { $this->count++; }
        };

        $task = new ScheduledTask($job, 'job');

        // Act
        $task->run();

        // Assert
        $this->assertSame(1, $called);
    }

    /**
     * run() with a callable 'job' handler must invoke it directly.
     */
    public function testRunInvokesCallableJobHandler(): void
    {
        // Arrange
        $called = 0;
        $task = new ScheduledTask(function () use (&$called) { $called++; }, 'job');

        // Act
        $task->run();

        // Assert
        $this->assertSame(1, $called);
    }

    /**
     * run() with a 'job' handler that is neither callable nor has handle() must
     * throw RuntimeException.
     */
    public function testRunThrowsForInvalidJobHandler(): void
    {
        // Arrange — a non-callable object without handle()
        $invalidJob = new \stdClass();
        $task = new ScheduledTask($invalidJob, 'job');

        // Act / Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/handle\(\)/');
        $task->run();
    }

    /**
     * run() with an unknown type must throw RuntimeException.
     */
    public function testRunThrowsForUnknownType(): void
    {
        // Arrange
        $task = new ScheduledTask('some-handler', 'unknown_type');

        // Act / Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown scheduled task type/');
        $task->run();
    }

    // =========================================================================
    // Overlap prevention (lock files)
    // =========================================================================

    /**
     * withoutOverlapping() skips execution when a lock file exists for an active PID.
     * We fake this by writing the current process's PID to the lock file before run().
     */
    public function testRunSkipsWhenOverlapLockExists(): void
    {
        // Arrange — use a temp dir for the lock file
        $lockDir = sys_get_temp_dir();
        $called  = 0;
        $task    = new ScheduledTask(function () use (&$called) { $called++; }, 'callable');
        $task->withoutOverlapping($lockDir);

        // Compute the expected lock file path using reflection
        $lockFileRef = new \ReflectionMethod($task, 'lockFile');
        $lockFile    = $lockFileRef->invoke($task);

        // Write the current PID so isLocked() returns true
        file_put_contents($lockFile, getmypid());

        try {
            // Act
            $task->run();

            // Assert — callable was NOT invoked because of the lock
            $this->assertSame(0, $called, 'run() must skip execution when a valid lock exists');
        } finally {
            @unlink($lockFile);
        }
    }

    /**
     * withoutOverlapping() acquires a lock, runs the handler, and then releases
     * the lock — the lock file must not exist after run() completes.
     */
    public function testRunAcquiresAndReleasesLock(): void
    {
        // Arrange
        $lockDir = sys_get_temp_dir();
        $task    = new ScheduledTask(fn() => null, 'callable');
        $task->withoutOverlapping($lockDir);

        // Compute lock path before run()
        $lockFileRef = new \ReflectionMethod($task, 'lockFile');
        $lockFile    = $lockFileRef->invoke($task);

        // Pre-condition: no stale lock
        @unlink($lockFile);

        // Act
        $task->run();

        // Assert — lock was released after execution
        $this->assertFileDoesNotExist($lockFile, 'Lock file must be deleted after run() completes');
    }

    /**
     * withoutOverlapping() with a custom lockDir uses that directory.
     */
    public function testWithoutOverlappingUsesCustomLockDir(): void
    {
        // Arrange
        $customDir = sys_get_temp_dir() . '/pramnos_sched_test_' . bin2hex(random_bytes(4));
        @mkdir($customDir, 0777, true);

        $task = new ScheduledTask(fn() => null, 'callable');
        $ret  = $task->withoutOverlapping($customDir);

        // Assert — fluent return
        $this->assertSame($task, $ret);

        // Cleanup
        @rmdir($customDir);
    }

    // =========================================================================
    // describeHandler() — various handler types
    // =========================================================================

    /**
     * getSummary()['handler'] must describe a string command handler.
     */
    public function testSummaryHandlerDescribesStringCommand(): void
    {
        // Arrange
        $task = new ScheduledTask('cleanup:temp', 'command');

        // Assert
        $this->assertSame('cleanup:temp', $task->getSummary()['handler']);
    }

    /**
     * getSummary()['handler'] must describe a Closure as 'Closure'.
     */
    public function testSummaryHandlerDescribesClosure(): void
    {
        // Arrange
        $task = new ScheduledTask(fn() => null, 'callable');

        // Assert
        $this->assertSame('Closure', $task->getSummary()['handler']);
    }

    /**
     * getSummary()['handler'] must describe an object handler using its class name.
     */
    public function testSummaryHandlerDescribesObjectHandler(): void
    {
        // Arrange — non-closure object
        $job = new \stdClass();
        $task = new ScheduledTask($job, 'job');

        // Assert
        $this->assertStringContainsString('stdClass', $task->getSummary()['handler']);
    }

    /**
     * Convenience aliases everyTenMinutes and everyThirtyMinutes must produce
     * the correct step expressions.
     */
    public function testEveryTenAndThirtyMinutesAliases(): void
    {
        $ten    = self::callableTask(fn() => null)->everyTenMinutes();
        $thirty = self::callableTask(fn() => null)->everyThirtyMinutes();

        $this->assertStringStartsWith('*/10', $ten->getCronExpression()->getExpression());
        $this->assertStringStartsWith('*/30', $thirty->getCronExpression()->getExpression());
    }

    /**
     * everyFifteenMinutes() must produce the correct step expression (every 15 min).
     * Covers the alias method which delegates to everyNMinutes(15).
     */
    public function testEveryFifteenMinutesAlias(): void
    {
        // Arrange / Act
        $task = self::callableTask(fn() => null)->everyFifteenMinutes();

        // Assert
        $this->assertStringStartsWith('*/15', $task->getCronExpression()->getExpression());
    }

    /**
     * getSummary()['handler'] must describe an array handler as 'ClassName::method'.
     * The array form — [$object_or_class, 'method'] — is a standard PHP callable
     * pattern. Both the string-class and object-class variants must be rendered.
     * Covers the `is_array($this->handler)` branch in describeHandler().
     */
    public function testSummaryHandlerDescribesArrayHandlerWithStringClass(): void
    {
        // Arrange — [string class, method]
        $task = new ScheduledTask(['stdClass', 'create'], 'callable');

        // Act
        $desc = $task->getSummary()['handler'];

        // Assert — format is ClassName::method
        $this->assertSame('stdClass::create', $desc,
            'Array handler with string class must be described as ClassName::method');
    }

    /**
     * Array handler where first element is an object instance must use get_class()
     * for the class name. Covers the ternary true branch inside describeHandler().
     */
    public function testSummaryHandlerDescribesArrayHandlerWithObjectClass(): void
    {
        // Arrange — [object instance, method]
        $obj  = new \stdClass();
        $task = new ScheduledTask([$obj, 'someMethod'], 'callable');

        // Act
        $desc = $task->getSummary()['handler'];

        // Assert — format is ClassName::method using get_class()
        $this->assertSame('stdClass::someMethod', $desc,
            'Array handler with object instance must use get_class() for class name');
    }

    /**
     * isLocked() must treat the task as locked when the lock file contains pid=0
     * (i.e., when posix_kill cannot verify — safe to assume locked).
     * Covers the `return true` branch when PID is 0.
     */
    public function testRunSkipsWhenLockFileContainsPidZero(): void
    {
        // Arrange — lock file with pid=0
        $lockDir = sys_get_temp_dir();
        $called  = 0;
        $task    = new ScheduledTask(function () use (&$called) { $called++; }, 'callable');
        $task->withoutOverlapping($lockDir);

        $lockFileRef = new \ReflectionMethod($task, 'lockFile');
        $lockFile    = $lockFileRef->invoke($task);

        // Write pid=0 so isLocked() hits the 'return true' fallback
        file_put_contents($lockFile, '0');

        try {
            // Act
            $task->run();

            // Assert — callable was skipped because lock is considered held
            $this->assertSame(0, $called,
                'run() must skip execution when lock file exists even with pid=0');
        } finally {
            @unlink($lockFile);
        }
    }
}
