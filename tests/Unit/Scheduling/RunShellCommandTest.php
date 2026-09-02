<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\ScheduledTask;

/**
 * The one line in the scheduler that reaches the shell.
 *
 * `runShellCommand()` is a documented seam — every other test in this area asserts *what would be
 * run* by overriding it — which is why its four statements had never executed. Somebody has to run
 * the real one, or the seam is the only thing that has ever worked.
 *
 * What it must get right is the **exit status**. The scheduler decides whether a task succeeded
 * from this number: a `passthru()` whose status is dropped reports every command as successful,
 * including the ones that failed, and a schedule that cannot fail is a schedule nobody checks.
 */
#[CoversClass(ScheduledTask::class)]
class RunShellCommandTest extends TestCase
{
    /** Runs a real command through the real seam, swallowing its output. */
    private function runCommand(string $command): int
    {
        $task = new class extends ScheduledTask {
            public function __construct() {}

            public function exposeRunShellCommand(string $command): int
            {
                return $this->runShellCommand($command);
            }
        };

        // `passthru()` writes straight to the output; a scheduler wants it on the console and a
        // test does not.
        ob_start();

        try {
            return $task->exposeRunShellCommand($command);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A command that succeeds reports `0`.
     */
    public function testASuccessfulCommandReportsZero(): void
    {
        // Act + Assert
        $this->assertSame(0, $this->runCommand('true'));
    }

    /**
     * A command that fails reports its status, not `0`.
     *
     * The assertion the scheduler depends on. `passthru()` puts the status in a by-reference
     * argument, which is the easiest thing in PHP to forget — and forgetting it means every
     * scheduled task reports success.
     */
    public function testAFailingCommandReportsItsStatus(): void
    {
        // Act
        $status = $this->runCommand('false');

        // Assert
        $this->assertNotSame(0, $status, 'a failed command was reported as a success');
        $this->assertSame(1, $status);
    }

    /**
     * A specific exit code comes back as itself.
     *
     * Not merely "non-zero": a task that exits 2 to mean "nothing to do" and 1 to mean "broken"
     * needs the two distinguished, and this is the only place the number survives.
     */
    public function testASpecificExitCodeComesBackAsItself(): void
    {
        // Act + Assert
        $this->assertSame(3, $this->runCommand('exit 3'));
        $this->assertSame(42, $this->runCommand('exit 42'));
    }

    /**
     * A command that does not exist is a failure rather than an error.
     *
     * The shell answers 127, and the scheduler wants that as a status it can log — not as an
     * exception from inside a task runner that is halfway through a batch.
     */
    public function testAMissingCommandIsAStatusRatherThanAnError(): void
    {
        // Act
        $status = $this->runCommand('pramnos-no-such-command-exists 2>/dev/null');

        // Assert
        $this->assertSame(127, $status);
    }
}
