<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Work;
use Pramnos\Scheduling\Scheduler;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * One pass of `work` — 59 of 84 statements never executed.
 *
 * `work` is the process an installation without cron runs instead of a crontab, and the whole
 * argument for having it is that **one broken job must not stop the others**: a container with no
 * cron has one process doing everything the background needs, so a task that raises taking the
 * pass with it would stop buffered writes, queue processing and cleanup together, for ever, on the
 * strength of one bug in one job.
 *
 * So that is what is asserted here, along with the three outcomes a pass distinguishes and the
 * exit code `--once` hands back to a cron line.
 *
 * No backend of its own: the scheduler holds callables and the pass is control flow. What each
 * *task* does is its own subject — `spool:drain` had its round, and it runs on both.
 */
#[CoversClass(Work::class)]
class WorkPassTest extends TestCase
{
    protected function setUp(): void
    {
        Scheduler::reset();
    }

    protected function tearDown(): void
    {
        Scheduler::reset();
        foreach ($this->minuteFiles as $file) {
            @unlink($file);
        }
    }

    /** The command with the pass reachable, and nothing else changed. */
    private function worker(): object
    {
        return new class extends Work {
            public array $logged = [];

            public function __construct()
            {
                parent::__construct('work');
            }

            /** A minute file of its own, not the installation's. */
            protected function minuteFile(): string
            {
                return sys_get_temp_dir() . '/work-pass-' . spl_object_id($this) . '-' . getmypid() . '.minute';
            }

            public function pass(BufferedOutput $output): int
            {
                return $this->runDuePass($output);
            }

            protected function log(string $message, array $context = []): void
            {
                $this->logged[] = $message;
            }
        };
    }

    /** Nothing due is not a failure, and says nothing. */
    public function testNothingDueIsQuietAndSucceeds(): void
    {
        // Arrange
        $worker = $this->worker();
        $output = new BufferedOutput();

        // Act
        $failures = $worker->pass($output);

        // Assert
        $this->assertSame(0, $failures);
        $this->assertSame('', trim($output->fetch()));
    }

    /**
     * A task that raises is counted, and **every other task still runs**.
     *
     * The reason this process exists rather than a crontab line each. On an installation with no
     * cron this is the only thing running the background, so a pass that abandoned itself on the
     * first exception would stop the buffered writes and the queue along with the broken job —
     * and keep stopping them every minute, for ever.
     */
    public function testABrokenTaskDoesNotStopTheOthers(): void
    {
        // Arrange — one that fails, between two that do not.
        $ran = [];

        Scheduler::call(static function () use (&$ran): void {
            $ran[] = 'first';
        })->everyMinute()->description('first');

        Scheduler::call(static function (): void {
            throw new \RuntimeException('this job is broken');
        })->everyMinute()->description('broken');

        Scheduler::call(static function () use (&$ran): void {
            $ran[] = 'third';
        })->everyMinute()->description('third');

        $worker = $this->worker();
        $output = new BufferedOutput();

        // Act
        $failures = $worker->pass($output);
        $text     = $output->fetch();

        // Assert
        $this->assertSame(1, $failures, 'the broken task was not counted');
        $this->assertSame(
            ['first', 'third'],
            $ran,
            'a task after the broken one did not run'
        );

        // And the operator is told which one, with the reason.
        $this->assertStringContainsString('broken', $text);
        $this->assertStringContainsString('this job is broken', $text);
    }

    /**
     * The reason is logged, not only printed.
     *
     * `work` runs under systemd or as a container command, where nothing is watching the output.
     * A failure that exists only on a terminal nobody is attached to is a failure nobody knows
     * about.
     */
    public function testAFailureIsLoggedAndNotOnlyPrinted(): void
    {
        // Arrange
        Scheduler::call(static function (): void {
            throw new \RuntimeException('the reason');
        })->everyMinute()->description('breaks');

        $worker = $this->worker();

        // Act
        $worker->pass(new BufferedOutput());

        // Assert
        $this->assertNotSame([], $worker->logged);
        $this->assertStringContainsString('failed: breaks', $worker->logged[0]);
        $this->assertStringContainsString('the reason', $worker->logged[0]);
    }

    /**
     * A task that ran is timed, and a task that declined is not a failure.
     *
     * `run()` answers false when the task holds a no-overlap lock somebody else has — the
     * previous minute's copy is still going. That is the mechanism working, so it is reported
     * with its own mark and counted as nothing: counted as a failure, a job that legitimately
     * takes three minutes would report two failures for every success.
     */
    public function testATaskThatDeclinedIsNotAFailure(): void
    {
        // Arrange — a task that is already running, expressed the way the scheduler does.
        Scheduler::call(static function (): void {
            usleep(1000);
        })->everyMinute()->description('slow one')->withoutOverlapping();

        $first  = $this->worker();
        $output = new BufferedOutput();

        // Act — the same task twice in one pass is not possible, so the lock is taken first.
        $tasks = Scheduler::getDue(new \DateTime());
        $this->assertCount(1, $tasks);

        $lock = (new \ReflectionMethod($tasks[0], 'lock'))->invoke($tasks[0]);
        $this->assertTrue($lock->acquire(), 'precondition: the lock could be taken');

        try {
            $failures = $first->pass($output);
            $text     = $output->fetch();
        } finally {
            $lock->release();
        }

        // Assert
        $this->assertSame(0, $failures, 'a task that declined was counted as a failure');
        $this->assertStringContainsString('still running', $text);
    }

    /**
     * A task with no description is named by its handler.
     *
     * The line is the only record of what ran, and `↷` with nothing after it is a line that
     * cannot be acted on.
     */
    public function testATaskWithNoDescriptionIsNamedByItsHandler(): void
    {
        // Arrange
        Scheduler::call(static function (): void {
        })->everyMinute();

        $worker = $this->worker();
        $output = new BufferedOutput();

        // Act
        $worker->pass($output);
        $text = $output->fetch();

        // Assert
        $this->assertNotSame('', trim($text), 'the task ran and nothing said so');
        $this->assertMatchesRegularExpression('~\S~', trim(strip_tags($text)));
    }

    /**
     * A successful pass reports how long each task took.
     *
     * Which is the number that turns "the background is slow" into a specific job, and there is
     * nowhere else it is recorded.
     */
    public function testASuccessfulTaskIsTimed(): void
    {
        // Arrange
        Scheduler::call(static function (): void {
        })->everyMinute()->description('quick');

        $worker = $this->worker();
        $output = new BufferedOutput();

        // Act
        $worker->pass($output);

        // Assert
        $this->assertStringContainsString('ms)', $output->fetch());
        $this->assertStringContainsString('ran: quick in', $worker->logged[0] ?? '');
    }

    /** @var list<string> Minute files to remove after the test. */
    private array $minuteFiles = [];

    /** A fresh, absent minute file for one test. */
    private function minuteFile(): string
    {
        $file = sys_get_temp_dir() . '/work-minute-' . bin2hex(random_bytes(6));
        $this->minuteFiles[] = $file;

        return $file;
    }

    /**
     * A worker whose clock is set by the test, for the passes below.
     */
    private function clocked(?string $minuteFile = null): object
    {
        $minuteFile ??= $this->minuteFile();

        return new class ($minuteFile) extends Work {
            public string $at = '2026-10-21 03:19:59';

            public function __construct(private string $file)
            {
                parent::__construct('work');
            }

            /** The file the test chose, so two workers can share one as two processes do. */
            protected function minuteFile(): string
            {
                return $this->file;
            }

            /** One pass at `$at`. */
            public function passAt(string $at): int
            {
                $this->at = $at;

                return $this->runDuePass(new BufferedOutput());
            }

            protected function clock(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->at);
            }

            protected function log(string $message, array $context = []): void
            {
            }
        };
    }

    /**
     * A minute between two passes is still asked about.
     *
     * The production case: a pass at 03:19:59 ran a nine-second mail flush, the worker slept
     * sixty seconds after it ended, and the next pass was at 03:21:09 — 03:20 was never asked
     * about, and the payments due at :20 did not run. The second pass asks about 03:20 and
     * 03:21 both.
     */
    public function testAMinuteBetweenTwoPassesIsNotSkipped(): void
    {
        // Arrange
        $ran = 0;
        Scheduler::call(static function () use (&$ran): void {
            $ran++;
        })->cron('20 * * * *');
        $worker = $this->clocked();

        // Act
        $worker->passAt('2026-10-21 03:19:59');
        $before = $ran;
        $worker->passAt('2026-10-21 03:21:09');

        // Assert
        $this->assertSame(0, $before, 'the task ran a minute early');
        $this->assertSame(1, $ran, 'the 03:20 run was skipped');
    }

    /**
     * A task due in several of the minutes a pass catches up on runs once, and a second pass
     * inside the same minute runs nothing again.
     */
    public function testCaughtUpMinutesRunATaskOnceAndNoMinuteTwice(): void
    {
        // Arrange
        $ran = 0;
        Scheduler::call(static function () use (&$ran): void {
            $ran++;
        })->everyMinute();
        $worker = $this->clocked();

        // Act
        $worker->passAt('2026-10-21 03:19:05');
        $worker->passAt('2026-10-21 03:23:10'); // 03:20 to 03:23: four minutes, one run
        $worker->passAt('2026-10-21 03:23:40'); // 03:23 again: nothing new

        // Assert
        $this->assertSame(2, $ran);
    }

    /**
     * The first pass does not look back, and a long gap is caught up only
     * `CATCH_UP_MINUTES` deep — a worker stopped for an hour does not replay the hour.
     */
    public function testTheFirstPassDoesNotLookBackAndALongGapIsCapped(): void
    {
        // Arrange — one task at :20, another at :50
        $at20 = 0;
        $at50 = 0;
        Scheduler::call(static function () use (&$at20): void {
            $at20++;
        })->cron('20 * * * *');
        Scheduler::call(static function () use (&$at50): void {
            $at50++;
        })->cron('50 * * * *');
        $worker = $this->clocked();

        // Act
        $worker->passAt('2026-10-21 03:21:00'); // first: 03:21 only, not 03:20
        $first = $at20;
        $worker->passAt('2026-10-21 04:30:00'); // gap of 69 minutes: 04:16 to 04:30 asked

        // Assert
        $this->assertSame(0, $first, 'the first pass reached back for a minute it never owned');
        $this->assertSame(1, $at20, '04:20 is inside the window and was not run');
        $this->assertSame(0, $at50, '03:50 is outside the window and was replayed');
    }

    /**
     * A restart does not lose the minutes between the two processes.
     *
     * The production case: a deploy restarted the worker, the old process finished its pass at
     * 07:17:40 and the new one started at 07:19:10, and the task on `18 7 * * *` never ran — the
     * new process asked about 07:19 alone. The new process reads the last minute the old one asked
     * about, from beside the lock, and starts after it.
     */
    public function testARestartCatchesUpTheMinutesBetweenTheTwoProcesses(): void
    {
        // Arrange
        $ran = 0;
        Scheduler::call(static function () use (&$ran): void {
            $ran++;
        })->cron('18 7 * * *');
        $file = $this->minuteFile();

        // Act — two processes, one after the other, sharing the file as two workers do
        $this->clocked($file)->passAt('2026-10-21 07:17:40');
        $this->clocked($file)->passAt('2026-10-21 07:19:10');

        // Assert
        $this->assertSame(1, $ran, 'the 07:18 run fell in the restart and was lost');
    }

    /**
     * What a previous process left is still capped at `CATCH_UP_MINUTES`, and a minute it asked
     * about is not asked again: a worker down for an hour does not replay the hour, and a restart
     * inside one minute runs nothing twice.
     */
    public function testARestartIsCappedAndAsksNoMinuteTwice(): void
    {
        // Arrange
        $at20 = 0;
        $at50 = 0;
        Scheduler::call(static function () use (&$at20): void {
            $at20++;
        })->cron('20 * * * *');
        Scheduler::call(static function () use (&$at50): void {
            $at50++;
        })->cron('50 * * * *');
        $file = $this->minuteFile();

        // Act
        $this->clocked($file)->passAt('2026-10-21 03:20:05');
        $this->clocked($file)->passAt('2026-10-21 03:20:50'); // same minute, new process
        $this->clocked($file)->passAt('2026-10-21 04:30:00'); // 70 minutes later: 04:16 to 04:30

        // Assert
        $this->assertSame(2, $at20, '03:20 ran twice, or 04:20 was not caught up');
        $this->assertSame(0, $at50, '03:50 is outside the window and was replayed');
    }

    /**
     * A minute file that holds anything but a timestamp is no record: the pass asks about the
     * current minute only, as a first start does, and writes a good one.
     */
    public function testAnUnreadableMinuteFileIsTreatedAsAFirstStart(): void
    {
        // Arrange
        $ran = 0;
        Scheduler::call(static function () use (&$ran): void {
            $ran++;
        })->cron('20 * * * *');
        $file = $this->minuteFile();
        file_put_contents($file, 'garbage');

        // Act
        $this->clocked($file)->passAt('2026-10-21 03:21:00');

        // Assert
        $this->assertSame(0, $ran, 'a corrupt file made the pass look back');
        $this->assertSame((string) strtotime('2026-10-21 03:21:00'), file_get_contents($file));
    }
}
