<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table as TableHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Application\Application;
use Pramnos\Database\DeferredWriteQueue;

/**
 * Writes the rows that were queued because their chunk was already compressed.
 *
 * {@see DeferredWriteQueue} catches a late write instead of losing it; this
 * command is the other half — it puts those rows where they belong. The work it
 * does is arranged around the one expensive operation involved: a compressed
 * chunk has to be decompressed before it accepts a write, and compressed again
 * afterwards. Doing that pair once per row would cost more than the data is
 * worth, so the drain groups the backlog by chunk and pays it once per chunk.
 *
 * Run it from cron, as often as the application's tolerance for late data
 * requires — hourly is a reasonable default.
 *
 * ## Usage
 *
 * ```
 * php pramnos timescale:drain                     # write everything waiting
 * php pramnos timescale:drain --status            # what is waiting, without writing
 * php pramnos timescale:drain --table=readings    # one table only
 * php pramnos timescale:drain --retry-failed      # re-queue rows that failed
 * ```
 *
 * Exit codes: 0 = everything waiting was written (including "nothing was
 * waiting"), 1 = at least one row could not be written and is now marked
 * failed.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class TimescaleDrain extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'timescale:drain';

    protected function configure(): void
    {
        $this
            ->setName('timescale:drain')
            ->setDescription(
                'Write queued rows into their hypertables, one decompress/compress '
                . 'pair per chunk'
            )
            ->addOption(
                'table',
                't',
                InputOption::VALUE_REQUIRED,
                'Limit to one table'
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_NONE,
                'Report what is waiting, per table, and write nothing'
            )
            ->addOption(
                'retry-failed',
                null,
                InputOption::VALUE_NONE,
                'Put failed rows back in the queue before draining'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = Application::getInstance();
        if (!$app instanceof Application
            || !$app->database instanceof \Pramnos\Database\Database) {
            $output->writeln('<error>No application instance with a database available.</error>');

            return Command::FAILURE;
        }

        $queue = $this->makeQueue($app->database);
        $only  = $input->getOption('table');
        $only  = (is_string($only) && $only !== '') ? $only : null;

        if ($input->getOption('retry-failed')) {
            $reset = $queue->retryFailed($only);
            $output->writeln(
                '<info>' . $reset . ' failed row(s) put back in the queue.</info>'
            );
        }

        if ($input->getOption('status')) {
            return $this->status($output, $queue, $only);
        }

        return $this->drain($output, $queue, $only);
    }

    /**
     * The queue this command drives.
     *
     * A seam rather than a `new` in the middle of execute(), so that the
     * command's own behaviour — what it prints, what it returns — can be tested
     * without a database behind it.
     *
     * @param  \Pramnos\Database\Database $db
     * @return DeferredWriteQueue
     */
    protected function makeQueue($db): DeferredWriteQueue
    {
        return new DeferredWriteQueue($db);
    }

    /**
     * Report the backlog without touching it.
     *
     * @param  DeferredWriteQueue $queue
     * @param  string|null        $only
     * @return int
     */
    protected function status(OutputInterface $output, DeferredWriteQueue $queue, ?string $only): int
    {
        $tables = $only !== null ? [$only] : $queue->tablesWithPendingRows();

        if ($tables === []) {
            $output->writeln('<info>Nothing is waiting.</info>');

            return $this->reportFailures($output, $queue, $only);
        }

        $helper = new TableHelper($output);
        $helper->setHeaders(['Table', 'Waiting', 'Failed', 'Writable from']);

        foreach ($tables as $table) {
            $cutoff = $queue->writeCutoff($table);
            $helper->addRow([
                $table,
                number_format($queue->pending($table)),
                number_format($queue->failed($table)),
                $cutoff === null
                    ? 'any time (no compression policy)'
                    : date('Y-m-d H:i', $cutoff),
            ]);
        }

        $helper->render();

        return Command::SUCCESS;
    }

    /**
     * Do the work.
     *
     * @param  DeferredWriteQueue $queue
     * @param  string|null        $only
     * @return int
     */
    protected function drain(OutputInterface $output, DeferredWriteQueue $queue, ?string $only): int
    {
        $started = time();
        $report  = $queue->process(
            $only,
            static fn(string $message) => $output->writeln($message)
        );

        $inserted = 0;
        $failed   = 0;

        foreach ($report as $table => $stats) {
            $inserted += $stats['inserted'];
            $failed   += $stats['failed'];

            $output->writeln(
                $table . ': <info>' . number_format($stats['inserted'])
                . '</info> written'
                . ($stats['chunks'] > 0
                    ? ' across ' . $stats['chunks'] . ' chunk(s)'
                    : '')
                . ($stats['failed'] > 0
                    ? ', <error>' . number_format($stats['failed']) . ' failed</error>'
                    : '')
            );
        }

        if ($report === []) {
            $output->writeln('<info>Nothing was waiting.</info>');

            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(
            number_format($inserted) . ' row(s) written in '
            . (time() - $started) . 's.'
        );

        if ($failed > 0) {
            $output->writeln(
                '<error>' . number_format($failed) . ' row(s) could not be written '
                . 'and are kept with their error message. Fix the cause, then '
                . 'run with --retry-failed.</error>'
            );

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Mention failed rows when there is nothing else to say.
     *
     * A queue reported as empty while rows sit in it marked failed is the one
     * way this command could mislead, so the empty report says so.
     *
     * @param  DeferredWriteQueue $queue
     * @param  string|null        $only
     * @return int
     */
    protected function reportFailures(OutputInterface $output, DeferredWriteQueue $queue, ?string $only): int
    {
        $failed = $queue->failed($only);

        if ($failed > 0) {
            $output->writeln(
                '<comment>' . number_format($failed) . ' row(s) failed earlier and '
                . 'are still kept. Run with --retry-failed once the cause is fixed.</comment>'
            );
        }

        return Command::SUCCESS;
    }
}
