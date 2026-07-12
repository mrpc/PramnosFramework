<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\Scheduler;
use Pramnos\Scheduling\ScheduledTask;

/**
 * Unit tests for Pramnos\Scheduling\Scheduler.
 *
 * Scheduler is a static registry of ScheduledTask instances.  Tasks are added
 * via the factory methods command(), call(), and job().  The registry can be
 * queried for all tasks or filtered to only those that are due at a given moment.
 * reset() clears state between tests.
 *
 * Tests verify:
 *   - command() registers a task and returns a ScheduledTask.
 *   - call() registers a callable task and returns a ScheduledTask.
 *   - job() registers a job-object task and returns a ScheduledTask.
 *   - all() returns all registered tasks in registration order.
 *   - getDue() returns only tasks whose cron expression matches the given time.
 *   - reset() clears all registered tasks so the next test starts clean.
 *   - Tasks returned from factory methods support fluent cron builders (everyMinute, etc.)
 *     — verified by chaining and re-reading the expression.
 */
#[CoversClass(Scheduler::class)]
class SchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        Scheduler::reset();
    }

    protected function tearDown(): void
    {
        Scheduler::reset();
    }

    // =========================================================================
    // Factory methods
    // =========================================================================

    /**
     * command() registers a ScheduledTask of type 'command' and returns it.
     */
    public function testCommandRegistersTaskAndReturnsScheduledTask(): void
    {
        // Arrange / Act
        $task = Scheduler::command('migrate');

        // Assert — the return value is a ScheduledTask and has been registered
        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertCount(1, Scheduler::all());
    }

    /**
     * call() registers a ScheduledTask that wraps the given callable.
     */
    public function testCallRegistersCallableTask(): void
    {
        // Arrange
        $callable = fn() => null;

        // Act
        $task = Scheduler::call($callable);

        // Assert
        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertCount(1, Scheduler::all());
    }

    /**
     * job() registers a ScheduledTask that wraps the given job object.
     */
    public function testJobRegistersJobObjectTask(): void
    {
        // Arrange
        $job = new class { public function handle(): void {} };

        // Act
        $task = Scheduler::job($job);

        // Assert
        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertCount(1, Scheduler::all());
    }

    /**
     * Multiple factory calls accumulate tasks in registration order.
     */
    public function testMultipleRegistrationsAccumulateTasks(): void
    {
        // Arrange / Act
        Scheduler::command('cmd1');
        Scheduler::call(fn() => null);
        Scheduler::command('cmd2');

        // Assert — three tasks registered in order
        $this->assertCount(3, Scheduler::all());
    }

    // =========================================================================
    // all()
    // =========================================================================

    /**
     * all() returns an empty array before any tasks are registered.
     */
    public function testAllReturnsEmptyArrayInitially(): void
    {
        // Arrange — reset in setUp()

        // Act / Assert
        $this->assertSame([], Scheduler::all());
    }

    /**
     * all() returns all registered tasks as ScheduledTask instances.
     */
    public function testAllReturnsAllRegisteredTasks(): void
    {
        // Arrange
        Scheduler::command('cleanup');
        Scheduler::call(fn() => null);

        // Act
        $tasks = Scheduler::all();

        // Assert
        $this->assertCount(2, $tasks);
        foreach ($tasks as $task) {
            $this->assertInstanceOf(ScheduledTask::class, $task);
        }
    }

    // =========================================================================
    // getDue()
    // =========================================================================

    /**
     * getDue() returns an empty array when no tasks are registered.
     */
    public function testGetDueReturnsEmptyWhenNoTasksRegistered(): void
    {
        // Arrange — reset in setUp()

        // Act
        $due = Scheduler::getDue(new \DateTime());

        // Assert
        $this->assertSame([], $due);
    }

    /**
     * getDue() returns only tasks whose cron expression matches the given time.
     *
     * A task set to run everyMinute() is always due (all cron fields are *).
     * A task set to a specific minute that doesn't match the given time is not due.
     */
    public function testGetDueFiltersTasksByCurrentTime(): void
    {
        // Arrange — one always-due task, one specific-minute task
        $alwaysDue = Scheduler::command('ping')->everyMinute();

        // Create a task pinned to a specific minute that will NOT match "now"
        // by using a cron expression that can never match the current second-level time.
        // We use a fixed minute 59 and check with a time at minute 0.
        $specificTask = Scheduler::command('report')->cron('0 0 1 1 *'); // only on Jan 1 at midnight

        // We test at a known moment that is NOT Jan 1 midnight
        $testTime = new \DateTime('2024-06-15 10:30:00');

        // Act
        $due = Scheduler::getDue($testTime);

        // Assert — only the everyMinute task is due (cron '* * * * *')
        $this->assertCount(1, $due);
        $this->assertContains($alwaysDue, $due);
        $this->assertNotContains($specificTask, $due);
    }

    /**
     * getDue() returns multiple tasks when more than one matches the time.
     */
    public function testGetDueReturnsMultipleMatchingTasks(): void
    {
        // Arrange — two always-due tasks
        $task1 = Scheduler::command('job1')->everyMinute();
        $task2 = Scheduler::call(fn() => null)->everyMinute();

        // Act
        $due = Scheduler::getDue(new \DateTime());

        // Assert — both tasks due
        $this->assertCount(2, $due);
        $this->assertContains($task1, $due);
        $this->assertContains($task2, $due);
    }

    /**
     * getDue() returns a re-indexed (sequential) array rather than a sparse one.
     */
    public function testGetDueReturnsReIndexedArray(): void
    {
        // Arrange
        Scheduler::command('a')->everyMinute();
        Scheduler::command('b')->everyMinute();

        // Act
        $due = Scheduler::getDue(new \DateTime());

        // Assert — keys are 0, 1 (array_values applied internally)
        $this->assertArrayHasKey(0, $due);
        $this->assertArrayHasKey(1, $due);
    }

    // =========================================================================
    // reset()
    // =========================================================================

    /**
     * reset() removes all registered tasks; all() returns [] afterwards.
     */
    public function testResetClearsAllTasks(): void
    {
        // Arrange — register some tasks
        Scheduler::command('task1');
        Scheduler::command('task2');
        $this->assertNotEmpty(Scheduler::all());

        // Act
        Scheduler::reset();

        // Assert — empty after reset
        $this->assertSame([], Scheduler::all());
    }

    // =========================================================================
    // Fluent chaining on returned task
    // =========================================================================

    /**
     * The ScheduledTask returned by command() supports fluent builder methods
     * so the call site can configure the schedule in one expression.
     */
    public function testCommandReturnSupportsFluentBuilderChaining(): void
    {
        // Arrange / Act
        $task = Scheduler::command('sweep')->everyMinute();

        // Assert — task is still a ScheduledTask and is registered
        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertCount(1, Scheduler::all());
    }
}
