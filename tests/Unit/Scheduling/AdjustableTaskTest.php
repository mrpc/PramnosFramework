<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\ScheduledTask;
use Pramnos\Scheduling\ScheduleOverrides;

/**
 * The rules of an adjustable task that need no database: which frequencies exist and what cron
 * they are, what a task offers, the hours window across midnight, the next run past a closed
 * window, and the per-run limit — clamped to the declared range and handed to the task.
 *
 * The table, the screen and the late/paused status are {@see \Pramnos\Tests\Integration\Scheduling\ScheduleOverridesTest}.
 */
#[CoversClass(ScheduleOverrides::class)]
#[CoversClass(ScheduledTask::class)]
final class AdjustableTaskTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function frequencies(): array
    {
        return [
            'every minute'   => ['every:1', '* * * * *'],
            'every 15'       => ['every:15', '*/15 * * * *'],
            'hourly'         => ['hours:1', '0 * * * *'],
            'every 6 hours'  => ['hours:6', '0 */6 * * *'],
            'daily'          => ['daily:07:30', '30 7 * * *'],
            'weekly'         => ['weekly:1:11:15', '15 11 * * 1'],
            'not on the list' => ['every:7', null],
            'not hours'      => ['hours:5', null],
            'bad time'       => ['daily:25:00', null],
            'bad day'        => ['weekly:7:11:15', null],
            'free cron'      => ['*/3 * * * *', null],
            'the code\'s'    => ['', null],
        ];
    }

    /**
     * Each frequency on the list is one cron expression, and nothing else is accepted: the screen
     * offers no free cron, so an expression typed into the form is not a schedule.
     */
    #[DataProvider('frequencies')]
    public function testEachFrequencyIsOneExpressionAndNothingElseIsAccepted(string $frequency, ?string $cron): void
    {
        // Act
        $actual = ScheduleOverrides::cronFor($frequency);

        // Assert
        $this->assertSame($cron, $actual);
    }

    /**
     * A task offers what it names, and the time-of-day kinds at any time; with nothing named it
     * offers the whole list.
     */
    public function testATaskOffersWhatItNames(): void
    {
        // Arrange
        $named = ['every:15', 'weekly'];

        // Act & Assert
        $this->assertTrue(ScheduleOverrides::offers($named, 'weekly:3:09:00'));
        $this->assertTrue(ScheduleOverrides::offers($named, 'every:15'));
        $this->assertFalse(ScheduleOverrides::offers($named, 'every:5'), 'a frequency the task does not offer');
        $this->assertFalse(ScheduleOverrides::offers($named, 'daily:09:00'));
        $this->assertFalse(ScheduleOverrides::offers(null, 'every:7'), 'off the list altogether');
        $this->assertCount(12, ScheduleOverrides::kinds(null));
    }

    /**
     * A window that ends before it starts crosses midnight; one with half of it, or the same start
     * and end, is no window at all.
     */
    public function testTheHoursWindowCrossesMidnight(): void
    {
        // Arrange
        $night = ['hoursfrom' => '22:00', 'hoursto' => '06:00'];
        $at    = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-21 ' . $time);

        // Act & Assert
        $this->assertTrue(ScheduleOverrides::inWindow($night, $at('23:30')));
        $this->assertTrue(ScheduleOverrides::inWindow($night, $at('05:59')));
        $this->assertFalse(ScheduleOverrides::inWindow($night, $at('06:00')), 'the end is not inside');
        $this->assertFalse(ScheduleOverrides::inWindow($night, $at('12:00')));
        $this->assertTrue(ScheduleOverrides::inWindow(['hoursfrom' => '09:00', 'hoursto' => null], $at('03:00')), 'half a window closed the task');
        $this->assertTrue(ScheduleOverrides::inWindow(['hoursfrom' => '09:00', 'hoursto' => '09:00'], $at('03:00')));
        $this->assertNull(ScheduleOverrides::minuteOfDay('24:00'));
    }

    /**
     * The next run skips forward past a closed window to the first minute the expression is due
     * inside it — the screen's "Outside hours · next 09:00" — and is null while paused.
     */
    public function testTheNextRunIsTheFirstInsideTheWindow(): void
    {
        // Arrange — on the hour, 22:00 to 06:00
        $task = (new ScheduledTask('digest:send', 'command'))->hourly()->adjustable('digest');
        $task->useOverride(['enabled' => 1, 'frequency' => null, 'hoursfrom' => '22:00', 'hoursto' => '06:00', 'runlimit' => null]);

        // Act
        $next  = $task->nextDue(new \DateTimeImmutable('2026-10-21 10:30'));
        $early = $task->nextDue(new \DateTimeImmutable('2026-10-21 03:10'));
        $task->useOverride(['enabled' => 0]);

        // Assert
        $this->assertSame('2026-10-21 22:00', $next?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 04:00', $early?->format('Y-m-d H:i'));
        $this->assertNull($task->nextDue(new \DateTimeImmutable('2026-10-21 10:30')), 'a paused task has a next run');
    }

    /**
     * A task that is not adjustable takes no override, and its label is its description, else the
     * adjustable label, else its handler — the label never becomes the description, which is part
     * of the key its history is kept under.
     */
    public function testATaskThatIsNotAdjustableTakesNoOverrideAndTheLabelLeavesTheKeyAlone(): void
    {
        // Arrange
        $plain    = (new ScheduledTask('digest:send', 'command'))->hourly();
        $labelled = (new ScheduledTask('digest:send', 'command'))->hourly();
        $before   = $labelled->key();

        // Act
        $plain->useOverride(['enabled' => 0]);
        $labelled->adjustable('digest', 'Weekly digest', limit: [600, 50]);

        // Assert
        $this->assertNull($plain->override());
        $this->assertFalse($plain->isPaused());
        $this->assertSame('Weekly digest', $labelled->label());
        $this->assertSame($before, $labelled->key(), 'making a task adjustable moved its history');
        $this->assertSame([50, 600], $labelled->adjustment()['limit'], 'the range was not put in order');
    }

    /**
     * An operator's limit reaches a command as `--limit=N`, clamped to the declared range; with no
     * limit set, the command line is the one written.
     */
    public function testTheLimitReachesACommandClamped(): void
    {
        // Arrange
        $task = new class ('digest:send') extends ScheduledTask {
            public string $lastCommand = '';

            public function __construct(string $handler)
            {
                parent::__construct($handler, 'command');
            }

            protected function runShellCommand(string $command): int
            {
                $this->lastCommand = $command;

                return 0;
            }
        };
        $task->adjustable('digest', limit: [50, 600]);

        // Act
        $task->run();
        $plain = $task->lastCommand;
        $task->useOverride(['enabled' => 1, 'runlimit' => 9000]);
        $task->run();

        // Assert
        $this->assertStringEndsWith('digest:send', $plain);
        $this->assertStringEndsWith('digest:send --limit=600', $task->lastCommand);
    }

    /** A callable, and a job's `handle()`, get the limit as their argument, and nothing without it. */
    public function testTheLimitReachesACallableAndAJob(): void
    {
        // Arrange
        $seen = [];
        $call = (new ScheduledTask(static function (...$args) use (&$seen): void {
            $seen[] = $args;
        }, 'callable'))->adjustable('call', limit: [1, 100]);
        $job = new class {
            public array $seen = [];

            public function handle(...$args): void
            {
                $this->seen[] = $args;
            }
        };
        $jobTask = (new ScheduledTask($job, 'job'))->adjustable('job', limit: [1, 100]);

        // Act
        $call->run();
        $call->useOverride(['enabled' => 1, 'runlimit' => 40]);
        $call->run();
        $jobTask->useOverride(['enabled' => 1, 'runlimit' => '']);
        $jobTask->run();
        $jobTask->useOverride(['enabled' => 1, 'runlimit' => 7]);
        $jobTask->run();

        // Assert
        $this->assertSame([[], [40]], $seen);
        $this->assertSame([[], [7]], $job->seen);
    }
}
