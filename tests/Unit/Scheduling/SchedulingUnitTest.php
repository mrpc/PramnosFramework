<?php

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\CronExpression;
use Pramnos\Scheduling\Scheduler;
use Pramnos\Scheduling\ScheduledTask;

/**
 * Unit tests for the Scheduling subsystem: CronExpression, ScheduledTask, Scheduler.
 *
 * All time-based assertions use deterministic DateTimeImmutable instances.
 */
class SchedulingUnitTest extends TestCase
{
    protected function setUp(): void
    {
        Scheduler::reset();
    }

    // =========================================================================
    // CronExpression — parsing
    // =========================================================================

    /**
     * An expression that does not have exactly 5 fields must throw
     * InvalidArgumentException immediately so misconfigured schedules
     * are caught at registration time.
     */
    public function testCronExpressionThrowsOnWrongFieldCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CronExpression('* * * *'); // only 4 fields
    }

    /**
     * getExpression() must return the original expression string unchanged.
     */
    public function testCronGetExpression(): void
    {
        $expr = '0 2 * * 1';
        $cron = new CronExpression($expr);
        $this->assertSame($expr, $cron->getExpression());
    }

    // =========================================================================
    // CronExpression — isDue()
    // =========================================================================

    /**
     * '* * * * *' means "every minute" — the expression must be due at any
     * moment of any day.
     */
    public function testEveryMinuteIsDueAtAnyTime(): void
    {
        // Arrange
        $cron = new CronExpression('* * * * *');

        // Assert — spot-check several moments
        $this->assertTrue($cron->isDue(new \DateTimeImmutable('2025-01-15 00:00')));
        $this->assertTrue($cron->isDue(new \DateTimeImmutable('2025-06-30 23:59')));
        $this->assertTrue($cron->isDue(new \DateTimeImmutable('2025-12-31 12:00')));
    }

    /**
     * '0 2 * * *' is due at 02:00 on any day, not at any other time.
     */
    public function testDailyAt2amIsDueOnlyAt0200(): void
    {
        // Arrange
        $cron = new CronExpression('0 2 * * *');

        // Assert — due at 02:00
        $this->assertTrue($cron->isDue(new \DateTimeImmutable('2025-03-15 02:00')));
        // Not due at any other hour
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-03-15 02:01')));
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-03-15 01:00')));
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-03-15 03:00')));
    }

    /**
     * '* /5 * * * *' is due at minute 0, 5, 10, …, 55, not at 1, 2, 3, 4, 6 …
     */
    public function testEveryFiveMinutesMatchesCorrectMinutes(): void
    {
        // Arrange
        $cron = new CronExpression('*/5 * * * *');

        // Assert — due at multiples of 5
        foreach ([0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55] as $minute) {
            $time = new \DateTimeImmutable("2025-01-01 10:{$minute}");
            $this->assertTrue($cron->isDue($time), "Should be due at minute {$minute}");
        }

        // Not due at non-multiples
        foreach ([1, 2, 3, 4, 6, 7, 11, 59] as $minute) {
            $pad  = str_pad((string) $minute, 2, '0', STR_PAD_LEFT);
            $time = new \DateTimeImmutable("2025-01-01 10:{$pad}");
            $this->assertFalse($cron->isDue($time), "Should NOT be due at minute {$minute}");
        }
    }

    /**
     * A range expression '1-5' in the minute field must match 1,2,3,4,5 and
     * not match 0 or 6.
     */
    public function testRangeExpression(): void
    {
        // Arrange
        $cron = new CronExpression('1-5 * * * *');

        // Assert — in range
        foreach ([1, 2, 3, 4, 5] as $min) {
            $pad  = str_pad((string) $min, 2, '0', STR_PAD_LEFT);
            $this->assertTrue($cron->isDue(new \DateTimeImmutable("2025-01-01 10:{$pad}")));
        }
        // Out of range
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-01-01 10:00')));
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-01-01 10:06')));
    }

    /**
     * A comma-separated list '0,15,30,45' in the minute field must match each
     * listed value and not others.
     */
    public function testCommaListExpression(): void
    {
        // Arrange
        $cron = new CronExpression('0,15,30,45 * * * *');

        // Assert — listed values
        foreach ([0, 15, 30, 45] as $min) {
            $pad  = str_pad((string) $min, 2, '0', STR_PAD_LEFT);
            $this->assertTrue($cron->isDue(new \DateTimeImmutable("2025-01-01 10:{$pad}")));
        }
        // Not listed
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-01-01 10:01')));
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-01-01 10:16')));
    }

    /**
     * '0 0 * * 0' is due at midnight on Sundays (day-of-week=0), not on
     * Mondays or other weekdays.
     */
    public function testWeeklyOnSunday(): void
    {
        // Arrange — '0 0 * * 0'
        $cron = new CronExpression('0 0 * * 0');

        // 2025-01-05 is a Sunday
        $this->assertTrue($cron->isDue(new \DateTimeImmutable('2025-01-05 00:00')));
        // 2025-01-06 is a Monday
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-01-06 00:00')));
    }

    /**
     * '0 0 1 * *' must be due on the 1st of each month at midnight, not on
     * any other day.
     */
    public function testMonthlyOnFirst(): void
    {
        // Arrange
        $cron = new CronExpression('0 0 1 * *');

        $this->assertTrue($cron->isDue(new \DateTimeImmutable('2025-03-01 00:00')));
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-03-02 00:00')));
        $this->assertFalse($cron->isDue(new \DateTimeImmutable('2025-03-15 00:00')));
    }

    // =========================================================================
    // CronExpression — withTime()
    // =========================================================================

    /**
     * withTime() must replace the minute and hour fields to produce a new
     * expression that fires at the requested time.
     */
    public function testWithTimeReplacesMinuteAndHour(): void
    {
        // Arrange — start with a daily expression (minute=0, hour=0)
        $cron    = new CronExpression('0 0 * * *');
        $updated = $cron->withTime('14:30');

        // Assert — new expression is due at 14:30
        $this->assertTrue($updated->isDue(new \DateTimeImmutable('2025-01-15 14:30')));
        $this->assertFalse($updated->isDue(new \DateTimeImmutable('2025-01-15 00:00')));
        $this->assertSame('30 14 * * *', $updated->getExpression());
    }

    /**
     * withTime() must throw InvalidArgumentException for invalid time strings.
     */
    public function testWithTimeThrowsOnInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CronExpression('* * * * *'))->withTime('25:00');
    }

    // =========================================================================
    // ScheduledTask — fluent API
    // =========================================================================

    /**
     * daily() must set the expression to '0 0 * * *'.
     */
    public function testDailySetsCorrectExpression(): void
    {
        // Arrange
        $task = Scheduler::call(fn() => null)->daily();

        // Assert
        $this->assertSame('0 0 * * *', $task->getCronExpression()->getExpression());
    }

    /**
     * hourly() must set the expression to '0 * * * *'.
     */
    public function testHourlySetsCorrectExpression(): void
    {
        $task = Scheduler::call(fn() => null)->hourly();
        $this->assertSame('0 * * * *', $task->getCronExpression()->getExpression());
    }

    /**
     * everyFiveMinutes() must set the expression to '* /5 * * * *' (every 5 minutes).
     */
    public function testEveryFiveMinutesSetsCorrectExpression(): void
    {
        $task = Scheduler::call(fn() => null)->everyFiveMinutes();
        $this->assertSame('*/5 * * * *', $task->getCronExpression()->getExpression());
    }

    /**
     * daily()->at('02:30') must produce '30 2 * * *'.
     */
    public function testDailyAtSetsTime(): void
    {
        // Arrange
        $task = Scheduler::call(fn() => null)->daily()->at('02:30');

        // Assert
        $this->assertSame('30 2 * * *', $task->getCronExpression()->getExpression());
    }

    /**
     * weekly() must set the expression to '0 0 * * 0'.
     */
    public function testWeeklySetsCorrectExpression(): void
    {
        $task = Scheduler::call(fn() => null)->weekly();
        $this->assertSame('0 0 * * 0', $task->getCronExpression()->getExpression());
    }

    /**
     * monthly() must set the expression to '0 0 1 * *'.
     */
    public function testMonthlySetsCorrectExpression(): void
    {
        $task = Scheduler::call(fn() => null)->monthly();
        $this->assertSame('0 0 1 * *', $task->getCronExpression()->getExpression());
    }

    /**
     * description() must be reflected in getSummary().
     */
    public function testDescriptionAppearsInSummary(): void
    {
        // Arrange
        $task = Scheduler::call(fn() => null)->daily()->description('Daily cleanup');

        // Assert
        $this->assertSame('Daily cleanup', $task->getSummary()['description']);
    }

    /**
     * withoutOverlapping() must set the no_overlap flag in getSummary().
     */
    public function testWithoutOverlappingFlagInSummary(): void
    {
        // Arrange
        $task = Scheduler::call(fn() => null)->hourly()->withoutOverlapping();

        // Assert
        $this->assertTrue($task->getSummary()['no_overlap']);
    }

    /**
     * isDue() on a ScheduledTask must delegate to CronExpression and return
     * correct results.
     */
    public function testScheduledTaskIsDue(): void
    {
        // Arrange — task due at 03:00 daily
        $task = Scheduler::call(fn() => null)->daily()->at('03:00');

        // Assert — due at 03:00
        $this->assertTrue($task->isDue(new \DateTimeImmutable('2025-05-01 03:00')));
        // Not due at 02:00
        $this->assertFalse($task->isDue(new \DateTimeImmutable('2025-05-01 02:00')));
    }

    /**
     * A 'callable' task must execute the closure when run() is called.
     */
    public function testCallableTaskRuns(): void
    {
        // Arrange
        $ran = false;
        $task = Scheduler::call(function () use (&$ran) { $ran = true; })->everyMinute();

        // Act
        $task->run();

        // Assert
        $this->assertTrue($ran, 'Callable task must execute the closure');
    }

    /**
     * A 'job' object with a handle() method must have handle() called by run().
     */
    public function testJobTaskCallsHandle(): void
    {
        // Arrange
        $job = new class {
            public bool $handled = false;
            public function handle(): void { $this->handled = true; }
        };

        $task = Scheduler::job($job)->everyMinute();

        // Act
        $task->run();

        // Assert
        $this->assertTrue($job->handled);
    }

    // =========================================================================
    // Scheduler — static registry
    // =========================================================================

    /**
     * Scheduler::command() must create a task with type 'command' and the
     * given command name as handler.
     */
    public function testSchedulerCommandCreatesCommandTask(): void
    {
        // Arrange & Act
        $task = Scheduler::command('migrate');

        // Assert
        $summary = $task->getSummary();
        $this->assertSame('command', $summary['type']);
        $this->assertSame('migrate', $summary['handler']);
    }

    /**
     * Scheduler::call() must create a task with type 'callable'.
     */
    public function testSchedulerCallCreatesCallableTask(): void
    {
        $task = Scheduler::call(fn() => null);
        $this->assertSame('callable', $task->getSummary()['type']);
    }

    /**
     * Scheduler::job() must create a task with type 'job'.
     */
    public function testSchedulerJobCreatesJobTask(): void
    {
        $task = Scheduler::job(new \stdClass());
        $this->assertSame('job', $task->getSummary()['type']);
    }

    /**
     * all() must return every registered task in registration order.
     */
    public function testSchedulerAllReturnsTasks(): void
    {
        // Arrange
        Scheduler::command('a');
        Scheduler::command('b');
        Scheduler::command('c');

        // Act
        $all = Scheduler::all();

        // Assert
        $this->assertCount(3, $all);
    }

    /**
     * getDue() must return only the tasks whose expression is currently
     * satisfied, filtered by the given moment.
     */
    public function testGetDueFiltersToOnlyDueTasks(): void
    {
        // Arrange — one task due at 10:00, one at 11:00
        Scheduler::call(fn() => null)->daily()->at('10:00');
        Scheduler::call(fn() => null)->daily()->at('11:00');

        // Act — check at 10:00
        $due = Scheduler::getDue(new \DateTimeImmutable('2025-01-15 10:00'));

        // Assert — only the 10:00 task is due
        $this->assertCount(1, $due);
    }

    /**
     * getDue() must return an empty array when no tasks are due.
     */
    public function testGetDueReturnsEmptyWhenNoDueTasks(): void
    {
        // Arrange — one task at 23:59
        Scheduler::call(fn() => null)->daily()->at('23:59');

        // Act — check at 00:00 — not due
        $due = Scheduler::getDue(new \DateTimeImmutable('2025-01-15 00:00'));

        // Assert
        $this->assertEmpty($due);
    }

    /**
     * reset() must remove all tasks so a fresh test does not inherit state
     * from a previous test.
     */
    public function testSchedulerResetClearsAllTasks(): void
    {
        // Arrange
        Scheduler::command('something');
        $this->assertNotEmpty(Scheduler::all());

        // Act
        Scheduler::reset();

        // Assert
        $this->assertEmpty(Scheduler::all());
    }
}
