<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\ScheduledTask;

/**
 * A scheduled `command` task runs the right console, and says so when it fails.
 *
 * Two defects, found together on an installation where `spool:drain` had never run:
 *
 * **It shelled out to `php pramnos`.** That is the framework's own entry point, which a
 * scaffolded application does not have — the scaffolder generates `<cliName>.php` in the
 * project root and documents that convention. So every `command` task in such a project
 * answered `Could not open input file: pramnos`.
 *
 * **And nothing noticed.** `passthru()` was called without its status argument, so the
 * task reported `✓ Done` and `schedule:run` exited 0. The failure existed only as a line
 * of stdout that cron sends to /dev/null. Measured consequence: 478 rows waiting in the
 * write spool, a `tokenactions` table that had never had a row, and a Performance panel
 * reporting "no data" — with a green scheduler above it.
 *
 * Either fix alone would have exposed the other. The second is the one that matters more:
 * it is the difference between a bug that is found the first time it happens and a bug
 * that is found by someone counting rows in a spool file days later.
 */
#[CoversClass(ScheduledTask::class)]
class ScheduledCommandExecutionTest extends TestCase
{
    /**
     * A task that records the command instead of running it.
     *
     * @param string $handler The command to schedule
     * @param int    $status  The exit status the shell should report
     * @return ScheduledTask&object{lastCommand: string}
     */
    private function task(string $handler, int $status = 0): ScheduledTask
    {
        return new class($handler, $status) extends ScheduledTask {
            public string $lastCommand = '';

            public function __construct(string $handler, private int $status)
            {
                parent::__construct($handler, 'command');
            }

            protected function runShellCommand(string $command): int
            {
                $this->lastCommand = $command;

                return $this->status;
            }
        };
    }

    /**
     * The command runs through the console the scheduler itself was started with.
     *
     * Asserted as "this PHP binary and this script", which under the test runner is
     * PHPUnit — the point is that it is the *running* entry point rather than a fixed
     * name that may not exist.
     *
     * @return void
     */
    public function testTheCommandRunsThroughTheRunningConsole(): void
    {
        // Arrange
        $task = $this->task('spool:drain');

        // Act
        $task->run();

        // Assert — the running PHP, the running script, then the command
        $this->assertStringContainsString(PHP_BINARY, $task->lastCommand);
        $this->assertStringContainsString(
            basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')),
            $task->lastCommand
        );
        $this->assertStringEndsWith('spool:drain', $task->lastCommand);
        // The literal that could not work in a scaffolded application
        $this->assertStringNotContainsString('php pramnos ', $task->lastCommand);
    }

    /**
     * A command that exits non-zero raises, so the callers report it as failed.
     *
     * `schedule:run` and `work` both already catch per task, print the failure and count
     * it. Throwing is what connects a broken command to that reporting — and what would
     * have caught the wrong binary the first minute it ran.
     *
     * @return void
     */
    public function testANonZeroExitIsReportedAsAFailure(): void
    {
        // Arrange — the shell answers 127, as it does for "command not found"
        $task = $this->task('spool:drain', 127);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/spool:drain.*127/');

        // Act
        $task->run();
    }

    /**
     * A command that succeeds does not raise, and reports that it ran.
     *
     * The other half: a change that threw on everything would satisfy the test above
     * while breaking every schedule there is.
     *
     * @return void
     */
    public function testASuccessfulCommandRunsQuietly(): void
    {
        // Arrange + Act
        $task = $this->task('queue:cleanup', 0);

        // Assert — run() returns true (it ran, rather than being skipped as overlapping)
        $this->assertTrue($task->run());
    }

    /**
     * The overlap lock is still released when a command fails.
     *
     * `run()` releases in a `finally`, and the new throw goes through it. Without that,
     * one failing command would lock its own task out for ever — turning a loud failure
     * into a silent one, which is the defect this change exists to remove.
     *
     * @return void
     */
    public function testAFailedCommandStillReleasesItsOverlapLock(): void
    {
        // Arrange
        $lockDir = sys_get_temp_dir() . '/pramnos_sched_test_' . bin2hex(random_bytes(4));
        mkdir($lockDir, 0777, true);

        $task = $this->task('spool:drain', 1);
        $task->withoutOverlapping($lockDir);

        // Act
        try {
            $task->run();
        } catch (\RuntimeException) {
            // expected
        }

        // Assert — nothing left behind, so the next minute can try again
        $this->assertSame(
            [],
            array_values(array_diff((array) scandir($lockDir), ['.', '..'])),
            'a failed command must not leave its lock held'
        );

        rmdir($lockDir);
    }

    /**
     * `PRAMNOS_BIN` still wins where an installation defines it.
     *
     * The override predates this and is the documented way to point the scheduler at
     * something else; the new default must not take it away.
     *
     * @return void
     */
    public function testAnExplicitBinaryOverridesTheDetectedOne(): void
    {
        // Arrange — a subclass standing in for the constant, which cannot be
        // defined in one test without leaking into every test after it
        $task = new class('spool:drain') extends ScheduledTask {
            public string $lastCommand = '';

            public function __construct(string $handler)
            {
                parent::__construct($handler, 'command');
            }

            protected function consoleBinary(): string
            {
                return '/custom/bin/console';
            }

            protected function runShellCommand(string $command): int
            {
                $this->lastCommand = $command;

                return 0;
            }
        };

        // Act
        $task->run();

        // Assert
        $this->assertSame('/custom/bin/console spool:drain', $task->lastCommand);
    }
}
