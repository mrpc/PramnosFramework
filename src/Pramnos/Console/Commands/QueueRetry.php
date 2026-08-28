<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Queue\QueueItem;
use Pramnos\Queue\QueueManager;

/**
 * Re-queues permanently-failed tasks so the worker will process them again.
 *
 * Delegates to QueueManager::retryTask(), which resets a task in the terminal
 * 'failed' state back to 'pending' with a zeroed attempt counter and cleared
 * error. Tasks that are not in the 'failed' state are ignored.
 *
 * ## Usage
 *
 * ```
 * php pramnos queue:retry 42        # retry a single failed task
 * php pramnos queue:retry --all     # retry every failed task
 * ```
 *
 * Override getControllerName()/createQueueManager() in a subclass to inject an
 * application-specific controller or QueueManager.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class QueueRetry extends Command
{
    /**
     * Lazily-created QueueManager used for retry operations.
     *
     * @var QueueManager|null
     */
    private ?QueueManager $queueManager = null;

    protected function configure(): void
    {
        $this->setName('queue:retry')
            ->setDescription('Re-queue permanently-failed tasks so they run again')
            ->setHelp('Resets failed tasks back to pending. Pass a task id to retry one, or --all for every failed task')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'ID of a single failed task to retry'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Retry every failed task in the queue'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id  = $input->getArgument('id');
        $all = (bool)$input->getOption('all');

        // A single id and --all are mutually exclusive; requiring exactly one
        // avoids the ambiguity of "retry which tasks?".
        if ($all && $id !== null) {
            $output->writeln('<error>Provide either a task id or --all, not both.</error>');
            return Command::FAILURE;
        }
        if (!$all && $id === null) {
            $output->writeln('<error>Specify a failed task id (e.g. "queue:retry 42") or use --all.</error>');
            return Command::FAILURE;
        }

        if ($all) {
            $ids = $this->getFailedTaskIds();
            if (empty($ids)) {
                $output->writeln('<info>No failed tasks to retry.</info>');
                return Command::SUCCESS;
            }

            $requeued = 0;
            foreach ($ids as $taskId) {
                if ($this->retryTask((int)$taskId)) {
                    $requeued++;
                }
            }

            $output->writeln('<info>Re-queued ' . $requeued . ' failed task(s).</info>');
            return Command::SUCCESS;
        }

        // Single task path.
        $taskId = (int)$id;
        if ($this->retryTask($taskId)) {
            $output->writeln('<info>Re-queued 1 task (ID ' . $taskId . ').</info>');
            return Command::SUCCESS;
        }

        $output->writeln(
            '<comment>Task ' . $taskId . ' was not re-queued (not found or not in a failed state).</comment>'
        );
        return Command::FAILURE;
    }

    // ── Data access (overridable seams) ────────────────────────────────────────

    /**
     * Reset a single failed task back to pending.
     *
     * @param  int $taskId
     * @return bool  True when the task was re-queued
     */
    protected function retryTask(int $taskId): bool
    {
        // @codeCoverageIgnoreStart
        // Genuine live-DB boundary: delegates to QueueManager::retryTask(),
        // which issues an UPDATE against the queueitems table. Not executable
        // without a database; unit tests override this seam. QueueManager has
        // its own tests and the real path is covered by the integration suite.
        return $this->getQueueManager()->retryTask($taskId);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Return the ids of all tasks currently in the 'failed' state.
     *
     * @return int[]
     */
    protected function getFailedTaskIds(): array
    {
        // @codeCoverageIgnoreStart
        // Genuine live-DB boundary: resolves the queue controller and SELECTs
        // the ids of failed tasks from the QueueItem model. Cannot run without a
        // database; unit tests override this seam and the integration suite
        // exercises the real query.
        $model = $this->createQueueItemModel($this->getQueueController());
        $rows  = $model->getList("WHERE status = 'failed'", 'ORDER BY taskid ASC');

        $ids = [];
        foreach ($rows ?: [] as $row) {
            if (isset($row->taskid)) {
                $ids[] = (int)$row->taskid;
            }
        }
        return $ids;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Resolve the application controller that provides database access.
     *
     * @return \Pramnos\Application\Controller
     */
    protected function getQueueController()
    {
        // @codeCoverageIgnoreStart
        // Genuine live-DB boundary: boots the real application, opens the
        // database connection and resolves an application controller. Not
        // executable without a live environment; covered by integration tests.
        /** @var \Pramnos\Console\Application $consoleApp */
        $consoleApp  = $this->getApplication();
        $application = $consoleApp->internalApplication;
        $application->init();
        $application->database->setTrackingInfo(null, 'QueueRetryCLI', []);

        return \Pramnos\Queue\QueueManager::controllerOrPlain($application, $this->getControllerName());
        // @codeCoverageIgnoreEnd
    }

    /**
     * Lazily build (and cache) the QueueManager.
     *
     * @return QueueManager
     */
    protected function getQueueManager(): QueueManager
    {
        if ($this->queueManager === null) {
            $this->queueManager = $this->createQueueManager($this->getQueueController());
        }
        return $this->queueManager;
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    /**
     * Controller name used to initialise the QueueManager / QueueItem model.
     *
     * @return string
     */
    protected function getControllerName(): string
    {
        return 'Queueitems';
    }

    /**
     * Factory for the QueueManager. Override to inject an application-specific
     * subclass.
     *
     * @param  \Pramnos\Application\Controller $controller
     * @return QueueManager
     */
    protected function createQueueManager($controller): QueueManager
    {
        // @codeCoverageIgnoreStart
        // Instantiates the QueueManager against a live controller/DB. Overridden
        // in unit tests; QueueManager has its own dedicated coverage.
        return new QueueManager($controller);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Factory for the QueueItem model. Override to inject an application
     * specific subclass.
     *
     * @param  \Pramnos\Application\Controller $controller
     * @return QueueItem
     */
    protected function createQueueItemModel($controller): QueueItem
    {
        // @codeCoverageIgnoreStart
        // Instantiates the QueueItem ORM model against a live controller/DB.
        // Overridden in unit tests; QueueItem has its own dedicated coverage.
        return new QueueItem($controller);
        // @codeCoverageIgnoreEnd
    }
}
