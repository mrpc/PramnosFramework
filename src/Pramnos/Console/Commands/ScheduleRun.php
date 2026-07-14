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
 * @license    MIT
 */
class ScheduleRun extends Command
{
    protected static $defaultName = 'schedule:run';

    /** Path to the schedule definition file. Overridable for testing (default: ROOT/app/schedule.php). */
    public ?string $scheduleFile = null;

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
        Scheduler::loadDefinitions($this->scheduleFile);
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

            $start = microtime(true);
            try {
                $ran = $task->run();
                $ms  = (int) round((microtime(true) - $start) * 1000);
                if ($ran) {
                    $output->writeln("  <info>✓ Done</info>");
                    $this->logSchedule("ran: {$label} ({$summary['expression']}) in {$ms}ms");
                } else {
                    $output->writeln("  <comment>↷ Skipped (previous run still active)</comment>");
                    $this->logSchedule("skipped: {$label} ({$summary['expression']}) — overlapping run");
                }
            } catch (\Throwable $e) {
                $output->writeln("  <error>✗ Failed: {$e->getMessage()}</error>");
                $this->logSchedule("failed: {$label} ({$summary['expression']}) — {$e->getMessage()}", ['level' => 'error']);
                ++$errors;
            }
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Record a scheduled-task outcome to the `schedule` log channel.
     *
     * Cron typically redirects schedule:run output to /dev/null, so this is the
     * only durable record of what ran, when and with what result. The entries
     * appear in the framework log viewer under `schedule.log`. Isolated in its
     * own method so tests can capture calls without touching the filesystem.
     *
     * @param array<string,mixed> $context Extra context (e.g. ['level' => 'error']).
     */
    protected function logSchedule(string $message, array $context = []): void
    {
        \Pramnos\Logs\Logger::log($message, 'schedule', 'log', false, $context);
    }
}
