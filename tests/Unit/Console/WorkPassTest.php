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
}
