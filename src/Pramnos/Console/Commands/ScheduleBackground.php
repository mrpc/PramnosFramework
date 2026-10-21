<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Scheduling\ScheduledTask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The detached process a `runInBackground()` task runs in. Not for typing: the scheduler starts it.
 *
 * It runs the command the way the scheduler would have — the same console, its output kept —
 * with what the scheduler could not do without waiting: it takes the overlap lock and holds it
 * until the command exits, and records the run's end, duration, exit status and output on the
 * history row the scheduler opened (`--run`).
 */
class ScheduleBackground extends Command
{
    /** @var string The command name */
    protected static $defaultName = 'schedule:background';

    /** Registers the name, the command to run, and the scheduler's run and lock. */
    protected function configure(): void
    {
        $this->setName('schedule:background')
            ->setHidden(true)
            ->setDescription('Run a scheduled command in the background (started by the scheduler)')
            ->addArgument('task', InputArgument::REQUIRED, 'The command and its arguments, as scheduled')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'The run-history row the scheduler opened')
            ->addOption('lock-dir', null, InputOption::VALUE_REQUIRED, 'Hold the task\'s overlap lock from this directory')
            ->addOption('stale', null, InputOption::VALUE_REQUIRED, 'The overlap lock\'s staleness, in seconds', '0');
    }

    /** Run the command once, holding the lock and finishing the history row. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $task = $this->task((string) $input->getArgument('task'));
        $lockDir = (string) ($input->getOption('lock-dir') ?? '');
        if ($lockDir !== '') {
            $task->withoutOverlapping($lockDir, (int) $input->getOption('stale'));
        }
        $run = (int) ($input->getOption('run') ?? 0);

        try {
            $ran = $run > 0 ? $task->runRequested($run) : $task->run();
        } catch (\Throwable $e) {
            $output->writeln($e->getMessage());

            return self::FAILURE;
        }

        return $ran ? self::SUCCESS : self::FAILURE;
    }

    /** The task to run — a seam, so a test can run something other than a real command. */
    protected function task(string $command): ScheduledTask
    {
        return new ScheduledTask($command, 'command');
    }
}
