<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Pramnos\Queue\QueueItem;

/**
 * Lists permanently-failed tasks from the background queue.
 *
 * A task reaches the terminal 'failed' state only once it has exhausted all of
 * its retry attempts (see QueueManager::markTaskAsFailed()). This command gives
 * operators visibility into which tasks need attention and, together with
 * queue:retry, a way to re-queue them.
 *
 * ## Usage
 *
 * ```
 * php pramnos queue:failed
 * php pramnos queue:failed --json
 * php pramnos queue:failed --limit=50
 * ```
 *
 * Override getControllerName()/createQueueItemModel() in a subclass to inject
 * an application-specific controller or QueueItem model.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class QueueFailed extends Command
{
    protected function configure(): void
    {
        $this->setName('queue:failed')
            ->setDescription('List permanently-failed tasks in the background queue')
            ->setHelp('Shows tasks that exhausted all retry attempts and are now in the failed state')
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output results as JSON instead of a table'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of failed tasks to list (0 = unlimited)',
                100
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');
        $json  = (bool)$input->getOption('json');

        $tasks = $this->getFailedTasks($limit);

        if ($json) {
            $output->writeln((string)json_encode(
                ['count' => count($tasks), 'tasks' => $tasks],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            return Command::SUCCESS;
        }

        if (empty($tasks)) {
            $output->writeln('<info>No failed tasks in the queue.</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Type', 'Attempts', 'Last Error', 'Updated']);

        foreach ($tasks as $task) {
            $table->addRow([
                $task['id'],
                $task['type'],
                $task['attempts'] . '/' . $task['maxattempts'],
                $this->truncate((string)($task['error'] ?? ''), 60),
                (string)($task['updatedat'] ?? $task['completedat'] ?? ''),
            ]);
        }

        $table->render();
        $output->writeln('<comment>' . count($tasks) . ' failed task(s).</comment>');

        return Command::SUCCESS;
    }

    // ── Data access (overridable seams) ────────────────────────────────────────

    /**
     * Fetch the failed tasks as normalised associative rows.
     *
     * The default implementation initialises the application, resolves the
     * queue controller and queries the QueueItem model for tasks in the
     * 'failed' state, newest first. Override in tests to supply canned data
     * without a live database.
     *
     * @param  int $limit  Maximum rows to return (0 = unlimited)
     * @return array<int, array<string, mixed>>
     */
    protected function getFailedTasks(int $limit = 0): array
    {
        // @codeCoverageIgnoreStart
        // Genuine live-DB boundary: this body resolves the queue controller
        // (which boots the application + opens a database connection) and runs
        // a SELECT against the QueueItem model. It cannot execute without a real
        // database, so it is excluded from line coverage. Every unit test
        // overrides this seam with canned rows; the real query path is verified
        // by the integration suite (./dockertest, MySQL/PostgreSQL/TimescaleDB).
        // The pure normalisation logic it delegates to (normalizeTask) IS unit
        // tested directly.
        $model = $this->createQueueItemModel($this->getQueueController());

        $order = 'ORDER BY updatedat DESC, taskid DESC';
        if ($limit > 0) {
            $order .= ' LIMIT ' . $limit;
        }

        $rows = $model->getList("WHERE status = 'failed'", $order);

        return array_map([$this, 'normalizeTask'], $rows ?: []);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Normalise a QueueItem (or any object exposing the same public properties)
     * into a flat associative row used for both table and JSON output.
     *
     * @param  QueueItem|object $task
     * @return array<string, mixed>
     */
    protected function normalizeTask(object $task): array
    {
        return [
            'id'          => isset($task->taskid) ? (int)$task->taskid : null,
            'type'        => $task->type        ?? '',
            'status'      => $task->status      ?? 'failed',
            'attempts'    => isset($task->attempts) ? (int)$task->attempts : 0,
            'maxattempts' => isset($task->maxattempts) ? (int)$task->maxattempts : 0,
            'error'       => $task->error       ?? null,
            'createdat'   => $task->createdat   ?? null,
            'updatedat'   => $task->updatedat   ?? null,
            'completedat' => $task->completedat ?? null,
        ];
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
        $application->database->setTrackingInfo(null, 'QueueFailedCLI', []);

        return \Pramnos\Queue\QueueManager::controllerOrPlain($application, $this->getControllerName());
        // @codeCoverageIgnoreEnd
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    /**
     * Controller name used to initialise the QueueItem model.
     *
     * @return string
     */
    protected function getControllerName(): string
    {
        return 'Queueitems';
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
        // QueueItem has its own dedicated tests; unit tests override this
        // factory, so the raw instantiation line is excluded from coverage.
        return new QueueItem($controller);
        // @codeCoverageIgnoreEnd
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Truncate a single-line preview of a (possibly multi-line) error message.
     *
     * @param  string $text
     * @param  int    $max
     * @return string
     */
    private function truncate(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 1) . '…';
        }
        if (strlen($text) > $max) {
            return substr($text, 0, $max - 1) . '…';
        }
        return $text;
    }
}
