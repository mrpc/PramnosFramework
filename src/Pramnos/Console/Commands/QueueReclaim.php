<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Queue\QueueManager;

/**
 * Give back the tasks whose worker died holding them.
 *
 * A worker claims a task by setting `status = 'processing'` with a lock expiry. If it
 * then dies — a fatal, an OOM kill, a `SIGKILL`, a machine going away — nothing writes
 * the end of that story, because **a process that dies cannot record its own failure**.
 *
 * `getNextTask()` picks up a stalled row on its own, and that covers the ordinary case.
 * It does not cover a type whose workers are all dead (the reclaim happens when somebody
 * asks for work, so nobody asks) or a row that has used all its attempts (the stalled
 * branch requires attempts remaining, so it stays `processing` for ever — never claimed,
 * never failed, counted as *in progress*, and invisible to `queue:cleanup`, which reads
 * only terminal states).
 *
 * Run it on a schedule, next to `queue:cleanup`. It is one statement per outcome and the
 * lock expiry is the entire safety condition: a worker that is alive holds a lock that
 * has not expired, so nothing running can have its task taken away.
 *
 * ```
 * php pramnos queue:reclaim                  # everything eligible
 * php pramnos queue:reclaim --grace=60       # allow for worker clocks disagreeing
 * php pramnos queue:reclaim --type=imports   # one queue at a time
 * php pramnos queue:reclaim --dry-run        # count without writing
 * ```
 */
class QueueReclaim extends Command
{
    protected function configure(): void
    {
        $this->setName('queue:reclaim')
            ->setDescription('Requeue or fail tasks abandoned by a worker that died holding them')
            ->addOption(
                'grace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Extra seconds past the lock expiry before a task is eligible',
                0
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Restrict to these task type(s); repeatable'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what is eligible without changing anything'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Pramnos\Console\Application $consoleApp */
        $consoleApp  = $this->getApplication();
        $application = $consoleApp->internalApplication;
        $application->init();
        $application->database->setTrackingInfo(null, 'QueueReclaimCLI', []);

        $controller   = QueueManager::controllerOrPlain($application, $this->getControllerName());
        $queueManager = $this->createQueueManager($controller);

        $grace = (int) $input->getOption('grace');
        $types = $input->getOption('type');
        $types = is_array($types) && $types !== [] ? $types : null;

        if ($input->getOption('dry-run')) {
            /*
             * Counted, not written — and counted through the same `throughput()` the health
             * command uses, so a dry run cannot disagree with the reclaim about what is in
             * progress. What it cannot say is the requeued/failed split, which depends on
             * each row's attempts; the run itself reports that.
             */
            $numbers = $queueManager->throughput(300, $types);

            $output->writeln(
                '<comment>Dry run.</comment> '
                . $numbers['processing'] . ' task(s) currently processing; '
                . 'those whose lock expired more than ' . $grace . 's ago would be reclaimed.'
            );

            return Command::SUCCESS;
        }

        $counts = $queueManager->reclaimAbandonedTasks($grace, $types);

        if ($counts['requeued'] === 0 && $counts['failed'] === 0) {
            $output->writeln('<info>Nothing to reclaim.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(
            '<info>Requeued ' . $counts['requeued'] . ' task(s); '
            . 'recorded ' . $counts['failed'] . ' as failed (no attempts left).</info>'
        );

        return Command::SUCCESS;
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    /**
     * Controller name used to initialise the QueueManager.
     */
    protected function getControllerName(): string
    {
        return 'Queueitems';
    }

    /**
     * Factory method for the QueueManager. Override to inject a subclass.
     *
     * @param  \Pramnos\Application\Controller $controller
     */
    protected function createQueueManager($controller): QueueManager
    {
        return new QueueManager($controller);
    }
}
