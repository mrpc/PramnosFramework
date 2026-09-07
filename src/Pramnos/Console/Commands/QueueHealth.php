<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Queue\QueueManager;

/**
 * Is the queue keeping up? Arrivals against completions, over a window.
 *
 * **A depth is a level; this is a rate.** The installation that asked for it watched a
 * dashboard read "a few thousand pending" for hours while one worker served a type
 * receiving 6.1 items a second against a ceiling of 4.4 — the level moved slowly enough
 * to look steady, the queue grew by 1.7 a second, and by the time anybody looked the
 * backlog was 175,000. The number that would have caught it in the first hour was on no
 * screen.
 *
 * **The exit status is the point.** `1` when the queue is losing, so `cron` mails it and
 * a monitor can page on it without anything parsing the output:
 *
 * ```
 * php pramnos queue:health --window=300              # every queue, last 5 minutes
 * php pramnos queue:health --type=imports --json     # one queue, for a monitor
 * php pramnos queue:health --per-type                # a line per type, and which one is losing
 * ```
 *
 * A per-type run is how the reported incident is visible at all: the totals across every
 * queue were healthy, and one type inside them was not.
 */
class QueueHealth extends Command
{
    /** Losing: the queue took in more than it finished over the window. */
    public const LOSING = 1;

    protected function configure(): void
    {
        $this->setName('queue:health')
            ->setDescription('Report arrivals against completions; exit 1 when the queue is losing')
            ->addOption(
                'window',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seconds to measure over',
                300
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Restrict to these task type(s); repeatable'
            )
            ->addOption(
                'per-type',
                null,
                InputOption::VALUE_NONE,
                'A line per task type, so one losing queue is not hidden by healthy totals'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Machine-readable output'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Pramnos\Console\Application $consoleApp */
        $consoleApp  = $this->getApplication();
        $application = $consoleApp->internalApplication;
        $application->init();
        $application->database->setTrackingInfo(null, 'QueueHealthCLI', []);

        $controller   = QueueManager::controllerOrPlain($application, $this->getControllerName());
        $queueManager = $this->createQueueManager($controller);

        $window = (int) $input->getOption('window');
        $types  = $input->getOption('type');
        $types  = is_array($types) && $types !== [] ? $types : null;

        $report = ['overall' => $queueManager->throughput($window, $types)];

        if ($input->getOption('per-type')) {
            foreach ($this->typesToReport($queueManager, $types) as $type) {
                $report['types'][$type] = $queueManager->throughput($window, $type);
            }
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report));
        } else {
            $this->render($output, $report);
        }

        /*
         * Losing overall, **or** any single type losing.
         *
         * The totals hid the reported incident completely: every other queue was draining
         * and one was not, so the sum looked healthy. An alert that only watches the sum is
         * an alert that fires after the backlog is large enough to move the sum.
         */
        foreach ($report['types'] ?? [] as $numbers) {
            if ($numbers['losing']) {
                return self::LOSING;
            }
        }

        return $report['overall']['losing'] ? self::LOSING : Command::SUCCESS;
    }

    /**
     * Which types to break down, when `--per-type` was asked for.
     *
     * The types named on the command line, or the ones the queue has actually seen inside
     * the window — read from the table rather than from `getTaskTypes()`, which scans a
     * directory of handler classes. A type with a handler and no traffic is noise here, and
     * a type with traffic and no handler is exactly what somebody needs to see.
     *
     * @param  string[]|null $types
     * @return string[]
     */
    protected function typesToReport(QueueManager $manager, ?array $types): array
    {
        if ($types !== null) {
            return $types;
        }

        return $manager->activeTaskTypes();
    }

    /**
     * @param array{overall: array<string, mixed>, types?: array<string, array<string, mixed>>} $report
     */
    private function render(OutputInterface $output, array $report): void
    {
        $window = (int) $report['overall']['window'];
        $output->writeln('<info>Queue throughput over the last ' . $window . 's</info>');
        $output->writeln('');
        $output->writeln($this->line('all types', $report['overall']));

        foreach ($report['types'] ?? [] as $type => $numbers) {
            $output->writeln($this->line($type, $numbers));
        }

        $output->writeln('');

        if ($report['overall']['losing']) {
            $output->writeln(
                '<error>The queue is losing: more arrived than finished.</error> '
                . 'Either the arrival rate rose or there are not enough workers for it.'
            );

            return;
        }

        $output->writeln('<info>Keeping up.</info>');
    }

    /**
     * @param array<string, mixed> $numbers
     */
    private function line(string $label, array $numbers): string
    {
        $net = (int) $numbers['net'];

        // The tag goes around the finished, padded number. Interpolated into the format
        // string it counts towards the field width, so the columns walk.
        $shown = str_pad(($net > 0 ? '+' : '') . $net, 7);
        $shown = $net < 0 ? '<error>' . $shown . '</error>' : $shown;

        return sprintf(
            '  %-24s in %-7d out %-7d net %s pending %-8d processing %d',
            $label,
            (int) $numbers['arrivals'],
            (int) $numbers['completions'],
            $shown,
            (int) $numbers['pending'],
            (int) $numbers['processing']
        );
    }

    // ── Configurable hooks ────────────────────────────────────────────────────

    protected function getControllerName(): string
    {
        return 'Queueitems';
    }

    /**
     * @param  \Pramnos\Application\Controller $controller
     */
    protected function createQueueManager($controller): QueueManager
    {
        return new QueueManager($controller);
    }
}
