<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Scheduling\Scheduler;

/**
 * Runs all scheduled tasks that are due at the current moment.
 *
 * Intended to be called every minute by a system cron job:
 * ```
 * * * * * * php pramnos schedule:run >> /dev/null 2>&1
 * ```
 *
 * ## Options
 *
 * `--pretend` — lists due tasks without actually executing them.
 *
 * Exit codes:
 *   0 — success (all due tasks ran without exception)
 *   1 — one or more tasks threw an exception
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Console
 */
class ScheduleRun extends Command
{
    protected static $defaultName = 'schedule:run';

    protected function configure(): void
    {
        $this
            ->setName('schedule:run')
            ->setDescription('Run all scheduled tasks that are currently due')
            ->addOption(
                'pretend',
                null,
                InputOption::VALUE_NONE,
                'List due tasks without executing them (dry run)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now  = new \DateTime();
        $due  = Scheduler::getDue($now);
        $errors = 0;

        if (empty($due)) {
            $output->writeln('<info>No tasks due at ' . $now->format('Y-m-d H:i') . '.</info>');
            return Command::SUCCESS;
        }

        foreach ($due as $task) {
            $summary = $task->getSummary();
            $label   = $summary['description'] ?: $summary['handler'];

            if ($input->getOption('pretend')) {
                $output->writeln("[dry-run] Would run: <info>{$label}</info>");
                continue;
            }

            $output->writeln("Running: <info>{$label}</info>");

            try {
                $task->run();
                $output->writeln("  <info>✓ Done</info>");
            } catch (\Throwable $e) {
                $output->writeln("  <error>✗ Failed: {$e->getMessage()}</error>");
                ++$errors;
            }
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
