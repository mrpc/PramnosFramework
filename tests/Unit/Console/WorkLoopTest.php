<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Work;
use Pramnos\Scheduling\Scheduler;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The loop `php pramnos work` runs — the half of the command a pass never reaches.
 *
 * {@see WorkPassTest} covers `runDuePass()`: what happens to one due task, and what happens to
 * the others when it raises. This covers `execute()`, which is where the process's promises
 * live and where none of them had been executed:
 *
 *   - **`--once` does not take the worker lock.** It is the cron equivalent, and a crontab line
 *     that refused to run because a long-running worker holds the lock would be a cron line that
 *     silently does nothing.
 *   - **A second worker refuses to start**, because two workers would run every job twice.
 *   - **It stops when its lock is taken over**, which is what a deploy looks like from inside: the
 *     replacement started before this one was told to stop, and carrying on means both are
 *     running the same schedule.
 *   - **`--max-runtime` is a clean exit, not a failure.** A supervisor reads the exit code, and a
 *     non-zero one turns a planned restart into a reported crash.
 *   - **The lock is released whatever happens**, including when the loop leaves by an exception.
 *
 * The task-running seam is stubbed rather than the scheduler: `runDuePass()` has its own tests,
 * and letting real framework tasks run inside this one would put a database and a spool
 * directory behind assertions about control flow.
 */
#[CoversClass(Work::class)]
class WorkLoopTest extends TestCase
{
    protected function setUp(): void
    {
        Scheduler::reset();
    }

    protected function tearDown(): void
    {
        Scheduler::reset();
    }

    /**
     * The command with every side effect of a long-running process replaced.
     *
     * Signals are not installed, because a handler registered in the test process outlives the
     * test; `sleepUnlessStopping()` is left real but reached with `shouldStop()` already true, so
     * the suite never waits a second for it.
     */
    private function worker(): object
    {
        return new class extends Work {
            /** Passes to allow before `shouldStop()` starts answering true. */
            public int $stopAfter = 1;

            /** What `checkIfRunning()` answers — true means another worker holds the lock. */
            public bool $alreadyRunning = false;

            /** What `heartbeat()` answers — false means this worker's lock was taken over. */
            public bool $keepsLock = true;

            /** Raised from `heartbeat()` when set, to reach the `finally`. */
            public ?\Throwable $heartbeatFails = null;

            public int $passes = 0;

            public int $failuresPerPass = 0;

            /** @var list<string> the lifecycle calls, in order */
            public array $calls = [];

            public function __construct()
            {
                parent::__construct('work');
            }

            protected function runDuePass(\Symfony\Component\Console\Output\OutputInterface $out): int
            {
                $this->passes++;
                $this->calls[] = 'pass';

                return $this->failuresPerPass;
            }

            protected function checkIfRunning(): bool
            {
                $this->calls[] = 'checkIfRunning';

                return $this->alreadyRunning;
            }

            protected function startJob(): void
            {
                $this->calls[] = 'startJob';
            }

            public function endJob(): void
            {
                $this->calls[] = 'endJob';
            }

            protected function installStopSignals(?callable $onStop = null): void
            {
                $this->calls[] = 'installStopSignals';
            }

            protected function systemd(): \Pramnos\Console\SystemdNotifier
            {
                $this->calls[] = 'systemd';

                // The real notifier, not a double: with no `NOTIFY_SOCKET` in the environment —
                // which is every environment but a systemd unit — `ready()` has nothing to write
                // to and says so by returning false. Doubling it would only hide that.
                return parent::systemd();
            }

            protected function heartbeat(array $extra = []): bool
            {
                $this->calls[] = 'heartbeat';

                if ($this->heartbeatFails !== null) {
                    throw $this->heartbeatFails;
                }

                return $this->keepsLock;
            }

            protected function shouldStop(): bool
            {
                return $this->passes >= $this->stopAfter;
            }

            protected function log(string $message, array $context = []): void
            {
            }
        };
    }

    /** Run it, and hand back the tester so the caller can read the output and the status. */
    private function execute(object $worker, array $input = []): CommandTester
    {
        $tester = new CommandTester($worker);
        $tester->execute($input);

        return $tester;
    }

    // ── The cron equivalent ───────────────────────────────────────────────────

    /**
     * `--once` runs exactly one pass and never touches the worker lock.
     *
     * The lock exists to stop a second *worker* starting beside the first; a crontab line is not
     * a second worker, and the tasks it runs take their own locks through `withoutOverlapping()`.
     * Taking the worker lock here would mean a cron line that does nothing on any installation
     * that also runs `php pramnos work` — and does it silently, once a minute, forever.
     */
    public function testOnceRunsOnePassAndTakesNoWorkerLock(): void
    {
        // Arrange
        $worker = $this->worker();

        // Act
        $tester = $this->execute($worker, ['--once' => true]);

        // Assert
        $this->assertSame(1, $worker->passes);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['pass'], $worker->calls, 'a one-shot pass touched the worker lifecycle');
    }

    /**
     * And its exit code carries the outcome, because that is all cron can see.
     *
     * A failed task with a zero exit is a job that has been broken for a month in an environment
     * whose only monitoring is `MAILTO`.
     */
    public function testOnceFailsWhenATaskFailed(): void
    {
        // Arrange
        $worker = $this->worker();
        $worker->failuresPerPass = 1;

        // Act
        $tester = $this->execute($worker, ['--once' => true]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
    }

    // ── The long-running worker ───────────────────────────────────────────────

    /**
     * A second worker says so and exits non-zero, without starting.
     *
     * Two workers run every scheduled job twice. `withoutOverlapping()` on the individual tasks
     * narrows the damage but does not prevent it: a task without that flag — most of them — has
     * nothing stopping two copies.
     */
    public function testASecondWorkerRefusesToStart(): void
    {
        // Arrange
        $worker = $this->worker();
        $worker->alreadyRunning = true;

        // Act
        $tester = $this->execute($worker);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Another worker is already running', $tester->getDisplay());
        $this->assertSame(0, $worker->passes, 'the second worker ran the schedule anyway');
        $this->assertNotContains('startJob', $worker->calls);
    }

    /**
     * A worker that starts announces its interval and tells systemd it is up.
     *
     * `ready()` matters for `Type=notify`: without it systemd waits for the readiness it was
     * promised, decides the unit failed to start, and restarts a process that is working.
     */
    public function testAStartingWorkerAnnouncesItselfAndSignalsReadiness(): void
    {
        // Arrange
        $worker = $this->worker();

        // Act
        $tester = $this->execute($worker, ['--interval' => '30']);

        // Assert
        $this->assertStringContainsString('Checking every 30s', $tester->getDisplay());
        $this->assertContains('systemd', $worker->calls);
        $this->assertContains('installStopSignals', $worker->calls);
        $this->assertSame(
            ['checkIfRunning', 'startJob', 'installStopSignals', 'systemd'],
            array_slice($worker->calls, 0, 4),
            'the lock is taken before the signals that release it are installed'
        );
    }

    /**
     * A lock taken over by a replacement stops this worker, and says why.
     *
     * What a deploy looks like from inside: the new process started before this one was told to
     * stop. Both hold the same schedule, so the one that no longer owns the lock has to leave —
     * quietly carrying on is how a job runs twice for the length of a rollout.
     */
    public function testALockTakenOverStopsTheWorker(): void
    {
        // Arrange — never asked to stop; only the lost lock ends the loop.
        $worker = $this->worker();
        $worker->stopAfter = PHP_INT_MAX;
        $worker->keepsLock = false;

        // Act
        $tester = $this->execute($worker);

        // Assert
        $this->assertSame(1, $worker->passes, 'the loop carried on after losing its lock');
        $this->assertStringContainsString('Lock taken over', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode(), 'being replaced is not a failure');
        $this->assertSame('endJob', end($worker->calls));
    }

    /**
     * `--max-runtime` ends the loop cleanly and says so.
     *
     * The exit code is the point. A supervisor is told to restart on exit, and a non-zero status
     * turns a planned recycle into a crash in whatever reads the unit's history.
     */
    public function testMaxRuntimeStopsTheLoopWithASuccessfulExit(): void
    {
        // Arrange — a zero-second budget is already spent when the first pass ends.
        $worker = $this->worker();
        $worker->stopAfter = PHP_INT_MAX;

        // Act
        $tester = $this->execute($worker, ['--max-runtime' => '1', '--interval' => '1']);

        // Assert
        $this->assertStringContainsString('Reached max runtime', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('endJob', end($worker->calls));
    }

    /**
     * The lock is released even when the loop leaves by an exception.
     *
     * `runDuePass()` catches everything a task can raise, so what reaches here comes from the
     * loop's own machinery — a heartbeat against a database that has gone away. Without the
     * `finally`, that leaves a lock nothing holds, and the next worker refuses to start until
     * somebody deletes it by hand.
     */
    public function testTheLockIsReleasedWhenTheLoopThrows(): void
    {
        // Arrange
        $worker = $this->worker();
        $worker->stopAfter = PHP_INT_MAX;
        $worker->heartbeatFails = new \RuntimeException('the database went away');

        // Act
        try {
            $this->execute($worker);
            $this->fail('the exception was swallowed');
        } catch (\RuntimeException $exception) {
            // Assert
            $this->assertSame('the database went away', $exception->getMessage());
        }

        $this->assertSame('endJob', end($worker->calls), 'the worker lock was left behind');
    }

    /**
     * An interval of zero is clamped to a second rather than spinning.
     *
     * `--interval=0` reads as "check constantly" and would be a busy loop taking a database
     * connection per iteration. One second is the smallest honest answer to that request.
     */
    public function testAZeroIntervalIsClampedRatherThanSpinning(): void
    {
        // Arrange
        $worker = $this->worker();

        // Act
        $tester = $this->execute($worker, ['--interval' => '0']);

        // Assert
        $this->assertStringContainsString('Checking every 1s', $tester->getDisplay());
    }

    /**
     * A worker asked to stop does not sit out the rest of its interval.
     *
     * A plain `sleep(60)` would make every SIGTERM take up to a minute to be noticed, and a
     * deploy waits for each one. The loop sleeps a second at a time and re-checks; asserted by
     * timing a `sleepUnlessStopping()` on a 60-second interval that is already stopping, which
     * must return at once rather than in a minute.
     */
    public function testStoppingDoesNotWaitOutTheInterval(): void
    {
        // Arrange
        $worker = new class extends Work {
            public function __construct()
            {
                parent::__construct('work');
                $this->interval = 60;
            }

            protected function shouldStop(): bool
            {
                return true;
            }

            public function sleepNow(): void
            {
                $this->sleepUnlessStopping();
            }
        };

        // Act
        $started = microtime(true);
        $worker->sleepNow();
        $elapsed = microtime(true) - $started;

        // Assert
        $this->assertLessThan(1.0, $elapsed, 'a stopping worker slept through its interval');
    }

    /**
     * A worker not yet stopping sleeps in one-second steps.
     *
     * The other half of the same claim: the early return above would also be satisfied by a
     * `sleepUnlessStopping()` that never sleeps at all, which would turn the loop into a spin.
     */
    public function testAWorkerThatIsNotStoppingActuallySleeps(): void
    {
        // Arrange — one second of interval, so the assertion costs the suite one second once.
        $worker = new class extends Work {
            public function __construct()
            {
                parent::__construct('work');
                $this->interval = 1;
            }

            protected function shouldStop(): bool
            {
                return false;
            }

            public function sleepNow(): void
            {
                $this->sleepUnlessStopping();
            }
        };

        // Act
        $started = microtime(true);
        $worker->sleepNow();
        $elapsed = microtime(true) - $started;

        // Assert
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'the loop does not wait between passes');
    }

    /**
     * The worker lock has a name of its own, and one for the whole process.
     *
     * Not one per task: the tasks take their own through `withoutOverlapping()`. A per-task name
     * here would let two workers start, each holding a different lock, running every job twice.
     */
    public function testTheWorkerLockNamesTheProcessRatherThanATask(): void
    {
        // Arrange
        $worker = new class extends Work {
            public function __construct()
            {
                parent::__construct('work');
            }

            public function jobName(): string
            {
                return $this->getJobName();
            }
        };

        // Act & Assert
        $this->assertSame('pramnos-work.lock', $worker->jobName());
    }
}
