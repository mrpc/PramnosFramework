<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Queue\QueueManager;

/**
 * CLI command that purges old terminal-state tasks from the queue table.
 *
 * Run periodically (e.g. daily via cron or the Scheduler) to keep the
 * queueitems table lean. By default it removes completed and failed tasks
 * older than 24 hours. Warning tasks are kept 10× longer by default.
 *
 * Override createQueueManager() in a subclass to inject an application-
 * specific QueueManager.
 *
 */
class CleanupQueue extends Command
{
    protected function configure(): void
    {
        $this->setName('queue:cleanup')
            ->setDescription('Delete old completed tasks from the queue table')
            ->addOption(
                'hours',
                null,
                InputOption::VALUE_OPTIONAL,
                'Remove completed/failed tasks older than this many hours',
                24
            )
            ->addOption(
                'include-failed',
                null,
                InputOption::VALUE_NEGATABLE,
                'Also purge failed tasks (use --no-include-failed to keep them)',
                true
            )
            ->addOption(
                'include-warning',
                null,
                InputOption::VALUE_NONE,
                'Also purge warning tasks (kept 10× longer than completed tasks by default)'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum rows to delete per run (0 = unlimited)',
                0
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Pramnos\Console\Application $consoleApp */
        $consoleApp  = $this->getApplication();
        $application = $consoleApp->internalApplication;
        $application->init();
        $application->database->setTrackingInfo(null, 'CleanupQueueCLI', []);

        $controller   = $application->getController($this->getControllerName());
        $queueManager = $this->createQueueManager($controller);

        $hours   = (int)$input->getOption('hours');
        $limit   = (int)$input->getOption('limit');
        $statuses = ['completed'];

        if ($input->getOption('include-failed')) {
            $statuses[] = 'failed';
        }
        if ($input->getOption('include-warning')) {
            $statuses[] = 'warning';
        }

        $output->writeln('<info>Purging tasks with status: ' . implode(', ', $statuses) . '</info>');
        $output->writeln('<info>Removing tasks completed more than ' . $hours . ' hours ago</info>');

        $before = $queueManager->getStats();
        $output->writeln('Before: ' . json_encode($before));

        $deleted = $queueManager->purgeOldTasks($hours, $statuses, $limit);
        $output->writeln('<info>Deleted ' . $deleted . ' task(s)</info>');

        // Warning tasks decay 10× more slowly than completed/failed
        $deletedWarning = $queueManager->purgeOldTasks($hours * 10, ['warning'], $limit);
        $output->writeln('<info>Deleted ' . $deletedWarning . ' warning task(s)</info>');

        $after = $queueManager->getStats();
        $output->writeln('After: ' . json_encode($after));

        return Command::SUCCESS;
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    /**
     * Controller name used to initialise the QueueManager.
     *
     * Override when the application uses a different controller name.
     *
     * @return string
     */
    protected function getControllerName(): string
    {
        return 'Queueitems';
    }

    /**
     * Factory method for the QueueManager.
     *
     * Override to inject an application-specific subclass.
     *
     * @param  \Pramnos\Application\Controller $controller
     * @return QueueManager
     */
    protected function createQueueManager($controller): QueueManager
    {
        return new QueueManager($controller);
    }
}
