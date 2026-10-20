<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\FrameworkSchedule;
use Pramnos\Scheduling\ScheduledTask;
use Pramnos\Scheduling\Scheduler;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A scheduled command marked `inProcess()` runs through the console, in this process — no PHP
 * started for it — with the exit status and output a child process would have given.
 *
 * Every-minute tasks were a PHP boot each, 1,440 a day per task. In process, an every-minute
 * drain costs what the drain costs.
 */
#[CoversClass(ScheduledTask::class)]
#[CoversClass(FrameworkSchedule::class)]
class ScheduledCommandInProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        ScheduledTask::useConsole(null);
        Scheduler::reset();
        parent::tearDown();
    }

    /** A console with a command that echoes its argument, and one that fails. */
    private function console(): Application
    {
        $console = new Application();
        $console->add(new class extends Command {
            /** `probe:echo <word>`: prints the word, succeeds. */
            protected function configure(): void
            {
                $this->setName('probe:echo')->addArgument('word', InputArgument::REQUIRED);
            }

            /** Prints and succeeds. */
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('said ' . $input->getArgument('word'));

                return Command::SUCCESS;
            }
        });
        $console->add(new class extends Command {
            /** `probe:boom`: throws. */
            protected function configure(): void
            {
                $this->setName('probe:boom');
            }

            /** Throws, as a command that breaks does. */
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new \RuntimeException('the drain broke');
            }
        });

        return $console;
    }

    /** A task whose shell must never be reached: a spawn fails the test. */
    private function task(string $handler): ScheduledTask
    {
        return new class($handler) extends ScheduledTask {
            public function __construct(string $handler)
            {
                parent::__construct($handler, 'command');
            }

            /** The seam a child process would go through. */
            protected function runShellCommand(string $command): int
            {
                throw new \LogicException('a process was started for an in-process task');
            }
        };
    }

    /**
     * In process, the command runs through the console with its argument, its output shown, and
     * no process started.
     */
    public function testAnInProcessTaskRunsThroughTheConsole(): void
    {
        // Arrange
        ScheduledTask::useConsole($this->console());
        $task = $this->task('probe:echo hello')->inProcess();

        // Act
        ob_start();
        $ran = $task->run();
        $shown = (string) ob_get_clean();

        // Assert
        $this->assertTrue($ran);
        $this->assertStringContainsString('said hello', $shown);
    }

    /**
     * A command that throws is a failed task — the exception the scheduler reports, with the
     * reason in it — and the scheduler's process goes on.
     */
    public function testAFailingInProcessTaskIsReportedWithItsReason(): void
    {
        // Arrange
        ScheduledTask::useConsole($this->console());
        $task = $this->task('probe:boom')->inProcess();

        // Act
        ob_start();
        try {
            $task->run();
            $message = '';
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        } finally {
            ob_end_clean();
        }

        // Assert
        $this->assertStringContainsString("'probe:boom' exited with status 1", $message);
        $this->assertStringContainsString('the drain broke', $message);
    }

    /**
     * With no console known, an in-process task starts a process as before; and a framework
     * command named by `FrameworkSchedule::inProcess()` is registered in process.
     */
    public function testWithoutAConsoleItStartsAProcessAndFrameworkTasksCanOptIn(): void
    {
        // Arrange
        $task = $this->task('probe:echo hello')->inProcess();
        FrameworkSchedule::inProcess('spool:drain');

        // Act
        try {
            $task->run();
            $spawned = false;
        } catch (\LogicException) {
            $spawned = true;
        }
        FrameworkSchedule::register();
        $drain = array_values(array_filter(Scheduler::all(), static fn (ScheduledTask $t): bool => $t->getSummary()['handler'] === 'spool:drain'))[0] ?? null;

        // Assert
        $this->assertTrue($spawned, 'without a console the task ran nowhere');
        $this->assertNotNull($drain);
        $flag = new \ReflectionProperty(ScheduledTask::class, 'inProcess');
        $this->assertTrue($flag->getValue($drain), 'the framework task was not marked in process');
    }
}
