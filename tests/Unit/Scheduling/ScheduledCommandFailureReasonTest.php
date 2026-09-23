<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\ScheduledTask;

/**
 * A failed scheduled command must say **why**, not only that it failed.
 *
 * `schedule.log` read `failed: channels:collect (0 * * * *) — Scheduled command
 * 'channels:collect' exited with status 1`, and there was nothing anywhere saying
 * what went wrong. Finding it out took reading application tables to see how far
 * the pass had got and comparing that against the day's deploy times, because the
 * exception message the command had printed no longer existed.
 *
 * It no longer existed because `passthru()` writes the child's output to the
 * parent's stdout and keeps none of it, and the parent is `schedule:run` under
 * cron — **whose stdout is `/dev/null` on every installation this framework
 * documents**. The status survived; the reason was written to a device that
 * discards it.
 *
 * So `runShellCommand()` reads the output instead of inheriting it, echoes it on
 * as it arrives, and keeps the tail for the exception. These tests run real shell
 * commands through the real seam, because a mocked one is what let this stand.
 */
#[CoversClass(ScheduledTask::class)]
class ScheduledCommandFailureReasonTest extends TestCase
{
    /** @var string[] */
    private array $scripts = [];

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
        $this->scripts = [];
    }

    /**
     * A scheduled task whose "command" is a real shell script.
     *
     * `consoleBinary()` is overridden to `sh` so the handler can be a path: the
     * handler goes through `escapeshellcmd()`, which mangles quotes, so a path is
     * the only shape that survives it intact.
     */
    private function taskRunning(string $shell): ScheduledTask
    {
        $path = sys_get_temp_dir() . '/pf-sched-' . bin2hex(random_bytes(6)) . '.sh';
        file_put_contents($path, "#!/bin/sh\n" . $shell . "\n");
        $this->scripts[] = $path;

        return new class($path) extends ScheduledTask {
            public function __construct(string $path)
            {
                parent::__construct($path, 'command');
            }

            protected function consoleBinary(): string
            {
                return 'sh';
            }
        };
    }

    /**
     * Run it and hand back what it threw, with the console output swallowed.
     */
    private function failureOf(ScheduledTask $task): \RuntimeException
    {
        ob_start();

        try {
            $task->run();
        } catch (\RuntimeException $ex) {
            return $ex;
        } finally {
            ob_end_clean();
        }

        // Outside the try, so `fail()`'s own exception is not caught above — a
        // `$this->fail()` inside a `catch (\RuntimeException)` is swallowed by it.
        $this->fail('the command was expected to fail');
    }

    /**
     * The reason is in the message.
     *
     * The whole finding in one assertion: an operator reading `schedule.log` can
     * see what the command said, rather than only that it exited non-zero.
     */
    public function testTheExceptionCarriesWhatTheCommandPrinted(): void
    {
        // Arrange
        $task = $this->taskRunning('echo "PDOException: could not find driver" >&2; exit 1');

        // Act
        $failure = $this->failureOf($task);

        // Assert
        $this->assertStringContainsString('exited with status 1', $failure->getMessage());
        $this->assertStringContainsString(
            'PDOException: could not find driver',
            $failure->getMessage(),
            'the reason the command failed was discarded, which is the whole bug'
        );
    }

    /**
     * Standard error is captured too, and it is where the reason usually is.
     *
     * The test above writes to stderr on purpose. This one proves the choice is
     * deliberate rather than incidental by checking the other stream as well —
     * both have to arrive, since a command may report on either.
     */
    public function testBothStreamsAreCaptured(): void
    {
        // Arrange
        $task = $this->taskRunning('echo "on stdout"; echo "on stderr" >&2; exit 2');

        // Act
        $message = $this->failureOf($task)->getMessage();

        // Assert
        $this->assertStringContainsString('on stdout', $message);
        $this->assertStringContainsString('on stderr', $message);
    }

    /**
     * A chatty command cannot fill the log, and the end is what is kept.
     *
     * The tail rather than the head: a command that prints progress has its error
     * at the bottom, so keeping the first 2 KB would reliably keep the useless
     * half. The cap matters because `schedule.log` is appended to on every tick.
     */
    public function testOnlyTheTailIsKept(): void
    {
        // Arrange — far more than the cap, with the marker at the very end
        $task = $this->taskRunning(
            'i=0; while [ $i -lt 400 ]; do echo "0123456789012345678901234567890123456789"; '
            . 'i=$((i+1)); done; echo "THE-ACTUAL-ERROR"; exit 1'
        );

        // Act
        $message = $this->failureOf($task)->getMessage();

        // Assert
        $this->assertStringContainsString('THE-ACTUAL-ERROR', $message, 'the end of the output is what matters');

        // The prefix plus at most the tail, with a little room for the sentence itself.
        $this->assertLessThan(
            ScheduledTask::OUTPUT_TAIL + 512,
            strlen($message),
            'an unbounded message fills schedule.log one tick at a time'
        );
    }

    /**
     * The output still reaches the console while the command runs.
     *
     * `passthru()`'s one virtue was that an interactive `schedule:run` showed the
     * work. Reading the pipe instead would have taken that away, so the chunks are
     * echoed on as they arrive.
     */
    public function testTheOutputIsStillPrinted(): void
    {
        // Arrange
        $task = $this->taskRunning('echo "work in progress"; exit 0');

        // Act
        ob_start();
        $task->run();
        $printed = (string) ob_get_clean();

        // Assert
        $this->assertStringContainsString('work in progress', $printed);
    }

    /**
     * A command that succeeds raises nothing, whatever it printed.
     *
     * The other direction: a change that attached output to every run would pass
     * every assertion above while breaking every schedule there is.
     */
    public function testASuccessfulCommandRaisesNothing(): void
    {
        // Arrange
        $task = $this->taskRunning('echo "all good"; exit 0');

        // Act
        ob_start();
        $ran = $task->run();
        ob_end_clean();

        // Assert
        $this->assertTrue($ran);
    }

    /**
     * An application that overrode the seam gets the message it always got.
     *
     * `runShellCommand()` is `protected`, so an override predating this never
     * records any output — and the property is null rather than `''` precisely so
     * that case is distinguishable. Without it the message would end in a bare
     * `Output:` for every such installation.
     */
    public function testAnOverriddenSeamLeavesTheMessageUnchanged(): void
    {
        // Arrange — the shape an application's own subclass has today
        $task = new class extends ScheduledTask {
            public function __construct()
            {
                parent::__construct('reports:nightly', 'command');
            }

            protected function runShellCommand(string $command): int
            {
                return 7;
            }
        };

        // Act
        $message = $this->failureOf($task)->getMessage();

        // Assert
        $this->assertStringContainsString("'reports:nightly' exited with status 7", $message);
        $this->assertStringNotContainsString('Output:', $message);
    }
}
