<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\CommandBase;

/**
 * A supervised worker ignores Ctrl+C, and traps the signal a supervisor actually sends.
 *
 * `SIGTERM` is how an orchestrator or `systemctl stop` asks a worker to leave. Ctrl+C is
 * not — a supervised worker has no terminal, so a `SIGINT` reaching it came from somewhere
 * it was not addressed: an operator interrupting a shell that happens to share the process
 * group, a script signalling a group rather than a pid. Taking the whole pool down for a
 * keystroke in a neighbouring window is a pool that goes down for a keystroke.
 *
 * **`SIG_IGN` rather than a trap that does nothing**, because the default action for
 * `SIGINT` is termination: an untrapped one kills the process outright, mid-task, and the
 * row it was holding is left `processing` behind a lock nobody holds — the leak
 * `queue:reclaim` exists to clean up.
 *
 * And only when supervised. An interactive run keeps Ctrl+C, because there it is the
 * operator talking to this process and it should stop.
 *
 * Contributed by a consuming application that had built it on its own side, and the only
 * reason it was not upstream sooner is that *supervised* needed a definition the framework
 * could state. It had one already: `PRAMNOS_JOB_LOCK_FILE`, which is how a supervisor hands
 * the lock path down.
 */
#[CoversClass(CommandBase::class)]
class SupervisedWorkerIgnoresInterruptTest extends TestCase
{
    /**
     * A command whose signal wiring is reachable without running a daemon.
     */
    private function command(): object
    {
        return new class extends CommandBase {
            protected function getJobName(): string
            {
                return 'interrupt-probe';
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                return 0;
            }

            /** @return int[] */
            public function exposeStopSignals(): array
            {
                return $this->stopSignals();
            }

            public function exposeInstall(): void
            {
                $this->installStopSignals();
            }

            public function exposeSupervised(): bool
            {
                return $this->isSupervised();
            }
        };
    }

    private function supervise(?string $lockFile): void
    {
        if ($lockFile === null) {
            putenv(CommandBase::LOCK_FILE_ENV);
            unset($_ENV[CommandBase::LOCK_FILE_ENV]);

            return;
        }

        putenv(CommandBase::LOCK_FILE_ENV . '=' . $lockFile);
        $_ENV[CommandBase::LOCK_FILE_ENV] = $lockFile;
    }

    /**
     * Supervised: `SIGTERM` is trapped and `SIGINT` is not in the list.
     *
     * The list is what gets a handler. `SIGINT`'s absence from it is only half the story —
     * see the next test for why it has to be actively ignored rather than merely untrapped.
     */
    #[RunInSeparateProcess]
    public function testSupervisedTrapsOnlyTheSupervisorsSignal(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('No pcntl here, so there is nothing to install.');
        }

        // Arrange
        $command = $this->command();
        $this->supervise('/tmp/pramnos-interrupt-probe.lock');

        // Act
        $signals = $command->exposeStopSignals();

        // Assert
        $this->assertTrue($command->exposeSupervised());
        $this->assertContains(SIGTERM, $signals);
        $this->assertNotContains(SIGINT, $signals, 'Ctrl+C was trapped on a supervised worker');
    }

    /**
     * And `SIGINT` is set to `SIG_IGN`, not left at its default.
     *
     * The assertion that matters, and the reason a missing trap is not enough: the default
     * action for `SIGINT` is to terminate. Left untrapped, a stray one kills the worker
     * outright with a task in hand.
     */
    #[RunInSeparateProcess]
    public function testSupervisedActivelyIgnoresTheInterrupt(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('No pcntl here.');
        }

        // Arrange
        $command = $this->command();
        $this->supervise('/tmp/pramnos-interrupt-probe.lock');

        // Act
        $command->exposeInstall();

        // Assert — asked of the process, not of our own bookkeeping
        $this->assertSame(SIG_IGN, pcntl_signal_get_handler(SIGINT));

        // and SIGTERM is a handler rather than an action
        $this->assertNotSame(SIG_IGN, pcntl_signal_get_handler(SIGTERM));
        $this->assertNotSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM));
    }

    /**
     * Interactive: Ctrl+C still stops it.
     *
     * The control, and the half a fix like this loses by accident. An operator running
     * `queue:process` in a terminal expects Ctrl+C to work, and a worker that ignored it
     * would have to be killed — which abandons the task it is holding, so "safer" would have
     * made it less safe.
     */
    #[RunInSeparateProcess]
    public function testAnInteractiveRunStillStopsOnCtrlC(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('No pcntl here.');
        }

        // Arrange — nobody handed down a lock file
        $command = $this->command();
        $this->supervise(null);

        // Act
        $signals = $command->exposeStopSignals();
        $command->exposeInstall();

        // Assert
        $this->assertFalse($command->exposeSupervised());
        $this->assertContains(SIGINT, $signals, 'Ctrl+C stopped working in a terminal');
        $this->assertNotSame(SIG_IGN, pcntl_signal_get_handler(SIGINT));
    }

    /**
     * Both cases still stop on the signal a supervisor sends.
     *
     * The invariant across the branch: whatever is decided about Ctrl+C, `SIGTERM` is trapped
     * either way. A conditional that got that wrong would leave one of the two able to be
     * killed mid-task by a `systemctl stop`.
     */
    #[RunInSeparateProcess]
    public function testSigtermIsTrappedEitherWay(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('No pcntl here.');
        }

        foreach (array('/tmp/pramnos-interrupt-probe.lock', null) as $lockFile) {
            // Arrange
            $command = $this->command();
            $this->supervise($lockFile);

            // Assert
            $this->assertContains(
                SIGTERM,
                $command->exposeStopSignals(),
                'SIGTERM was not trapped with lock file: ' . var_export($lockFile, true)
            );
        }
    }
}
