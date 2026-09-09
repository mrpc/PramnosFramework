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
            )
            ->addOption(
                'history',
                null,
                InputOption::VALUE_OPTIONAL,
                'Read the hourly roll-up instead of live rows, this many hours back — the '
                . 'only way to answer a window longer than the purge retention',
                false
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

        /*
         * `--history` reads the buckets, which outlive the rows.
         *
         * The live numbers cannot answer a window longer than the purge retention, and
         * before this they did not say so: `--window=86400` on an installation purging
         * hourly counted the ninety minutes that survived, reported the rate as if it were
         * the day, and exited 0.
         */
        $historyHours = $input->getOption('history');
        if ($historyHours !== false && $historyHours !== null) {
            $hours = (int) $historyHours > 0 ? (int) $historyHours : 168;
            $rows  = $queueManager->history($hours, $types);

            if ($input->getOption('json')) {
                $output->writeln((string) json_encode(['history' => $rows, 'hours' => $hours]));
            } else {
                $this->renderHistory($output, $rows, $hours);
            }

            return 0;
        }

        $report = ['overall' => $queueManager->throughput($window, $types)];

        if ($input->getOption('per-type')) {
            foreach ($this->typesToReport($queueManager, $types) as $type) {
                $report['types'][$type] = $queueManager->throughput($window, $type);
            }
        }

        // Detected rather than configured: the retention is an argument on somebody else's
        // crontab, and the oldest surviving completion is a fact this connection has.
        $report['truncated'] = $this->windowReachesPastTheRows($queueManager, $window);

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
    /**
     * Does the requested window reach further back than the rows that are still there?
     *
     * If it does, `throughput()` is measuring a shorter period than the caller asked for
     * and reporting the rate as if it were the whole thing — which is not an error the
     * command could previously have known about, because the retention lives in a cron
     * argument and not in the database.
     *
     * Best effort: a table that cannot be read answers "no", which is the behaviour before
     * this existed. A health command that failed because it could not check its own caveat
     * would be worse than the caveat.
     */
    private function windowReachesPastTheRows(QueueManager $queueManager, int $window): bool
    {
        try {
            $horizon = $queueManager->retentionHorizon();
        } catch (\Throwable) {
            return false;
        }

        if ($horizon === null) {
            return false;
        }

        return strtotime($horizon) > (time() - max(1, $window));
    }

    /**
     * The hourly roll-up, per type.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderHistory(OutputInterface $output, array $rows, int $hours): void
    {
        $output->writeln('<info>Queue history over the last ' . $hours . 'h, from the hourly roll-up</info>');
        $output->writeln('');

        if ($rows === []) {
            $output->writeln(
                '<comment>Nothing recorded.</comment> The roll-up is written by '
                . '`queue:cleanup` — until it has run once there is nothing to read, and if '
                . 'the framework migrations have not been applied there is nowhere to write.'
            );

            return;
        }

        $output->writeln(sprintf(
            '%-19s  %-20s %7s %9s %9s %9s %9s %7s',
            'hour',
            'type',
            'tasks',
            'exec avg',
            'exec max',
            'wait avg',
            'wait max',
            'fail'
        ));

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%-19s  %-20s %7d %9.3f %9.3f %9.3f %9.3f %6.1f%%',
                substr((string) $row['bucket'], 0, 19),
                substr((string) $row['type'], 0, 20),
                (int) $row['tasks'],
                (float) $row['exec_mean'],
                (float) $row['exec_max'],
                (float) $row['wait_mean'],
                (float) $row['wait_max'],
                ((float) $row['fail_rate']) * 100
            ));
        }

        $output->writeln('');
        $output->writeln(
            '<comment>wait</comment> is how long a task sat before a worker took it. It is '
            . 'the number a latency burst shows in first, and it is recorded nowhere else.'
        );
    }

    private function render(OutputInterface $output, array $report): void
    {
        $window = (int) $report['overall']['window'];
        $output->writeln('<info>Queue throughput over the last ' . $window . 's</info>');

        if (!empty($report['truncated'])) {
            $output->writeln(
                '<comment>The oldest completed task in the table is newer than this '
                . 'window</comment>, so these numbers cover less than ' . $window . 's and '
                . 'the rate is that of the shorter period. `queue:cleanup` has deleted the '
                . 'rest. Use --history for a window this long.'
            );
        }
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
        } else {
            $output->writeln('<info>Keeping up.</info>');
        }

        $this->renderEffort($output, $report['overall']);
    }

    /**
     * Whether the workers are computing or waiting — and it is the more useful line.
     *
     * `execution_time` is wall clock around the handler, so a task taking 0.9 seconds reads
     * the same whether it is working or waiting. An installation chasing queue throughput
     * ruled in an atomic claim, a missing index, TimescaleDB compression and a CPU-bound
     * handler over four hours, because every one of those fitted the wall clock. The tasks
     * were at 1.6% CPU, waiting on a cache invalidation that scanned the whole redis
     * keyspace per model save, and only `/proc/<pid>/wchan` said so.
     *
     * So the ratio is printed with the next step attached, because the two answers point in
     * opposite directions: computing means faster code or more cores, waiting means adding
     * workers will not help and the thing being waited on is already saturated.
     *
     * @param array<string, mixed> $numbers
     */
    private function renderEffort(OutputInterface $output, array $numbers): void
    {
        $computing = $numbers['computing'] ?? null;

        if ($computing === null) {
            $output->writeln(
                '<comment>No CPU time recorded for this window</comment> — run the framework '
                . 'migrations, or this platform has no getrusage(). Without it, waiting and '
                . 'working look the same.'
            );

            return;
        }

        $percent = round($computing * 100, 1);

        if ($computing < 0.2) {
            $output->writeln(sprintf(
                '<error>These tasks are not computing: %.1f%% of their wall clock was CPU.</error> '
                . 'They are waiting on something — a database, redis, an HTTP call. More '
                . 'workers will not help; whatever they wait for is already the limit.',
                $percent
            ));

            return;
        }

        $output->writeln(sprintf(
            'Computing %.1f%% of wall clock%s',
            $percent,
            $computing < 0.6 ? ' — a fair share of it is waiting.' : '.'
        ));
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
            '  %-24s in %-7d out %-7d net %s pending %-8d processing %-4d %s',
            $label,
            (int) $numbers['arrivals'],
            (int) $numbers['completions'],
            $shown,
            (int) $numbers['pending'],
            (int) $numbers['processing'],
            $this->clears($numbers)
        );
    }

    /**
     * When this queue clears, or that it will not.
     *
     * **"never" is the useful answer**, and an estimate usually refuses to give it: dividing
     * the backlog by the completion rate alone produces a figure that recedes every time it
     * is refreshed, so a queue reads as nearly finished right up until it obviously is not.
     *
     * The installation that asked for this had aggregated across types and got *"about a
     * month"* out of one queue draining in nine hours and another that never cleared —
     * describing neither and pointing at nothing. Which is the same lesson as `--per-type`,
     * one column over.
     *
     * @param array<string, mixed> $numbers
     */
    private function clears(array $numbers): string
    {
        if (!array_key_exists('clears_in', $numbers)) {
            return '';
        }

        $seconds = $numbers['clears_in'];

        if ($seconds === null) {
            return 'clears never at this rate';
        }

        if ($seconds === 0) {
            return 'empty';
        }

        if ($seconds < 3600) {
            return 'clears in ' . (int) ceil($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            return 'clears in ' . round($seconds / 3600, 1) . 'h';
        }

        return 'clears in ' . round($seconds / 86400, 1) . 'd';
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
